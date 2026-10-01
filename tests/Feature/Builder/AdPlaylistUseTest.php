<?php

use App\Models\BuilderAd;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\Screen;
use App\Models\Store;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Where an ad may be chosen — Publish alone decides (owner, 2026-10-01)
|--------------------------------------------------------------------------
|
| "Agar publish honga toh hi content library aur channel mein show honga warna nahi honga, aur channel mein add ha toh
| content library mein show naah ho usko — yeh tick wala kaam hat jayega." A draft is offered nowhere; a published ad
| is offered to a screen's Content library, its holding picture and a channel's picker alike; and whichever takes it
| first keeps it from the other (a file plays from playlists or from channels, never both — 2026-09-26). The "Show in
| playlists" tick of 2026-09-22 is gone, column and address.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->store = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->designer = createStoreUser($this->store, [
        'ad-view', 'ad-store', 'ad-update', 'screen-view', 'screen-update', 'screen-playlist', 'media-view',
        'channel-view', 'channel-update',
    ], 'Designer');
    $this->actingAs($this->designer)->withSession(['current_store_id' => $this->store->id]);

    $this->screen = Screen::factory()->create(['store_id' => $this->store->id, 'name' => 'Lobby TV']);
});

/** The ids a picker answers with. */
function pickerIds(string $uri, string $key = 'media'): array
{
    return collect(test()->getJson($uri)->assertOk()->json($key))->pluck('id')->all();
}

test('a draft is offered nowhere; published, it is in the Content library and the channel picker at once', function () {
    $ad = BuilderAd::factory()->withText()->create(['store_id' => $this->store->id, 'name' => 'Winter sale']);
    $channel = Channel::factory()->create(['store_id' => $this->store->id, 'name' => 'Deals']);

    // A draft has no page yet: no picker has anything of it.
    expect(Media::count())->toBe(0);

    $this->postJson("/builder/{$ad->id}/publish")->assertOk();
    $page = Media::sole();

    expect(pickerIds("/channels/{$channel->id}/library?type=html"))->toContain($page->id)
        ->and(pickerIds("/screens/{$this->screen->id}/available-media"))->toContain($page->id)
        ->and(pickerIds("/screens/{$this->screen->id}/media-options"))->toContain($page->id);

    // The playlist and the holding picture take it as they take any file: there is no tick to ask first.
    $version = $this->getJson("/screens/{$this->screen->id}/playlist")->json('version');
    $this->putJson("/screens/{$this->screen->id}/playlist", ['version' => $version, 'items' => [
        ['media_id' => $page->id, 'duration_seconds' => 10],
    ]])->assertOk();
    $this->putJson("/screens/{$this->screen->id}", [
        'name' => $this->screen->name, 'orientation' => $this->screen->orientation, 'timezone' => $this->screen->timezone,
        'default_media_id' => $page->id,
    ])->assertOk();

    expect(PlaylistItem::where('media_id', $page->id)->exists())->toBeTrue()
        ->and($this->screen->fresh()->default_media_id)->toBe($page->id);
});

test('unpublished, an ad leaves both pickers until it is published again', function () {
    $ad = BuilderAd::factory()->withText()->published()->create(['store_id' => $this->store->id]);
    $channel = Channel::factory()->create(['store_id' => $this->store->id]);

    $this->postJson("/builder/{$ad->id}/unpublish")->assertOk();

    expect(pickerIds("/channels/{$channel->id}/library?type=html"))->not->toContain($ad->media_id)
        ->and(pickerIds("/screens/{$this->screen->id}/available-media"))->not->toContain($ad->media_id);

    $this->postJson("/builder/{$ad->id}/publish")->assertOk();

    expect(pickerIds("/channels/{$channel->id}/library?type=html"))->toContain($ad->media_id)
        ->and(pickerIds("/screens/{$this->screen->id}/available-media"))->toContain($ad->media_id);
});

test('an ad a channel shows stays out of the Content library, and one a playlist holds stays out of the channel picker', function () {
    $inChannel = BuilderAd::factory()->withText()->published()->create(['store_id' => $this->store->id, 'name' => 'Channel sale']);
    $onPlaylist = BuilderAd::factory()->withText()->published()->create(['store_id' => $this->store->id, 'name' => 'Lobby sale']);
    $channel = Channel::factory()->create(['store_id' => $this->store->id, 'name' => 'Deals']);

    ChannelAd::factory()->create(['channel_id' => $channel->id, 'media_id' => $inChannel->media_id]);
    PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $onPlaylist->media_id, 'position' => 0, 'duration_seconds' => 10]);

    expect(pickerIds("/screens/{$this->screen->id}/available-media"))->not->toContain($inChannel->media_id)->toContain($onPlaylist->media_id)
        ->and(pickerIds("/channels/{$channel->id}/library?type=html"))->not->toContain($onPlaylist->media_id);
});

test('the tick is gone: no address answers it, and no ad carries it', function () {
    $ad = BuilderAd::factory()->withText()->published()->create(['store_id' => $this->store->id]);

    $this->postJson("/builder/{$ad->id}/in-playlists", ['in_playlists' => false])->assertNotFound();

    expect(collect($this->getJson('/builder/data')->assertOk()->json('ads'))->firstWhere('id', $ad->id))->not->toHaveKey('in_playlists')
        ->and(Schema::hasColumn('builder_ads', 'in_playlists'))->toBeFalse();
});
