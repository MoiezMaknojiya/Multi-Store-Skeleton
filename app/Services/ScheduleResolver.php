<?php

namespace App\Services;

use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\ScheduleRule;
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
 * itself keeps no hours of its own, and a file keeps no dates of its own (owner,
 * 2026-10-01: the library is where files are kept, the playlist where they are
 * scheduled). For a FILE one question decides it — does any of the item's rules say
 * yes? (no rules = whenever) — and an Ad Builder page taken off the screens (a draft)
 * never plays.
 *
 * A CHANNEL line asks a different first question — is the channel on the air, with
 * ads running today? — and follows one extra rule, because an organization darkens its screen
 * at night by scheduling its OWN files, not a channel it merely added:
 *
 *   channel line with a schedule of its own  → follows that schedule, like a file
 *   channel line with none                   → plays while at least one of the organization's
 *                                              own files is due, so it never lights up
 *                                              a screen the organization has left dark
 *   …and when no file on the playlist is live at all, an unscheduled channel plays on
 *   its own: a screen given only channels, or whose own files are all drafts, is not a
 *   screen asking to be dark.
 *
 * Then, when the answer is nothing at all, three different nothings — because a TV
 * showing the wrong one looks broken:
 *
 *   empty playlist            → "No content", which is true and useful on a screen
 *                               that has just been paired
 *   nothing due, default set  → the organization's own holding picture
 *   nothing due, no default   → BLACK, with no message. Outside its hours an organization's
 *                               television should look switched off, not faulty.
 */
class ScheduleResolver
{
    /** Media::isPlayableNow(): there, and not an Ad Builder page taken off the screens. */
    private function playable(?Media $media): bool
    {
        return $media !== null && $media->isPlayableNow();
    }

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
        return $this->resolveLoaded($screen, $this->load($screen), CarbonImmutable::instance($at ?? now()));
    }

    /**
     * The screen's playlist with everything a decision reads, loaded once — so the same lines can be asked
     * about many moments (the offline timeline, docs/AD-BUILDER-SPEC.md §15) without a query each.
     *
     * @return Collection<int, PlaylistItem>
     */
    public function load(Screen $screen): Collection
    {
        // A page's design comes along: an Ad Builder page taken off the screens (unpublished) is not played (Media::isDraft()).
        return $screen->playlistItems()
            ->with(['media.builderAd', 'channel.ads', 'scheduleRules'])
            ->get()
            // A line whose file or channel no longer exists is simply not there.
            ->filter(fn (PlaylistItem $item) => $item->media !== null || $item->channel !== null)
            ->values();
    }

    /**
     * What the screen should show at one moment, from a playlist already loaded. A channel line's ads for
     * that moment ride on the line (`liveAds`), so describe the answer before asking about another moment.
     *
     * @param  Collection<int, PlaylistItem>  $playlist
     * @return array{blank: bool, items: Collection<int, PlaylistItem>, fallback: ?Media, local_time: CarbonImmutable}
     */
    public function resolveLoaded(Screen $screen, Collection $playlist, CarbonImmutable $instant): array
    {
        $local = $screen->localTime($instant);

        $liveFiles = $playlist->filter(fn (PlaylistItem $item) => $this->playable($item->media));
        $dueFileIds = $liveFiles->filter(fn (PlaylistItem $item) => $item->isDueAt($local))->pluck('id')->flip();

        // An unscheduled channel rides along with the organization's own content — or plays by
        // itself when there is no live file for it to ride along with.
        $channelsMayRideAlong = $dueFileIds->isNotEmpty() || $liveFiles->isEmpty();

        // Platform Channels locked for the organization (docs/BILLING-SPEC.md §6, owner 2026-10-08): a platform channel's line keeps
        // its place on the playlist and plays nothing until they are unlocked again — then it plays by itself.
        $platformChannelsLocked = $screen->organization !== null && ! $screen->organization->platform_channels_unlocked;

        $due = $playlist
            ->filter(function (PlaylistItem $item) use ($local, $dueFileIds, $channelsMayRideAlong, $platformChannelsLocked) {
                if ($item->media !== null) {
                    return $dueFileIds->has($item->id);
                }

                if ($platformChannelsLocked && $item->channel->isPlatformChannel()) {
                    return false;
                }

                $ads = $item->channel->liveAdsOn($local);

                if ($ads->isEmpty()) {
                    return false;
                }

                $item->setRelation('liveAds', $ads);

                return $item->scheduleRules->isEmpty() ? $channelsMayRideAlong : $item->isDueAt($local);
            })
            ->values();

        $fallback = $due->isEmpty() ? $this->fallbackFor($screen) : null;

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
     * Every moment after $from, up to $until, at which this screen's answer can change — a superset, never
     * a miss: each local midnight (a rule's dates and weekdays, a channel ad's dates all turn over there) and
     * every clock time a rule on the playlist opens or closes at, on every day in between. The offline
     * timeline asks the resolver about each (docs/AD-BUILDER-SPEC.md §15).
     *
     * @param  Collection<int, PlaylistItem>  $playlist
     * @return list<CarbonImmutable>
     */
    public function changePoints(Screen $screen, Collection $playlist, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $timezone = $screen->timezone ?: Screen::DEFAULT_TIMEZONE;
        $times = $playlist
            ->flatMap(fn (PlaylistItem $item) => $item->scheduleRules)
            ->flatMap(fn (ScheduleRule $rule) => $rule->clockTimes())
            ->unique()
            ->values();

        $points = [];

        for ($day = $screen->localTime($from)->startOfDay(); $day->lte($screen->localTime($until)); $day = $day->addDay()) {
            $points[] = $day;

            // Seconds said outright: a format without them takes the clock's own, and the change would land
            // up to a minute after the window really opened.
            foreach ($times as $time) {
                $points[] = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $day->toDateString().' '.$time.':00', $timezone);
            }
        }

        return collect($points)
            ->map(fn (CarbonInterface $point) => CarbonImmutable::instance($point))
            ->filter(fn (CarbonImmutable $point) => $point->gt($from) && $point->lte($until))
            ->unique(fn (CarbonImmutable $point) => $point->getTimestamp())
            ->sortBy(fn (CarbonImmutable $point) => $point->getTimestamp())
            ->values()
            ->all();
    }

    /** The holding picture, if the organization set one — never an Ad Builder page taken off the screens. */
    private function fallbackFor(Screen $screen): ?Media
    {
        $media = $screen->defaultMedia;

        return $this->playable($media) ? $media : null;
    }
}
