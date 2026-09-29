<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Notifications\DiskAlmostFullNotification;
use App\Notifications\DiskSpaceLowNotification;
use Closure;
use Illuminate\Notifications\Notification as MailNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The server's own disk, guarded (owner's decisions, 2026-09-29). Every shop has its 512 MB (StoreStorage), but
 * shops are made at signup by anybody with an inbox, and the platform's library and the ads network have no wall
 * of their own — so whatever the shops' allowances add up to, an upload is refused once the disk would be left with
 * less than its reserve (config signage.upload_reserve_bytes, 5 GB unless SIGNAGE_UPLOAD_RESERVE_MB says), and the
 * super admins are told by email, at most once every ALERT_HOURS hours. Screens keep playing what they have.
 *
 * Long before that, the super admins are warned ("server per jab 10gb khaali rahe toh email aye"): `disk:check`
 * looks every hour, and while less than the warning is free (config signage.disk_warning_bytes, 10 GB unless
 * SIGNAGE_DISK_WARNING_MB says) they get one email a day — uploads still work — and one again once the disk has
 * been above it and dropped below it again.
 */
class DiskGuard
{
    /** A warning is sent at most once in this many hours while the disk stays full. */
    public const ALERT_HOURS = 6;

    /** While the disk stays below the warning, it is said at most once in this many hours. */
    public const WARNING_HOURS = 24;

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

    /** Below how much free space the super admins are warned, in bytes. */
    public function warning(): int
    {
        return max(0, (int) config('signage.disk_warning_bytes'));
    }

    /**
     * The hourly look (`disk:check`): while less than the warning is free, the super admins are told — once a day,
     * and again whenever the disk has been above it in between. Whether the email went out now.
     */
    public function warnWhenLow(): bool
    {
        $free = $this->freeBytes();

        if ($free === null) {
            return false;
        }

        if ($free >= $this->warning()) {
            Cache::forget('disk-guard:low-warned');   // above it again: the next drop is told at once

            return false;
        }

        if (! Cache::add('disk-guard:low-warned', true, now()->addHours(self::WARNING_HOURS))) {
            return false;
        }

        $this->tellTheSuperAdmins(new DiskSpaceLowNotification($free, $this->warning(), $this->reserve()));

        return true;
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

    /** Once in ALERT_HOURS: every super admin is told that uploads are paused. */
    private function warnTheSuperAdmins(int $free): void
    {
        if (! Cache::add('disk-guard:warned', true, now()->addHours(self::ALERT_HOURS))) {
            return;
        }

        $this->tellTheSuperAdmins(new DiskAlmostFullNotification($free, $this->reserve()));
    }

    /** Every super admin gets $notification. A mail server that refuses is reported, never thrown. */
    private function tellTheSuperAdmins(MailNotification $notification): void
    {
        // Platform memberships sit on the sentinel store 0, which has no row in `stores`: read the pivot itself.
        $admins = User::whereIn('id', DB::table('store_user')
            ->where('store_id', 0)
            ->where('role_id', Role::superAdminId())
            ->pluck('user_id'))->get();

        try {
            Notification::send($admins, $notification);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
