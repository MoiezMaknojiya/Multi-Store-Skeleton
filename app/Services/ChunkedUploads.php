<?php

namespace App\Services;

use App\Http\Requests\Signage\StoreMediaRequest;
use App\Models\Channel;
use App\Models\Organization;
use App\Models\PlayerAppRelease;
use App\Models\Upload;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Files sent in chunks by the tus protocol 1.0 — the server half of the uploader every page that takes a file uses
 * (docs/UPLOADS-SPEC.md, owner 2026-09-29).
 *
 * Everything that would refuse the finished file is asked when the upload is OPENED, before a byte is sent: the
 * permission, the place (an organization the person works in, a channel within reach), the format by its name, the size, the
 * organization's 512 MB counting every upload still on its way into that organization, and every open upload into it at most
 * that much — decided under the organization's lock, as OrganizationStorage decides — and the server's reserve counting every
 * open upload's missing bytes. When the last byte is in, the
 * form the file was chosen for posts the upload's id, and the file goes through the same door as ever
 * (Http\Requests\Concerns\TakesAFinishedUpload): its format read from its bytes, a video's length from the file, the organization's
 * wall decided again under its lock. A finished upload reserves no organization's room any more — the door decides that — so it is
 * kept two hours for its door (FINISHED_LIFETIME_HOURS), not a day, and one the door refuses for what the file itself is goes at
 * once (refuseForGood).
 */
class ChunkedUploads
{
    /** What the browser sends at a time. */
    public const CHUNK_BYTES = 5 * 1024 * 1024;

    /** The most one request may carry. */
    public const MAX_CHUNK_BYTES = 16 * 1024 * 1024;

    /** Uploads one person may have open at once. */
    public const MAX_OPEN_PER_USER = 20;

    /** An unfinished upload is kept this long, then uploads:prune takes it. */
    public const LIFETIME_HOURS = 24;

    /**
     * A finished upload is kept at most this long for the form it was chosen for — its Save, or Try Again after a refusal room
     * can fix — then uploads:prune takes it. It holds no organization's room any more (reservedInOrganization), so kept a day,
     * twenty of them were 5 GB of the server's disk a person could hold for that long. Finishing never lengthens its day.
     */
    public const FINISHED_LIFETIME_HOURS = 2;

    /**
     * The name a finished upload's file has on its way into place, until the row naming its own place is made (the TV app's,
     * PlayerAppController::store). A request that died in between leaves one; uploads:prune takes it after an hour.
     */
    public const INCOMING_PREFIX = '.incoming-';

    /**
     * Said in a refusal's answer when the upload is no use any more — gone, or given up because the file itself was refused —
     * so the uploader shows the reason without a Try Again that could only fail (resources/js/core/upload-dropzone.js).
     */
    public const GIVEN_UP = 'upload_given_up';

    /**
     * How long a door may hold a finished upload while it makes its row (finishLock): the two minutes a picture's turn and its
     * work fit in (MediaStorage::PICTURE_TURN_SECONDS).
     */
    public const FINISH_LOCK_SECONDS = 120;

    public function __construct(private readonly OrganizationStorage $quota, private readonly DiskGuard $disk) {}

    /** The largest file any of the four places takes. */
    public static function maxSize(): int
    {
        return StoreMediaRequest::MAX_KILOBYTES * 1024;
    }

