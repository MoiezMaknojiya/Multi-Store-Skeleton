<?php

namespace App\Http\Controllers\Device;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\Screen;
use App\Services\DevicePairing;
use App\Services\NetworkAdResolver;
use App\Services\ScheduleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The machine-facing side of the app: four endpoints, no session, no cookies.
 * Everything here is spoken by a TV, never by a person.
 */
class DeviceController extends Controller
{
    /**
     * How far ahead a manifest says what the screen should show (docs/AD-BUILDER-SPEC.md §15): a weekend
     * with the shop's line down still follows its dayparts; after that, the last answer plays on.
     */
    public const TIMELINE_HOURS = 72;

    public function __construct(private readonly DevicePairing $pairing) {}

    /**
     * A screen with no token asks to be adopted. Returns the six-character code it
     * should put on the TV, and the secret only it may poll with.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_uuid' => ['nullable', 'string', 'max:40'],
        ]);

        return response()->json($this->pairing->register($validated['device_uuid'] ?? null));
    }

    /**
     * "Has anyone claimed my code yet?" Answers pending / paired / expired /
     * unknown. The token is handed over exactly once, here.
     */
    public function pairStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_uuid' => ['required', 'string', 'max:40'],
            'poll_secret' => ['required', 'string', 'max:64'],
        ]);

        return response()->json(
            $this->pairing->collect($validated['device_uuid'], $validated['poll_secret'])
        );
    }

    /**
     * What this screen should be showing, right now.
     *
     *  - version     : changes only when the resolved playlist changes, so the
     *                  player can skip a re-render on every poll
     *  - server_time : the moment the server resolved this manifest, for a device
     *                  (or a person reading the response) to compare with its own clock
     *  - blank       : this screen HAS a playlist, but none of it is due right now
     *                  and there is no holding picture. Not the same as an empty
     *                  playlist, which shows "No content" — that message is right
     *                  for a freshly paired screen and reads as a fault at 3am
     *  - items[]     : each carries url + checksum, so a future native shell can
     *                  swap in a locally cached file without a contract change.
     *                  A CHANNEL line is one entry of type "channel" holding its
     *                  own ads[] (each with url + checksum) and per_pass — see
     *                  channelEntry()
     */
    public function playlist(Request $request, ScheduleResolver $resolver, NetworkAdResolver $ads): JsonResponse
    {
        $screen = $request->attributes->get('screen');

        // ONE instant answers the whole manifest — the items, the break, the timeline and server_time.
        $now = CarbonImmutable::now();

        // The whole schedule — each file's own window and each item's rules — is
        // resolved HERE, not on the TV. A cheap box with a wrong clock still shows
        // the right thing, and nothing outside its window is even sent to the device.
        // This is also what makes a television switched on at two in the afternoon
        // show the two-o'clock playlist: it asks, and the answer is for right now.
        $playlist = $resolver->load($screen);
        $items = $this->itemsFor($resolver->resolveLoaded($screen, $playlist, $now));

        // The network advertising break, if this screen carries any. Sent WITH the
        // playlist rather than fetched separately: the television already asks this
        // question every thirty seconds, and a second poll would be a second thing
        // to go wrong on a cheap box.
        $adBreak = $ads->breakFor($screen, $now);

        // The holding picture travels alongside the items too (docs/AD-BUILDER-SPEC.md
        // §15): a television working from a cached manifest, once everything in it has
        // expired, falls back to it the way the server would have.
        $fallback = $screen->defaultMedia;
        $fallback = $fallback?->isPlayableNow($now) ? $this->manifestItem(
            0, $fallback, $fallback->duration_seconds ?: PlaylistItem::DEFAULT_IMAGE_SECONDS
        ) : null;

        return response()->json([
            'screen' => [
                'id' => $screen->id,
                'name' => $screen->name,
                'orientation' => $screen->orientation,
            ],
            'server_time' => $now->toIso8601String(),
            'blank' => $items['blank'],
            'version' => $this->playlistVersion($screen, $items['items'], $items['blank'], $adBreak),
            'items' => $items['items'],
            'fallback' => $fallback,
            'ad_break' => [
                // Counted from the moment the player started, not from the clock —
                // the owner's choice, so two shops that booted at different times do
                // not cut to advertising at the same instant.
                'every_seconds' => Campaign::breakEverySeconds(),
                'items' => $adBreak->map(fn (Campaign $campaign) => $this->campaignItem($campaign))->values()->all(),
            ],
            // What this screen should show at every moment its answer changes over the next few days, so a
            // television whose line drops keeps following its dayparts and dates from memory (§15) — and so
            // it knows every file those days may play, to hold before the line goes, and nothing further off:
            // a file outside its window never reaches the device. Moved by none of the version: it is a change
            // to what plays NOW that must.
            'timeline' => $this->timeline($screen, $playlist, $now, $resolver, $ads),
        ]);
    }

    /**
     * The items a resolved answer puts on the screen, and whether it is dark.
     *
     * @param  array{blank: bool, items: Collection<int, PlaylistItem>, fallback: ?Media}  $resolved
     * @return array{items: list<array<string, mixed>>, blank: bool}
     */
    private function itemsFor(array $resolved): array
    {
        $items = $resolved['items']
            ->map(fn (PlaylistItem $item) => $item->isChannel()
                ? $this->channelEntry($item)
                : $this->manifestItem(
                    $item->id, $item->media, $item->duration_seconds ?? PlaylistItem::DEFAULT_IMAGE_SECONDS
                ))->values()->all();

        // Nothing due: the shop's own holding picture rather than a black rectangle
        // in the middle of the afternoon. It goes out as an ordinary item, so the
        // player needs no idea that it is a fallback. Id 0, because no playlist row
        // stands behind it.
        if ($items === [] && $resolved['fallback']) {
            $items = [$this->manifestItem(
                0,
                $resolved['fallback'],
                $resolved['fallback']->duration_seconds ?: PlaylistItem::DEFAULT_IMAGE_SECONDS
            )];
        }

        return ['items' => $items, 'blank' => $resolved['blank']];
    }

    /**
     * The next TIMELINE_HOURS of this screen, from memory's point of view (docs/AD-BUILDER-SPEC.md §15):
     * the answer at now and at every moment it can change after — a daypart opening or closing, a new day,
     * a file starting or expiring, a campaign's window — each only when it differs from the one before.
     *
     * Entries name their items by key into one `lines` map, so the same line is sent once however many
     * entries carry it: a menu board changing four times a day stays a few kilobytes, gzipped.
     *
     * @param  Collection<int, PlaylistItem>  $playlist
     * @return array{until: string, entries: list<array<string, mixed>>, lines: array<string, array<string, mixed>>}
     */
    private function timeline(Screen $screen, Collection $playlist, CarbonImmutable $now, ScheduleResolver $resolver, NetworkAdResolver $ads): array
    {
        $until = $now->addHours(self::TIMELINE_HOURS);

        $points = collect([$now])
            ->merge($resolver->changePoints($screen, $playlist, $now, $until))
            ->merge($ads->changePoints($screen, $now, $until))
            ->unique(fn (CarbonImmutable $point) => $point->getTimestamp())
            ->sortBy(fn (CarbonImmutable $point) => $point->getTimestamp())
            ->values();

        $breaks = $ads->breaksAt($screen, $points->all());
        $lines = [];
        $entries = [];
        $last = null;
        // Each line is described — url, cache key and all — once, not at every change point: a file line
        // is the same at every moment, a channel line the same on every moment with the same ads.
        $keys = [];

        foreach ($points as $at) {
            // Described at once: a channel line's ads for this moment ride on the line, and the next moment
            // overwrites them. (Loops, not arrow functions: an arrow function works on a COPY of $lines.)
            $resolved = $resolver->resolveLoaded($screen, $playlist, $at);
            $entry = ['blank' => $resolved['blank'], 'items' => [], 'ads' => []];

            foreach ($resolved['items'] as $line) {
                $variant = $line->isChannel() ? 'p'.$line->id.':'.$line->liveAds->pluck('id')->implode(',') : 'f'.$line->id;
                $entry['items'][] = $keys[$variant] ??= $this->lineKey($lines, $line->isChannel()
                    ? $this->channelEntry($line)
                    : $this->manifestItem($line->id, $line->media, $line->duration_seconds ?? PlaylistItem::DEFAULT_IMAGE_SECONDS));
            }

            // Nothing due: the holding picture, exactly as itemsFor() sends it now.
            if ($entry['items'] === [] && $resolved['fallback']) {
                $entry['items'][] = $keys['fallback:'.$resolved['fallback']->id] ??= $this->lineKey($lines, $this->itemsFor($resolved)['items'][0]);
            }

            foreach ($breaks[$at->getTimestamp()] as $campaign) {
                $entry['ads'][] = $keys['c'.$campaign->id] ??= $this->lineKey($lines, $this->campaignItem($campaign));
            }

            if ($entry === $last) {
                continue;
            }

            $last = $entry;
            $entries[] = ['at' => $at->toIso8601String(), ...$entry];
        }

        return ['until' => $until->toIso8601String(), 'entries' => $entries, 'lines' => $lines];
    }

    /** A line kept once in the timeline's map, under a key made of what it is. */
    private function lineKey(array &$lines, array $item): string
    {
        $key = substr(hash('sha256', json_encode($item)), 0, 16);
        $lines[$key] = $item;

        return $key;
    }

    /** One network advert as the player plays it. */
    private function campaignItem(Campaign $campaign): array
    {
        return [
            'id' => 'c'.$campaign->id,
            'type' => $campaign->type,
            'url' => $campaign->url,
            'checksum' => $campaign->cacheKey(),
            'duration' => $campaign->play_seconds,
            'mime' => $campaign->mime_type,
        ];
    }

    /** One entry in the manifest, whatever it was resolved from. The id identifies
     *  the ENTRY, not the file: the same poster twice in a playlist is two entries. */
    private function manifestItem(int $id, Media $media, int $duration): array
    {
        return [
            'id' => $id,
            'type' => $media->type,
            'url' => $media->url,
            // A cache key: the player's service worker files each copy under it, so a
            // republished page or a replaced file is a new address and never shown stale.
            'checksum' => $media->cacheKey(),
            'duration' => $duration,
            'mime' => $media->mime_type,
            // When the file itself stops being current, so a television working from a
            // cached manifest can stop showing it at the right moment (§15). The schedule
            // rules and dayparts are the server's alone and are not re-judged offline.
            'expires_at' => $media->expires_at?->toIso8601String(),
        ];
    }

    /**
     * A channel line: its ads for today, in order, and how many of them one pass plays.
     *
     * Sent whole rather than already cut down to one pass, because only the player
     * knows when a pass has finished — so only the player can move on to the next ads.
     * Every ad carries its own url + checksum like any other file, and its id is
     * prefixed with the line's, so the same channel twice in one playlist is two
     * entries rather than one.
     */
    private function channelEntry(PlaylistItem $item): array
    {
        return [
            'id' => 'p'.$item->id,
            'type' => 'channel',
            // null: every ad, every pass.
            'per_pass' => $item->channel->ads_per_pass,
            // The resolver hands the day's ads over on the line itself. Read defensively
            // all the same: a television asking what to show must never meet a 500 because
            // some later caller resolved the line a different way.
            'ads' => collect($item->liveAds ?? [])->map(fn (ChannelAd $ad) => [
                'id' => 'p'.$item->id.'-a'.$ad->id,
                'type' => $ad->type,
                'url' => $ad->url,
                'checksum' => $ad->cacheKey(),
                'duration' => $ad->play_seconds,
                'mime' => $ad->mime_type,
            ])->all(),
        ];
    }

    /** "I am alive." The only thing a screen reports, and all the Online chip needs. */
    public function heartbeat(Request $request): JsonResponse
    {
        $screen = $request->attributes->get('screen');

        $screen->forceFill(['last_seen_at' => now()])->save();

        return response()->json(['status' => 'ok']);
    }

    /**
     * A stable fingerprint of what the player should be showing.
     *
     * Blankness is part of it: going dark changes nothing about the (empty) item
     * list, and the player has to notice anyway. So is the advertising break — a
     * campaign starting or ending changes nothing about the shop's own playlist, and
     * the television would otherwise carry the old advert until something else
     * happened to change.
     *
     * @param  Collection<int, Campaign>  $adBreak
     */
    private function playlistVersion(Screen $screen, array $items, bool $blank, Collection $adBreak): string
    {
        $ads = $adBreak->map(fn (Campaign $campaign) => $campaign->id.':'.$campaign->cacheKey())->implode(',');

        return substr(hash(
            'sha256',
            $screen->id.'|'.$screen->orientation.'|'.($blank ? 'blank' : 'live').'|'.json_encode($items).'|'.$ads
        ), 0, 16);
    }
}
