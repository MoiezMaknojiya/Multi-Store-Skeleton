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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The Assets tab: everything the Builder's ads are made of (docs/AD-BUILDER-SPEC.md §3).
 *
 * Its own shelf, not the store's media library — the library is what a shop PLAYS, this is raw material
 * that only means something inside a design. Same store wall as everything else.
 */
class BuilderAssetController extends Controller
{
    use HandlesCrudData;

    public function __construct(private readonly MediaStorage $storage) {}

    public function index(): View
    {
        // Above the stores the shelf can be narrowed to one shop; a store's own people see theirs alone.
        $stores = auth()->user()->globalRole() !== null
            ? Store::orderBy('name')->get(['id', 'name'])->toArray()
            : [];

        return view('builder.assets', ['stores' => $stores]);
    }

    /** The shelf, newest first, each row saying which ads use it. */
    public function data(Request $request): JsonResponse
    {
        $filters = $request->validate(['store_id' => ['nullable', 'integer', 'min:1']]);

        // Only ever narrows what visibleTo allows — see BuilderController::data.
        $query = BuilderAsset::visibleTo(auth()->user())
            ->when($filters['store_id'] ?? null, fn (Builder $query, int|string $storeId) => $query->where('store_id', $storeId))
            ->with('store:id,name')
            ->latest();

        return $this->paginatedResponse(
            $request,
            $query,
            ['title'],
            'assets',
            ['*'],
            // One query for the page rather than one per row: which ads name each asset.
            function (Collection $rows) {
                $usage = $this->usageFor($rows->pluck('id')->all(), $rows->pluck('store_id')->unique()->all());

                $rows->each(function (BuilderAsset $asset) use ($usage) {
                    $asset->setAttribute('used_by', $usage[$asset->id] ?? []);
                    $asset->setAttribute('store_name', $asset->store?->name);
                });
            },
        );
    }

    /** Put a file on the shelf. */
    public function store(BuilderAssetRequest $request): JsonResponse
    {
        $storeId = $this->targetStoreId($request->validated());

        $file = $request->file('file');
        $stored = $this->storage->storeBuilderAsset($file, $storeId, $request->validated());

        $asset = BuilderAsset::fromStoredFile(
            $storeId,
            $this->titleFor($request->input('title'), $file->getClientOriginalName()),
            $stored,
            auth()->id(),
        );

        ActivityLog::record('ad_asset.uploaded', $asset, "Uploaded {$asset->title} to the ad builder", storeId: $storeId);

        return response()->json(['message' => 'Uploaded', 'asset' => $asset]);
    }

    /**
     * Take a file off the shelf — refused while an ad still uses it, and the refusal says which ads, so
     * nobody has to hunt for the one design that breaks (the same courtesy a held role gets).
     */
    public function destroy(BuilderAsset $asset): JsonResponse
    {
        $asset = BuilderAsset::visibleTo(auth()->user())->findOrFail($asset->id);

        $used = $this->usageFor([$asset->id], [$asset->store_id])[$asset->id] ?? [];

        if ($used !== []) {
            throw ValidationException::withMessages([
                'title' => 'Still used by '.implode(', ', array_slice($used, 0, 3))
                    .(count($used) > 3 ? ' and '.(count($used) - 3).' more' : '')
                    .'. Take it out of those ads first.',
            ]);
        }

        $title = $asset->title;
        $storeId = $asset->store_id;

        // Inside a transaction, so the model's hook really does unlink the files after the row is gone —
        // outside one, "after commit" means at once, before the DELETE has run.
        DB::transaction(fn () => $asset->delete());

        ActivityLog::record('ad_asset.deleted', null, "Deleted ad asset {$title}", storeId: $storeId);

        return response()->json(['message' => 'Deleted']);
    }

    /**
     * Which ads name these assets, as {assetId: [ad name, …]}.
     *
     * An id inside the document is what "used" means, so the documents are read and searched. The stores
     * are narrowed first, so one shop's shelf never reads another shop's designs.
     *
     * @param  array<int, int>  $assetIds
     * @param  array<int, int>  $storeIds
     * @return array<int, array<int, string>>
     */
    private function usageFor(array $assetIds, array $storeIds): array
    {
        if ($assetIds === [] || $storeIds === []) {
            return [];
        }

        $usage = [];

        BuilderAd::whereIn('store_id', $storeIds)
            ->get(['id', 'name', 'document'])
            ->each(function (BuilderAd $ad) use ($assetIds, &$usage) {
                $document = json_encode($ad->document);

                foreach ($assetIds as $assetId) {
                    // The document keeps asset ids as numbers under `assetId`, in elements and in
                    // background layers alike, so one search covers both.
                    if (is_string($document) && preg_match('/"assetId":\s*'.$assetId.'\b/', $document) === 1) {
                        $usage[$assetId][] = $ad->name;
                    }
                }
            });

        return $usage;
    }

    /**
     * Which shop's shelf the file goes on — the same answer BuilderController gives for a new ad. A store's
     * person uploads to the store they are working in. The platform team stands in no store, so the page
     * sends the shop chosen in its Shop list, and it has to be a shop that exists.
     *
     * @param  array<string, mixed>  $validated
     */
    private function targetStoreId(array $validated): int
    {
        if (auth()->user()->globalRole() !== null) {
            $storeId = (int) ($validated['store_id'] ?? 0);

            if (! $storeId || ! Store::whereKey($storeId)->exists()) {
                throw ValidationException::withMessages([
                    'file' => 'Choose the shop in the Shop list first — an ad\'s pictures belong to one shop.',
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
