<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\BuilderFont;
use App\Services\GoogleFontInstaller;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Every installed Ad Builder font gets the weights Google has added since it was installed (owner, 2026-10-05:
 * "google font k jese jese new weight aye dalte raho"; docs/AD-BUILDER-SPEC.md §7a). Scheduled weekly in
 * routes/console.php. A family Google cannot answer for stays as it was, and the next week tries again.
 */
class RefreshFonts extends Command
{
    protected $signature = 'fonts:refresh';

    protected $description = 'Give every installed Ad Builder font the weights Google has added since it was installed';

    public function handle(GoogleFontInstaller $installer): int
    {
        foreach (BuilderFont::orderBy('family')->get() as $font) {
            try {
                $added = $installer->refresh($font);
            } catch (ValidationException $refused) {
                $this->warn("{$font->family}: ".collect($refused->errors())->flatten()->first());

                continue;
            } catch (Throwable $failed) {
                report($failed);
                $this->warn("{$font->family}: {$failed->getMessage()}");

                continue;
            }

            if ($added === []) {
                $this->line("{$font->family}: has every weight Google has.");

                continue;
            }

            $words = (count($added) === 1 ? 'weight ' : 'weights ').implode(', ', $added);
            ActivityLog::record('ad_font.updated', null, "Added {$words} to the font {$font->family} for the ad builder");
            $this->info("{$font->family}: added {$words}.");
        }

        return self::SUCCESS;
    }
}
