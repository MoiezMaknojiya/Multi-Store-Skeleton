<?php

use App\Models\ActivityLog;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\PlaylistItem;
use App\Models\Role;
use App\Models\Screen;
use App\Models\Store;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| A store's own channels
|--------------------------------------------------------------------------
|
| The owner's rules (2026-09-16): a store's role may carry the channel permissions, and then
| the store keeps channels of its own — made, changed and deleted from inside the store, and
| offered to that store's screens alone. The platform's channels stay the platform's, offered
| to every shop; another store's channels are never within reach.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->superAdmin = createSuperAdmin();
    $this->alpha = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->beta = Store::factory()->create(['name' => 'Beta Deli']);

    $this->keeper = createStoreUser($this->alpha, ['channel-view', 'channel-store', 'channel-update', 'channel-destroy', 'screen-view', 'screen-playlist']);
    $this->rivalKeeper = createStoreUser($this->beta, ['channel-view', 'channel-store', 'channel-update', 'channel-destroy', 'screen-view', 'screen-playlist'], 'Rival Keeper');

    $this->platformChannel = Channel::factory()->create(['name' => 'GAMA']);
    $this->alphaChannel = Channel::factory()->create(['name' => 'Alpha Specials', 'store_id' => $this->alpha->id]);
    $this->betaChannel = Channel::factory()->create(['name' => 'Beta Specials', 'store_id' => $this->beta->id]);

    $this->alphaScreen = Screen::factory()->create(['store_id' => $this->alpha->id]);
});

function asKeeper($test)
{
    return $test->actingAs($test->keeper)->withSession(['current_store_id' => $test->alpha->id]);
}

test('a channel made inside a store belongs to that store', function () {
    asKeeper($this)->postJson('/channels', ['name' => 'Lunch Deals', 'ads_per_pass' => 2])->assertOk();

    $channel = Channel::firstWhere('name', 'Lunch Deals');
    expect($channel->store_id)->toBe($this->alpha->id)
        ->and($channel->created_by)->toBe($this->keeper->id)
        ->and(ActivityLog::where('action', 'channel.created')->value('store_id'))->toBe($this->alpha->id);
});

test('inside a store only its own channels are listed, opened, changed or deleted', function () {
    $names = asKeeper($this)->getJson('/channels/data')->assertOk()->json('channels.*.name');
    expect($names)->toBe(['Alpha Specials']);

    asKeeper($this)->get('/channels')->assertOk()->assertSee('Channels of Alpha Mart');
    asKeeper($this)->get("/channels/{$this->alphaChannel->id}")->assertOk();

    foreach ([$this->platformChannel, $this->betaChannel] as $outOfReach) {
        asKeeper($this)->get("/channels/{$outOfReach->id}")->assertNotFound();
        asKeeper($this)->putJson("/channels/{$outOfReach->id}", ['name' => 'Taken'])->assertNotFound();
        asKeeper($this)->deleteJson("/channels/{$outOfReach->id}", ['password' => 'password'])->assertNotFound();
    }

    asKeeper($this)->putJson("/channels/{$this->alphaChannel->id}", ['name' => 'Alpha Weekly'])->assertOk();

    expect($this->alphaChannel->fresh()->name)->toBe('Alpha Weekly')
        ->and($this->platformChannel->fresh()->name)->toBe('GAMA')
        ->and($this->betaChannel->fresh()->name)->toBe('Beta Specials');
});

test('with no store selected a store member sees no channels at all', function () {
    $this->actingAs($this->keeper)->getJson('/channels/data')->assertForbidden();
});

test('above the stores every channel is listed, each saying whose screens it reaches', function () {
    $rows = collect($this->actingAs($this->superAdmin)->getJson('/channels/data')->assertOk()->json('channels'))->keyBy('name');

    expect($rows->keys()->sort()->values()->all())->toBe(['Alpha Specials', 'Beta Specials', 'GAMA'])
        ->and($rows['GAMA']['store_name'])->toBeNull()
        ->and($rows['Alpha Specials']['store_name'])->toBe('Alpha Mart');

    // A channel made above the stores is the platform's.
    $this->actingAs($this->superAdmin)->postJson('/channels', ['name' => 'Thanksgiving'])->assertOk();
    expect(Channel::firstWhere('name', 'Thanksgiving')->store_id)->toBeNull();
});

