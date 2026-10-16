<?php

namespace App\Console\Commands;

use App\Services\ChunkedUploads;
use Illuminate\Console\Command;

/**
 * Uploads never finished go after ChunkedUploads::LIFETIME_HOURS, finished ones never posted after FINISHED_LIFETIME_HOURS,
 * and so does any part no row names any more — its organization or its person deleted meanwhile — and a TV app file a request
 * left under its incoming name (docs/UPLOADS-SPEC.md). Scheduled hourly in routes/console.php.
 */
class PruneUploads extends Command
{
    protected $signature = 'uploads:prune';

    protected $description = 'Remove uploads past their time ('.ChunkedUploads::LIFETIME_HOURS.' hours, '
        .ChunkedUploads::FINISHED_LIFETIME_HOURS.' once finished), and parts no upload names';

    public function handle(ChunkedUploads $uploads): int
    {
        $this->info($uploads->prune().' upload(s) removed.');

        return self::SUCCESS;
    }
}
