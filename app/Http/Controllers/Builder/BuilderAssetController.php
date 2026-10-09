<?php

namespace App\Http\Controllers\Builder;

use App\Http\Controllers\Concerns\HandlesCrudData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Builder\BuilderAssetRequest;
use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Organization;
use App\Services\MediaStorage;
use App\Services\OrganizationStorage;
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
 * Its own shelf, not the organization's media library — the library is what an organization PLAYS, this is raw material
 * that only means something inside a design. Same organization wall as everything else: an organization's shelf is its own files
 * alone — uploaded, or copied in with a Premium Template (owner, 2026-10-07). Above the organizations an upload with no
 * organization chosen is the platform's (owner, 2026-09-29), for its ads for every organization, and deleted there alone.
 */
class BuilderAssetController extends Controller
{
    use HandlesCrudData;

    public function __construct(private readonly MediaStorage $storage, private readonly OrganizationStorage $quota) {}

    public function index(): View
    {
        // Above the organizations the Owner list (owner, 2026-10-08): the platform's files, where the page opens and where an upload
        // goes; All, every file with its owner on it; or one organization's. An organization's own people see their own alone (owner,
        // 2026-10-07).
        $organizations = $this->aboveTheOrganizations()
            ? Organization::orderBy('name')->get(['id', 'name'])->toArray()
            : [];

        // The shelf's storage comes with the page (none for the platform's files, which have no wall),
        // so the meter is there at once instead of pushing the drop box down when the list arrives.
        return view('builder.assets', [
            'organizations' => $organizations,
            'aboveTheOrganizations' => $this->aboveTheOrganizations(),
            'storage' => $this->storageOf(null),
        ]);
    }

    /**
     * The storage of the organization the shelf is showing: inside an organization its own; above the organizations the organization chosen
     * in the Owner list, or none while the platform's files or all of them are listed.
     *
     * @return array{used: int, limit: int}|null
     */
    private function storageOf(?int $chosenOrganizationId): ?array
    {
        $organizationId = $this->aboveTheOrganizations() ? $chosenOrganizationId : ((int) session('current_organization_id') ?: null);

        return $organizationId !== null && Organization::whereKey($organizationId)->exists() ? $this->quota->summary($organizationId) : null;
    }

    /** The shelf, newest first, each row saying whose it is, which ads use it and whether this person may delete it. */
    public function data(Request $request): JsonResponse
    {
        // The Owner list, as on the Ads page: the platform's files (`platform`), one organization's (its id), or everything.
        $filters = $request->validate(['organization_id' => ['nullable', 'regex:/^(platform|[1-9][0-9]{0,9})$/']]);
        $owner = $filters['organization_id'] ?? null;
        $shelf = $owner !== null && $owner !== 'platform' ? (int) $owner : null;

        // Only ever narrows what visibleTo allows — see BuilderController::data.
        $query = BuilderAsset::visibleTo(auth()->user())
            ->when($owner === 'platform', fn (Builder $query) => $query->onShelfOf(null))
            ->when($shelf !== null, fn (Builder $query) => $query->onShelfOf($shelf))
            ->with('organization:id,name')
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
                    $asset->setAttribute('used_by', $usage[$asset->id] ?? []);
                    // Why it may not be deleted yet, in destroy's own words, so the shelf says it before any
                    // confirmation (destroy decides again).
                    $asset->setAttribute('in_use_message', isset($usage[$asset->id]) ? $this->stillUsedMessage($usage[$asset->id]) : null);
                    $asset->setAttribute('organization_name', $asset->organization?->name);
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
        $organizationId = $this->targetOrganizationId($request->validated());

        $file = $request->file('file');
        // What came, before a picture is made lighter (PictureOptimizer) — read now, while the upload is still on disk.
        $uploaded = (int) $file->getSize();

        $asset = $this->storage->addBuilderAsset(
            $file,
            $organizationId,
            $request->validated(),
            $this->titleFor($request->input('title'), $file->getClientOriginalName()),
            auth()->id(),
        );

        ActivityLog::record('ad_asset.uploaded', $asset, $organizationId === null
            ? "Uploaded {$asset->title} to the ad builder, shared with every organization"
            : "Uploaded {$asset->title} to the ad builder", organizationId: $organizationId);

        $request->forgetFinishedUpload();

        return response()->json([
            'message' => 'Uploaded',
            'asset' => $asset,
            'storage' => $this->quota->summary($organizationId),
            // How much lighter the picture was made, for the uploader to say.
            'lighter' => $asset->size < $uploaded ? ['from' => $uploaded, 'to' => $asset->size] : null,
        ]);
    }

