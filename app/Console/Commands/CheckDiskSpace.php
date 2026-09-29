<?php

namespace App\Console\Commands;

use App\Services\DiskGuard;
use Illuminate\Console\Command;

/**
 * How much of the server's disk is free — scheduled every hour in routes/console.php. While less than the warning
 * is free, the super admins get one email a day (owner's rule, 2026-09-29: "server per jab 10gb khaali rahe toh
 * email aye"), uploads still working until the reserve (DiskGuard).
 */
class CheckDiskSpace extends Command
{
    protected $signature = 'disk:check';

    protected $description = "Say how much of the server's disk is free, and warn the super admins while it is below the warning";

    public function handle(DiskGuard $disk): int
    {
        $free = $disk->freeBytes();

        if ($free === null) {
            $this->warn('The system cannot say how much of the disk is free.');

            return self::SUCCESS;
        }

        $this->info('Free: '.DiskGuard::inWords($free).'. The super admins are warned below '
            .DiskGuard::inWords($disk->warning()).', and uploads stop below '.DiskGuard::inWords($disk->reserve()).'.');

        if ($disk->warnWhenLow()) {
            $this->warn('Below the warning: the super admins have been emailed.');
        }

        return self::SUCCESS;
    }
}
