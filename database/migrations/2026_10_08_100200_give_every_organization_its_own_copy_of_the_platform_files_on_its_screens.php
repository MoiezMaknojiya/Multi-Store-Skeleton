<?php

use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\Media;
use App\Services\AdPublisher;
use App\Services\MediaStorage;
use App\Services\OrganizationStorage;
use App\Services\TemplateCopier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The Content Library is the organization's own (owner, 2026-10-07 and 2026-10-08 — "platfrom se jo template bane ha woo smart stop
 * per copy kardo aur jis screen per jo ha woo laga do"; docs/BILLING-SPEC.md §5): from now on a screen's playlist holds its organization's
 * files alone, so every line naming a platform library row is given the organization's own copy and keeps its place, seconds and
 * schedule — a platform ad's page through Use This Template (the organization's own ad with its own files, TemplateCopier) and Publish
 * (AdPublisher), a plain platform picture or video copied into the organization's library; one copy per platform row per organization,
 * however many lines name it. Each copy's `copied_from_id` names what it came from. On live (2026-10-08): Smart Stop's four screens,
 * whose televisions show the very same menus.
 *
 * A copy the organization has no room for is not made — that line is left as it was and said in the log — so a deploy never stops
 * here. `down()` points every line back at the platform's row and takes the copies away (an ad's copied pictures stay on the
 * organization's shelf, as the organization's own files).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('media', 'copied_from_id')) {
            return;
        }

        $lines = DB::table('playlist_items')
            ->join('media', 'media.id', '=', 'playlist_items.media_id')
            ->join('screens', 'screens.id', '=', 'playlist_items.screen_id')
            ->whereNull('media.organization_id')
            ->whereNotNull('screens.organization_id')
            ->orderBy('playlist_items.id')
            ->get(['playlist_items.id as line', 'playlist_items.media_id', 'screens.organization_id']);

        $copies = [];

        foreach ($lines as $line) {
            $key = "{$line->organization_id}|{$line->media_id}";

            if (! array_key_exists($key, $copies)) {
                $copies[$key] = $this->copyFor((int) $line->organization_id, (int) $line->media_id);
            }

            if ($copies[$key] !== null) {
                DB::table('playlist_items')->where('id', $line->line)->update(['media_id' => $copies[$key]]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('media', 'copied_from_id')) {
            return;
        }

        $copies = DB::table('media as copy')
            ->join('media as source', 'source.id', '=', 'copy.copied_from_id')
            ->whereNotNull('copy.organization_id')
            ->whereNull('source.organization_id')
            ->get(['copy.id', 'copy.copied_from_id']);

        foreach ($copies as $copy) {
            DB::table('playlist_items')->where('media_id', $copy->id)->update(['media_id' => $copy->copied_from_id]);

            $ad = BuilderAd::firstWhere('media_id', $copy->id);

            if ($ad !== null) {
                // Its page and poster go with it (BuilderAd::booted).
                DB::transaction(fn () => $ad->delete());

                continue;
            }

            $media = Media::find($copy->id);
            $files = [$media->disk, $media->path, $media->thumbnail_path];
            $media->delete();
            app(MediaStorage::class)->deleteFiles(...$files);
        }
    }

    /** The organization's own copy of a platform row: the one it holds already, a template's own ad, or a copied file. */
    private function copyFor(int $organizationId, int $mediaId): ?int
    {
        $held = DB::table('media')->where('organization_id', $organizationId)->where('copied_from_id', $mediaId)->value('id');

        if ($held !== null) {
            return (int) $held;
        }

        $source = Media::find($mediaId);
        $template = BuilderAd::premiumTemplates()->where('media_id', $mediaId)->first();

        try {
            $copy = $template !== null ? $this->adFrom($template, $organizationId) : $this->fileFrom($source, $organizationId);
        } catch (ValidationException $refused) {
            Log::warning("Organization {$organizationId} keeps the platform's {$source->title} on its screens: ".collect($refused->errors())->flatten()->first());

            return null;
        }

        $copy->forceFill(['copied_from_id' => $mediaId])->save();

        return $copy->id;
    }

    /** Use This Template, then Publish: the organization's own ad, whose page its screens play. */
    private function adFrom(BuilderAd $template, int $organizationId): Media
    {
        $name = $template->published_name ?? $template->name;
        $ad = app(TemplateCopier::class)->copy($template, $organizationId, BuilderAd::nameForCopy($name, $organizationId, keepItIfFree: true), null);
        $page = app(AdPublisher::class)->publish($ad);

        ActivityLog::record('ad.copied', $ad, "Made ad {$ad->name} from the premium template {$name}, and published it for the screens that showed it",
            organizationId: $organizationId);

        return $page;
    }

    /** A plain platform picture or video, copied into the organization's library with its preview, counted to its 512 MB. */
    private function fileFrom(Media $source, int $organizationId): Media
    {
        $name = (string) Str::ulid();
        $path = "media/{$organizationId}/{$name}.".pathinfo($source->path, PATHINFO_EXTENSION);
        $thumbnail = $source->thumbnail_path !== null ? "media/{$organizationId}/thumbs/{$name}.jpg" : null;
        $disk = Storage::disk($source->disk);
        $bytes = (int) $source->size + ($thumbnail !== null && $disk->exists($source->thumbnail_path) ? (int) $disk->size($source->thumbnail_path) : 0);

        return app(OrganizationStorage::class)->withRoom($organizationId, $bytes, function () use ($source, $organizationId, $path, $thumbnail, $disk) {
            $disk->copy($source->path, $path);

            if ($thumbnail !== null && $disk->exists($source->thumbnail_path)) {
                $disk->copy($source->thumbnail_path, $thumbnail);
            }

            $copy = $source->replicate(['copied_from_id']);
            $copy->forceFill(['organization_id' => $organizationId, 'path' => $path, 'thumbnail_path' => $thumbnail !== null && $disk->exists($thumbnail) ? $thumbnail : null, 'created_by' => null])->save();

            ActivityLog::record('media.copied', $copy, "Copied {$source->title} from the platform's library, for the screens that showed it", organizationId: $organizationId);

            return $copy;
        });
    }
};
