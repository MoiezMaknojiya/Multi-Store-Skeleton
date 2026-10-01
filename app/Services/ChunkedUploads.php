<?php

namespace App\Services;

use App\Http\Requests\Signage\StoreMediaRequest;
use App\Models\Channel;
use App\Models\Store;
use App\Models\Upload;
use App\Models\User;
use Closure;
use Illuminate\Http\UploadedFile;
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
 * permission, the place (a shop the person works in, a channel within reach), the format by its name, the size, the
 * shop's 512 MB counting every upload still open for that shop — decided under the shop's lock, as StoreStorage
 * decides — and the server's reserve counting every open upload's missing bytes. When the last byte is in, the form
 * the file was chosen for posts the upload's id, and the file goes through the same door as ever
 * (Http\Requests\Concerns\TakesAFinishedUpload): its format read from its bytes, a video's length from the file.
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

    public function __construct(private readonly StoreStorage $quota, private readonly DiskGuard $disk) {}

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

        $storeId = $this->placeFor($user, $purpose, $meta);
        $filename = $this->filenameOf($meta['name'] ?? $meta['filename'] ?? null);

        $extension = Str::contains($filename, '.') ? Str::lower(Str::afterLast($filename, '.')) : '';

        abort_unless(in_array($extension, explode(',', StoreMediaRequest::ALLOWED_MIMES), true), 415,
            'Only '.StoreMediaRequest::FORMATS_IN_WORDS.' can be uploaded.');

        if ($size < 1) {
            throw ValidationException::withMessages(['file' => 'The file is empty.']);
        }

        abort_if($size > self::maxSize(), 413, StoreMediaRequest::tooLargeMessage());

        abort_if(Upload::where('user_id', $user->id)->open()->count() >= self::MAX_OPEN_PER_USER, 429,
            'Too many uploads at once: wait for some to finish, or cancel some.');

        // The server's own reserve, with what every open upload has still to send (DiskGuard).
        $open = Upload::open();
        $this->disk->assertRoomFor($size + (int) (clone $open)->sum('size') - (int) (clone $open)->sum('received'));

        $attributes = [
            'user_id' => $user->id,
            'store_id' => $storeId,
            'purpose' => $purpose,
            'filename' => $filename,
            'file_type' => $this->typeOf($meta['type'] ?? $meta['filetype'] ?? null),
            'size' => $size,
            'received' => 0,
            'expires_at' => now()->addHours(self::LIFETIME_HOURS),
        ];

        $upload = $storeId === null
            ? Upload::create($attributes)
            : $this->reservedInShop($storeId, $size, fn () => Upload::create($attributes));

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

            $current->forceFill(['received' => $offset + $written])->save();

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

    /** Give an upload up: its row, then its bytes. */
    public function discard(Upload $upload): void
    {
        $upload->delete();
        Storage::disk(Upload::DISK)->delete($upload->partName());
    }

    /** Take the expired uploads, and any part no row names (older than an hour) — nothing else there; how many went. */
    public function prune(): int
    {
        $removed = 0;

        Upload::where('expires_at', '<=', now())->lazyById()->each(function (Upload $upload) use (&$removed) {
            $this->discard($upload);
            $removed++;
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

        return $removed;
    }

    /**
     * The shop the file will count to (null for none) — once the person may put a file there at all, by the same
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
            'asset' => $this->shelfFor($user, $meta['store'] ?? null),
        };
    }

    /** The Media page's library: the shop the person works in, or — above the stores — the one the page chose. */
    private function libraryFor(User $user, ?string $library): ?int
    {
        abort_unless($user->can('media-store'), 403);

        if ($user->globalRole() === null) {
            return $this->currentStore('Select an organization before uploading — media belongs to the organization it is uploaded in.');
        }

        if ($library === null || $library === '' || $library === 'platform') {
            return null;
        }

        return $this->existingStore($library, 'That organization no longer exists. Reload the page and choose again.');
    }

    /** A channel's Upload joins the channel's library: the shop's for a shop's channel, none for the platform's. */
    private function channelLibrary(User $user, ?string $channel): ?int
    {
        abort_unless($user->can('channel-update'), 403);

        $found = is_string($channel) && ctype_digit($channel) ? Channel::visibleTo($user)->find((int) $channel) : null;

        abort_if($found === null, 404, 'That channel was not found.');

        return $found->store_id;
    }

    /** An advert belongs to the ads network, above every shop. */
    private function adsNetwork(): ?int
    {
        abort_unless(Gate::allows('campaign-manage'), 403);

        return null;
    }

    /**
     * The Ad Builder's shelf: the shop the person works in, or — above the stores — the one the page chose, or with
     * none chosen the shelf the platform shares with every shop (owner, 2026-09-29).
     */
    private function shelfFor(User $user, ?string $store): ?int
    {
        abort_unless($user->can('ad-store'), 403);

        if ($user->globalRole() !== null) {
            return $store === null || $store === '' || $store === 'shared'
                ? null
                : $this->existingStore($store, 'That organization no longer exists. Reload the page and choose again.');
        }

        return $this->currentStore('Select an organization before uploading — an ad\'s pictures belong to the organization they were uploaded for.');
    }

    private function currentStore(string $missing): int
    {
        $storeId = (int) session('current_store_id');

        if ($storeId === 0) {
            throw ValidationException::withMessages(['file' => $missing]);
        }

        return $storeId;
    }

    private function existingStore(?string $id, string $missing): int
    {
        if (! is_string($id) || ! ctype_digit($id) || ! Store::whereKey((int) $id)->exists()) {
            throw ValidationException::withMessages(['file' => $missing]);
        }

        return (int) $id;
    }

    /**
     * Make the upload's row only while the shop has room for it beside what it holds and every upload still open for
     * it — decided under the shop's row lock, reading inside the lock (StoreStorage::withRoom). Call it outside any
     * other transaction.
     *
     * @param  Closure(): Upload  $create
     */
    private function reservedInShop(int $storeId, int $size, Closure $create): Upload
    {
        return DB::transaction(function () use ($storeId, $size, $create) {
            if (Store::whereKey($storeId)->lockForUpdate()->first(['id']) === null) {
                throw ValidationException::withMessages(['file' => 'That organization no longer exists. Reload the page and choose again.']);
            }

            $taken = $this->quota->used($storeId) + (int) Upload::where('store_id', $storeId)->open()->sum('size');

            if ($taken + $size > StoreStorage::LIMIT_BYTES) {
                throw ValidationException::withMessages(['file' => $this->quota->fullMessage($storeId, $size, $taken)]);
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

    /** What the browser said the file is, kept only as a plain type ("video/mp4"); the rules read the bytes anyway. */
    private function typeOf(mixed $type): ?string
    {
        return is_string($type) && preg_match('#^[a-z]+/[a-z0-9.+-]{1,80}$#', $type) === 1 ? $type : null;
    }
}