    /**
     * Open an upload of $size bytes, or refuse it — with the reason, before any byte.
     *
     * @param  array<string, string>  $meta  the upload's metadata: its name and type, its purpose, and where it goes
     */
    public function open(User $user, int $size, array $meta): Upload
    {
        $purpose = $meta['purpose'] ?? '';

        if (! in_array($purpose, Upload::PURPOSES, true)) {
            throw ValidationException::withMessages(['file' => 'Say where the file is going.']);
        }

        $organizationId = $this->placeFor($user, $purpose, $meta);
        $filename = $this->filenameOf($meta['name'] ?? $meta['filename'] ?? null);

        $extension = Str::contains($filename, '.') ? Str::lower(Str::afterLast($filename, '.')) : '';

        // The TV app (Developer → TV App) is an APK of its own size; every other place takes pictures and videos.
        if ($purpose === 'app-release') {
            abort_unless($extension === 'apk', 415, PlayerAppRelease::ONLY_AN_APK);
        } else {
            abort_unless(in_array($extension, explode(',', StoreMediaRequest::ALLOWED_MIMES), true), 415,
                'Only '.StoreMediaRequest::FORMATS_IN_WORDS.' can be uploaded.');
        }

        if ($size < 1) {
            throw ValidationException::withMessages(['file' => 'The file is empty.']);
        }

        abort_if($purpose === 'app-release' && $size > PlayerAppRelease::MAX_BYTES, 413, PlayerAppRelease::tooLargeMessage());
        abort_if($size > self::maxSize(), 413, StoreMediaRequest::tooLargeMessage());

        abort_if(Upload::where('user_id', $user->id)->open()->count() >= self::MAX_OPEN_PER_USER, 429,
            'Too many uploads at once: wait for some to finish, or cancel some.');

        // The server's own reserve, with what every open upload has still to send (DiskGuard).
        $open = Upload::open();
        $this->disk->assertRoomFor($size + (int) (clone $open)->sum('size') - (int) (clone $open)->sum('received'));

        $attributes = [
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'purpose' => $purpose,
            'filename' => $filename,
            'file_type' => $this->typeOf($meta['type'] ?? $meta['filetype'] ?? null),
            'size' => $size,
            'received' => 0,
            'expires_at' => now()->addHours(self::LIFETIME_HOURS),
        ];

        $upload = $organizationId === null
            ? Upload::create($attributes)
            : $this->reservedInOrganization($organizationId, $size, fn () => Upload::create($attributes));

        Storage::disk(Upload::DISK)->put($upload->partName(), '');

        return $upload;
    }

    /**
     * Write one chunk at $offset, or refuse it; the new offset. Under the upload's row lock, so two chunks sent for the
     * same place cannot both land: the second finds the offset moved (409) and asks where to carry on.
     *
     * @param  resource  $body
     */
    public function append(Upload $upload, int $offset, $body, ?int $declared): int
    {
        return DB::transaction(function () use ($upload, $offset, $body, $declared) {
            $current = Upload::whereKey($upload->id)->lockForUpdate()->first();

            abort_if($current === null || $current->expires_at->isPast(), 404, 'That upload has expired. Choose the file again.');
            abort_if($offset !== $current->received, 409, 'The upload is further on: it carries on from there.');

            $most = min($current->remaining(), self::MAX_CHUNK_BYTES);

            abort_if($declared !== null && $declared > $most, 413, 'That is more than the file said it was.');

            $out = @fopen($current->partPath(), 'c+b');

            abort_if($out === false, 404, 'That upload has expired. Choose the file again.');

            try {
                // Whatever a write that failed left past the offset goes first.
                ftruncate($out, $offset);
                fseek($out, $offset);

                // One byte more than may come, to see a body that runs past the end.
                $written = (int) stream_copy_to_stream($body, $out, $most + 1);

                if ($written > $most || ($declared !== null && $written !== $declared)) {
                    ftruncate($out, $offset);

                    abort($written > $most ? 413 : 503, $written > $most
                        ? 'That is more than the file said it was.'
                        : 'The chunk arrived incomplete: it is sent again.');
                }

                fflush($out);
            } finally {
                fclose($out);
            }

            $current->received = $offset + $written;

            if ($current->isComplete()) {
                $current->expires_at = $current->expires_at->min(now()->addHours(self::FINISHED_LIFETIME_HOURS));
            }

            $current->save();

            return $current->received;
        });
    }

    /** The person's own upload, complete and unexpired, opened for $purpose — or null. */
    public function finished(User $user, string $id, string $purpose): ?Upload
    {
        $upload = Upload::whereKey($id)->where('user_id', $user->id)->where('purpose', $purpose)->open()->first();

        if ($upload === null || ! $upload->isComplete()) {
            return null;
        }

        clearstatcache(true, $upload->partPath());

        return is_file($upload->partPath()) && filesize($upload->partPath()) === $upload->size ? $upload : null;
    }

    /**
     * The finished upload as the `file` a form would have posted. `test`, because its bytes came by chunks and not by
     * PHP's own upload handling, so is_uploaded_file() would say no — every rule still reads the file itself (its
     * format by finfo, a video's length by VideoDuration), never what the browser claimed.
     */
    public function asUploadedFile(Upload $upload): UploadedFile
    {
        return new UploadedFile($upload->partPath(), $upload->filename, $upload->file_type, null, true);
    }

    /**
     * The lock a door holds while it reads a finished upload and makes its row (TakesAFinishedUpload): one upload makes one row,
     * and nothing gives the upload up under the door meanwhile (cancel, prune).
     */
    public static function finishLock(string $id): Lock
    {
        return Cache::lock('upload-finish:'.$id, self::FINISH_LOCK_SECONDS);
    }

