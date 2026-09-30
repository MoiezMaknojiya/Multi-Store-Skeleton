<?php

namespace App\Http\Controllers\Builder;

use App\Http\Controllers\Concerns\HandlesCrudData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Builder\BuilderAssetRequest;
use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Store;
use App\Services\MediaStorage;
use App\Services\StoreStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The Assets page: everything the Builder's ads are made of (docs/AD-BUILDER-SPEC.md §3).
 *
 * Its own shelf, not the store's media library — the library is what a shop PLAYS, this is raw material
 * that only means something inside a design. Same store wall as everything else — and above it, the shelf the
 * platform shares with every shop (owner, 2026-09-29): an upload with no shop chosen goes there, every shop's
 * designs may use it, and it is deleted with Delete Shared Assets.
 */
class BuilderAssetController extends Controller
{
    use HandlesCrudData;

    public function __construct(private readonly MediaStorage $storage, private readonly StoreStorage $quota) {}

    public function index(): View
    {
        // Above the stores the shelf lists every shop's and the shared files, or one shop's and the shared (owner,
        // 2026-09-29: All shops is where a file for every shop goes, so there is no second option saying the same); a
        // store's own people see theirs and the shared ones.
        $stores = $this->aboveTheStores()
            ? Store::orderBy('name')->get(['id', 'name'])->toArray()
            : [];

        return view('builder.assets', ['stores' => $stores, 'aboveTheStores' => $this->aboveTheStores()]);
    }

    /**
     * The storage of the shop the shelf is showing: inside a store its own; above the stores the shop chosen
     * in the Shop list, or none while All shops is listed.
     *
     * @return array{used: int, limit: int}|null
     */
    private function storageOf(?int $chosenStoreId): ?array
    {
        $storeId = $this->aboveTheStores() ? $chosenStoreId : ((int) session('current_store_id') ?: null);

        return $storeId !== null && Store::whereKey($storeId)->exists() ? $this->quota->summary($storeId) : null;
    }

    /** The shelf, newest first, each row saying whose it is, which ads use it and whether this person may delete it. */
    public function data(Request $request): JsonResponse
    {
        // A shop's id — that shop's files and the shared ones; nothing lists everything in reach.
        $filters = $request->validate(['store_id' => ['nullable', 'integer', 'min:1']]);
        $shelf = isset($filters['store_id']) ? (int) $filters['store_id'] : null;

        // Only ever narrows what visibleTo allows — see BuilderController::data.
        $query = BuilderAsset::visibleTo(auth()->user())
            ->when($shelf !== null, fn (Builder $query) => $query->onShelfOf($shelf))
            ->with('store:id,name')
            ->latest();

        $listing = $this->paginatedResponse(
            $request,
            $query,
            ['title'],
            'assets',
            ['*'],
            // One pass over the designs for the page rather than one per row: which ads name each asset.
            function (Collection $rows) {
                $usage = $this->usageFor($rows);

                $rows->each(function (BuilderAsset $asset) use ($usage) {
                    $asset->setAttribute('used_by', $usage[$asset->id]['names'] ?? []);
                    $asset->setAttribute('used_elsewhere', $usage[$asset->id]['elsewhere'] ?? 0);
                    // Why it may not be deleted yet, in destroy's own words, so the shelf says it before any
                    // confirmation (destroy decides again).
                    $asset->setAttribute('in_use_message', isset($usage[$asset->id]) ? $this->stillUsedMessage($usage[$asset->id]) : null);
                    $asset->setAttribute('store_name', $asset->store?->name);
                    $asset->setAttribute('shared', $asset->isShared());
                    $asset->setAttribute('owner_label', $this->ownerLabel($asset));
                    $asset->setAttribute('can_delete', $this->mayDelete($asset));
                });
            },
        );

        return $listing->setData([
            ...$listing->getData(true),
            'storage' => $this->storageOf($shelf),
        ]);
    }

