<?php

use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\ScheduleRule;
use App\Models\Screen;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Channels: who may manage them, and what deleting one does
|--------------------------------------------------------------------------
|
| A channel made above the organizations is offered to every organization; one made inside an organization belongs
| to that organization (owner's rules). Above the organizations the channel permissions reach every
| channel; inside an organization, only that organization's own (Channel::visibleTo) — anything else is not
| found. Whoever holds a permission may use it on any channel within reach, not only the
| ones they made (owner's decision).
|
*/

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
    // Every route under /channels — a channel's ads included — read from the route table, so one added
    // later is asked too.
    $routes = routesUnder('channels');

    expect($routes)->not->toBeEmpty();

    foreach ($routes as [$method, $uri]) {
        expect($this->json($method, $uri)->status())->toBe(401, "{$method} {$uri}");
    }
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
    $staff = createPlatformUser(['channel-view', 'channel-store'], 'Content Manager');

    $this->actingAs($staff)->postJson('/channels', ['name' => 'Thanksgiving'])->assertOk();

    expect(Channel::firstWhere('name', 'Thanksgiving')->created_by)->toBe($staff->id);
});

test('a global user without the permission is refused', function () {
    $staff = createPlatformUser(['channel-view'], 'Content Manager');

    $this->actingAs($staff)->postJson('/channels', ['name' => 'Thanksgiving'])->assertForbidden();

    expect(Channel::count())->toBe(0);
});

test('during "Log in as" the channel pages answer to the organization person\'s permissions, never the super admin\'s', function () {
    // This is how the owner actually reaches an organization: impersonation makes them an ORGANIZATION user, so
    // what opens is what that person's role allows in that organization. The super admin behind the
    // session holds every channel permission, and none of it comes along: a cashier without
    // channel-view is refused the channel pages, while the organization's own side — where a channel is
    // added to a screen — works as it should; and somebody whose role does carry channel-view
    // sees their organization's own channels and the platform's, to look at — never another organization's,
    // which the super admin behind the session would see.
    $organization = Organization::factory()->create();
    $screen = Screen::factory()->create(['organization_id' => $organization->id]);
    Channel::factory()->create(['name' => 'GAMA']);
    Channel::factory()->create(['name' => 'Deli Specials', 'organization_id' => $organization->id]);
    Channel::factory()->create(['name' => 'Next Door Deals', 'organization_id' => Organization::factory()->create()->id]);

    $cashier = createOrganizationUser($organization, ['screen-view', 'screen-playlist'], 'Cashier');
    $keeper = createOrganizationUser($organization, ['channel-view'], 'Channel Keeper');

    $loggedInAs = fn (User $person) => $this->actingAs($person)->withSession([
        'current_organization_id' => $organization->id,
        'impersonating_original_id' => $this->admin->id,
        'impersonating_user_id' => $person->id,
    ]);

    $loggedInAs($cashier);
    $this->getJson('/channels/data')->assertForbidden();
    expect(collect($this->getJson("/screens/{$screen->id}/available-channels")->assertOk()->json('channels'))->pluck('title')->sort()->values()->all())
        ->toBe(['Deli Specials', 'GAMA']);

    $loggedInAs($keeper);
    $rows = collect($this->getJson('/channels/data')->assertOk()->json('channels'));
    expect($rows->pluck('read_only', 'name')->all())->toBe(['Deli Specials' => false, 'GAMA' => true]);
});

test('the Channels link follows channel-view — above the organizations and inside one', function () {
    $link = 'href="'.route('channels.view').'"';

    $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertSee($link, false);

    $organization = Organization::factory()->create();
    $keeper = createOrganizationUser($organization, ['channel-view']);
    $cashier = createOrganizationUser($organization, ['screen-view'], 'Cashier');

    $this->actingAs($keeper)->withSession(['current_organization_id' => $organization->id])
        ->get('/dashboard')->assertOk()->assertSee($link, false);
    $this->actingAs($cashier)->withSession(['current_organization_id' => $organization->id])
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
    $staff = createPlatformUser(['channel-update'], 'Content Manager');

    $this->actingAs($staff)->putJson("/channels/{$channel->id}", ['name' => 'GAMA Wholesale'])->assertOk();

    expect($channel->fresh()->name)->toBe('GAMA Wholesale');
});