    /** Give an upload up: its row, then its bytes. Its door's own (it holds finishLock), and prune's under the same lock. */
    public function discard(Upload $upload): void
    {
        $upload->delete();
        Storage::disk(Upload::DISK)->delete($upload->partName());
    }

    /**
     * Give an upload up at its person's word (tus termination: the box's ×, a form shut) — never while a door is making its row
     * from it: its file would go from under the save that is reading it (409, and the door forgets the upload itself once done).
     */
    public function cancel(Upload $upload): void
    {
        $lock = self::finishLock($upload->id);

        abort_unless($lock->get(), 409, 'This file is being added. Wait a moment.');

        try {
            $this->discard($upload);
        } finally {
            $lock->release();
        }
    }

    /**
     * Refuse an upload for good: what is wrong is the file itself (its format, its size, its length, a file nobody can read), or
     * the upload is gone, and posting it again would be refused again. It is given up at once — kept for a day, it held its
     * place among the person's twenty and its bytes on the disk for nothing — and the answer says so (GIVEN_UP).
     */
    public function refuseForGood(ValidationException $refusal, ?Upload $upload = null): never
    {
        if ($upload !== null) {
            $this->discard($upload);
        }

        if (request()->expectsJson()) {
            $refusal->response = response()->json([
                'message' => $refusal->getMessage(),
                'errors' => $refusal->errors(),
                self::GIVEN_UP => true,
            ], $refusal->status);
        }

        throw $refusal;
    }