    /** Put a file on the shelf. */
    public function store(BuilderAssetRequest $request): JsonResponse
    {
        $storeId = $this->targetStoreId($request->validated());

        $file = $request->file('file');

        $asset = $this->storage->addBuilderAsset(
            $file,
            $storeId,
            $request->validated(),
            $this->titleFor($request->input('title'), $file->getClientOriginalName()),
            auth()->id(),
        );

        ActivityLog::record('ad_asset.uploaded', $asset, $storeId === null
            ? "Uploaded {$asset->title} to the ad builder, shared with every shop"
            : "Uploaded {$asset->title} to the ad builder", storeId: $storeId);

        $request->forgetFinishedUpload();

        return response()->json(['message' => 'Uploaded', 'asset' => $asset, 'storage' => $this->quota->summary($storeId)]);
    }

    /**
     * Take a file off the shelf — refused while an ad still uses it, and the refusal says which ads, so
     * nobody has to hunt for the one design that breaks (the same courtesy a held role gets). A shared file
     * goes from every shop's shelf, so it takes Delete Shared Assets, and any shop's ad keeps it.
     */
    public function destroy(BuilderAsset $asset): JsonResponse
    {
        $asset = BuilderAsset::visibleTo(auth()->user())->findOrFail($asset->id);

        abort_unless($this->mayDelete($asset), 403, $asset->isShared()
            ? 'Deleting a file shared with every shop needs the Delete Shared Assets permission.'
            : 'Deleting a file needs the Delete Ads permission.');

        $usage = $this->usageFor(collect([$asset]))[$asset->id] ?? null;

        if ($usage !== null) {
            throw ValidationException::withMessages(['title' => $this->stillUsedMessage($usage)]);
        }

        $title = $asset->title;
        $shared = $asset->isShared();
        // A shop's file belongs to its shop's log; a shared one's to where the person deleting it stands.
        $storeId = $asset->store_id ?? $this->standingStoreId();

        // Inside a transaction, so the model's hook really does unlink the files after the row is gone —
        // outside one, "after commit" means at once, before the DELETE has run.
        DB::transaction(fn () => $asset->delete());

        ActivityLog::record('ad_asset.deleted', null, $shared
            ? "Deleted {$title}, shared with every shop, from the ad builder"
            : "Deleted ad asset {$title}", storeId: $storeId);

        return response()->json(['message' => 'Deleted', 'storage' => $this->quota->summary($shared ? $this->standingStoreId() : $storeId)]);
    }

    /**
     * Which ads name these assets, as {assetId: {names: [ad name, …], elsewhere: n}}.
     *
     * An id inside the document is what "used" means, so the documents are read and searched: a shop's own file in
     * its shop's designs only, a shared file in every shop's. A store's person is told the names of their own shop's
     * ads and only the number of other shops' (one shop never learns another's designs); above the stores every ad
     * is named, and one of another shop's with the shop's name.
     *
     * @param  Collection<int, BuilderAsset>  $assets
     * @return array<int, array{names: array<int, string>, elsewhere: int}>
     */
    private function usageFor(Collection $assets): array
    {
        $shared = $assets->filter(fn (BuilderAsset $asset) => $asset->isShared())->pluck('id')->all();
        $byStore = $assets->reject(fn (BuilderAsset $asset) => $asset->isShared())
            ->groupBy(fn (BuilderAsset $asset) => (int) $asset->store_id)
            ->map(fn (Collection $group) => $group->pluck('id')->all());

        if ($shared === [] && $byStore->isEmpty()) {
            return [];
        }

        $viewerStore = $this->aboveTheStores() ? null : $this->standingStoreId();
        $usage = [];

        BuilderAd::query()
            // Only a shared file is looked for past its own shop.
            ->when($shared === [], fn (Builder $query) => $query->whereIn('store_id', $byStore->keys()->all()))
            ->with('store:id,name')
            ->select(['id', 'store_id', 'name', 'document', 'published_document'])
            ->lazyById(200)
            ->each(function (BuilderAd $ad) use ($shared, $byStore, $viewerStore, &$usage) {
                $candidates = [...$shared, ...($byStore->get((int) $ad->store_id) ?? [])];

                // The draft and the version on the screens alike: a file only the published page still shows is in
                // use on every television carrying it (the brute-force round, 2026-09-29 — deleting it broke them).
                $document = json_encode([$ad->document, $ad->published_document]);

                if ($candidates === [] || ! is_string($document)) {
                    return;
                }

                foreach ($candidates as $assetId) {
                    // The document keeps asset ids as numbers under `assetId`, in elements and in background
                    // layers alike, so one search covers both.
                    if (preg_match('/"assetId":\s*'.$assetId.'\b/', $document) !== 1) {
                        continue;
                    }

                    $usage[$assetId] ??= ['names' => [], 'elsewhere' => 0];
                    $sharedFile = in_array($assetId, $shared, true);

                    if ($viewerStore !== null && (int) $ad->store_id !== $viewerStore) {
                        $usage[$assetId]['elsewhere']++;
                    } else {
                        $usage[$assetId]['names'][] = $viewerStore === null && $sharedFile && $ad->store !== null
                            ? "{$ad->name} ({$ad->store->name})"
                            : $ad->name;
                    }
                }
            });

        return $usage;
    }

