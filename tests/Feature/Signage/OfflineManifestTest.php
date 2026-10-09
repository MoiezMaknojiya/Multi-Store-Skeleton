<?php

use App\Models\BuilderAd;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\ScheduleRule;
use App\Models\Screen;

/*
|--------------------------------------------------------------------------
| What a television is sent so it can keep playing with no line
|--------------------------------------------------------------------------
|
| docs/AD-BUILDER-SPEC.md §15. The manifest says a little more than what plays now: the holding picture
| alongside the items (`fallback`), and what every moment of the next days shows (`timeline`,
| OfflineTimelineTest) — which is also every file the set's worker warms and keeps. A file item carries no
| dates of its own: a file keeps its name alone (owner, 2026-10-01). None of it moves the version — it is a
| change to what plays that must. And each file's cache key moves only when its bytes may have, so a new
| title costs no download.
|
*/

beforeEach(function () {
    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->screen = Screen::factory()->withToken('tok')->create(['organization_id' => $this->organization->id]);
});

/** A picture in the organization's library, playable from now unless told otherwise. */
function picture(Organization $organization, string $title, array $more = []): Media
{
    return Media::factory()->create(['organization_id' => $organization->id, 'title' => $title, 'type' => Media::TYPE_IMAGE, ...$more]);
}

function line(Screen $screen, Media|Channel $what, int $position, ?array $rule = null): PlaylistItem
{
    $item = PlaylistItem::create([
        'screen_id' => $screen->id,
        'media_id' => $what instanceof Media ? $what->id : null,
        'channel_id' => $what instanceof Channel ? $what->id : null,
        'position' => $position,
        'duration_seconds' => $what instanceof Media ? 10 : null,
    ]);

    if ($rule !== null) {
        $item->scheduleRules()->create($rule);
    }

    return $item;
}

function manifestOf($test): array
{
    return $test->withHeader('Authorization', 'Bearer tok')->getJson('/device/playlist')->assertOk()->json();
}

test('a file item carries no dates of its own: when it plays is its line\'s, worked out on the server', function () {
    // A file keeps its name alone (owner, 2026-10-01); the timeline says what each moment shows.
    line($this->screen, picture($this->organization, 'Logo'), 0);

    $item = manifestOf($this)['items'][0];

    expect($item)->not->toHaveKey('expires_at')
        ->and(array_keys($item))->toBe(['id', 'type', 'url', 'checksum', 'duration', 'mime']);
});

test('the holding picture travels alongside the items, so a set can fall back to it by itself', function () {
    $holding = picture($this->organization, 'Holding');
    $this->screen->update(['default_media_id' => $holding->id]);
    line($this->screen, picture($this->organization, 'Menu'), 0);

    $manifest = manifestOf($this);

    expect($manifest['items'])->toHaveCount(1)
        ->and($manifest['fallback']['id'])->toBe(0)
        ->and($manifest['fallback']['url'])->toBe($holding->url)
        ->and($manifest['fallback']['checksum'])->toBe($holding->cacheKey());

    $this->screen->update(['default_media_id' => null]);

    expect(manifestOf($this)['fallback'])->toBeNull();
});

/** Every file address a manifest's timeline names — its file lines and its channel lines' ads. */
function timelineUrls(array $manifest): array
{
    $lines = collect($manifest['timeline']['lines']);

    return $lines->pluck('url')
        ->merge($lines->pluck('ads')->filter()->flatten(1)->pluck('url'))
        ->filter()->unique()->values()->all();
}

test('the timeline names every file the next days may play — and nothing further off, and no draft', function () {
    $now = picture($this->organization, 'Now');
    $tomorrow = picture($this->organization, 'Tomorrow only');
    $nextYear = picture($this->organization, 'Next year');
    $holding = picture($this->organization, 'Holding');
    $draft = BuilderAd::factory()->withText()->published()->create(['organization_id' => $this->organization->id, 'name' => 'Draft page']);
    $draft->update(['published_at' => null]);   // unpublished: its page is on no screen
    $channel = Channel::factory()->create(['organization_id' => $this->organization->id]);
    $channelPicture = picture($this->organization, 'Channel ad');
    ChannelAd::factory()->create(['channel_id' => $channel->id, 'media_id' => $channelPicture->id]);

    $this->screen->update(['default_media_id' => $holding->id]);
    line($this->screen, $now, 0);
    // From tomorrow: not due now, but inside the days a set holds — so it is on the set before the line drops.
    line($this->screen, $tomorrow, 1, ['recurrence_type' => ScheduleRule::DAILY, 'starts_on' => now()->addDay()->toDateString(), 'position' => 1]);
    // From next year: a file outside its window never reaches the device (rule 02), not even to be kept.
    line($this->screen, $nextYear, 2, ['recurrence_type' => ScheduleRule::DAILY, 'starts_on' => now()->addYear()->toDateString(), 'position' => 2]);
    line($this->screen, $draft->media, 3);
    line($this->screen, $channel, 4);

    $manifest = manifestOf($this);
    $urls = timelineUrls($manifest);

    expect($manifest)->not->toHaveKey('assets')
        ->and($urls)->toContain($now->url, $tomorrow->url, $channelPicture->url)
        ->and($urls)->not->toContain($nextYear->url)
        ->and($urls)->not->toContain($draft->media->url)
        ->and($manifest['fallback']['url'])->toBe($holding->url)
        // Due now: the first picture alone — and the channel line, which rides along with it.
        ->and(collect($manifest['items'])->where('type', '!=', 'channel')->pluck('url')->all())->toBe([$now->url])
        ->and(collect($manifest['items'])->where('type', 'channel'))->toHaveCount(1);
});

