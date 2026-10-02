<?php

use App\Models\ActivityLog;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Role;
use App\Models\Screen;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| An organization's own channels
|--------------------------------------------------------------------------
|
| The owner's rules (2026-09-16): an organization's role may carry the channel permissions, and then
| the organization keeps channels of its own — made, changed and deleted from inside the organization, and
| offered to that organization's screens alone. The platform's channels stay the platform's, offered
| to every organization; another organization's channels are never within reach.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->superAdmin = createSuperAdmin();
    $this->alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->beta = Organization::factory()->create(['name' => 'Beta Deli']);

    $this->keeper = createOrganizationUser($this->alpha, ['channel-view', 'channel-store', 'channel-update', 'channel-destroy', 'screen-view', 'screen-playlist']);

    $this->platformChannel = Channel::factory()->create(['name' => 'GAMA']);
    $this->alphaChannel = Channel::factory()->create(['name' => 'Alpha Specials', 'organization_id' => $this->alpha->id]);
    $this->betaChannel = Channel::factory()->create(['name' => 'Beta Specials', 'organization_id' => $this->beta->id]);

    $this->alphaScreen = Screen::factory()->create(['organization_id' => $this->alpha->id]);
});

function asKeeper($test)
{
    return $test->actingAs($test->keeper)->withSession(['current_organization_id' => $test->alpha->id]);
}

test('a channel made inside an organization belongs to that organization', function () {
    asKeeper($this)->postJson('/channels', ['name' => 'Lunch Deals', 'ads_per_pass' => 2])->assertOk();

    $channel = Channel::firstWhere('name', 'Lunch Deals');
    expect($channel->organization_id)->toBe($this->alpha->id)
        ->and($channel->created_by)->toBe($this->keeper->id)
        ->and(ActivityLog::where('action', 'channel.created')->value('organization_id'))->toBe($this->alpha->id);
});

test("inside an organization its own channels are managed, the platform's only looked at, and another organization's not found", function () {
    // Owner, 2026-09-19: an organization sees the platform's channels too — read-only, each marked so.
    $rows = collect(asKeeper($this)->getJson('/channels/data')->assertOk()->json('channels'));
    expect($rows->pluck('read_only', 'name')->all())->toBe(['Alpha Specials' => false, 'GAMA' => true]);

    asKeeper($this)->get('/channels')->assertOk()->assertSee('Channels of Alpha Mart');
    asKeeper($this)->get("/channels/{$this->alphaChannel->id}")->assertOk()
        ->assertSee('dusk="add-channel-ad"', false)
        ->assertDontSee('dusk="channel-read-only-note"', false);

    // The platform's channel opens to be read: nothing on the page adds, changes or takes out an ad.
    asKeeper($this)->get("/channels/{$this->platformChannel->id}")->assertOk()
        ->assertSee('dusk="channel-read-only-note"', false)
        ->assertDontSee('dusk="add-channel-ad"', false)
        ->assertDontSee('channel-ad-modal', false);

    asKeeper($this)->get("/channels/{$this->betaChannel->id}")->assertNotFound();

    foreach ([$this->platformChannel, $this->betaChannel] as $outOfReach) {
        asKeeper($this)->putJson("/channels/{$outOfReach->id}", ['name' => 'Taken'])->assertNotFound();
        asKeeper($this)->deleteJson("/channels/{$outOfReach->id}", ['password' => 'password'])->assertNotFound();
    }

    asKeeper($this)->putJson("/channels/{$this->alphaChannel->id}", ['name' => 'Alpha Weekly'])->assertOk();

    expect($this->alphaChannel->fresh()->name)->toBe('Alpha Weekly')
        ->and($this->platformChannel->fresh()->name)->toBe('GAMA')
        ->and($this->betaChannel->fresh()->name)->toBe('Beta Specials');
});

