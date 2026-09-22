<?php

namespace App\Services;

use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\Screen;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * What one screen should be showing at one moment.
 *
 * The single place the schedule is put together, and the answer every television
 * gets. It runs on the SERVER, never on the TV: a cheap box with a wrong clock still
 * shows the right thing, and a file outside its window never reaches the device at
 * all.
 *
 * WHEN something plays is said once, on the line, inside the screen — the screen
 * itself keeps no hours of its own. For a FILE two questions decide it, and either can
 * say no:
 *
 *   1. Is the file itself live?              (media start / expiry)
 *   2. Does any of the item's rules say yes? (no rules = whenever)
 *
 * A CHANNEL line asks a different first question — is the channel on the air, with
 * ads running today? — and follows one extra rule, because a shop darkens its screen
 * at night by scheduling its OWN files, not a channel it merely added:
 *
 *   channel line with a schedule of its own  → follows that schedule, like a file
 *   channel line with none                   → plays while at least one of the shop's
 *                                              own files is due, so it never lights up
 *                                              a screen the shop has left dark
 *   …and when no file on the playlist is live at all, an unscheduled channel plays on
 *   its own: a screen given only channels, or whose own files have all expired, is not
 *   a screen asking to be dark.
 *
 * Then, when the answer is nothing at all, three different nothings — because a TV
 * showing the wrong one looks broken:
 *
 *   empty playlist            → "No content", which is true and useful on a screen
 *                               that has just been paired
 *   nothing due, default set  → the shop's own holding picture
 *   nothing due, no default   → BLACK, with no message. Outside its hours a shop's
 *                               television should look switched off, not faulty.
 */
class ScheduleResolver
{
    /**
     * A channel line among the `items` carries the ads it plays in its `liveAds`
     * relation, so nothing downstream has to work out the dates a second time.
     *
     * @return array{
     *     blank: bool,
     *     items: Collection<int, PlaylistItem>,
     *     fallback: ?Media,
     *     local_time: CarbonImmutable
     * }
     */
    public function resolve(Screen $screen, ?CarbonInterface $at = null): array
    {
        // ONE instant answers the whole manifest. Reading the clock again per item
        // would let a file expiring mid-loop be both in and out of the same answer.
        $instant = CarbonImmutable::instance($at ?? now());
        $local = $screen->localTime($instant);

        // A page's design comes along: an Ad Builder page taken off the screens (unpublished) is not played (Media::isDraft()).
        $playlist = $screen->playlistItems()
            ->with(['media.builderAd', 'channel.ads', 'scheduleRules.daypart.exceptions'])
            ->get()
            // A line whose file or channel no longer exists is simply not there.
            ->filter(fn (PlaylistItem $item) => $item->media !== null || $item->channel !== null);

        $liveFiles = $playlist->filter(fn (PlaylistItem $item) => $item->media?->isPlayableNow($instant) === true);
        $dueFileIds = $liveFiles->filter(fn (PlaylistItem $item) => $item->isDueAt($local))->pluck('id')->flip();

        // An unscheduled channel rides along with the shop's own content — or plays by
        // itself when there is no live file for it to ride along with.
        $channelsMayRideAlong = $dueFileIds->isNotEmpty() || $liveFiles->isEmpty();

        $due = $playlist
            ->filter(function (PlaylistItem $item) use ($local, $dueFileIds, $channelsMayRideAlong) {
                if ($item->media !== null) {
                    return $dueFileIds->has($item->id);
                }

                $ads = $item->channel->liveAdsOn($local);

                if ($ads->isEmpty()) {
                    return false;
                }

                $item->setRelation('liveAds', $ads);

                return $item->scheduleRules->isEmpty() ? $channelsMayRideAlong : $item->isDueAt($local);
            })
            ->values();

        $fallback = $due->isEmpty() ? $this->fallbackFor($screen, $instant) : null;

        return [
            // Black only when there IS a playlist and none of it is due right now.
            // An empty playlist is a different situation with a different answer:
            // that screen is waiting to be given something, and should say so.
            'blank' => $due->isEmpty() && $fallback === null && $playlist->isNotEmpty(),
            'items' => $due,
            'fallback' => $fallback,
            'local_time' => $local,
        ];
    }

    /**
     * The holding picture, if the shop set one and it is still live itself.
     *
     * A default that has expired is not a default — showing it would break the very
     * promise the expiry date makes.
     */
    private function fallbackFor(Screen $screen, CarbonImmutable $instant): ?Media
    {
        $media = $screen->defaultMedia;

        return $media && $media->isPlayableNow($instant) ? $media : null;
    }
}
