<?php

use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\Channel;
use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\Screen;
use App\Models\Store;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Where an ad may play — "Show in playlists"
|--------------------------------------------------------------------------
|
| Owner, 2026-09-22: "agar woh same ad channel mein hui aur playlist mein toh masla hoga". An ad inside a
| channel AND on the playlist that carries that channel plays twice in one pass, so an ad is for CHANNELS
| ONLY until somebody ticks "Show in playlists" in the editor, beside Publish.
|
| The tick opens the shop's own pickers — the playlist's and the holding picture's — and the playlist itself
| behind them. Taking it off again is refused while a screen still carries the ad, and the refusal names the
| screens: nothing is ever pulled off a television behind somebody's back.
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

test('a freshly published ad is for channels only: the channel picker has it, the playlist pickers do not', function () {
    $ad = BuilderAd::factory()->withText()->create(['store_id' => $this->store->id, 'name' => 'Winter sale']);
    $channel = Channel::factory()->create(['store_id' => $this->store->id, 'name' => 'Deals']);

    $this->postJson("/builder/{$ad->id}/publish")->assertOk()->assertJsonPath('ad.in_playlists', false);
    $page = Media::sole();

    expect($ad->fresh()->in_playlists)->toBeFalse()
        ->and(pickerIds("/channels/{$channel->id}/library?type=html"))->toContain($page->id)
        ->and(pickerIds("/screens/{$this->screen->id}/available-media"))->not->toContain($page->id)
        ->and(pickerIds("/screens/{$this->screen->id}/media-options"))->not->toContain($page->id)
        // The library still holds it: it is the shop's file, only not one a playlist may pick.
        ->and(pickerIds('/media/data'))->toContain($page->id);

    // And the listing says which of the two it is, beside its published badge.
    $row = collect($this->getJson('/builder/data')->assertOk()->json('ads'))->firstWhere('id', $ad->id);
    expect($row['status'])->toBe('published')->and($row['in_playlists'])->toBeFalse();
});

test('the tick opens the playlist pickers and the playlist itself, and is written to the log', function () {
    $ad = BuilderAd::factory()->withText()->create(['store_id' => $this->store->id, 'name' => 'Winter sale']);
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();
    $page = Media::sole();

    // Before the tick the wall behind the picker refuses the line, naming the ad and what to do.
    $version = $this->getJson("/screens/{$this->screen->id}/playlist")->json('version');
    $line = ['version' => $version, 'items' => [['media_id' => $page->id, 'duration_seconds' => 10]]];
    $this->putJson("/screens/{$this->screen->id}/playlist", $line)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items' => 'The ad Winter sale is for channels only. Tick "Show in playlists" in the Ad Builder to put it on a screen.']);

    $this->postJson("/builder/{$ad->id}/in-playlists", ['in_playlists' => true])
        ->assertOk()
        ->assertJsonPath('message', 'Playlists can use this ad now')
        ->assertJsonPath('ad.in_playlists', true);

    expect(pickerIds("/screens/{$this->screen->id}/available-media"))->toContain($page->id)
        ->and(pickerIds("/screens/{$this->screen->id}/media-options"))->toContain($page->id)
        ->and(ActivityLog::where('action', 'ad.playlists_changed')->value('description'))
        ->toBe('Ad Winter sale may now be played from a playlist');

    $this->putJson("/screens/{$this->screen->id}/playlist", $line)->assertOk();
    expect(PlaylistItem::where('screen_id', $this->screen->id)->where('media_id', $page->id)->exists())->toBeTrue();
});

test('the tick is not a change to the design: a published ad stays published, and a draft stays a draft', function () {
    $published = BuilderAd::factory()->withText()->published()->create(['store_id' => $this->store->id]);
    $draft = BuilderAd::factory()->withText()->create(['store_id' => $this->store->id]);

    $this->postJson("/builder/{$published->id}/in-playlists", ['in_playlists' => false])
        ->assertOk()
        ->assertJsonPath('message', 'Channels only — a playlist cannot pick this ad')
        ->assertJsonPath('ad.status', 'published');

    // An ad nobody has published yet can be ticked too — it simply has no page to offer anybody yet.
    $this->postJson("/builder/{$draft->id}/in-playlists", ['in_playlists' => true])
        ->assertOk()
        ->assertJsonPath('ad.status', 'draft')
        ->assertJsonPath('ad.in_playlists', true);

    expect($published->fresh()->hasUnpublishedChanges())->toBeFalse()
        ->and($published->fresh()->status())->toBe('published');
});

test('taking the tick off is refused while a screen still carries the ad, and says which screens', function () {
    $ad = BuilderAd::factory()->withText()->published()->create(['store_id' => $this->store->id, 'name' => 'Winter sale']);
    $second = Screen::factory()->create(['store_id' => $this->store->id, 'name' => 'Window TV']);
    PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $ad->media_id, 'position' => 0, 'duration_seconds' => 10]);
    PlaylistItem::create(['screen_id' => $second->id, 'media_id' => $ad->media_id, 'position' => 0, 'duration_seconds' => 10]);

    $this->postJson("/builder/{$ad->id}/in-playlists", ['in_playlists' => false])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['in_playlists' => 'Still on the screens Lobby TV, Window TV. Take it off those screens first.']);

    expect($ad->fresh()->in_playlists)->toBeTrue()
        ->and(ActivityLog::where('action', 'ad.playlists_changed')->exists())->toBeFalse();

    // Off the screens, and the tick comes off — one screen, so the refusal would have been singular.
    PlaylistItem::where('media_id', $ad->media_id)->delete();
    $this->postJson("/builder/{$ad->id}/in-playlists", ['in_playlists' => false])->assertOk();

    expect($ad->fresh()->in_playlists)->toBeFalse()
        ->and(pickerIds("/screens/{$this->screen->id}/available-media"))->not->toContain($ad->media_id);
});

test('the tick needs Update Ads, and another store\'s ad does not exist here', function () {
    $ad = BuilderAd::factory()->withText()->published()->create(['store_id' => $this->store->id]);
    $elsewhere = BuilderAd::factory()->withText()->published()->create(['store_id' => Store::factory()->create()->id]);

    $this->postJson("/builder/{$elsewhere->id}/in-playlists", ['in_playlists' => false])->assertNotFound();

    $viewer = createStoreUser($this->store, ['ad-view'], 'Viewer');
    $this->actingAs($viewer)->withSession(['current_store_id' => $this->store->id])
        ->postJson("/builder/{$ad->id}/in-playlists", ['in_playlists' => false])->assertForbidden();

    expect($elsewhere->fresh()->in_playlists)->toBeTrue()->and($ad->fresh()->in_playlists)->toBeTrue();
});

test('the answer says what shape it wants', function () {
    $ad = BuilderAd::factory()->withText()->published()->create(['store_id' => $this->store->id]);

    $this->postJson("/builder/{$ad->id}/in-playlists", [])->assertStatus(422)->assertJsonValidationErrors('in_playlists');
    $this->postJson("/builder/{$ad->id}/in-playlists", ['in_playlists' => 'maybe'])->assertStatus(422)->assertJsonValidationErrors('in_playlists');
    $this->postJson("/builder/{$ad->id}/in-playlists", ['in_playlists' => ['yes']])->assertStatus(422)->assertJsonValidationErrors('in_playlists');

    expect($ad->fresh()->in_playlists)->toBeTrue();
});
