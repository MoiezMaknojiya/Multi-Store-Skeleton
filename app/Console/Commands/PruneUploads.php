<?php

namespace App\Console\Commands;

use App\Services\ChunkedUploads;
use Illuminate\Console\Command;

/**
 * Uploads never finished go after ChunkedUploads::LIFETIME_HOURS, and so does any part no row names any more — its
 * shop or its person deleted meanwhile (docs/UPLOADS-SPEC.md). Scheduled hourly in routes/console.php.
 */
class PruneUploads extends Command
{
    protected $signature = 'uploads:prune';

    protected $description = 'Remove uploads never finished within '.ChunkedUploads::LIFETIME_HOURS.' hours, and parts no upload names';

    public function handle(ChunkedUploads $uploads): int
    {
        $this->info($uploads->prune().' upload(s) removed.');

        return self::SUCCESS;
    }
}
