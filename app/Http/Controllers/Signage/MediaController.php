<?php

namespace App\Http\Controllers\Signage;

use App\Http\Controllers\Concerns\HandlesCrudData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Signage\StoreMediaRequest;
use App\Http\Requests\Signage\UpdateMediaRequest;
use App\Models\ActivityLog;
use App\Models\Media;
use App\Services\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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

    /** Render the media library page */
    public function index(): View
    {
        return view('media.index');
    }

    /** Return paginated, searchable media as JSON, scoped to the current store. */
    public function data(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', 'in:image,video'],
            'orientation' => ['nullable', 'in:landscape,portrait'],
            'sort' => ['nullable', 'string'],
        ]);

        $query = Media::visibleTo(auth()->user());

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['orientation'])) {
            $query->where('orientation', $filters['orientation']);
        }

        [$column, $direction] = self::SORTS[$filters['sort'] ?? 'newest'] ?? self::SORTS['newest'];
        $query->orderBy($column, $direction);

        return $this->paginatedResponse($request, $query, ['title', 'description'], 'media');
    }

    /** Upload a file into the current store's library. */
    public function store(StoreMediaRequest $request, MediaStorage $storage): JsonResponse
    {
        $storeId = $this->currentStoreId();
        $file = $request->file('file');

        $title = $this->titleFor($request->input('title'), $file);

        $attributes = $storage->store($file, $storeId, $request->only(['duration_seconds', 'width', 'height', 'poster']));

        $media = Media::create([
            ...$attributes,
            'title' => $title,
            'created_by' => auth()->id(),
        ]);

        ActivityLog::record('media.uploaded', $media, "Uploaded {$media->type} {$media->title}");

        return response()->json(['message' => 'File uploaded successfully', 'media' => $media]);
    }

    /**
     * What to call the file: the owner's own words, or the file's name.
     *
     * The typed title is already capped at 255 by StoreMediaRequest. The FALLBACK
     * is not, and it must be treated as untrusted: a filename arrives in the
     * multipart header and a client can put anything of any length there, real
     * filesystem limits or not. Left alone it reaches a varchar(255) column and a
     * shop owner gets a 500 instead of a file in their library.
     *
     * Two other shapes worth handling rather than storing: a title of nothing but
     * spaces, and a file called ".jpg", whose name-without-extension is empty.
     * Both would otherwise leave a blank row that nobody can identify.
     */
    private function titleFor(?string $typed, UploadedFile $file): string
    {
        $title = trim((string) $typed);

        if ($title === '') {
            $title = trim(pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME));
        }

        $title = mb_substr($title, 0, 255);

        return $title === '' ? 'Untitled' : $title;
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

        // The row goes first: a stale file on disk is harmless, a row pointing at a
        // deleted file is a broken thumbnail on every screen that lists it.
        DB::transaction(function () use ($media, $storage) {
            $media->delete();
            $storage->delete($media);
        });

        ActivityLog::record('media.deleted', null, "Deleted media {$title}", storeId: $media->store_id);

        return response()->json(['message' => 'Media deleted successfully']);
    }

    /** Media always belongs to a store, so an upload needs a store context. Super
     *  admins and global users work above the stores and have none — they can read
     *  every library but must step into a store to add to one. */
    private function currentStoreId(): int
    {
        $storeId = (int) session('current_store_id');

        if (! $storeId) {
            throw ValidationException::withMessages([
                'file' => 'Select a store before uploading — media belongs to the store it is uploaded in.',
            ]);
        }

        return $storeId;
    }
}