test("inside an organization the platform's channel counts this organization's screens only, and does not name its maker", function () {
    // How far the platform's channel has spread in OTHER organizations is not this organization's business, and neither is
    // who on the platform's team made it.
    $this->platformChannel->update(['created_by' => $this->superAdmin->id]);
    $betaScreen = Screen::factory()->create(['organization_id' => $this->beta->id]);
    foreach ([$this->alphaScreen, $betaScreen] as $screen) {
        PlaylistItem::create(['screen_id' => $screen->id, 'channel_id' => $this->platformChannel->id, 'position' => 0]);
    }

    $inAlpha = collect(asKeeper($this)->getJson('/channels/data')->assertOk()->json('channels'))->keyBy('name');
    expect($inAlpha['GAMA']['screens_count'])->toBe(1)
        ->and($inAlpha['GAMA']['organizations_count'])->toBe(1)
        ->and($inAlpha['GAMA']['created_by_name'])->toBeNull();

    $above = collect($this->actingAs($this->superAdmin)->getJson('/channels/data')->assertOk()->json('channels'))->keyBy('name');
    expect($above['GAMA']['screens_count'])->toBe(2)
        ->and($above['GAMA']['organizations_count'])->toBe(2)
        ->and($above['GAMA']['created_by_name'])->toBe($this->superAdmin->name);
});

test('with no organization selected an organization member sees no channels at all', function () {
    $this->actingAs($this->keeper)->getJson('/channels/data')->assertForbidden();
});

test('above the organizations every channel is listed, each saying whose screens it reaches', function () {
    $rows = collect($this->actingAs($this->superAdmin)->getJson('/channels/data')->assertOk()->json('channels'))->keyBy('name');

    expect($rows->keys()->sort()->values()->all())->toBe(['Alpha Specials', 'Beta Specials', 'GAMA'])
        ->and($rows['GAMA']['organization_name'])->toBeNull()
        ->and($rows['Alpha Specials']['organization_name'])->toBe('Alpha Mart');

    // A channel made above the organizations is the platform's.
    $this->actingAs($this->superAdmin)->postJson('/channels', ['name' => 'Thanksgiving'])->assertOk();
    expect(Channel::firstWhere('name', 'Thanksgiving')->organization_id)->toBeNull();
});

test("a screen's channel box offers the platform's channels and its own organization's — never another organization's", function () {
    $offered = collect(asKeeper($this)->getJson("/screens/{$this->alphaScreen->id}/available-channels")->assertOk()->json('channels'))->keyBy('title');

    expect($offered->keys()->sort()->values()->all())->toBe(['Alpha Specials', 'GAMA'])
        ->and($offered['Alpha Specials']['is_organization_channel'])->toBeTrue()
        ->and($offered['GAMA']['is_organization_channel'])->toBeFalse();
});

