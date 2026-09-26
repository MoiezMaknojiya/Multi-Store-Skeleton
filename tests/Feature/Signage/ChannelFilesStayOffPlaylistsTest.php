<?php

use App\Models\BuilderAd;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\Screen;
use App\Models\Store;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| A file plays from playlists or from channels, never both
|--------------------------------------------------------------------------
|
| Owner, 2026-09-26: "agar koi bhi file channel k ander assign ha toh woo playlist mein nahi dikhe warna woo
| 2 bar ho jayegi" — then "add naah ho sake nahi, dikhao hi nahi". A file in a channel AND on the playlist that
| carries the channel plays twice in one pass. So the playlist's picker never shows a file any channel holds —
| the shop's own channel or the platform's, paused or not — and a channel's pickers never show a file a
| playlist holds. Out of every channel (or off every playlist), a file is the other side's to choose again.
| InputAbuseAttackTest holds the walls behind the two pickers.
|
*/

beforeEach(function () {
    $this->store = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->manager = createStoreUser($this->store, [
        'screen-view', 'screen-playlist', 'channel-view', 'channel-update',
    ], 'Manager');
    $this->actingAs($this->manager)->withSession(['current_store_id' => $this->store->id]);

    $this->screen = Screen::factory()->create(['store_id' => $this->store->id, 'name' => 'Counter TV']);
    $this->channel = Channel::factory()->create(['store_id' => $this->store->id, 'name' => 'Our Deals']);
});

/** The ids a picker offers. */
function offeredIn(TestCase $test, string $uri): array
{
    return collect($test->getJson($uri)->assertOk()->json('media'))->pluck('id')->all();
}

test("the playlist's picker leaves out every file a channel shows, and offers it again once it leaves the channel", function () {
    $free = Media::factory()->create(['store_id' => $this->store->id, 'title' => 'Menu board']);
    $inOwnChannel = Media::factory()->create(['store_id' => $this->store->id, 'title' => 'Deal poster']);
    $inPlatformChannel = Media::factory()->create(['store_id' => $this->store->id, 'title' => 'GAMA promo']);
    $inPausedChannel = Media::factory()->create(['store_id' => $this->store->id, 'title' => 'Old promo']);

    $ownAd = ChannelAd::factory()->create(['channel_id' => $this->channel->id, 'media_id' => $inOwnChannel->id]);
    // The platform's channel may show a shop's file; that shop's playlists leave it out all the same.
    ChannelAd::factory()->create([
        'channel_id' => Channel::factory()->create(['store_id' => null, 'name' => 'GAMA'])->id,
        'media_id' => $inPlatformChannel->id,
    ]);
    // Paused, the channel still holds it — and plays it again the moment it is switched back on.
    ChannelAd::factory()->create([
        'channel_id' => Channel::factory()->paused()->create(['store_id' => $this->store->id, 'name' => 'Summer'])->id,
        'media_id' => $inPausedChannel->id,
    ]);

    $picker = "/screens/{$this->screen->id}/available-media";
    expect(offeredIn($this, $picker))->toBe([$free->id]);

    $this->deleteJson("/channels/{$this->channel->id}/ads/{$ownAd->id}")->assertOk();

    expect(offeredIn($this, $picker))->toEqualCanonicalizing([$free->id, $inOwnChannel->id]);
});

test('an Ad Builder ad a channel shows stays off the playlist, "Show in playlists" or not', function () {
    $ad = BuilderAd::factory()->withText()->published()->create(['store_id' => $this->store->id, 'in_playlists' => true]);
    $picker = "/screens/{$this->screen->id}/available-media";

    expect(offeredIn($this, $picker))->toContain($ad->media_id);

    ChannelAd::factory()->create(['channel_id' => $this->channel->id, 'media_id' => $ad->media_id]);

    expect(offeredIn($this, $picker))->not->toContain($ad->media_id);
});

test("a channel's pickers leave out every file a playlist holds, and offer it again once it leaves the playlist", function () {
    $free = Media::factory()->create(['store_id' => $this->store->id, 'title' => 'Deal poster']);
    $onPlaylist = Media::factory()->create(['store_id' => $this->store->id, 'title' => 'Menu board']);
    PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $onPlaylist->id, 'position' => 0, 'duration_seconds' => 10]);

    // The shop's own channel…
    expect(offeredIn($this, "/channels/{$this->channel->id}/library?type=files"))->toBe([$free->id]);

    // …and, above the stores, the platform's channel looking into this shop's library.
    $gama = Channel::factory()->create(['store_id' => null, 'name' => 'GAMA']);
    $this->actingAs(createSuperAdmin())->withSession([]);
    $platformPicker = "/channels/{$gama->id}/library?type=files&library={$this->store->id}";
    expect(offeredIn($this, $platformPicker))->toBe([$free->id]);

    PlaylistItem::where('media_id', $onPlaylist->id)->delete();

    expect(offeredIn($this, $platformPicker))->toEqualCanonicalizing([$free->id, $onPlaylist->id]);
});

test('an ad keeps its own file when it is saved, even one a playlist came to hold before the rule', function () {
    // A channel ad and a playlist line sharing a file, as they could before 2026-09-26: re-timing the ad keeps
    // its file — the wall stops only a file coming INTO a channel from a playlist.
    $shared = Media::factory()->create(['store_id' => $this->store->id, 'title' => 'Menu board']);
    $ad = ChannelAd::factory()->create(['channel_id' => $this->channel->id, 'media_id' => $shared->id, 'duration_seconds' => 10]);
    PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $shared->id, 'position' => 0, 'duration_seconds' => 10]);

    $this->postJson("/channels/{$this->channel->id}/ads/{$ad->id}", ['media_id' => $shared->id, 'seconds' => 20])->assertOk();
    $this->postJson("/channels/{$this->channel->id}/ads/{$ad->id}", ['seconds' => 25])->assertOk();

    expect($ad->fresh()->duration_seconds)->toBe(25)->and($ad->fresh()->media_id)->toBe($shared->id);
});

test('both pickers say why a file is not there', function () {
    $this->get("/screens/{$this->screen->id}")
        ->assertOk()
        ->assertSee('Files that play in a channel are not listed here, so nothing plays twice.');

    $this->get("/channels/{$this->channel->id}")
        ->assertOk()
        ->assertSee("Files on a screen's playlist are not listed here, so nothing plays twice.", false);
});
