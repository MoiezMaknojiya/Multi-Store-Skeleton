<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Concerns\HandlesCrudData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StorePlayerAppReleaseRequest;
use App\Models\ActivityLog;
use App\Models\PlayerAppRelease;
use App\Services\ApkInspector;
use App\Services\ChunkedUploads;
use App\Services\DiskGuard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Developer → TV App (owner, 2026-10-10 — "mein new apk apne super admin mein upload karu waha super admin mein developer ka
 * tab ho sub se end mein"): the super admin uploads a new version of the Android player, and every TV with the app is offered
 * it (/player-app/latest.json, read by the app's AppUpdater) the next time it starts, or within a day. Android lets nothing
 * install without a press, so a TV asks "Update available"; nothing here can update a set by itself.
 */
class PlayerAppController extends Controller
{
    use HandlesCrudData;

    public function __construct(private readonly ApkInspector $inspector, private readonly DiskGuard $disk) {}

    public function index(): View
    {
        return view('developer.index', ['package' => PlayerAppRelease::PACKAGE]);
    }

    /** Every version, newest first; the newest is the one TVs are offered. */
    public function data(Request $request): JsonResponse
    {
        $currentId = PlayerAppRelease::current()?->id;

        return $this->paginatedResponse(
            $request,
            PlayerAppRelease::with('uploader:id,first_name,last_name')->newestFirst(),
            ['version_name', 'notes'],
            'releases',
            batchTransform: fn ($releases) => $releases->transform(fn (PlayerAppRelease $release) => [
                'id' => $release->id,
                'name' => $release->version_name,
                'version_code' => $release->version_code,
                'version_name' => $release->version_name,
                'version_read_from_file' => $release->version_read_from_file,
                'size' => $release->size,
                'size_words' => Number::fileSize($release->size, 1),
                'sha256' => $release->sha256,
                'notes' => $release->notes,
                'url' => $release->url(),
                'uploaded_by' => $release->uploader?->name,
                'uploaded_at' => $release->created_at?->toIso8601String(),
                'current' => $release->id === $currentId,
            ]),
        );
    }

    /**
     * What a finished upload says about itself, before it is saved: whether it is the TV app, and its version when the file
     * says one — so the page shows the version it read, or asks for it.
     */
    public function inspect(Request $request, ChunkedUploads $uploads): JsonResponse
    {
        $id = $request->validate(['upload' => ['bail', 'required', 'string', 'uuid']], [
            'upload.*' => 'That upload is not finished, or it has expired. Choose the file again.',
        ])['upload'];

        $upload = $uploads->finished($request->user(), $id, 'app-release');

        if ($upload === null) {
            throw ValidationException::withMessages(['file' => 'That upload is not finished, or it has expired. Choose the file again.']);
        }

        $read = $this->inspector->inspect($upload->partPath());

        if (! $read['is_apk']) {
            throw ValidationException::withMessages(['file' => PlayerAppRelease::ONLY_AN_APK]);
        }

        if ($read['package'] !== null && $read['package'] !== PlayerAppRelease::PACKAGE) {
            throw ValidationException::withMessages(['file' => "This is another app ({$read['package']}), not the TV app."]);
        }

        // A version the file says that is not newer is refused now, before anybody types notes for it and presses Publish.
        $notNewer = $read['version_code'] !== null ? PlayerAppRelease::notNewerThanCurrent($read['version_name'], $read['version_code']) : null;

        if ($notNewer !== null) {
            throw ValidationException::withMessages(['file' => $notNewer]);
        }

        $current = PlayerAppRelease::current();

        return response()->json([
            'version_read' => $read['version_code'] !== null && $read['version_name'] !== null,
            'version_code' => $read['version_code'],
            'version_name' => $read['version_name'],
            'current' => $current === null ? null : ['version_code' => $current->version_code, 'version_name' => $current->version_name],
        ]);
    }

    public function store(StorePlayerAppReleaseRequest $request): JsonResponse
    {
        $file = $request->file('file');
        [$code, $name] = $request->version();
        $size = (int) $file->getSize();

        $this->disk->assertRoomFor($size);

        $sha256 = hash_file('sha256', $file->getRealPath());
        $path = Storage::disk('public')->putFileAs(PlayerAppRelease::FOLDER, $file, PlayerAppRelease::fileNameFor($name, $code));

        try {
            $release = PlayerAppRelease::create([
                'version_code' => $code,
                'version_name' => $name,
                'version_read_from_file' => $request->versionWasRead(),
                'path' => $path,
                'size' => $size,
                'sha256' => $sha256,
                'notes' => filled($request->input('notes')) ? trim((string) $request->input('notes')) : null,
                'uploaded_by' => $request->user()->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            // The same version code, uploaded at the same moment in another tab.
            Storage::disk('public')->delete($path);

            throw ValidationException::withMessages(['file' => "Version code {$code} has just been uploaded. Reload the page."]);
        }

        $request->forgetFinishedUpload();

        ActivityLog::record('player_app.uploaded', $release, "Uploaded version {$name} (code {$code}) of the TV app");

        return response()->json([
            'message' => "Version {$name} is uploaded: every TV with the app is offered it the next time it starts, or within a day.",
            'release' => ['id' => $release->id, 'version_code' => $code, 'version_name' => $name],
        ]);
    }

    /**
     * A version taken away: its file and its row. A TV that installed it keeps it; if it was the newest, TVs are offered the
     * one before it, which only a TV still older than that one takes.
     */
    public function destroy(PlayerAppRelease $release): JsonResponse
    {
        $wasCurrent = $release->is(PlayerAppRelease::current());

        Storage::disk('public')->delete($release->path);
        $release->delete();

        ActivityLog::record('player_app.deleted', null, "Deleted version {$release->version_name} (code {$release->version_code}) of the TV app");

        $now = PlayerAppRelease::current();

        return response()->json(['message' => match (true) {
            ! $wasCurrent => "Version {$release->version_name} is deleted.",
            $now === null => "Version {$release->version_name} is deleted. No version is offered to TVs now.",
            default => "Version {$release->version_name} is deleted. TVs are offered {$now->version_name} now; a TV that has {$release->version_name} keeps it.",
        }]);
    }

    /** What a television reads: the version it is offered, or 404 while there is none. Open, and never cached. */
    public function latest(): JsonResponse
    {
        $current = PlayerAppRelease::current();

        abort_if($current === null, 404, 'No version of the TV app has been uploaded yet.');

        return response()->json($current->forTelevisions())->header('Cache-Control', 'no-cache, no-store');
    }
}
