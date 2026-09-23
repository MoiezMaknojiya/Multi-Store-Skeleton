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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The machine-facing side of the app: four endpoints, no session, no cookies.
 * Everything here is spoken by a TV, never by a person.
 */
class DeviceController extends Controller
{
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

        // The whole schedule — each file's own window and each item's rules — is
        // resolved HERE, not on the TV. A cheap box with a wrong clock still shows
        // the right thing, and nothing outside its window is even sent to the device.
        // This is also what makes a television switched on at two in the afternoon
        // show the two-o'clock playlist: it asks, and the answer is for right now.
        $resolved = $resolver->resolve($screen);

        $items = $resolved['items']
            ->map(fn (PlaylistItem $item) => $item->isChannel()
                ? $this->channelEntry($item)
                : $this->manifestItem(
                    $item->id, $item->media, $item->duration_seconds ?? PlaylistItem::DEFAULT_IMAGE_SECONDS
                ))->all();

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

        // The network advertising break, if this screen carries any. Sent WITH the
        // playlist rather than fetched separately: the television already asks this
        // question every thirty seconds, and a second poll would be a second thing
        // to go wrong on a cheap box.
        $adBreak = $ads->breakFor($screen);

        // The holding picture travels alongside the items too (docs/AD-BUILDER-SPEC.md
        // §15): a television working from a cached manifest, once everything in it has
        // expired, falls back to it the way the server would have.
        $fallback = $screen->defaultMedia;
        $fallback = $fallback?->isPlayableNow() ? $this->manifestItem(
            0, $fallback, $fallback->duration_seconds ?: PlaylistItem::DEFAULT_IMAGE_SECONDS
        ) : null;

        return response()->json([
            'screen' => [
                'id' => $screen->id,
                'name' => $screen->name,
                'orientation' => $screen->orientation,
            ],
            'server_time' => now()->toIso8601String(),
            'blank' => $resolved['blank'],
            'version' => $this->playlistVersion($screen, $items, $resolved['blank'], $adBreak),
            'items' => $items,
            'fallback' => $fallback,
            // Every file this screen may need, due now or not, so a set can hold the whole
            // playlist before the internet goes — and drop what is no longer on it (§15).
            'assets' => $this->assetsFor($screen, $adBreak),
            'ad_break' => [
                // Counted from the moment the player started, not from the clock —
                // the owner's choice, so two shops that booted at different times do
                // not cut to advertising at the same instant.
                'every_seconds' => Campaign::breakEverySeconds(),
                'items' => $adBreak->map(fn (Campaign $campaign) => [
                    'id' => 'c'.$campaign->id,
                    'type' => $campaign->type,
                    'url' => $campaign->url,
                    'checksum' => $campaign->cacheKey(),
                    'duration' => $campaign->play_seconds,
                    'mime' => $campaign->mime_type,
                ])->all(),
            ],
        ]);
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
     * Every file this screen could be asked to show — each line's file whether due now or not, every
     * ad of every channel on the playlist, the holding picture and the network adverts — as address +
     * cache key, for the set to warm its cache with and to prune it by (docs/AD-BUILDER-SPEC.md §15).
     * Never a draft: an unpublished page is on no screen.
     *
     * @param  Collection<int, Campaign>  $adBreak
     * @return array<int, array{url: string, checksum: string, type: string}>
     */
    private function assetsFor(Screen $screen, Collection $adBreak): array
    {
        $lines = $screen->playlistItems()->with(['media.builderAd', 'channel.ads.media.builderAd'])->get();

        $files = $lines->map(fn (PlaylistItem $item) => $item->media)
            ->merge($lines->flatMap(fn (PlaylistItem $item) => ($item->channel?->ads ?? collect())->map(fn (ChannelAd $ad) => $ad->media)))
            ->push($screen->defaultMedia)
            ->filter(fn (?Media $media) => $media !== null && ! $media->isDraft())
            ->unique('id')
            ->map(fn (Media $media) => ['url' => $media->url, 'checksum' => $media->cacheKey(), 'type' => $media->type]);

        $adverts = $adBreak->map(fn (Campaign $campaign) => [
            'url' => $campaign->url, 'checksum' => $campaign->cacheKey(), 'type' => $campaign->type,
        ]);

        return $files->merge($adverts)->values()->all();
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