test("a playlist refuses another organization's channel, and carries its own", function () {
    $save = fn (array $items) => asKeeper($this)->putJson("/screens/{$this->alphaScreen->id}/playlist", [
        'items' => $items,
        'version' => $this->alphaScreen->fresh()->playlistFingerprint(),
    ]);

    $save([['channel_id' => $this->betaChannel->id]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items' => 'One of those channels has been deleted, or is not offered to this organization. Reload the page to see the current list.']);

    $save([['channel_id' => $this->alphaChannel->id], ['channel_id' => $this->platformChannel->id]])->assertOk();

    expect(PlaylistItem::where('screen_id', $this->alphaScreen->id)->orderBy('position')->pluck('channel_id')->all())
        ->toBe([$this->alphaChannel->id, $this->platformChannel->id]);
});

test("a name must stand apart within one organization's list — the platform's channels and the organization's own", function () {
    asKeeper($this)->postJson('/channels', ['name' => 'GAMA'])
        ->assertStatus(422)->assertJsonValidationErrors(['name' => "There is already a channel with this name in this organization's list. Choose a different name."]);
    asKeeper($this)->postJson('/channels', ['name' => 'Alpha Specials'])->assertStatus(422)->assertJsonValidationErrors('name');

    // Another organization's list is its own business.
    asKeeper($this)->postJson('/channels', ['name' => 'Beta Specials'])->assertOk();

    // And an organization's name never blocks the platform.
    $this->actingAs($this->superAdmin)->postJson('/channels', ['name' => 'Alpha Specials'])->assertOk();
    $this->actingAs($this->superAdmin)->postJson('/channels', ['name' => 'GAMA'])
        ->assertStatus(422)->assertJsonValidationErrors(['name' => 'There is already a channel with this name. Every organization sees the name, so it has to be different.']);
});

test("deleting an organization takes its own channels, their files and their playlist lines — and leaves everybody else's", function () {
    // Alpha's channel shows a file of Alpha's library; the platform's channel one of its own library — and
    // one it borrowed from Alpha's, which goes with Alpha's library while the channel stays.
    $ad = ChannelAd::factory()->create(['channel_id' => $this->alphaChannel->id]);
    $platformAd = ChannelAd::factory()->create(['channel_id' => $this->platformChannel->id]);
    $borrowed = ChannelAd::factory()->create([
        'channel_id' => $this->platformChannel->id,
        'media_id' => Media::factory()->create(['organization_id' => $this->alpha->id])->id,
    ]);
    foreach ([$ad, $platformAd, $borrowed] as $each) {
        Storage::disk('public')->put($each->media->path, 'image');
        Storage::disk('public')->put($each->media->thumbnail_path, 'thumb');
    }

    // Alpha's own channel on Alpha's screen — and, next door, Beta's screen carrying the platform's channel
    // and Beta's own.
    $alphaLine = PlaylistItem::create(['screen_id' => $this->alphaScreen->id, 'channel_id' => $this->alphaChannel->id, 'position' => 0]);
    $betaScreen = Screen::factory()->create(['organization_id' => $this->beta->id]);
    $betaLines = collect([$this->platformChannel, $this->betaChannel])->map(fn (Channel $channel, int $position) => PlaylistItem::create([
        'screen_id' => $betaScreen->id, 'channel_id' => $channel->id, 'position' => $position,
    ]));

    $owner = createOrganizationMember($this->alpha, Role::OWNER);
    $this->actingAs($owner)->withSession(['current_organization_id' => $this->alpha->id])
        ->delete('/settings/organization', ['confirm_name' => 'Alpha Mart', 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    expect(Channel::find($this->alphaChannel->id))->toBeNull()
        ->and(ChannelAd::find($ad->id))->toBeNull()
        ->and(Media::find($ad->media_id))->toBeNull()
        ->and(PlaylistItem::find($alphaLine->id))->toBeNull()
        ->and(PlaylistItem::where('channel_id', $this->alphaChannel->id)->exists())->toBeFalse()
        ->and(Channel::find($this->platformChannel->id))->not->toBeNull()
        ->and(ChannelAd::where('channel_id', $this->platformChannel->id)->pluck('id')->all())->toBe([$platformAd->id])
        ->and(Media::find($borrowed->media_id))->toBeNull()
        ->and(Media::find($platformAd->media_id))->not->toBeNull()
        ->and(Channel::find($this->betaChannel->id))->not->toBeNull()
        ->and(PlaylistItem::whereKey($betaLines->pluck('id'))->orderBy('position')->pluck('channel_id')->all())
        ->toBe([$this->platformChannel->id, $this->betaChannel->id]);

    Storage::disk('public')->assertMissing([
        $ad->media->path, $ad->media->thumbnail_path, $borrowed->media->path, $borrowed->media->thumbnail_path,
    ]);
    Storage::disk('public')->assertExists([$platformAd->media->path, $platformAd->media->thumbnail_path]);
});

test("an organization channel's deletion is in that organization's history", function () {
    asKeeper($this)->deleteJson("/channels/{$this->alphaChannel->id}", ['password' => 'password'])->assertOk();

    expect(ActivityLog::where('action', 'channel.deleted')->value('organization_id'))->toBe($this->alpha->id);
});
