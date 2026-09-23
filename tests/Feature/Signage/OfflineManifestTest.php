<?php

use App\Models\BuilderAd;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\ScheduleRule;
use App\Models\Screen;
use App\Models\Store;

/*
|--------------------------------------------------------------------------
| What a television is sent so it can keep playing with no line
|--------------------------------------------------------------------------
|
| docs/AD-BUILDER-SPEC.md §15. The manifest says a little more than what plays now: when each file stops
| being current (`expires_at`), the holding picture alongside the items (`fallback`), and every file the
| screen may need whether due now or not (`assets`), so the set's worker can warm and prune its cache.
| None of it moves the version — it is a change to what plays that must.
|
*/

beforeEach(function () {
    $this->store = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->screen = Screen::factory()->withToken('tok')->create(['store_id' => $this->store->id]);
});

/** A picture in the store's library, playable from now unless told otherwise. */
function picture(Store $store, string $title, array $more = []): Media
{
    return Media::factory()->create(['store_id' => $store->id, 'title' => $title, 'type' => Media::TYPE_IMAGE, ...$more]);
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

test('each file item says when it expires, and one that never does says so too', function () {
    $ending = picture($this->store, 'Sale', ['expires_at' => now()->addHours(2)]);
    $lasting = picture($this->store, 'Logo');
    line($this->screen, $ending, 0);
    line($this->screen, $lasting, 1);

    $items = collect(manifestOf($this)['items']);

    expect($items->firstWhere('url', $ending->url)['expires_at'])->toBe($ending->expires_at->toIso8601String())
        ->and($items->firstWhere('url', $lasting->url))->toHaveKey('expires_at')
        ->and($items->firstWhere('url', $lasting->url)['expires_at'])->toBeNull();
});

test('the holding picture travels alongside the items, so a set can fall back to it by itself', function () {
    $holding = picture($this->store, 'Holding');
    $this->screen->update(['default_media_id' => $holding->id]);
    line($this->screen, picture($this->store, 'Menu'), 0);

    $manifest = manifestOf($this);

    expect($manifest['items'])->toHaveCount(1)
        ->and($manifest['fallback']['id'])->toBe(0)
        ->and($manifest['fallback']['url'])->toBe($holding->url)
        ->and($manifest['fallback']['checksum'])->toBe($holding->cacheKey());

    // A holding picture that has expired is not one — the same rule the resolver follows.
    $holding->update(['expires_at' => now()->subMinute()]);

    expect(manifestOf($this)['fallback'])->toBeNull();

    $this->screen->update(['default_media_id' => null]);

    expect(manifestOf($this)['fallback'])->toBeNull();
});

test('assets name every file the screen may need — due now or not — and nothing it may not', function () {
    $now = picture($this->store, 'Now');
    $later = picture($this->store, 'Tonight only');
    $holding = picture($this->store, 'Holding');
    $draft = BuilderAd::factory()->withText()->published()->create(['store_id' => $this->store->id, 'name' => 'Draft page']);
    $draft->update(['published_at' => null]);   // unpublished: its page is on no screen
    $channel = Channel::factory()->create(['store_id' => $this->store->id]);
    $channelPicture = picture($this->store, 'Channel ad');
    ChannelAd::factory()->create(['channel_id' => $channel->id, 'media_id' => $channelPicture->id]);

    $this->screen->update(['default_media_id' => $holding->id]);
    line($this->screen, $now, 0);
    // Scheduled from next year: not due today, but the set should hold it already.
    line($this->screen, $later, 1, ['recurrence_type' => ScheduleRule::DAILY, 'starts_on' => now()->addYear()->toDateString(), 'position' => 1]);
    line($this->screen, $draft->media, 2);
    line($this->screen, $channel, 3);

    $manifest = manifestOf($this);
    $assets = collect($manifest['assets']);

    expect($assets->pluck('url')->all())->toContain($now->url, $later->url, $holding->url, $channelPicture->url)
        ->and($assets->pluck('url')->all())->not->toContain($draft->media->url)
        ->and($assets->firstWhere('url', $later->url)['checksum'])->toBe($later->cacheKey())
        ->and($assets->firstWhere('url', $later->url)['type'])->toBe('image')
        // Due now: the first picture alone (the second is scheduled for next year) — and the channel line,
        // which rides along with it.
        ->and(collect($manifest['items'])->where('type', '!=', 'channel')->pluck('url')->all())->toBe([$now->url])
        ->and(collect($manifest['items'])->where('type', 'channel'))->toHaveCount(1);
});

test('the network adverts are among the assets, with the campaign’s own cache key', function () {
    $this->store->update(['accepts_network_ads' => true]);
    $this->screen->update(['accepts_network_ads' => true]);
    $campaign = Campaign::factory()->create(['name' => 'Cola']);
    $campaign->screens()->attach($this->screen);
    line($this->screen, picture($this->store, 'Menu'), 0);

    $assets = collect(manifestOf($this)['assets']);

    expect($assets->firstWhere('url', $campaign->url)['checksum'])->toBe($campaign->cacheKey());
});

test('neither the fallback nor the assets move the version: it is what plays that does', function () {
    line($this->screen, picture($this->store, 'Menu'), 0);
    $before = manifestOf($this)['version'];

    $holding = picture($this->store, 'Holding');
    $this->screen->update(['default_media_id' => $holding->id]);
    line($this->screen, picture($this->store, 'Next year'), 1, ['recurrence_type' => ScheduleRule::DAILY, 'starts_on' => now()->addYear()->toDateString(), 'position' => 1]);

    $after = manifestOf($this);

    expect($after['version'])->toBe($before)
        ->and($after['fallback'])->not->toBeNull()
        ->and($after['assets'])->toHaveCount(3);
});

test('the player is a web app: its manifest names the app and opens the player full screen', function () {
    $this->get('/player.webmanifest')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json')
        ->assertJsonPath('start_url', '/player')
        ->assertJsonPath('display', 'fullscreen')
        ->assertJsonPath('scope', '/player')
        ->assertJsonPath('icons.0.src', '/player-icons/icon-192.png');

    expect(file_exists(public_path('player-sw.js')))->toBeTrue()
        ->and(file_exists(public_path('player-icons/icon-512.png')))->toBeTrue();

    // The player page links the manifest, and every address in the worker is a real one of this app.
    $this->get('/player')->assertOk()->assertSee('rel="manifest"', false)->assertSee('/player.webmanifest', false);
});
