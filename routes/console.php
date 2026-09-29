<?php

use App\Models\ActivityLog;
use App\Services\ActivityLogPartitioner;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Activity log yearly maintenance — forget-proof
|--------------------------------------------------------------------------
| Runs on the 1st of every month. maintain() is idempotent: most months it
| finds nothing to do; at a year boundary it opens the new year's partition
| and drops everything older than 2 years (data included). Monthly (rather
| than yearly) so a missed run self-heals within a month. The manual button
| on the Activity Log page keeps working alongside this.
|
| Requires the standard Laravel scheduler on the server:
|   * * * * * php artisan schedule:run
*/
Schedule::call(function () {
    $result = app(ActivityLogPartitioner::class)->maintain(now()->year);

    // Only log when something actually happened — quiet months stay quiet.
    if ($result['created'] !== [] || $result['dropped'] !== []) {
        $droppedYears = collect($result['dropped'])->pluck('year')->implode(', ') ?: 'none';
        $createdYears = implode(', ', $result['created']) ?: 'none';
        ActivityLog::record('activity.maintenance', null,
            "Scheduled maintenance — partitions created: {$createdYears}; dropped (with data): {$droppedYears}");
    }
})->monthlyOn(1, '00:30')->name('activity-log-partition-maintenance');

/*
|--------------------------------------------------------------------------
| Accounts never confirmed — removed after a week (owner's rule, 2026-09-29)
|--------------------------------------------------------------------------
| Daily, in the quiet of the night: an account that has not confirmed its email within User::UNVERIFIED_DAYS
| days goes, with the store it made alone (PruneUnverifiedAccounts).
*/
Schedule::command('accounts:prune-unverified')->dailyAt('03:15')->name('prune-unverified-accounts')->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| The server's disk — looked at every hour (owner's rule, 2026-09-29)
|--------------------------------------------------------------------------
| While less than the warning is free (10 GB), the super admins get one email a day; uploads still work until the
| reserve (5 GB). A disk can fill without an upload — the database's own records, the nightly backups, the logs —
| so it is looked at on the clock, not only when somebody uploads (DiskGuard::warnWhenLow).
*/
Schedule::command('disk:check')->hourly()->name('disk-space-check')->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Uploads never finished — taken after a day (docs/UPLOADS-SPEC.md)
|--------------------------------------------------------------------------
| A file sent in chunks and never added anywhere keeps its bytes for 24 hours; then this takes them, with any part no
| row names any more (its shop or its person deleted meanwhile).
*/
Schedule::command('uploads:prune')->hourly()->name('prune-uploads')->withoutOverlapping();