test('a network advert rides in the break and in the timeline, under the campaign’s own cache key', function () {
    $this->organization->update(['accepts_network_ads' => true]);
    $this->screen->update(['accepts_network_ads' => true]);
    $campaign = Campaign::factory()->create(['name' => 'Cola']);
    $campaign->screens()->attach($this->screen);
    line($this->screen, picture($this->organization, 'Menu'), 0);

    $manifest = manifestOf($this);
    $advert = collect($manifest['timeline']['lines'])->firstWhere('url', $campaign->url);

    expect($manifest['ad_break']['items'][0]['checksum'])->toBe($campaign->cacheKey())
        ->and($advert['checksum'])->toBe($campaign->cacheKey());
});

test('neither the fallback nor tomorrow’s files move the version: it is what plays now that does', function () {
    line($this->screen, picture($this->organization, 'Menu'), 0);
    $before = manifestOf($this)['version'];

    $holding = picture($this->organization, 'Holding');
    $this->screen->update(['default_media_id' => $holding->id]);
    line($this->screen, picture($this->organization, 'Tomorrow'), 1, ['recurrence_type' => ScheduleRule::DAILY, 'starts_on' => now()->addDay()->toDateString(), 'position' => 1]);

    $after = manifestOf($this);

    expect($after['version'])->toBe($before)
        ->and($after['fallback'])->not->toBeNull()
        ->and(count($after['timeline']['entries']))->toBeGreaterThan(1);
});

/* ── What moves a cache key: the bytes, and nothing else ────────────────── */

test('a picture keeps its cache key through a new title — only a new file moves it', function () {
    $poster = picture($this->organization, 'Poster', ['path' => 'media/1/01J8X0000000000000000000AA.jpg', 'size' => 1000]);
    $key = $poster->cacheKey();

    $this->travel(5)->minutes();
    $poster->update(['title' => 'Poster, renamed']);

    expect($poster->fresh()->cacheKey())->toBe($key);

    $poster->update(['path' => 'media/1/01J8X0000000000000000000BB.jpg']);

    expect($poster->fresh()->cacheKey())->not->toBe($key);
});

test('a video keeps its cache key through a new title too', function () {
    $video = picture($this->organization, 'Promo', ['type' => Media::TYPE_VIDEO, 'mime_type' => 'video/mp4', 'path' => 'media/1/01J8X0000000000000000000CC.mp4']);
    $key = $video->cacheKey();

    $this->travel(5)->minutes();
    $video->update(['title' => 'Promo, renamed']);

    expect($video->fresh()->cacheKey())->toBe($key);
});

test('a channel ad is kept under its file’s own key: one file on a playlist and in a channel is one copy on the set', function () {
    $picture = picture($this->organization, 'Shared');
    $channel = Channel::factory()->create(['organization_id' => $this->organization->id]);
    $ad = ChannelAd::factory()->create(['channel_id' => $channel->id, 'media_id' => $picture->id]);

    line($this->screen, $picture, 0);
    line($this->screen, $channel, 1);

    $items = collect(manifestOf($this)['items']);
    $file = $items->firstWhere('type', 'image');
    $inChannel = $items->firstWhere('type', 'channel')['ads'][0];

    expect($ad->cacheKey())->toBe($picture->cacheKey())
        ->and($inChannel['url'])->toBe($file['url'])
        ->and($inChannel['checksum'])->toBe($file['checksum']);
});

test('a campaign keeps its cache key through a new name — a replaced file moves it', function () {
    $campaign = Campaign::factory()->create(['name' => 'Cola']);
    $key = $campaign->cacheKey();

    $this->travel(5)->minutes();
    $campaign->update(['name' => 'Cola Zero']);

    expect($campaign->fresh()->cacheKey())->toBe($key);

    $campaign->update(['path' => 'campaigns/01J8X0000000000000000000DD.jpg']);

    expect($campaign->fresh()->cacheKey())->not->toBe($key);
});

test('the player is a web app: its manifest names the app and opens the player full screen', function () {
    $this->get('/player.webmanifest')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json')
        ->assertJsonPath('start_url', '/player')
        ->assertJsonPath('display', 'fullscreen')
        ->assertJsonPath('scope', '/player')
        ->assertJsonPath('icons.0.src', '/icon-192.png')
        ->assertJsonPath('icons.2.purpose', 'maskable');

    expect(file_exists(public_path('player-sw.js')))->toBeTrue()
        ->and(file_exists(public_path('icon-maskable-512.png')))->toBeTrue();

    // The player page links the manifest, and every address in the worker is a real one of this app.
    $this->get('/player')->assertOk()->assertSee('rel="manifest"', false)->assertSee('/player.webmanifest', false);
});
