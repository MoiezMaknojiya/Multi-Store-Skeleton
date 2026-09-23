<?php

namespace App\Services;

use App\Models\BuilderAd;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\PlaylistItem;
use Illuminate\Support\Facades\Storage;

/**
 * Publishing an ad (docs/AD-BUILDER-SPEC.md §9): the whole bridge between the Builder and the rest of
 * the app.
 *
 * The design is compiled into one page, written to the ad's own folder, and a `media` row of type `html`
 * is created — or refreshed, keeping the same id — so the playlists already carrying it get the new
 * version rather than losing it. Everything else (the picker, schedule rules, copy-playlist, the device
 * manifest, the cache key) needs no changes at all, because from here on the ad simply IS a media row.
 *
 * One place for it, because two things publish: the editor's Publish button and `builder:examples`.
 */
class AdPublisher
{
    public function __construct(private readonly AdCompiler $compiler) {}

    /** Compile and publish; returns the media row a playlist plays. */
    public function publish(BuilderAd $ad, ?int $actorId = null): Media
    {
        $disk = Storage::disk('public');
        $html = $this->compiler->compile($ad);
        $path = $ad->storageDirectory().'/index.html';

        $disk->put($path, $html);

        $media = $ad->media ?? new Media;
        $poster = $this->posterFor($ad, $media);

        // Published and last changed at the very same moment: two separate clocks could straddle a
        // second, and the listing would then call a just-published ad a draft. The media row is stamped
        // with it too, ALWAYS: a republish that happens to keep the page's length and the ad's name would
        // otherwise change no column, Eloquent would skip the UPDATE, and the checksum a screen caches by
        // (and the poster's ?v=) would never move off the old version.
        $now = now();

        $media->forceFill([
            'store_id' => $ad->store_id,
            'title' => $ad->name,
            'type' => Media::TYPE_HTML,
            'mime_type' => 'text/html',
            'disk' => 'public',
            'path' => $path,
            'thumbnail_path' => $poster,
            'size' => strlen($html),
            // The ad's own shape (docs/AD-BUILDER-SPEC.md §12), so every picker that says "portrait" for a
            // photograph says it for this page the same way.
            'width' => $ad->stageWidth(),
            'height' => $ad->stageHeight(),
            'orientation' => $ad->orientation,
            'created_by' => $media->exists ? $media->created_by : $actorId,
            'updated_at' => $now,
        ])->save();

        // The version that is on the screens from now on, kept whole: the changes made after it are measured
        // against it, and "Discard changes" goes back to it (docs/AD-BUILDER-SPEC.md §9).
        $ad->forceFill([
            'media_id' => $media->id,
            'published_at' => $now,
            'published_document' => $ad->document,
            'published_name' => $ad->name,
            'updated_at' => $now,
        ])->save();

        return $media;
    }

    /**
     * The library row's own copy of the design's poster, or null when the design has none.
     *
     * A copy, never the design's file: the row can be deleted from the Media library, which takes the files
     * it names — and a shared poster went with it, leaving the design pointing at nothing. The copy's name
     * is fixed, so every publish overwrites it; the thumbnail's `?v=` moves with the row's timestamp.
     */
    private function posterFor(BuilderAd $ad, Media $media): ?string
    {
        $disk = Storage::disk('public');
        $copy = $ad->storageDirectory().'/published.jpg';

        if ($ad->thumbnail_path && $disk->exists($ad->thumbnail_path)) {
            $disk->put($copy, (string) $disk->get($ad->thumbnail_path));

            return $copy;
        }

        // The design lost its poster: the row's old copy would only show the previous version.
        if ($media->thumbnail_path === $copy) {
            $disk->delete($copy);
        }

        return null;
    }

    /**
     * Take the ad off the screens (Strapi's "Unpublish"): its page leaves every screen, channel, picker and
     * library until it is published again (Media::isDraft()). Nothing is deleted — every playlist line and
     * channel ad holding it keeps its place, and the next Publish brings it back to all of them at once.
     */
    public function unpublish(BuilderAd $ad): void
    {
        $ad->forceFill(['published_at' => null])->save();
    }

    /**
     * Go back to the version on the screens (Strapi's "Discard changes", Xibo's "Discard draft"): the design,
     * its name and its poster as they were published. The page is untouched — it IS that version.
     */
    public function discardChanges(BuilderAd $ad, ?int $actorId = null): void
    {
        $disk = Storage::disk('public');
        $published = $ad->storageDirectory().'/published.jpg';
        $poster = $ad->storageDirectory().'/poster.jpg';
        $publishedHadPoster = $ad->media?->thumbnail_path === $published && $disk->exists($published);

        if ($publishedHadPoster) {
            $disk->put($poster, (string) $disk->get($published));
        } elseif ($ad->thumbnail_path !== null) {
            // The published version had no picture: the draft's would only show what was thrown away.
            $disk->delete($ad->thumbnail_path);
        }

        $ad->forceFill([
            'document' => $ad->published_document,
            'name' => $ad->published_name ?? $ad->name,
            'thumbnail_path' => $publishedHadPoster ? $poster : null,
            'updated_by' => $actorId,
        ])->save();
    }

    /** How many screens have the published copy on their playlist. */
    public function screensShowing(Media $media): int
    {
        return PlaylistItem::where('media_id', $media->id)->distinct('screen_id')->count('screen_id');
    }

    /** How many channels carry the published copy as one of their ads. */
    public function channelsShowing(Media $media): int
    {
        return ChannelAd::where('media_id', $media->id)->distinct('channel_id')->count('channel_id');
    }
}
