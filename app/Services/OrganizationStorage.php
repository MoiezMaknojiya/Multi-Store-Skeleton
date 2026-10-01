<?php

namespace App\Services;

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Media;
use App\Models\Organization;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * How much of the server each organization holds, and the wall at 512 MB (owner's rule, 2026-09-28: "koi bhi store
 * 512 MB se upar na ja sake").
 *
 * An organization's storage is everything its rows name on disk: its library's files (uploads, channel uploads, the
 * Ad Builder's published pages) and the Ad Builder's shelf, each at the size it was stored with, and every
 * preview those rows name — a picture's thumbnail, a video's poster, a design's poster and its published copy
 * — at its size on disk, each file once. Previews count because they are bytes the organization's uploads put there:
 * a thousand tiny uploads with large posters would otherwise fill the server under an empty meter. The
 * platform's own library and the ads network's files belong to no organization and have no wall.
 *
 * Every write that adds to an organization is decided under the organization's row lock (withRoom), reading what is used
 * INSIDE the lock — the pattern of OrganizationTeam::changeTeam — so two uploads at the same moment cannot both
 * fit into the last few megabytes. A write that frees room, or keeps it the same, is never refused.
 */
class OrganizationStorage
{
    /** 512 MB, as the rest of the panel counts a megabyte (StoreMediaRequest::MAX_KILOBYTES). */
    public const LIMIT_BYTES = 512 * 1024 * 1024;

    /** Bytes the organization holds on disk now. */
    public function used(int $organizationId): int
    {
        $bytes = (int) Media::where('organization_id', $organizationId)->sum('size')
            + (int) BuilderAsset::where('organization_id', $organizationId)->sum('size');

        $previews = [];

        foreach (Media::where('organization_id', $organizationId)->whereNotNull('thumbnail_path')->get(['disk', 'thumbnail_path']) as $media) {
            $previews[$media->disk.'|'.$media->thumbnail_path] = true;
        }

        foreach (BuilderAsset::where('organization_id', $organizationId)->whereNotNull('thumbnail_path')->get(['disk', 'thumbnail_path']) as $asset) {
            $previews[$asset->disk.'|'.$asset->thumbnail_path] = true;
        }

        foreach (BuilderAd::where('organization_id', $organizationId)->whereNotNull('thumbnail_path')->pluck('thumbnail_path') as $poster) {
            $previews['public|'.$poster] = true;
        }

        foreach (array_keys($previews) as $file) {
            [$disk, $path] = explode('|', $file, 2);
            $bytes += $this->sizeOnDisk($disk, $path);
        }

        return $bytes;
    }

    /**
     * What the pages show about one library: null for the platform's own (no wall), else used and limit.
     *
     * @return array{used: int, limit: int}|null
     */
    public function summary(?int $organizationId): ?array
    {
        return $organizationId === null ? null : ['used' => $this->used($organizationId), 'limit' => self::LIMIT_BYTES];
    }

    /**
     * A quick look before a file is even written, so a full organization is told at once rather than after its upload
     * has been stored and taken away again. Not the decision: withRoom decides, under the lock.
     */
    public function assertRoomFor(int $organizationId, int $bytes, string $attribute = 'file'): void
    {
        if ($bytes <= 0) {
            return;
        }

        $used = $this->used($organizationId);

        if ($used + $bytes > self::LIMIT_BYTES) {
            throw ValidationException::withMessages([$attribute => $this->fullMessage($organizationId, $bytes, $used)]);
        }
    }

    /**
     * Run $write — the insert or update that makes $bytes more of this organization's — only while it fits, decided
     * under the organization's row lock. The lock is the transaction's first read, so what is read as used after it
     * includes every write that got there first (REPEATABLE READ takes its snapshot at the first plain read).
     * So call it OUTSIDE any other transaction: a read made before it in an outer one would fix the snapshot
     * earlier, and a write another request committed meanwhile would not be seen. Negative or zero $bytes is
     * never refused: nothing grows. No organization ($organizationId null) is the platform's own — an ad shared with every organization
     * — which has no wall: the write runs at once, in a transaction of its own.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    public function withRoom(?int $organizationId, int $bytes, Closure $write, string $attribute = 'file'): mixed
    {
        if ($organizationId === null) {
            return DB::transaction(fn () => $write());
        }

        return DB::transaction(function () use ($organizationId, $bytes, $write, $attribute) {
            if (Organization::whereKey($organizationId)->lockForUpdate()->first(['id']) === null) {
                throw ValidationException::withMessages([$attribute => 'That organization no longer exists. Reload the page and choose again.']);
            }

            $used = $this->used($organizationId);

            if ($bytes > 0 && $used + $bytes > self::LIMIT_BYTES) {
                throw ValidationException::withMessages([$attribute => $this->fullMessage($organizationId, $bytes, $used)]);
            }

            return $write();
        });
    }

    /**
     * The same, for a write that is only a nicety — a design's poster: with no room it simply does not
     * happen (null), and nothing is refused.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T|null
     */
    public function withRoomOrSkip(?int $organizationId, int $bytes, Closure $write): mixed
    {
        try {
            return $this->withRoom($organizationId, $bytes, $write);
        } catch (ValidationException) {
            return null;
        }
    }

    /** "Not enough storage: this needs 120.4 MB, and Alpha Mart has 30.2 MB left of its 512 MB. …" */
    public function fullMessage(int $organizationId, int $needed, int $used): string
    {
        $name = Organization::whereKey($organizationId)->value('name') ?? 'This organization';
        $left = max(0, self::LIMIT_BYTES - $used);

        return 'Not enough storage: this needs '.self::inWords($needed).', and '.$name.' has '
            .($left > 0 ? self::inWords($left).' left' : 'no space left')
            .' of its '.self::inWords(self::LIMIT_BYTES).'. Delete files you no longer use to make room.';
    }

    /** 1536 as "2 KB", 126 353 408 as "120.5 MB", 536 870 912 as "512 MB". */
    public static function inWords(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 KB';
        }

        if ($bytes < 1024 * 1024) {
            return max(1, (int) ceil($bytes / 1024)).' KB';
        }

        $megabytes = $bytes / (1024 * 1024);

        return (fmod($megabytes, 1.0) === 0.0 ? (string) (int) $megabytes : number_format($megabytes, 1)).' MB';
    }

    private function sizeOnDisk(string $disk, string $path): int
    {
        try {
            return (int) Storage::disk($disk)->size($path);
        } catch (Throwable) {
            return 0;
        }
    }
}
