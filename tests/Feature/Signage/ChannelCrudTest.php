<?php

use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\Role;
use App\Models\ScheduleRule;
use App\Models\Screen;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Channels: who may manage them, and what deleting one does
|--------------------------------------------------------------------------
|
| A channel made above the stores is offered to every shop; one made inside a store belongs
| to that store (owner's rules). Above the stores the channel permissions reach every
| channel; inside a store, only that store's own (Channel::visibleTo) — anything else is not
| found. Whoever holds a permission may use it on any channel within reach, not only the
| ones they made (owner's decision).
|
*/

/** Somebody on the platform's staff: a global role holding exactly these permissions. */
function channelStaff(array $permissions): User
{
    $role = Role::create(['name' => 'Content Manager', 'is_global' => true]);
    $role->permissions()->sync(grantPermissions($permissions)->pluck('id'));

    $user = User::factory()->create();
    $user->stores()->attach(0, ['role_id' => $role->id]);

    return $user;
}

beforeEach(function () {
    Storage::fake('public');

    $this->admin = createSuperAdmin(['channel-view', 'channel-store', 'channel-update', 'channel-destroy']);
});

/*
|--------------------------------------------------------------------------
| The two locks
|--------------------------------------------------------------------------
*/

test('guests cannot reach any channel endpoint', function () {
    $this->getJson('/channels/data')->assertUnauthorized();
    $this->postJson('/channels', ['name' => 'GAMA'])->assertUnauthorized();
});

test('a super admin creates a channel', function () {
    $this->actingAs($this->admin)
        ->postJson('/channels', ['name' => 'GAMA Wholesale', 'ads_per_pass' => 2, 'is_active' => true])
        ->assertOk();

    $channel = Channel::firstWhere('name', 'GAMA Wholesale');

    expect($channel->ads_per_pass)->toBe(2);
    expect($channel->is_active)->toBeTrue();
    expect($channel->created_by)->toBe($this->admin->id);
    $this->assertDatabaseHas('activity_logs', ['action' => 'channel.created']);
});

test('a global user given the permission creates one too', function () {
    $staff = channelStaff(['channel-view', 'channel-store']);

    $this->actingAs($staff)->postJson('/channels', ['name' => 'Thanksgiving'])->assertOk();

    expect(Channel::firstWhere('name', 'Thanksgiving')->created_by)->toBe($staff->id);
});

test('a global user without the permission is refused', function () {
    $staff = channelStaff(['channel-view']);

    $this->actingAs($staff)->postJson('/channels', ['name' => 'Thanksgiving'])->assertForbidden();

    expect(Channel::count())->toBe(0);
});

test("inside a store the channel permissions reach the store's own channels — the platform's is not found", function () {
    // Owner's rule (2026-09-16): a store's role may carry the channel permissions, for its own
    // store alone. What it makes is the store's; the platform's channels stay out of its reach.
    $store = Store::factory()->create();
    $keeper = createStoreUser($store, ['channel-view', 'channel-store', 'channel-update', 'channel-destroy']);
    $channel = Channel::factory()->create(['name' => 'GAMA']);

    $this->actingAs($keeper)->withSession(['current_store_id' => $store->id]);

    $this->postJson('/channels', ['name' => 'Mine'])->assertOk();
    expect($this->getJson('/channels/data')->assertOk()->json('channels.*.name'))->toBe(['Mine']);

    $this->get("/channels/{$channel->id}")->assertNotFound();
    $this->putJson("/channels/{$channel->id}", ['name' => 'Renamed'])->assertNotFound();
    $this->deleteJson("/channels/{$channel->id}", ['password' => 'password'])->assertNotFound();

    expect($channel->fresh()->name)->toBe('GAMA')
        ->and(Channel::firstWhere('name', 'Mine')->store_id)->toBe($store->id);
});

test('during "Log in as" the channel pages stay shut, while the shop\'s own side works', function () {
    // This is how the owner actually reaches a shop: impersonation makes them a STORE
    // user, so the platform's own pages close behind them — and the shop's side, which
    // is where a channel is added to a screen, opens as it should.
    $store = Store::factory()->create();
    $keeper = createStoreUser($store, ['screen-view', 'screen-playlist']);
    $screen = Screen::factory()->create(['store_id' => $store->id]);

    $this->actingAs($keeper)->withSession([
        'current_store_id' => $store->id,
        'impersonating_original_id' => $this->admin->id,
        'impersonating_user_id' => $keeper->id,
    ]);

    $this->getJson('/channels/data')->assertForbidden();
    $this->getJson("/screens/{$screen->id}/available-channels")->assertOk();
});

test('the Channels link follows channel-view — above the stores and inside one', function () {
    $link = 'href="'.route('channels.view').'"';

    $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertSee($link, false);

    $store = Store::factory()->create();
    $keeper = createStoreUser($store, ['channel-view']);
    $cashier = createStoreUser($store, ['screen-view'], 'Cashier');

    $this->actingAs($keeper)->withSession(['current_store_id' => $store->id])
        ->get('/dashboard')->assertOk()->assertSee($link, false);
    $this->actingAs($cashier)->withSession(['current_store_id' => $store->id])
        ->get('/dashboard')->assertOk()->assertDontSee($link, false);
});

test('the channels page opens for the super admin', function () {
    $this->actingAs($this->admin)->get('/channels')->assertOk()->assertSee('All Channels');
});

/*
|--------------------------------------------------------------------------
| Creating and editing
|--------------------------------------------------------------------------
*/

test('blank "ads each time" means every ad, every time', function () {
    $this->actingAs($this->admin)->postJson('/channels', ['name' => 'GAMA', 'ads_per_pass' => ''])->assertOk();

    expect(Channel::firstWhere('name', 'GAMA')->ads_per_pass)->toBeNull();
});

test('every shop sees the name, so it has to be unique', function () {
    Channel::factory()->create(['name' => 'GAMA']);

    $this->actingAs($this->admin)->postJson('/channels', ['name' => 'GAMA'])
        ->assertStatus(422)->assertJsonValidationErrors('name');
});

test('keeping its own name on an edit is not a clash', function () {
    $channel = Channel::factory()->create(['name' => 'GAMA']);

    $this->actingAs($this->admin)->putJson("/channels/{$channel->id}", ['name' => 'GAMA', 'ads_per_pass' => 3])
        ->assertOk();

    expect($channel->fresh()->ads_per_pass)->toBe(3);
});

test('ads each time must be a sensible number', function (mixed $value) {
    $this->actingAs($this->admin)->postJson('/channels', ['name' => 'GAMA', 'ads_per_pass' => $value])
        ->assertStatus(422)->assertJsonValidationErrors('ads_per_pass');
})->with([0, Channel::MAX_ADS_PER_PASS + 1, 'two']);

test('an edit that leaves a field out changes nothing about it', function () {
    // Pausing takes a channel off every screen carrying it, so it must never happen as
    // the side effect of a rename.
    $channel = Channel::factory()->perPass(2)->create(['name' => 'GAMA']);

    $this->actingAs($this->admin)->putJson("/channels/{$channel->id}", ['name' => 'GAMA Wholesale'])->assertOk();

    expect($channel->fresh())
        ->name->toBe('GAMA Wholesale')
        ->is_active->toBeTrue()
        ->ads_per_pass->toBe(2);
});

test('whoever holds channel-update may edit any channel, not only their own', function () {
    $channel = Channel::factory()->create(['name' => 'GAMA', 'created_by' => $this->admin->id]);
    $staff = channelStaff(['channel-update']);

    $this->actingAs($staff)->putJson("/channels/{$channel->id}", ['name' => 'GAMA Wholesale'])->assertOk();

    expect($channel->fresh()->name)->toBe('GAMA Wholesale');
});

test('and whoever holds channel-destroy may delete any channel', function () {
    $channel = Channel::factory()->create(['created_by' => $this->admin->id]);
    $staff = channelStaff(['channel-destroy']);

    $this->actingAs($staff)->deleteJson("/channels/{$channel->id}", ['password' => 'password'])->assertOk();

    expect(Channel::find($channel->id))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The listing
|--------------------------------------------------------------------------
*/

test('the listing counts the ads, the ones running today, and the screens and shops carrying it', function () {
    $this->travelTo('2026-10-10 12:00:00');

    $channel = Channel::factory()->create(['name' => 'GAMA', 'created_by' => $this->admin->id]);
    ChannelAd::factory()->count(2)->create(['channel_id' => $channel->id]);
    ChannelAd::factory()->running('2026-10-01', '2026-10-09')->create(['channel_id' => $channel->id]);

    [$alpha, $beta, $gone] = Store::factory()->count(3)->create();
    $counter = Screen::factory()->create(['store_id' => $alpha->id]);
    $window = Screen::factory()->create(['store_id' => $alpha->id]);
    $deli = Screen::factory()->create(['store_id' => $beta->id]);
    $closed = Screen::factory()->create(['store_id' => $gone->id]);

    foreach ([$counter, $counter, $window, $deli, $closed] as $position => $screen) {
        PlaylistItem::create(['screen_id' => $screen->id, 'channel_id' => $channel->id, 'position' => $position]);
    }

    // A shop that has been deleted carries nothing any more.
    $gone->delete();

    $row = collect($this->actingAs($this->admin)->getJson('/channels/data')->assertOk()->json('channels'))
        ->firstWhere('id', $channel->id);

    expect($row['ads_count'])->toBe(3);
    expect($row['running_ads_count'])->toBe(2);
    // The counter TV carries it twice and still counts once.
    expect($row['screens_count'])->toBe(3);
    expect($row['stores_count'])->toBe(2);
    expect($row['created_by_name'])->toBe($this->admin->name);
    // Only the name travels — the relation must not be serialised over the column.
    expect($row['created_by'])->toBe($this->admin->id);
});

/*
|--------------------------------------------------------------------------
| Pausing, deleting, and the person who made it
|--------------------------------------------------------------------------
*/

test('deleting a channel removes its ads, their files, and its line from every playlist', function () {
    $channel = Channel::factory()->create(['name' => 'GAMA']);
    $ads = ChannelAd::factory()->count(2)->create(['channel_id' => $channel->id]);
    $ads->each(function (ChannelAd $ad) {
        Storage::disk('public')->put($ad->path, 'ad');
        Storage::disk('public')->put($ad->thumbnail_path, 'thumb');
    });

    $store = Store::factory()->create();
    $poster = Media::factory()->create(['store_id' => $store->id]);
    [$counter, $window] = Screen::factory()->count(2)->create(['store_id' => $store->id]);

    foreach ([$counter, $window] as $screen) {
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $poster->id, 'position' => 0, 'duration_seconds' => 10]);
        $line = PlaylistItem::create(['screen_id' => $screen->id, 'channel_id' => $channel->id, 'position' => 1]);
        $line->scheduleRules()->create([
            'recurrence_type' => ScheduleRule::WEEKLY, 'recurrence_interval' => 1, 'recurrence_weekdays' => [1],
        ]);
    }

    $this->actingAs($this->admin)->deleteJson("/channels/{$channel->id}", ['password' => 'password'])->assertOk();

    expect(Channel::find($channel->id))->toBeNull();
    expect(ChannelAd::where('channel_id', $channel->id)->count())->toBe(0);
    expect(PlaylistItem::where('channel_id', $channel->id)->count())->toBe(0);
    expect(ScheduleRule::count())->toBe(0);

    $ads->each(function (ChannelAd $ad) {
        Storage::disk('public')->assertMissing($ad->path);
        Storage::disk('public')->assertMissing($ad->thumbnail_path);
    });

    // The shops' own files stay exactly where they were.
    expect(PlaylistItem::whereNotNull('media_id')->count())->toBe(2);

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'channel.deleted', 'description' => 'Deleted channel GAMA — it was on 2 screens',
    ]);
});