test('and whoever holds channel-destroy may delete any channel', function () {
    $channel = Channel::factory()->create(['created_by' => $this->admin->id]);
    $staff = createPlatformUser(['channel-destroy'], 'Content Manager');

    $this->actingAs($staff)->deleteJson("/channels/{$channel->id}", ['password' => 'password'])->assertOk();

    expect(Channel::find($channel->id))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The listing
|--------------------------------------------------------------------------
*/

test('the listing counts the ads, the ones running today, and the screens and organizations carrying it', function () {
    $this->travelTo('2026-10-10 12:00:00');

    $channel = Channel::factory()->create(['name' => 'GAMA', 'created_by' => $this->admin->id]);
    ChannelAd::factory()->count(2)->create(['channel_id' => $channel->id]);
    ChannelAd::factory()->running('2026-10-01', '2026-10-09')->create(['channel_id' => $channel->id]);

    [$alpha, $beta, $gone] = Organization::factory()->count(3)->create();
    $counter = Screen::factory()->create(['organization_id' => $alpha->id]);
    $window = Screen::factory()->create(['organization_id' => $alpha->id]);
    $deli = Screen::factory()->create(['organization_id' => $beta->id]);
    $closed = Screen::factory()->create(['organization_id' => $gone->id]);

    foreach ([$counter, $counter, $window, $deli, $closed] as $position => $screen) {
        PlaylistItem::create(['screen_id' => $screen->id, 'channel_id' => $channel->id, 'position' => $position]);
    }

    // An organization that has been deleted carries nothing any more.
    $gone->delete();

    $row = collect($this->actingAs($this->admin)->getJson('/channels/data')->assertOk()->json('channels'))
        ->firstWhere('id', $channel->id);

    expect($row['ads_count'])->toBe(3);
    expect($row['running_ads_count'])->toBe(2);
    // The counter TV carries it twice and still counts once.
    expect($row['screens_count'])->toBe(3);
    expect($row['organizations_count'])->toBe(2);
    expect($row['created_by_name'])->toBe($this->admin->name);
    // Only the name travels — the relation must not be serialised over the column.
    expect($row['created_by'])->toBe($this->admin->id);
});

/*
|--------------------------------------------------------------------------
| Pausing, deleting, and the person who made it
|--------------------------------------------------------------------------
*/

test('deleting a channel removes its ads and its line from every playlist, and leaves their files in the library', function () {
    $channel = Channel::factory()->create(['name' => 'GAMA']);
    $ads = ChannelAd::factory()->count(2)->create(['channel_id' => $channel->id]);
    $ads->each(function (ChannelAd $ad) {
        Storage::disk('public')->put($ad->media->path, 'ad');
        Storage::disk('public')->put($ad->media->thumbnail_path, 'thumb');
    });

    $organization = Organization::factory()->create();
    $poster = Media::factory()->create(['organization_id' => $organization->id]);
    [$counter, $window] = Screen::factory()->count(2)->create(['organization_id' => $organization->id]);

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

    // The files were never the channel's: they stay in the platform's library, rows and all
    // (docs/CHANNEL-CONTENT-SPEC.md).
    $ads->each(function (ChannelAd $ad) {
        $file = Media::find($ad->media_id);
        expect($file)->not->toBeNull();
        expect($file->organization_id)->toBeNull();
        Storage::disk('public')->assertExists($ad->media->path);
        Storage::disk('public')->assertExists($ad->media->thumbnail_path);
    });

    // The organizations' own files stay exactly where they were.
    expect(PlaylistItem::whereNotNull('media_id')->count())->toBe(2);

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'channel.deleted', 'description' => 'Deleted channel GAMA — it was on 2 screens',
    ]);
});

test('a channel on one screen is counted once in the table, and its deletion is logged as "1 screen"', function () {
    // The table, the confirmation and the log count screens the same way, so they can never disagree by
    // one — and one screen reads in the singular.
    $channel = Channel::factory()->create(['name' => 'GAMA']);
    $screen = Screen::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    PlaylistItem::create(['screen_id' => $screen->id, 'channel_id' => $channel->id, 'position' => 0]);

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
    $screen = Screen::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    PlaylistItem::create(['screen_id' => $screen->id, 'channel_id' => $channel->id, 'position' => 0]);

    $this->actingAs($this->admin)->putJson("/channels/{$channel->id}", ['name' => 'GAMA', 'is_active' => false])->assertOk();

    expect($channel->fresh()->is_active)->toBeFalse();
    expect(PlaylistItem::where('channel_id', $channel->id)->count())->toBe(1);
});

test('deleting the person who made a channel leaves the channel on the air', function () {
    // Owner's decision: a channel belongs to the platform or its organization, not to whoever typed it in, so
    // deleting that account never takes it along — the same as an organization's media.
    $admin = createSuperAdmin(['user-destroy']);
    $staff = createPlatformUser(['channel-view', 'channel-store', 'channel-update'], 'Content Manager');

    $this->actingAs($staff)->postJson('/channels', ['name' => 'Thanksgiving'])->assertOk();
    $channel = Channel::firstWhere('name', 'Thanksgiving');
    ChannelAd::factory()->create(['channel_id' => $channel->id, 'created_by' => $staff->id]);

    $this->actingAs($admin)->deleteJson("/users/{$staff->id}", ['password' => 'password'])->assertOk();

    expect(User::find($staff->id))->toBeNull();
    expect($channel->fresh())->not->toBeNull();
    expect($channel->fresh()->created_by)->toBeNull();
    expect(ChannelAd::where('channel_id', $channel->id)->count())->toBe(1);
});
