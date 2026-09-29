<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Notifications\DiskAlmostFullNotification;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The server's own disk, guarded (owner's decision, 2026-09-29). Every shop has its 512 MB (StoreStorage), but
 * shops are made at signup by anybody with an inbox, and the platform's library and the ads network have no wall
 * of their own — so whatever the shops' allowances add up to, an upload is refused once the disk would be left with
 * less than its reserve (config signage.upload_reserve_bytes, 5 GB unless SIGNAGE_UPLOAD_RESERVE_MB says). The
 * super admins are told by email, at most once every ALERT_HOURS hours. Screens keep playing what they have.
 */
class DiskGuard
{
    /** A warning is sent at most once in this many hours while the disk stays full. */
    public const ALERT_HOURS = 6;

    /** @param  (Closure(): (int|float|false|null))|null  $freeBytes  what is free now — the disk itself when not given */
    public function __construct(private readonly ?Closure $freeBytes = null) {}

    /** Refuse — on $attribute, in words a person can act on — a write of $bytes that would eat into the reserve. */
    public function assertRoomFor(int $bytes, string $attribute = 'file'): void
    {
        $free = $this->freeBytes();

        if ($free === null || $free - max(0, $bytes) >= $this->reserve()) {
            return;
        }

        $this->warnTheSuperAdmins($free);

        throw ValidationException::withMessages([
            $attribute => 'The server is almost out of space, so uploads are paused for now. Please try again later: '
                .'the team has been told.',
        ]);
    }

    /** What the reserve is, in bytes. */
    public function reserve(): int
    {
        return max(0, (int) config('signage.upload_reserve_bytes'));
    }

    /** What is free on the disk the files live on now, or null when the system cannot say (nothing is refused). */
    public function freeBytes(): ?int
    {
        $free = $this->freeBytes !== null ? ($this->freeBytes)() : @disk_free_space(storage_path('app'));

        return is_numeric($free) ? (int) $free : null;
    }

    /** 5368709120 as "5 GB", 734003200 as "700 MB" — the sizes a server's disk is spoken of in. */
    public static function inWords(int $bytes): string
    {
        $gigabytes = $bytes / (1024 ** 3);

        if ($gigabytes < 1) {
            return StoreStorage::inWords($bytes);
        }

        return (fmod($gigabytes, 1.0) === 0.0 ? (string) (int) $gigabytes : number_format($gigabytes, 1)).' GB';
    }

    /** Once in ALERT_HOURS: every super admin gets the warning. A mail server that refuses is reported, never thrown. */
    private function warnTheSuperAdmins(int $free): void
    {
        if (! Cache::add('disk-guard:warned', true, now()->addHours(self::ALERT_HOURS))) {
            return;
        }

        // Platform memberships sit on the sentinel store 0, which has no row in `stores`: read the pivot itself.
        $admins = User::whereIn('id', DB::table('store_user')
            ->where('store_id', 0)
            ->where('role_id', Role::superAdminId())
            ->pluck('user_id'))->get();

        try {
            Notification::send($admins, new DiskAlmostFullNotification($free, $this->reserve()));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