test('a screen in a deleted shop is counted nowhere — not in the table, not in the log', function () {
    $channel = Channel::factory()->create(['name' => 'GAMA']);

    $alive = Store::factory()->create();
    $gone = Store::factory()->create();

    foreach ([$alive, $gone] as $store) {
        $screen = Screen::factory()->create(['store_id' => $store->id]);
        PlaylistItem::create(['screen_id' => $screen->id, 'channel_id' => $channel->id, 'position' => 0]);
    }

    $gone->delete();

    $row = collect($this->actingAs($this->admin)->getJson('/channels/data')->assertOk()->json('channels'))
        ->firstWhere('id', $channel->id);
    expect($row['screens_count'])->toBe(1);

    $this->deleteJson("/channels/{$channel->id}", ['password' => 'password'])->assertOk();

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'channel.deleted', 'description' => 'Deleted channel GAMA — it was on 1 screen',
    ]);
});

test('pausing takes a channel off the air but keeps every line where it was', function () {
    $channel = Channel::factory()->create(['name' => 'GAMA']);
    $screen = Screen::factory()->create(['store_id' => Store::factory()->create()->id]);
    PlaylistItem::create(['screen_id' => $screen->id, 'channel_id' => $channel->id, 'position' => 0]);

    $this->actingAs($this->admin)->putJson("/channels/{$channel->id}", ['name' => 'GAMA', 'is_active' => false])->assertOk();

    expect($channel->fresh()->is_active)->toBeFalse();
    expect(PlaylistItem::where('channel_id', $channel->id)->count())->toBe(1);
});

test('deleting the person who made a channel leaves the channel on the air', function () {
    // Owner's decision: a channel belongs to the platform or its store, not to whoever typed it in, so
    // deleting that account never takes it along — the same as a shop's media.
    $admin = createSuperAdmin(['user-destroy']);
    $staff = channelStaff(['channel-view', 'channel-store', 'channel-update']);

    $this->actingAs($staff)->postJson('/channels', ['name' => 'Thanksgiving'])->assertOk();
    $channel = Channel::firstWhere('name', 'Thanksgiving');
    ChannelAd::factory()->create(['channel_id' => $channel->id, 'created_by' => $staff->id]);

    $this->actingAs($admin)->deleteJson("/users/{$staff->id}", ['password' => 'password'])->assertOk();

    expect(User::find($staff->id))->toBeNull();
    expect($channel->fresh())->not->toBeNull();
    expect($channel->fresh()->created_by)->toBeNull();
    expect(ChannelAd::where('channel_id', $channel->id)->count())->toBe(1);
});