test("a screen's channel box offers the platform's channels and its own store's — never another store's", function () {
    $offered = collect(asKeeper($this)->getJson("/screens/{$this->alphaScreen->id}/available-channels")->assertOk()->json('channels'))->keyBy('title');

    expect($offered->keys()->sort()->values()->all())->toBe(['Alpha Specials', 'GAMA'])
        ->and($offered['Alpha Specials']['is_store_channel'])->toBeTrue()
        ->and($offered['GAMA']['is_store_channel'])->toBeFalse();
});

test("a playlist refuses another store's channel, and carries its own", function () {
    $save = fn (array $items) => asKeeper($this)->putJson("/screens/{$this->alphaScreen->id}/playlist", [
        'items' => $items,
        'version' => $this->alphaScreen->fresh()->playlistFingerprint(),
    ]);

    $save([['channel_id' => $this->betaChannel->id]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items' => 'One of those channels has been deleted, or is not offered to this store. Reload the page to see the current list.']);

    $save([['channel_id' => $this->alphaChannel->id], ['channel_id' => $this->platformChannel->id]])->assertOk();

    expect(PlaylistItem::where('screen_id', $this->alphaScreen->id)->orderBy('position')->pluck('channel_id')->all())
        ->toBe([$this->alphaChannel->id, $this->platformChannel->id]);
});

test("a name must stand apart within one store's list — the platform's channels and the store's own", function () {
    asKeeper($this)->postJson('/channels', ['name' => 'GAMA'])
        ->assertStatus(422)->assertJsonValidationErrors(['name' => "There is already a channel with this name in this store's list. Choose a different name."]);
    asKeeper($this)->postJson('/channels', ['name' => 'Alpha Specials'])->assertStatus(422)->assertJsonValidationErrors('name');

    // Another store's list is its own business.
    asKeeper($this)->postJson('/channels', ['name' => 'Beta Specials'])->assertOk();

    // And a store's name never blocks the platform.
    $this->actingAs($this->superAdmin)->postJson('/channels', ['name' => 'Alpha Specials'])->assertOk();
    $this->actingAs($this->superAdmin)->postJson('/channels', ['name' => 'GAMA'])
        ->assertStatus(422)->assertJsonValidationErrors(['name' => 'There is already a channel with this name. Every shop sees the name, so it has to be different.']);
});

test("deleting a store takes its own channels, their files and their playlist lines — and leaves everybody else's", function () {
    $ad = ChannelAd::factory()->create([
        'channel_id' => $this->alphaChannel->id,
        'path' => "channels/{$this->alphaChannel->id}/deal.jpg",
        'thumbnail_path' => "channels/{$this->alphaChannel->id}/thumbs/deal.jpg",
    ]);
    Storage::disk('public')->put($ad->path, 'image');
    Storage::disk('public')->put($ad->thumbnail_path, 'thumb');

    $platformAd = ChannelAd::factory()->create(['channel_id' => $this->platformChannel->id, 'path' => 'channels/gama/promo.jpg', 'thumbnail_path' => null]);
    Storage::disk('public')->put($platformAd->path, 'image');

    $owner = createStoreMember($this->alpha, Role::OWNER);
    $this->actingAs($owner)->withSession(['current_store_id' => $this->alpha->id])
        ->delete('/settings/store', ['confirm_name' => 'Alpha Mart', 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    expect(Channel::find($this->alphaChannel->id))->toBeNull()
        ->and(ChannelAd::find($ad->id))->toBeNull()
        ->and(Channel::find($this->platformChannel->id))->not->toBeNull()
        ->and(Channel::find($this->betaChannel->id))->not->toBeNull();

    Storage::disk('public')->assertMissing([$ad->path, $ad->thumbnail_path]);
    Storage::disk('public')->assertExists($platformAd->path);
});

test("a store channel's deletion is in that store's history", function () {
    asKeeper($this)->deleteJson("/channels/{$this->alphaChannel->id}", ['password' => 'password'])->assertOk();

    expect(ActivityLog::where('action', 'channel.deleted')->value('store_id'))->toBe($this->alpha->id);
});
