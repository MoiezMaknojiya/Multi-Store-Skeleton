<?php

use App\Models\ActivityLog;
use App\Services\ActivityLogPartitioner;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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
