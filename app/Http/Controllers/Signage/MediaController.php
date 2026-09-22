<?php

namespace App\Http\Controllers\Signage;

use App\Http\Controllers\Concerns\HandlesCrudData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Signage\StoreMediaRequest;
use App\Http\Requests\Signage\UpdateMediaRequest;
use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\Media;
use App\Models\Store;
use App\Services\MediaStorage;
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
        'expiry_asc' => ['expires_at', 'asc'],
        'expiry_desc' => ['expires_at', 'desc'],
    ];

    /**
     * The media library page. Above the stores it reads one library at a time — the platform's own, or a
     * shop's — and the chooser decides where an upload lands as well (docs/CHANNEL-CONTENT-SPEC.md §3).
     */
    public function index(Request $request): View
    {
        return view('media.index', [
            'libraries' => $request->user()->globalRole() !== null
                ? Store::orderBy('name')->get(['id', 'name'])->toArray()
                : null,
        ]);
    }

    /**
     * Return paginated, searchable media as JSON, scoped to where the person stands. Above the stores the page
     * reads one library at a time — `library=platform` for the platform's own, or a shop's id — and with no
     * `library` every library within reach; inside a store there is only the store's, whatever is sent.
     */
    public function data(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in([Media::TYPE_IMAGE, Media::TYPE_VIDEO, Media::TYPE_HTML])],
            'orientation' => ['nullable', 'in:landscape,portrait'],
            'sort' => ['nullable', 'string'],
            'library' => ['nullable', 'string', 'regex:/^(platform|[1-9][0-9]{0,9})$/'],
        ]);

        // An Ad Builder page taken off the screens (unpublished) is in no library until it is published again (owner,
        // 2026-09-21): the Ad Builder is where a draft lives.
        $query = Media::visibleTo(auth()->user())->withoutDrafts()->with('store:id,name');

        if (auth()->user()->globalRole() !== null && filled($filters['library'] ?? null)) {
            $filters['library'] === 'platform'
                ? $query->platformOwned()
                : $query->where('store_id', (int) $filters['library']);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['orientation'])) {
            $query->where('orientation', $filters['orientation']);
        }

        [$column, $direction] = self::SORTS[$filters['sort'] ?? 'newest'] ?? self::SORTS['newest'];
        $query->orderBy($column, $direction);

        return $this->paginatedResponse($request, $query, ['title', 'description'], 'media', ['*'],
            // Why a file may not be deleted yet, so the panel says it before anybody confirms (spec §5).
            function (Collection $files) {
                $refusals = Media::stillInChannelsMessages($files->pluck('id')->all());

                $files->each(fn (Media $media) => $media->setAttribute('in_channels_message', $refusals[$media->id] ?? null));
            });
    }

    /** Upload a file into a library: the current store's, or — above the stores — the one the page chose. */
    public function store(StoreMediaRequest $request, MediaStorage $storage): JsonResponse
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

        return response()->json(['message' => 'File uploaded successfully', 'media' => $media]);
    }

    /** Rename a file, describe it, or set its schedule window. */
    public function update(UpdateMediaRequest $request, Media $media): JsonResponse
    {
        // Route middleware is not enough: the target has to be inside the store the
        // actor is working in, or it does not exist for them (404, never 403).
        $media = Media::visibleTo(auth()->user())->findOrFail($media->id);

        $media->update($request->validated());

        ActivityLog::record('media.updated', $media, "Updated media {$media->title}");

        return response()->json(['message' => 'Media updated successfully', 'media' => $media]);
    }

    /** Delete a file, its thumbnail and its row. */
    public function destroy(Media $media, MediaStorage $storage): JsonResponse
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

        ActivityLog::record('media.deleted', null, "Deleted media {$title}", storeId: $media->store_id);

        return response()->json(['message' => 'Media deleted successfully']);
    }

    /**
     * Whose library an upload joins. A store's person: the store they are working in — with none selected
     * the upload is refused, there being no library of their own to put it in. The platform team: the shop
     * chosen on the page, or with none chosen the platform's own library (null).
     */
    private function uploadTarget(StoreMediaRequest $request): ?int
    {
        if (auth()->user()->globalRole() === null) {
            $storeId = (int) session('current_store_id');

            if (! $storeId) {
                throw ValidationException::withMessages([
                    'file' => 'Select a store before uploading — media belongs to the store it is uploaded in.',
                ]);
            }

            return $storeId;
        }

        $storeId = (int) ($request->validated('store_id') ?? 0);

        if ($storeId !== 0 && ! Store::whereKey($storeId)->exists()) {
            throw ValidationException::withMessages([
                'file' => 'That shop no longer exists. Reload the page and choose again.',
            ]);
        }

        return $storeId === 0 ? null : $storeId;
    }
}
