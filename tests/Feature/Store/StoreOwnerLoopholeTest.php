<?php

use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Daypart;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Screen;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| A store's most powerful people, trying everything they should not be able to do
|--------------------------------------------------------------------------
|
| Not hand-made roles with convenient permission lists: the starter store roles an installation begins
| with — the Owner
| every public signup becomes, and the Admin and Staff an Owner hands out — run against every
| door in the app. Each test is an attack, and passing means the door held.
|
| The first test proves the Owner can still run their own shop, so a wall of 403s cannot be
| mistaken for security when it is really a broken fixture.
|
*/

beforeEach(function () {
    Storage::fake('public');
    Notification::fake();

    // Keep the seeder from printing a generated admin password on every test.
    putenv('SEED_ADMIN_PASSWORD=not-used-by-these-tests');

    // The real thing: every permission row, the Super-Admin, and the four store roles.
    $this->seed();
    registerPermissionGates();

    $this->admin = User::where('email', 'admin@gmail.com')->firstOrFail();

    $this->store = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->owner = createStoreMember($this->store, Role::OWNER);
    $this->screen = Screen::factory()->create(['store_id' => $this->store->id]);
    $this->poster = Media::factory()->create(['store_id' => $this->store->id, 'title' => 'Burger deal']);

    // Somebody else's shop, with something worth stealing in it.
    $this->rivalStore = Store::factory()->create(['name' => 'Beta Deli']);
    $this->rivalOwner = createStoreMember($this->rivalStore, Role::OWNER);
    $this->rivalScreen = Screen::factory()->create(['store_id' => $this->rivalStore->id]);
    $this->rivalMedia = Media::factory()->create(['store_id' => $this->rivalStore->id]);
    $this->rivalDaypart = Daypart::factory()->create(['store_id' => $this->rivalStore->id]);

    $this->actingAs($this->owner)->withSession(['current_store_id' => $this->store->id]);
});

function actAs(User $user, Store $store)
{
    return test()->actingAs($user)->withSession(['current_store_id' => $store->id]);
}

/*
|--------------------------------------------------------------------------
| The control: their own shop really does work
|--------------------------------------------------------------------------
*/

test('the seeded Owner runs their own shop', function () {
    $this->getJson('/screens/data')->assertOk();
    $this->getJson('/media/data')->assertOk();
    $this->getJson('/dayparts/data')->assertOk();
    $this->getJson('/members/data')->assertOk();
    $this->getJson('/roles/data')->assertOk();
    $this->get('/settings/store')->assertOk();
    $this->getJson("/screens/{$this->screen->id}/available-channels")->assertOk();

    $this->putJson("/screens/{$this->screen->id}/playlist", [
        'items' => [['media_id' => $this->poster->id, 'duration_seconds' => 10]],
        'version' => $this->screen->playlistFingerprint(),
    ])->assertOk();
});

/*
|--------------------------------------------------------------------------
| The platform's own rooms
|--------------------------------------------------------------------------
*/

test('every platform page is shut to a store Owner', function () {
    $channel = Channel::factory()->create(['name' => 'GAMA']);
    $ad = ChannelAd::factory()->create(['channel_id' => $channel->id]);

    $this->getJson('/channels/data')->assertForbidden();
    $this->postJson('/channels', ['name' => 'Mine'])->assertForbidden();
    $this->deleteJson("/channels/{$channel->id}/ads/{$ad->id}")->assertForbidden();
    $this->getJson('/campaigns/data')->assertForbidden();
    $this->getJson('/activity/data')->assertForbidden();
    $this->getJson('/permissions/data')->assertForbidden();
    $this->getJson('/stores/data')->assertForbidden();
    $this->getJson('/users/data')->assertForbidden();
    $this->deleteJson("/users/{$this->rivalOwner->id}")->assertForbidden();
    $this->postJson('/users/invitations', ['email' => 'x@example.com', 'role_id' => 1])->assertForbidden();
    $this->postJson("/users/{$this->rivalOwner->id}/impersonate")->assertForbidden();

    expect($channel->fresh()->name)->toBe('GAMA');
});

test('they may CARRY a channel but never change one', function () {
    $channel = Channel::factory()->create(['name' => 'GAMA']);
    ChannelAd::factory()->create(['channel_id' => $channel->id]);

    $this->putJson("/screens/{$this->screen->id}/playlist", [
        'items' => [['channel_id' => $channel->id]],
        'version' => $this->screen->playlistFingerprint(),
    ])->assertOk();

    $this->post("/channels/{$channel->id}/ads", ['seconds' => 5], ['Accept' => 'application/json'])->assertForbidden();
});

