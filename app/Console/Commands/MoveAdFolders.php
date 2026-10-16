<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Every Ad Builder file still in a folder named by an ad's id moved into the ad's own folder, the one nobody can guess
 * (BuilderAd::storageDirectory) — by running the migration that moved them all once more
 * (`2026_10_15_220000_give_every_ad_a_folder_nobody_can_guess`), which works out what is left from the disk and the rows and
 * changes nothing when nothing is.
 *
 * Every deploy runs it after the site has switched over (deploy/server/release.sh): the release before goes on serving while
 * the new one migrates, and what it writes in those seconds — a poster saved, a page published — goes into the folder named by
 * the id, at an address anybody could count to. Run before `builder:recompile`, so a page is written again where it now is.
 */
class MoveAdFolders extends Command
{
    private const MIGRATION = '2026_10_15_220000_give_every_ad_a_folder_nobody_can_guess';

    protected $signature = 'builder:move-ad-folders';

    protected $description = "Move every Ad Builder file still in a folder named by an ad's id into the ad's own";

    public function handle(): int
    {
        $file = database_path('migrations/'.self::MIGRATION.'.php');

        if (! is_file($file) || ! Schema::hasTable('builder_ads') || ! Schema::hasColumn('builder_ads', 'storage_key')) {
            $this->error('Run the migrations first: the ads have no folders of their own yet.');

            return self::FAILURE;
        }

        $migration = require $file;
        $migration->up();

        $left = $migration->foldersStillNamedByAnId();

        foreach ($left as $folder => $files) {
            $this->warn("{$folder} is still named by an id and holds {$files} file(s).");
        }

        $this->info($left === [] ? "Every ad's files are in its own folder." : 'The log says why each of these is still there ("Ad folders:").');

        return self::SUCCESS;
    }
}