    /**
     * Take the expired uploads, any part no row names (older than an hour) — nothing else there — and any TV app file a request
     * left under its incoming name (older than an hour); how many went.
     */
    public function prune(): int
    {
        $removed = 0;

        // One a door is still making its row from (it found the upload just before its end) is left to the door, or the next run.
        Upload::where('expires_at', '<=', now())->lazyById()->each(function (Upload $upload) use (&$removed) {
            $lock = self::finishLock($upload->id);

            if (! $lock->get()) {
                return;
            }

            try {
                $this->discard($upload);
                $removed++;
            } finally {
                $lock->release();
            }
        });

        $disk = Storage::disk(Upload::DISK);

        foreach ($disk->files() as $file) {
            if (! str_ends_with($file, '.part')) {
                continue;
            }

            $id = Str::beforeLast($file, '.part');
            $named = Str::isUuid($id) && Upload::whereKey($id)->exists();

            if (! $named && $disk->lastModified($file) < now()->subHour()->getTimestamp()) {
                $disk->delete($file);
                $removed++;
            }
        }

        // No row ever names one: a TV app version's row names the version's own file, which the incoming one becomes.
        $public = Storage::disk('public');

        foreach ($public->files(PlayerAppRelease::FOLDER) as $file) {
            if (str_starts_with(basename($file), self::INCOMING_PREFIX) && $public->lastModified($file) < now()->subHour()->getTimestamp()) {
                $public->delete($file);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * The organization the file will count to (null for none) — once the person may put a file there at all, by the same
     * permission and the same place the form's own door asks for.
     *
     * @param  array<string, string>  $meta
     */
    private function placeFor(User $user, string $purpose, array $meta): ?int
    {
        return match ($purpose) {
            'media' => $this->libraryFor($user, $meta['library'] ?? null),
            'channel' => $this->channelLibrary($user, $meta['channel'] ?? null),
            'campaign' => $this->adsNetwork(),
            'asset' => $this->shelfFor($user, $meta['organization'] ?? null),
            'app-release' => $this->tvApp($user),
        };
    }

    /** A new version of the TV app: the super admin's alone, and no organization's storage. */
    private function tvApp(User $user): ?int
    {
        abort_unless(Gate::forUser($user)->allows('super-admin-tier') && $user->can('player-app-store'), 403);

        return null;
    }

    /** The Media page's library: the organization the person works in, or — above the organizations — the one the page chose. */
    private function libraryFor(User $user, ?string $library): ?int
    {
        abort_unless($user->can('media-store'), 403);

        if ($user->globalRole() === null) {
            return $this->currentOrganization('Select an organization before uploading — media belongs to the organization it is uploaded in.');
        }

        if ($library === null || $library === '' || $library === 'platform') {
            return null;
        }

        return $this->existingOrganization($library, 'That organization no longer exists. Reload the page and choose again.');
    }

    /** A channel's Upload joins the channel's library: the organization's for an organization's channel, none for the platform's. */
    private function channelLibrary(User $user, ?string $channel): ?int
    {
        abort_unless($user->can('channel-update'), 403);

        $found = is_string($channel) && ctype_digit($channel) ? Channel::visibleTo($user)->find((int) $channel) : null;

        abort_if($found === null, 404, 'That channel was not found.');

        return $found->organization_id;
    }

    /** An advert belongs to the ads network, above every organization. */
    private function adsNetwork(): ?int
    {
        abort_unless(Gate::allows('campaign-manage'), 403);

        return null;
    }

    /**
     * The Ad Builder's shelf: the organization the person works in, or — above the organizations — the one the page chose, or with
     * none chosen the shelf the platform shares with every organization (owner, 2026-09-29).
     */
    private function shelfFor(User $user, ?string $organization): ?int
    {
        abort_unless($user->can('ad-store'), 403);

        if ($user->globalRole() !== null) {
            return $organization === null || $organization === '' || $organization === 'shared'
                ? null
                : $this->existingOrganization($organization, 'That organization no longer exists. Reload the page and choose again.');
        }

        return $this->currentOrganization('Select an organization before uploading — an ad\'s pictures belong to the organization they were uploaded for.');
    }

    private function currentOrganization(string $missing): int
    {
        $organizationId = (int) session('current_organization_id');

        if ($organizationId === 0) {
            throw ValidationException::withMessages(['file' => $missing]);
        }

        return $organizationId;
    }

    private function existingOrganization(?string $id, string $missing): int
    {
        if (! is_string($id) || ! ctype_digit($id) || ! Organization::whereKey((int) $id)->exists()) {
            throw ValidationException::withMessages(['file' => $missing]);
        }

        return (int) $id;
    }

    /**
     * Make the upload's row only while the organization has room for it — decided under the organization's row lock, reading
     * inside the lock (OrganizationStorage::withRoom), on two counts:
     *  - the wall: what it holds and every upload still on its way into it. A finished upload is not counted there: its door
     *    decides the wall again under the same lock when the file is posted, and one the door refused (the wall full) held the
     *    organization's room for a day while the meter showed it free;
     *  - what waits on the server's disk: every open upload into it, finished or not, never more than its storage — so its
     *    people together park no more than that, as before (a finished upload that was never posted, then the next, held
     *    twenty times 250 MB per person).
     * Call it outside any other transaction.
     *
     * @param  Closure(): Upload  $create
     */
    private function reservedInOrganization(int $organizationId, int $size, Closure $create): Upload
    {
        return DB::transaction(function () use ($organizationId, $size, $create) {
            $organization = Organization::whereKey($organizationId)->lockForUpdate()->first(['id', 'name', 'extra_storage_gb']);

            if ($organization === null) {
                throw ValidationException::withMessages(['file' => 'That organization no longer exists. Reload the page and choose again.']);
            }

            $limit = OrganizationStorage::limitOf((int) $organization->extra_storage_gb);
            $open = Upload::where('organization_id', $organizationId)->open();
            $taken = $this->quota->used($organizationId) + (int) (clone $open)->unfinished()->sum('size');

            if ($taken + $size > $limit) {
                throw ValidationException::withMessages(['file' => $this->quota->fullMessage($organizationId, $size, $taken)]);
            }

            if ((int) $open->sum('size') + $size > $limit) {
                throw ValidationException::withMessages(['file' => 'Files uploaded for '.$organization->name.' and not added yet already take its '
                    .OrganizationStorage::inWords($limit).'. Add or remove them first, or try again later.']);
            }

            return $create();
        });
    }

    /** The file's own name, without any folder of the sender's: readable text of at most 255 characters. */
    private function filenameOf(mixed $name): string
    {
        $name = is_string($name) ? trim(Str::afterLast(str_replace('\\', '/', $name), '/')) : '';

        if ($name === '' || ! mb_check_encoding($name, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $name) === 1 || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['file' => 'The file\'s name could not be read. Rename the file and choose it again.']);
        }

        return $name;
    }

    /**
     * What the browser said the file is, kept only as a plain type ("video/mp4") of at most the 100 characters its column
     * holds — a longer one was a 500 on MySQL's strict mode — and nothing after it (`\z`: a `$` lets a closing newline
     * through); the rules read the bytes anyway.
     */
    private function typeOf(mixed $type): ?string
    {
        return is_string($type) && preg_match('#^[a-z]{1,19}/[a-z0-9.+-]{1,80}\z#', $type) === 1 ? $type : null;
    }
}