test('network advertising cannot be switched on — not by the switch, not smuggled through a form', function () {
    $this->putJson('/network-ads/store', ['accepts' => true])->assertForbidden();
    $this->putJson('/network-ads/stores', ['store_ids' => [$this->store->id], 'accepts' => true])->assertForbidden();

    $this->putJson("/screens/{$this->screen->id}", ['name' => 'Counter TV', 'orientation' => 'landscape', 'accepts_network_ads' => true])->assertOk();
    $this->put('/settings/store', [
        'name' => 'Alpha Mart', 'street' => '1 Main', 'city' => 'Dallas', 'state' => 'TX', 'zip_code' => '75001', 'country' => 'USA',
        'accepts_network_ads' => true,
    ])->assertRedirect();

    expect($this->store->fresh()->accepts_network_ads)->toBeFalse()
        ->and($this->screen->fresh()->accepts_network_ads)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The wall around somebody else's shop
|--------------------------------------------------------------------------
*/

test("another shop's screens, files and hours do not exist for them", function () {
    $this->getJson("/screens/{$this->rivalScreen->id}/playlist")->assertNotFound();
    $this->getJson("/screens/{$this->rivalScreen->id}/available-channels")->assertNotFound();
    $this->deleteJson("/screens/{$this->rivalScreen->id}")->assertNotFound();
    $this->putJson("/media/{$this->rivalMedia->id}", ['title' => 'Mine now'])->assertNotFound();
    $this->deleteJson("/media/{$this->rivalMedia->id}")->assertNotFound();
    $this->deleteJson("/dayparts/{$this->rivalDaypart->id}")->assertNotFound();

    // And the file cannot be borrowed onto their own screen.
    $this->putJson("/screens/{$this->screen->id}/playlist", [
        'items' => [['media_id' => $this->rivalMedia->id, 'duration_seconds' => 10]],
        'version' => $this->screen->playlistFingerprint(),
    ])->assertStatus(422);

    expect(Screen::find($this->rivalScreen->id))->not->toBeNull()
        ->and($this->rivalMedia->fresh()->title)->not->toBe('Mine now');
});

test("another shop's people, invitations and roles cannot be reached", function () {
    $rivalRole = Role::create(['name' => 'Rival Cashier', 'store_id' => $this->rivalStore->id]);
    $rivalInvite = Invitation::factory()->create(['store_id' => $this->rivalStore->id]);

    $this->putJson("/members/{$this->rivalOwner->id}", ['role_id' => Role::starter(Role::STAFF)->id])->assertNotFound();
    $this->deleteJson("/members/{$this->rivalOwner->id}")->assertNotFound();
    $this->deleteJson("/members/invitations/{$rivalInvite->id}")->assertNotFound();
    $this->deleteJson("/roles/{$rivalRole->id}")->assertNotFound();

    $ids = collect($this->getJson('/members/data')->json('members'))->pluck('id');
    expect($ids)->not->toContain($this->rivalOwner->id);
});

test('they cannot walk into another shop by switching into it', function () {
    $this->post('/stores/switch', ['store_id' => $this->rivalStore->id])->assertForbidden();

    expect(session('current_store_id'))->toBe($this->store->id);
});

/*
|--------------------------------------------------------------------------
| Climbing the ladder
|--------------------------------------------------------------------------
*/

test('a custom role gets nothing the Owner does not hold, and nothing that reaches past the store', function () {
    // Not held by the seeded Owner: refused, whatever a store's role may carry in principle.
    foreach (['activity-view', 'channel-store'] as $name) {
        $this->postJson('/roles', [
            'name' => 'Climber',
            'permissions' => Permission::whereIn('name', ['screen-view', $name])->pluck('id')->all(),
        ])->assertStatus(422)->assertJsonValidationErrors(['permissions' => 'You can only give a role permissions you hold yourself.']);
    }

    // Never on any store's role: the accounts, yearly log maintenance and the permission catalogue.
    foreach (['user-destroy', 'activity-destroy', 'permission-update'] as $name) {
        $this->postJson('/roles', [
            'name' => 'Climber',
            'permissions' => Permission::whereIn('name', ['screen-view', $name])->pluck('id')->all(),
        ])->assertStatus(422)->assertJsonValidationErrors('permissions');
    }

    $this->postJson('/roles', ['name' => 'Owner', 'permissions' => Permission::where('name', 'screen-view')->pluck('id')->all()])
        ->assertStatus(422)->assertJsonValidationErrors('name');

    expect(Role::whereIn('name', ['Climber'])->exists())->toBeFalse();

    // Deleting the store is the Owner's to hand on — and it reaches this store alone.
    $this->postJson('/roles', ['name' => 'Closer', 'permissions' => Permission::whereIn('name', ['store-destroy'])->pluck('id')->all()])
        ->assertCreated();
    $closer = createStoreMember($this->store, Role::STAFF);
    DB::table('store_user')->where('user_id', $closer->id)->update(['role_id' => Role::firstWhere('name', 'Closer')->id]);

    // The Stores page is the platform's: a store's people delete only the store they work in, from Settings → Stores.
    actAs($closer, $this->store)->deleteJson("/stores/{$this->rivalStore->id}", ['confirm_name' => 'Beta Deli', 'password' => 'password'])
        ->assertForbidden();
    expect(Store::find($this->rivalStore->id))->not->toBeNull();
});

test('nobody is invited or moved into a platform role, or another store’s role', function () {
    $superAdminRole = Role::firstWhere('name', 'Super-Admin');
    $rivalRole = Role::create(['name' => 'Rival Cashier', 'store_id' => $this->rivalStore->id]);
    $staff = createStoreMember($this->store, Role::STAFF);

    $this->postJson('/members/invitations', ['email' => 'puppet@example.com', 'role_id' => $superAdminRole->id])
        ->assertStatus(422)->assertJsonValidationErrors('role_id');
    $this->postJson('/members/invitations', ['email' => 'puppet@example.com', 'role_id' => $rivalRole->id])
        ->assertStatus(422)->assertJsonValidationErrors('role_id');
    $this->putJson("/members/{$staff->id}", ['role_id' => $superAdminRole->id])
        ->assertStatus(422)->assertJsonValidationErrors('role_id');

    expect(DB::table('store_user')->where('store_id', 0)->count())->toBe(1)   // the seeded super admin only
        ->and(Invitation::count())->toBe(0);
});

test('the platform team is out of every store’s reach — the old takeover is gone', function () {
    // Under the old model, a store user kept power over a person they had created even after
    // that person became a super admin. Now nobody "created" anybody: a platform account is not
    // in any store's list, cannot be invited into one, and no store endpoint edits accounts.
    $ops = createPlatformUser(['user-view'], 'Support');

    $ids = collect($this->getJson('/members/data')->json('members'))->pluck('id');
    expect($ids)->not->toContain($ops->id)->not->toContain($this->admin->id);

    $this->postJson('/members/invitations', ['email' => $ops->email, 'role_id' => Role::starter(Role::ADMIN)->id])
        ->assertStatus(422)->assertJsonValidationErrors('email');

    // There is simply no endpoint that sets another person's password.
    $this->putJson("/users/{$this->admin->id}", ['password' => 'Hijack!2345', 'password_confirmation' => 'Hijack!2345'])
        ->assertStatus(405);
    $this->putJson("/members/{$this->admin->id}", ['password' => 'Hijack!2345'])->assertNotFound();
});

test('Owners are protected: an Admin cannot touch them, take ownership, or delete the store', function () {
    $admin = createStoreMember($this->store, Role::ADMIN);
    $staff = createStoreMember($this->store, Role::STAFF);

    actAs($admin, $this->store)->putJson("/members/{$this->owner->id}", ['role_id' => Role::starter(Role::STAFF)->id])->assertForbidden();
    actAs($admin, $this->store)->deleteJson("/members/{$this->owner->id}")->assertForbidden();
    actAs($admin, $this->store)->putJson("/members/{$staff->id}", ['role_id' => Role::starter(Role::OWNER)->id])->assertStatus(422);
    actAs($admin, $this->store)->postJson('/members/invitations', ['email' => 'boss@example.com', 'role_id' => Role::starter(Role::OWNER)->id])->assertStatus(422);
    actAs($admin, $this->store)->delete('/settings/store', ['confirm_name' => 'Alpha Mart', 'password' => 'password'])->assertForbidden();
    actAs($admin, $this->store)->putJson("/members/{$admin->id}", ['role_id' => Role::starter(Role::OWNER)->id])->assertForbidden();

    expect(roleKeyIn($this->owner, $this->store))->toBe(Role::OWNER)
        ->and(roleKeyIn($admin, $this->store))->toBe(Role::ADMIN)
        ->and(Store::find($this->store->id))->not->toBeNull();
});

test('Staff can neither see the team nor change it', function () {
    $staff = createStoreMember($this->store, Role::STAFF);

    actAs($staff, $this->store)->getJson('/members/data')->assertForbidden();
    actAs($staff, $this->store)->postJson('/members/invitations', ['email' => 'friend@example.com', 'role_id' => Role::starter(Role::STAFF)->id])->assertForbidden();
    actAs($staff, $this->store)->postJson('/roles', ['name' => 'Mine', 'permissions' => [1]])->assertForbidden();
    actAs($staff, $this->store)->get('/settings/store')->assertForbidden();
});

test('the only Owner cannot abandon the store — not by leaving, not by deleting their account', function () {
    $this->postJson('/members/leave')->assertStatus(422);
    $this->delete('/profile', ['password' => 'password'])->assertSessionHasErrorsIn('userDeletion', 'password');

    expect(roleKeyIn($this->owner, $this->store))->toBe(Role::OWNER)
        ->and(User::find($this->owner->id))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The machine-facing side
|--------------------------------------------------------------------------
*/

test('a logged-in session opens no device endpoint, and no screen hands out its token', function () {
    $this->getJson('/device/playlist')->assertUnauthorized();
    $this->postJson('/device/heartbeat')->assertUnauthorized();

    $rows = $this->getJson('/screens/data')->assertOk()->json('screens');
    expect($rows)->not->toBeEmpty();
    expect(json_encode($rows))->not->toContain('token');
});

test('with no shop selected, an Owner can reach nothing at all', function () {
    $this->actingAs($this->owner->fresh())->withSession(['current_store_id' => null]);

    $this->getJson('/screens/data')->assertForbidden();
    $this->getJson('/media/data')->assertForbidden();
    $this->getJson('/members/data')->assertForbidden();
});