    /**
     * Take a file off the shelf — refused while an ad still uses it, and the refusal says which ads, so
     * nobody has to hunt for the one design that breaks (the same courtesy a held role gets). The platform's file is
     * deleted above the organizations alone; the organizations' copies of it are theirs, and stay.
     */
    public function destroy(BuilderAsset $asset): JsonResponse
    {
        $asset = BuilderAsset::visibleTo(auth()->user())->findOrFail($asset->id);

        abort_unless($this->mayDelete($asset), 403, 'Deleting a file needs the Delete Ads permission.');

        $usage = $this->usageFor(collect([$asset]))[$asset->id] ?? null;

        if ($usage !== null) {
            throw ValidationException::withMessages(['title' => $this->stillUsedMessage($usage)]);
        }

        $title = $asset->title;
        $shared = $asset->isShared();
        // An organization's file belongs to its organization's log; the platform's to none.
        $organizationId = $asset->organization_id;

        // Inside a transaction, so the model's hook really does unlink the files after the row is gone —
        // outside one, "after commit" means at once, before the DELETE has run.
        DB::transaction(fn () => $asset->delete());

        ActivityLog::record('ad_asset.deleted', null, $shared
            ? "Deleted {$title}, the platform's, from the ad builder"
            : "Deleted ad asset {$title}", organizationId: $organizationId);

        return response()->json(['message' => 'Deleted', 'storage' => $this->quota->summary($organizationId)]);
    }

    /**
     * Which ads name these assets, as {assetId: [ad name, …]}.
     *
     * An id inside the document is what "used" means, so the documents are read and searched, the draft and the version on the
     * screens alike: an organization's file in its organization's designs, the platform's in the platform's ads for every
     * organization — an organization's designs use its own files alone (owner, 2026-10-07), copies of the platform's included.
     * So everybody who may delete a file may also open every ad that keeps it.
     *
     * @param  Collection<int, BuilderAsset>  $assets
     * @return array<int, list<string>>
     */
    private function usageFor(Collection $assets): array
    {
        $platform = $assets->filter(fn (BuilderAsset $asset) => $asset->isShared())->pluck('id')->all();
        $byOrganization = $assets->reject(fn (BuilderAsset $asset) => $asset->isShared())
            ->groupBy(fn (BuilderAsset $asset) => (int) $asset->organization_id)
            ->map(fn (Collection $group) => $group->pluck('id')->all());

        if ($platform === [] && $byOrganization->isEmpty()) {
            return [];
        }

        $usage = [];

        BuilderAd::query()
            ->where(fn (Builder $query) => $query->whereIn('organization_id', $byOrganization->keys()->all())
                ->when($platform !== [], fn (Builder $query) => $query->orWhereNull('organization_id')))
            ->select(['id', 'organization_id', 'name', 'document', 'published_document'])
            ->lazyById(200)
            ->each(function (BuilderAd $ad) use ($platform, $byOrganization, &$usage) {
                $candidates = $ad->isShared() ? $platform : ($byOrganization->get((int) $ad->organization_id) ?? []);

                // The draft and the version on the screens alike: a file only the published page still shows is in
                // use on every television carrying it (the brute-force round, 2026-09-29 — deleting it broke them).
                $document = json_encode([$ad->document, $ad->published_document]);

                if ($candidates === [] || ! is_string($document)) {
                    return;
                }

                foreach ($candidates as $assetId) {
                    // The document keeps asset ids as numbers under `assetId`, in elements and in background
                    // layers alike, so one search covers both.
                    if (preg_match('/"assetId":\s*'.$assetId.'\b/', $document) === 1) {
                        $usage[$assetId][] = $ad->name;
                    }
                }
            });

        return $usage;
    }

    /**
     * Why a file in use stays: the ads that keep it, by name.
     *
     * @param  list<string>  $names
     */
    private function stillUsedMessage(array $names): string
    {
        $named = implode(', ', array_slice($names, 0, 3)).(count($names) > 3 ? ' and '.(count($names) - 3).' more' : '');

        return "Still used by {$named}. Take it out of those ads first, and publish the ones whose screens still show it.";
    }

    /** Delete Ads — and, for the platform's file, standing above the organizations (an organization never sees one). */
    private function mayDelete(BuilderAsset $asset): bool
    {
        return Gate::allows('ad-destroy') && (! $asset->isShared() || $this->aboveTheOrganizations());
    }

    /** Whose file this is, above the organizations: its organization, or "Every organization" for the platform's own. */
    private function ownerLabel(BuilderAsset $asset): ?string
    {
        if (! $this->aboveTheOrganizations()) {
            return null;
        }

        return $asset->isShared() ? 'Platform' : $asset->organization?->name;
    }

    /**
     * Which shelf the file goes on — the same answer BuilderController gives for a new ad. An organization's person
     * uploads to the organization they are working in. The platform team stands in no organization: the page sends the organization
     * chosen in its Owner list, which has to exist — or none (Platform, or All), and the file is the platform's.
     *
     * @param  array<string, mixed>  $validated
     */
    private function targetOrganizationId(array $validated): ?int
    {
        if ($this->aboveTheOrganizations()) {
            $organizationId = (int) ($validated['organization_id'] ?? 0);

            if ($organizationId === 0) {
                return null;
            }

            if (! Organization::whereKey($organizationId)->exists()) {
                throw ValidationException::withMessages([
                    'file' => 'That organization no longer exists. Reload the page and choose again.',
                ]);
            }

            return $organizationId;
        }

        $organizationId = (int) session('current_organization_id');

        if (! $organizationId) {
            throw ValidationException::withMessages([
                'file' => 'Select an organization before uploading — an ad\'s pictures belong to the organization they were uploaded for.',
            ]);
        }

        return $organizationId;
    }

    private function aboveTheOrganizations(): bool
    {
        return auth()->user()->globalRole() !== null;
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
