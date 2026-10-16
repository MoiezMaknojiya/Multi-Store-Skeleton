<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Concerns\HandlesCrudData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StorePlayerAppReleaseRequest;
use App\Models\ActivityLog;
use App\Models\PlayerAppRelease;
use App\Models\Upload;
use App\Services\ApkInspector;
use App\Services\ChunkedUploads;
use App\Services\DiskGuard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * Developer → TV App (owner, 2026-10-10 — "mein new apk apne super admin mein upload karu waha super admin mein developer ka
 * tab ho sub se end mein"): the super admin uploads a new version of the Android player, and every TV with the app is offered
 * it (/player-app/latest.json, read by the app's AppUpdater) the next time it starts, or within a day. Android lets nothing
 * install without a press, so a TV asks "Update available"; nothing here can update a set by itself.
 */
class PlayerAppController extends Controller
{
    use HandlesCrudData;

    /** Said when the file could not be written or given its version's name. */
    private const NOT_SAVED = 'The app could not be saved. Try again.';

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

        $gone = ValidationException::withMessages(['file' => 'That upload is not finished, or it has expired. Choose the file again.']);
        $upload = $uploads->finished($request->user(), $id, 'app-release') ?? $uploads->refuseForGood($gone);

        // Read under the lock its door takes (ChunkedUploads::finishLock): a refusal here never gives the upload up under a
        // publish that is reading it, nor does the box's × give it up under this.
        $lock = ChunkedUploads::finishLock($upload->id);

        if (! $lock->get()) {
            throw ValidationException::withMessages(['file' => 'This file is being added already. Wait a moment.']);
        }

        try {
            return $this->readTheApp($uploads, $uploads->finished($request->user(), $id, 'app-release') ?? $uploads->refuseForGood($gone));
        } finally {
            $lock->release();
        }
    }

    /** What inspect answers for the finished upload it holds: its version, or the refusal that gives it up. */
    private function readTheApp(ChunkedUploads $uploads, Upload $upload): JsonResponse
    {
        $read = $this->inspector->inspect($upload->partPath());

        // What the file itself is refuses it however often it is asked: its upload goes at once (ChunkedUploads::refuseForGood).
        $refusal = match (true) {
            ! $read['is_apk'] => PlayerAppRelease::ONLY_AN_APK,
            $read['package'] !== null && $read['package'] !== PlayerAppRelease::PACKAGE => "This is another app ({$read['package']}), not the TV app.",
            // A version the file says that is not newer is refused now, before anybody types notes for it and presses Publish.
            $read['version_code'] !== null => PlayerAppRelease::notNewerThanCurrent($read['version_name'], $read['version_code']),
            default => null,
        };

        if ($refusal !== null) {
            $uploads->refuseForGood(ValidationException::withMessages(['file' => $refusal]), $upload);
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
        $public = Storage::disk('public');
        $path = PlayerAppRelease::FOLDER.'/'.PlayerAppRelease::fileNameFor($name, $code);

        // Written under a name of its own first, outside any lock, and given the version's name only once its row is made: the
        // same version published in two tabs at the same moment writes two files, and the tab whose row was refused takes away
        // its own — never the file the other tab's row names, which every TV is offered (it once deleted it, and every TV got
        // a 404). A file a request left under that name (it died before the move) goes with uploads:prune (ChunkedUploads::prune).
        $incoming = $public->putFileAs(PlayerAppRelease::FOLDER, $file, ChunkedUploads::INCOMING_PREFIX.Str::uuid().'.apk');

        if ($incoming === false) {
            throw ValidationException::withMessages(['file' => self::NOT_SAVED]);
        }

        try {
            // The row and its file's name in one transaction: a TV reads the row only once its file is there, and a move
            // that fails — or a request that dies before it — takes the row back with it. On MySQL the other tab's row of
            // the same version waits for this one to be decided, then is refused by the unique version code.
            $release = DB::transaction(function () use ($request, $public, $incoming, $path, $code, $name, $size, $sha256) {
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

                if (! $public->move($incoming, $path)) {
                    throw ValidationException::withMessages(['file' => self::NOT_SAVED]);
                }

                return $release;
            });
        } catch (UniqueConstraintViolationException) {
            // The same version code, uploaded at the same moment in another tab.
            $public->delete($incoming);

            throw ValidationException::withMessages(['file' => "Version code {$code} has just been uploaded. Reload the page."]);
        } catch (Throwable $failure) {
            // Its own file alone, while it still has the name it was written under; the row went back with the transaction.
            $public->delete($incoming);

            throw $failure;
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
