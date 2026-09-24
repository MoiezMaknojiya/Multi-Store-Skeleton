<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\Media;
use App\Services\AdCompiler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Every published Ad Builder page written again from the version on the screens (docs/AD-BUILDER-SPEC.md
 * §9, §15), with what the compiler writes today — its security policy, and whatever it has been taught since.
 *
 * Only the PUBLISHED version: a design's unpublished changes stay a draft exactly as they were — this is not a
 * Publish, and no screen is given anything its owner has not published. The page keeps its address; its row is
 * stamped, so its cache key moves and every screen showing it fetches the new copy. An ad published before
 * versions were kept (2026-09-21) has no version to write from: it is named and left alone, for somebody to
 * publish again from the editor.
 *
 * `--outdated` writes only the pages an older compiler wrote (their stamp is not AdCompiler::VERSION) and leaves
 * the rest untouched — no new copy for any screen to fetch. Every deploy runs it (deploy/server/release.sh),
 * after the site has switched to the new release, so a page never names a runtime the site does not serve yet.
 */
class RecompilePublishedAds extends Command
{
    protected $signature = 'builder:recompile
        {--ad=* : Only these ads, by id}
        {--outdated : Only the pages an older compiler wrote}';

    protected $description = 'Write every published Ad Builder page again from the version on the screens';

    public function handle(AdCompiler $compiler): int
    {
        $only = array_values(array_filter((array) $this->option('ad'), fn (mixed $id) => ctype_digit((string) $id)));

        $ads = BuilderAd::query()
            ->whereNotNull('published_at')
            ->whereNotNull('media_id')
            ->when($only !== [], fn ($query) => $query->whereIn('id', $only))
            ->with('media')
            ->orderBy('id')
            ->get();

        $written = 0;
        $current = 0;

        foreach ($ads as $ad) {
            $media = $ad->media;

            if ($media !== null && $this->option('outdated') && AdCompiler::wroteCurrent($this->pageOf($media))) {
                $current++;

                continue;
            }

            if ($media === null || $ad->published_document === null) {
                $this->warn("Skipped #{$ad->id} {$ad->name}: no published version is kept. Publish it again from the editor.");

                continue;
            }

            // The version on the screens, on a copy that is never saved: the design itself is not touched.
            $published = clone $ad;
            $published->document = $ad->published_document;
            $published->name = $ad->published_name ?? $ad->name;

            $html = $compiler->compile($published);

            Storage::disk($media->disk)->put($media->path, $html);

            // Stamped even when the length is the same, or the cache key would not move (as AdPublisher does).
            $media->forceFill(['size' => strlen($html), 'updated_at' => now()])->save();

            ActivityLog::record('ad.recompiled', $ad, "Wrote the published page of {$published->name} again");

            $this->line("Recompiled #{$ad->id} {$published->name}");
            $written++;
        }

        $this->info("{$written} page(s) written.".($this->option('outdated') ? " {$current} already current." : ''));

        return self::SUCCESS;
    }

    /** What the page on disk says now, or null when there is none to read. */
    private function pageOf(Media $media): ?string
    {
        $disk = Storage::disk($media->disk);

        return $disk->exists($media->path) ? (string) $disk->get($media->path) : null;
    }
}
