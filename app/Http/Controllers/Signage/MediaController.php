<?php

namespace App\Http\Controllers\Signage;

use App\Http\Controllers\Concerns\HandlesCrudData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Signage\StoreMediaRequest;
use App\Http\Requests\Signage\UpdateMediaRequest;
use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\Media;
use App\Models\Organization;
use App\Services\MediaStorage;
use App\Services\OrganizationStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MediaController extends Controller
{
    use HandlesCrudData;

    /** Sort keys the listing accepts, mapped to column + direction. Anything else
     *  falls back to newest-first rather than being passed to the query. */
    private const SORTS = [
        'newest' => ['created_at', 'desc'],
        'oldest' => ['created_at', 'asc'],
        'title_asc' => ['title', 'asc'],
        'title_desc' => ['title', 'desc'],
    ];

    /**
     * The media library page. Above the organizations it reads one library at a time — the platform's own, or an
     * organization's — and the chooser decides where an upload lands as well (docs/CHANNEL-CONTENT-SPEC.md §3). The
     * storage of the library it opens on comes with the page, so the meter is there at once instead of pushing
     * the filters and the drop box down when the list arrives.
     */
    public function index(Request $request, OrganizationStorage $quota): View
    {
        $aboveTheOrganizations = $request->user()->globalRole() !== null;

        return view('media.index', [
            'libraries' => $aboveTheOrganizations ? Organization::orderBy('name')->get(['id', 'name'])->toArray() : null,
            'storage' => $quota->summary($this->libraryOnThePage(null)),
        ]);
    }

    /**
     * Return paginated, searchable media as JSON, scoped to where the person stands. Above the organizations the page
     * reads one library at a time — `library=platform` for the platform's own, or an organization's id — and with no
     * `library` every library within reach; inside an organization there is only the organization's, whatever is sent.
     */
    public function data(Request $request, OrganizationStorage $quota): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in([Media::TYPE_IMAGE, Media::TYPE_VIDEO, Media::TYPE_HTML])],
            'orientation' => ['nullable', 'in:landscape,portrait'],
            'sort' => ['nullable', 'string'],
            'library' => ['nullable', 'string', 'regex:/^(platform|[1-9][0-9]{0,9})$/'],
        ]);

        // An Ad Builder page taken off the screens (unpublished) is in no library until it is published again (owner,
        // 2026-09-21): the Ad Builder is where a draft lives.
        $query = Media::visibleTo(auth()->user())->withoutDrafts()->with('organization:id,name');

        if (auth()->user()->globalRole() !== null && filled($filters['library'] ?? null)) {
            $filters['library'] === 'platform'
                ? $query->platformOwned()
                : $query->where('organization_id', (int) $filters['library']);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['orientation'])) {
            $query->where('orientation', $filters['orientation']);
        }

        [$column, $direction] = self::SORTS[$filters['sort'] ?? 'newest'] ?? self::SORTS['newest'];
        $query->orderBy($column, $direction);

        $listing = $this->paginatedResponse($request, $query, ['title'], 'media', ['*'],
            // Why a file may not be deleted yet, so the panel says it before anybody confirms (spec §5).
            function (Collection $files) {
                $refusals = Media::stillInChannelsMessages($files->pluck('id')->all());

                $files->each(fn (Media $media) => $media->setAttribute('in_channels_message', $refusals[$media->id] ?? null));
            });

        // How full the library on the page is — the organization's own, or above the organizations the organization chosen; the
        // platform's own library has no wall (OrganizationStorage).
        return $listing->setData([...$listing->getData(true), 'storage' => $quota->summary($this->libraryOnThePage($filters['library'] ?? null))]);
    }

    /** The organization whose library the page shows, or null for the platform's own (or every library at once). */
    private function libraryOnThePage(?string $library): ?int
    {
        if (auth()->user()->globalRole() === null) {
            return (int) session('current_organization_id') ?: null;
        }

        return $library !== null && $library !== 'platform' && Organization::whereKey((int) $library)->exists() ? (int) $library : null;
    }

    /** Upload a file into a library: the current organization's, or — above the organizations — the one the page chose. */
    public function store(StoreMediaRequest $request, MediaStorage $storage, OrganizationStorage $quota): JsonResponse
    {
        $media = $storage->addToLibrary(
            $request->file('file'),
            $this->uploadTarget($request),
            $request->only(['duration_seconds', 'width', 'height', 'poster']),
            $request->validated('title'),
            auth()->id(),
        );

        ActivityLog::record('media.uploaded', $media, "Uploaded {$media->type} {$media->title}"
            .($media->isPlatformOwned() ? " to the platform's library" : ''));

        $request->forgetFinishedUpload();

        return response()->json(['message' => 'File uploaded successfully', 'media' => $media, 'storage' => $quota->summary($media->organization_id)]);
    }

    /** Rename a file, describe it, or set its schedule window. */
    public function update(UpdateMediaRequest $request, Media $media): JsonResponse
    {
        // Route middleware is not enough: the target has to be inside the organization the
        // actor is working in, or it does not exist for them (404, never 403).
        $media = Media::visibleTo(auth()->user())->findOrFail($media->id);
        $before = $media->title;

        // A file keeps its name and nothing else of what a person types (owner, 2026-10-01: "srif naam rakho").
        $media->update(['title' => $request->validated('title')]);

        ActivityLog::record('media.renamed', $media, "Renamed {$before} to {$media->title}");

        return response()->json(['message' => 'File renamed.', 'media' => $media]);
    }

    /** Delete a file, its thumbnail and its row. */
    public function destroy(Media $media, MediaStorage $storage, OrganizationStorage $quota): JsonResponse
    {
        $media = Media::visibleTo(auth()->user())->findOrFail($media->id);
        $title = $media->title;

        // A channel showing this file would lose the ad without anybody deciding so: refused, naming them.
        if (($inUse = $media->stillInAChannelMessage()) !== null) {
            throw ValidationException::withMessages(['file' => $inUse]);
        }

        // A published ad's row published before each got a poster of its own still names the design's
        // poster: that file belongs to the design, which outlives this row, so it stays.
        $thumbnail = $media->type === Media::TYPE_HTML
            && BuilderAd::where('thumbnail_path', $media->thumbnail_path)->exists()
                ? null
                : $media->thumbnail_path;

        // The row goes first and the files only once that has committed: a stale file on disk is
        // harmless, a row pointing at a deleted file is a broken thumbnail on every screen that lists it.
        DB::transaction(function () use ($media, $storage, $thumbnail) {
            $media->delete();

            DB::afterCommit(fn () => $storage->deleteFiles($media->disk, $media->path, $thumbnail));
        });

        ActivityLog::record('media.deleted', null, "Deleted media {$title}", organizationId: $media->organization_id);

        return response()->json(['message' => 'Media deleted successfully', 'storage' => $quota->summary($media->organization_id)]);
    }

    /**
     * Whose library an upload joins. An organization's person: the organization they are working in — with none selected
     * the upload is refused, there being no library of their own to put it in. The platform team: the organization
     * chosen on the page, or with none chosen the platform's own library (null).
     */
    private function uploadTarget(StoreMediaRequest $request): ?int
    {
        if (auth()->user()->globalRole() === null) {
            $organizationId = (int) session('current_organization_id');

            if (! $organizationId) {
                throw ValidationException::withMessages([
                    'file' => 'Select an organization before uploading — media belongs to the organization it is uploaded in.',
                ]);
            }

            return $organizationId;
        }

        $organizationId = (int) ($request->validated('organization_id') ?? 0);

        if ($organizationId !== 0 && ! Organization::whereKey($organizationId)->exists()) {
            throw ValidationException::withMessages([
                'file' => 'That organization no longer exists. Reload the page and choose again.',
            ]);
        }

        return $organizationId === 0 ? null : $organizationId;
    }
}