    /**
     * Why a file in use stays: the ads the person may see by name, those of other shops counted — and nobody is told
     * to take a file out of ads they cannot open.
     *
     * @param  array{names: array<int, string>, elsewhere: int}  $usage
     */
    private function stillUsedMessage(array $usage): string
    {
        $names = $usage['names'];
        $elsewhere = $usage['elsewhere'];
        $others = $elsewhere === 1 ? 'an ad of another shop' : "{$elsewhere} ads of other shops";

        if ($names === []) {
            return "Still used by {$others}, so it stays: it can go once no shop's ad uses it.";
        }

        $named = implode(', ', array_slice($names, 0, 3)).(count($names) > 3 ? ' and '.(count($names) - 3).' more' : '');

        return $elsewhere > 0
            ? "Still used by {$named}, and by {$others}, so it stays: it can go once no shop's ad uses it."
            : "Still used by {$named}. Take it out of those ads first, and publish the ones whose screens still show it.";
    }

    /** A shop's own file with Delete Ads; one shared with every shop with Delete Shared Assets. */
    private function mayDelete(BuilderAsset $asset): bool
    {
        return Gate::allows($asset->isShared() ? 'ad-shared-destroy' : 'ad-destroy');
    }

    /** Whose file this is, in the words of the person looking: above the stores its shop or "Every shop", inside one the platform's. */
    private function ownerLabel(BuilderAsset $asset): ?string
    {
        if ($this->aboveTheStores()) {
            return $asset->isShared() ? 'Every shop' : $asset->store?->name;
        }

        return $asset->isShared() ? 'From the platform' : null;
    }

    /**
     * Which shelf the file goes on — the same answer BuilderController gives for a new ad. A store's person
     * uploads to the store they are working in. The platform team stands in no store: the page sends the shop
     * chosen in its Shop list, which has to exist — or none, and the file is shared with every shop.
     *
     * @param  array<string, mixed>  $validated
     */
    private function targetStoreId(array $validated): ?int
    {
        if ($this->aboveTheStores()) {
            $storeId = (int) ($validated['store_id'] ?? 0);

            if ($storeId === 0) {
                return null;
            }

            if (! Store::whereKey($storeId)->exists()) {
                throw ValidationException::withMessages([
                    'file' => 'That shop no longer exists. Reload the page and choose again.',
                ]);
            }

            return $storeId;
        }

        $storeId = (int) session('current_store_id');

        if (! $storeId) {
            throw ValidationException::withMessages([
                'file' => 'Select a store before uploading — an ad\'s pictures belong to the shop they were uploaded for.',
            ]);
        }

        return $storeId;
    }

    private function aboveTheStores(): bool
    {
        return auth()->user()->globalRole() !== null;
    }

    /** The shop a store's person is working in; none above the stores. */
    private function standingStoreId(): ?int
    {
        return $this->aboveTheStores() ? null : ((int) session('current_store_id') ?: null);
    }

    /** The name the person typed, or the file's own name without its extension. */
    private function titleFor(mixed $title, string $fileName): string
    {
        $title = is_string($title) ? trim($title) : '';

        if ($title !== '') {
            return mb_substr($title, 0, 255);
        }

        return mb_substr(pathinfo($fileName, PATHINFO_FILENAME) ?: 'Untitled', 0, 255);
    }
}
