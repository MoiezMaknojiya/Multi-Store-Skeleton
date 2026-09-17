<?php

use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Screen;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| A store's role may carry the platform permissions — for its own store alone
|--------------------------------------------------------------------------
|
| The owner's rules (2026-09-16): the super admin sees every permission and decides who holds
| what, and a store's people work within their store ("store k hisab se"). On a store's role,
| Channels and the Activity log reach that store and nothing past it; the store permissions work in
| Settings → Stores, on the store the person works in (2026-09-17: the Stores page is the platform's,
| and a role does not matter — its permissions do). What cannot work inside one store — the accounts
| (a store's people are its Members page), the permission catalogue, yearly log maintenance — is
| never offered, and refused.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->superAdmin = createSuperAdmin();
    $this->alpha = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->beta = Store::factory()->create(['name' => 'Beta Deli']);
    registerPermissionGates();
});

/** Give a starter store role these permissions on top of what it holds (and register their gates). */
function grantToStoreRole(string $key, array $names): Role
{
    $role = Role::starter($key);
    $role->permissions()->syncWithoutDetaching(grantPermissions($names)->pluck('id'));

    return $role;
}

/** The assignable checklist as the given person sees it, keyed by permission name. */
function checklistFor(User $viewer, array $query = [], ?Store $store = null): Collection
{
    $request = test()->actingAs($viewer);
    if ($store !== null) {
        $request = $request->withSession(['current_store_id' => $store->id]);
    }

    return collect($request->getJson('/roles/assignable?'.http_build_query($query))->assertOk()->json())->keyBy('name');
}

/*
|--------------------------------------------------------------------------
| The checklist, and what a role may carry
|--------------------------------------------------------------------------
*/

test('a store role is offered what works inside a store — and nothing else is even listed', function () {
    grantPermissions([...Permission::PLATFORM, ...Permission::SUPER_ADMIN_ONLY]);

    $checklist = checklistFor($this->superAdmin, ['role' => Role::owner()->id]);

    expect($checklist->keys()->sort()->values()->all())
        ->toBe(Permission::pluck('name')->filter(fn (string $name) => Permission::belongsToStores($name))->sort()->values()->all())
        ->and($checklist->keys())->toContain('store-destroy', 'channel-store', 'activity-view', 'media-view')
        ->not->toContain('user-view', 'user-destroy', 'activity-destroy', 'permission-view')
        ->and($checklist->first())->not->toHaveKey('unavailable');
});

test('a platform role is offered everything but the catalogue', function () {
    grantPermissions([...Permission::PLATFORM, ...Permission::SUPER_ADMIN_ONLY]);

    $checklist = checklistFor($this->superAdmin, ['type' => 'platform']);

    expect($checklist)->toHaveCount(Permission::count() - count(Permission::SUPER_ADMIN_ONLY))
        ->and($checklist->keys())->toContain('activity-destroy')->not->toContain('permission-update');
});

test('the super admin gives a store role what works inside a store — and nothing that does not', function () {
    $owner = Role::owner();
    $keep = $owner->permissions()->pluck('permissions.id')->all();
    $scoped = grantPermissions(['store-view', 'store-store', 'channel-view', 'channel-store', 'activity-view'])->pluck('id')->all();

    $this->actingAs($this->superAdmin)->putJson("/roles/{$owner->id}", ['name' => 'Owner', 'permissions' => array_values(array_unique([...$keep, ...$scoped]))])->assertOk();
    expect($owner->fresh()->permissions->pluck('name'))->toContain('store-view', 'channel-store', 'activity-view', 'store-destroy');

    $this->actingAs($this->superAdmin)->putJson("/roles/{$owner->id}", ['name' => 'Owner', 'permissions' => [...$keep, ...grantPermissions(['activity-destroy'])->pluck('id')]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['permissions' => 'A store role cannot hold Delete Old Activity Logs: it works above the stores only.']);

    $this->actingAs($this->superAdmin)->putJson("/roles/{$owner->id}", ['name' => 'Owner', 'permissions' => [...$keep, ...grantPermissions(['user-destroy'])->pluck('id')]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['permissions' => 'A store role cannot hold Delete Accounts: it works above the stores only.']);

    $this->actingAs($this->superAdmin)->putJson("/roles/{$owner->id}", ['name' => 'Owner', 'permissions' => [...$keep, ...grantPermissions(['permission-view'])->pluck('id')]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['permissions' => 'Permission management belongs to the Super-Admin role alone.']);

    expect($owner->fresh()->permissions->pluck('name'))->not->toContain('activity-destroy', 'user-destroy', 'permission-view');
});

test('a permission made on the Permissions page stays off store roles', function () {
    $custom = Permission::create(['name' => 'report-export']);

    expect(checklistFor($this->superAdmin, ['role' => Role::starter(Role::STAFF)->id])->keys())->not->toContain('report-export')
        ->and(checklistFor($this->superAdmin, ['type' => 'platform'])->keys())->toContain('report-export');

    $this->actingAs($this->superAdmin)->putJson('/roles/'.Role::starter(Role::STAFF)->id, ['name' => 'Staff', 'permissions' => [$custom->id]])
        ->assertStatus(422)->assertJsonValidationErrors('permissions');
});

test('inside a store a member gives a custom role only what they hold — its platform permissions included', function () {
    $owner = createStoreMember($this->alpha, Role::OWNER);

    // The Owner starts with store-destroy, so it is theirs to hand on; channel-view is not, and the accounts never are.
    $offered = checklistFor($owner, store: $this->alpha);
    expect($offered->keys())->toContain('store-destroy')->not->toContain('channel-view', 'user-view', 'activity-destroy');

    $this->actingAs($owner)->withSession(['current_store_id' => $this->alpha->id])->postJson('/roles', [
        'name' => 'Closer',
        'permissions' => Permission::whereIn('name', ['store-destroy', 'store-update'])->pluck('id')->all(),
    ])->assertCreated();

    $this->actingAs($owner)->withSession(['current_store_id' => $this->alpha->id])->postJson('/roles', [
        'name' => 'Nosy',
        'permissions' => grantPermissions(['channel-view'])->pluck('id')->all(),
    ])->assertStatus(422)->assertJsonValidationErrors(['permissions' => 'You can only give a role permissions you hold yourself.']);

    $this->actingAs($owner)->withSession(['current_store_id' => $this->alpha->id])->postJson('/roles', [
        'name' => 'Nosy',
        'permissions' => grantPermissions(['user-view'])->pluck('id')->all(),
    ])->assertStatus(422)->assertJsonValidationErrors(['permissions' => 'A store role cannot hold View Accounts: it works above the stores only.']);

    expect(Role::firstWhere('name', 'Closer')->permissions->pluck('name')->sort()->values()->all())->toBe(['store-destroy', 'store-update']);
});

/*
|--------------------------------------------------------------------------
| Settings → Stores: deleting the store is a permission
|--------------------------------------------------------------------------
*/

test('the Owner starts with store-destroy; without it even an Owner cannot delete the store, and an Admin given it can', function () {
    $owner = createStoreMember($this->alpha, Role::OWNER);
    $admin = createStoreMember($this->alpha, Role::ADMIN);

    expect(Role::starter(Role::OWNER)->permissions->pluck('name'))->toContain('store-destroy');

    // Taken away from Owners by the super admin.
    Role::starter(Role::OWNER)->permissions()->detach(Permission::firstWhere('name', 'store-destroy')->id);
    $this->actingAs($owner)->withSession(['current_store_id' => $this->alpha->id])
        ->get('/settings/store')->assertOk()->assertDontSee('Delete Store');
    $this->actingAs($owner)->withSession(['current_store_id' => $this->alpha->id])
        ->delete('/settings/store', ['confirm_name' => 'Alpha Mart', 'password' => 'password'])->assertForbidden();

    // Given to Admins instead.
    grantToStoreRole(Role::ADMIN, ['store-destroy']);
    $this->actingAs($admin)->withSession(['current_store_id' => $this->alpha->id])
        ->get('/settings/store')->assertOk()->assertSee('Delete Store')->assertDontSee('Transfer Ownership');
    $this->actingAs($admin)->withSession(['current_store_id' => $this->alpha->id])
        ->delete('/settings/store', ['confirm_name' => 'Alpha Mart', 'password' => 'password'])->assertRedirect(route('dashboard'));

    $this->assertDatabaseMissing('stores', ['id' => $this->alpha->id]);
    expect(ActivityLog::where('action', 'store.deleted')->value('store_id'))->toBe($this->alpha->id);
});

test('the Stores tab opens with View Stores, and every card on it is a permission of its own', function () {
    // No View Stores: no tab, and "Your stores" stays on the profile so they can still leave.
    $viewer = createStoreMember($this->alpha, Role::VIEWER);
    $this->actingAs($viewer)->withSession(['current_store_id' => $this->alpha->id])->get('/settings/store')->assertForbidden();
    $this->actingAs($viewer)->withSession(['current_store_id' => $this->alpha->id])->get('/profile')->assertOk()
        ->assertDontSee('dusk="settings-tab-store"', false)->assertSee('Your stores');

    // View Stores alone: the details to read and the stores they belong to — nothing to press.
    $reader = createStoreUser($this->alpha, ['store-view'], 'Reader');
    $this->actingAs($reader)->withSession(['current_store_id' => $this->alpha->id])->get('/settings/store')->assertOk()
        ->assertSee('Your role here does not let you change the store\'s details.', false)
        ->assertDontSee('dusk="store-details-form"', false)
        ->assertSee('dusk="your-stores"', false)
        ->assertDontSee('dusk="delete-store"', false)->assertDontSee('dusk="open-store-button"', false);
    $this->actingAs($reader)->withSession(['current_store_id' => $this->alpha->id])
        ->put('/settings/store', ['name' => 'Renamed'])->assertForbidden();
    // With the tab, "Your stores" moves off the profile.
    $this->actingAs($reader)->withSession(['current_store_id' => $this->alpha->id])->get('/profile')->assertOk()
        ->assertSee('dusk="settings-tab-store"', false)->assertDontSee('Your stores');

    // Each permission adds its own card, whatever the role is called.
    $closer = createStoreUser($this->alpha, ['store-view', 'store-destroy', 'store-store'], 'Closer');
    $this->actingAs($closer)->withSession(['current_store_id' => $this->alpha->id])->get('/settings/store')->assertOk()
        ->assertSee('dusk="delete-store"', false)->assertSee('dusk="open-store-button"', false);

    // Turning View Stores off hides the tab — even for the Owner role.
    $owner = createStoreMember($this->alpha, Role::OWNER);
    Role::owner()->permissions()->detach(Permission::firstWhere('name', 'store-view')->id);
    $this->actingAs($owner)->withSession(['current_store_id' => $this->alpha->id])->get('/settings/store')->assertForbidden();
    $this->actingAs($owner)->withSession(['current_store_id' => $this->alpha->id])->get('/profile')->assertOk()
        ->assertDontSee('dusk="settings-tab-store"', false);
});

/*
|--------------------------------------------------------------------------
| Stores, inside a store: Settings → Stores
|--------------------------------------------------------------------------
*/

test('"Your stores" lists every store the person belongs to, with their role in each — whatever those roles allow', function () {
    $gamma = Store::factory()->create(['name' => 'Gamma Grill']);
    Store::factory()->create(['name' => 'Delta Diner']);

    $person = createStoreMember($this->alpha, Role::OWNER);
    $person->stores()->attach($gamma->id, ['role_id' => Role::starter(Role::STAFF)->id]);

    $this->actingAs($person)->withSession(['current_store_id' => $this->alpha->id])->get('/settings/store')->assertOk()
        ->assertSee('dusk="store-membership-'.$this->alpha->id.'"', false)
        ->assertSee('dusk="store-membership-'.$gamma->id.'"', false)
        ->assertSee('Gamma Grill')->assertSee('Staff')
        ->assertDontSee('Delta Diner');
});

test('Create store opens a store the person owns at once — no invitation, and the active switch stays the platform\'s', function () {
    grantToStoreRole(Role::OWNER, ['store-store']);
    $owner = createStoreMember($this->alpha, Role::OWNER);

    $this->actingAs($owner)->withSession(['current_store_id' => $this->alpha->id])->post('/settings/store/open', [
        'store_name' => 'Alpha Mart East', 'store_street' => '9 Side St', 'store_city' => 'Austin', 'store_state' => 'TX',
        'store_zip_code' => '73301', 'store_country' => 'USA', 'is_active' => false, 'owner_email' => 'someone@example.com',
    ])->assertRedirect(route('store-settings.edit'))
        ->assertSessionHas('status', 'Alpha Mart East is open, and you are its Owner. Switch to it from the store menu.');

    $branch = Store::firstWhere('name', 'Alpha Mart East');
    expect($branch->is_active)->toBeTrue()
        ->and($branch->street)->toBe('9 Side St')
        ->and(roleKeyIn($owner, $branch))->toBe(Role::OWNER)
        ->and(session('current_store_id'))->toBe($this->alpha->id)
        ->and(DB::table('invitations')->count())->toBe(0)
        ->and(ActivityLog::where('action', 'store.created')->value('store_id'))->toBe($branch->id);
});

test('Create store asks for store-store, and checks the details under their own names', function () {
    $owner = createStoreMember($this->alpha, Role::OWNER);

    $this->actingAs($owner)->withSession(['current_store_id' => $this->alpha->id])
        ->post('/settings/store/open', ['store_name' => 'Nope'])->assertForbidden();

    grantToStoreRole(Role::OWNER, ['store-store']);
    // A fresh instance: the one above remembers the permissions it read on the first request.
    $this->actingAs($owner->fresh())->withSession(['current_store_id' => $this->alpha->id])->from('/settings/store')
        ->post('/settings/store/open', ['store_name' => '', 'store_zip_code' => '7A'])
        ->assertRedirect('/settings/store')
        ->assertSessionHasErrorsIn('newStore', [
            'store_name' => 'The store name field is required.',
            'store_zip_code' => 'Zip code can only contain numbers.',
        ])
        // The details form of the store being worked in never reads them back as its own.
        ->assertSessionDoesntHaveErrors(['name', 'zip_code']);

    expect(Store::count())->toBe(2);
});

test('Settings → Stores changes the store the person works in, each action by its own permission there', function () {
    $person = createStoreMember($this->alpha, Role::OWNER);
    $person->stores()->attach($this->beta->id, ['role_id' => Role::starter(Role::STAFF)->id]);

    // Working in Beta, where they are Staff: nothing.
    $this->actingAs($person)->withSession(['current_store_id' => $this->beta->id]);
    $this->put('/settings/store', ['name' => 'Taken', 'street' => '1', 'city' => 'X', 'state' => 'TX', 'zip_code' => '1', 'country' => 'USA'])
        ->assertForbidden();
    $this->delete('/settings/store', ['confirm_name' => 'Beta Deli', 'password' => 'password'])->assertForbidden();
    expect($this->beta->fresh()->name)->toBe('Beta Deli');

    // Working in Alpha, where the role allows it: the details change, but never the platform's active switch.
    $this->actingAs($person)->withSession(['current_store_id' => $this->alpha->id])->put('/settings/store', [
        'name' => 'Alpha Mart II', 'street' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'zip_code' => '73301', 'country' => 'USA', 'is_active' => false,
    ])->assertRedirect(route('store-settings.edit'));
    expect($this->alpha->fresh())->name->toBe('Alpha Mart II')->is_active->toBeTrue();
});

test('deleting the store the person is working in sends them to the dashboard', function () {
    $owner = createStoreMember($this->alpha, Role::OWNER);

    $this->actingAs($owner)->withSession(['current_store_id' => $this->alpha->id])
        ->delete('/settings/store', ['confirm_name' => 'Alpha Mart', 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    expect(session('current_store_id'))->toBeNull();
    $this->assertDatabaseMissing('stores', ['id' => $this->alpha->id]);
});

test('giving a store an owner stays above the stores', function () {
    grantToStoreRole(Role::OWNER, ['store-view', 'store-store']);
    $owner = createStoreMember($this->alpha, Role::OWNER);

    $this->actingAs($owner)->withSession(['current_store_id' => $this->alpha->id])
        ->postJson("/stores/{$this->alpha->id}/owner-invitation", ['email' => 'second@example.com'])->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Accounts are the platform's
|--------------------------------------------------------------------------
*/

test('a store\'s role never opens the accounts pages — not even holding their permissions', function () {
    // Written straight into the database, past the checklist that no longer offers them (owner's rule, 2026-09-17:
    // a store's people are its Members page).
    grantToStoreRole(Role::OWNER, ['user-view', 'user-destroy']);
    $owner = createStoreMember($this->alpha, Role::OWNER);
    $staff = createStoreMember($this->alpha, Role::STAFF);

    $this->actingAs($owner)->withSession(['current_store_id' => $this->alpha->id]);

    $this->get('/users')->assertForbidden();
    $this->getJson('/users/data')->assertForbidden();
    $this->deleteJson("/users/{$staff->id}", ['password' => 'password'])->assertForbidden();

    expect(User::find($staff->id))->not->toBeNull()
        ->and(roleKeyIn($staff, $this->alpha))->toBe(Role::STAFF);
});

test('what works only above the stores stays shut to a store\'s role, whatever it carries', function () {
    grantToStoreRole(Role::OWNER, ['user-view', 'user-destroy', 'store-view', 'activity-view']);
    $owner = createStoreMember($this->alpha, Role::OWNER);
    $staff = createStoreMember($this->alpha, Role::STAFF);

    $this->actingAs($owner)->withSession(['current_store_id' => $this->alpha->id]);

    $this->postJson("/users/{$staff->id}/impersonate")->assertForbidden();
    $this->getJson("/users/{$staff->id}/stores")->assertForbidden();
    $this->putJson("/users/{$staff->id}/stores/{$this->alpha->id}/role", ['role_id' => Role::starter(Role::ADMIN)->id])->assertForbidden();
    $this->getJson('/users/invitations')->assertForbidden();
    $this->getJson('/activity/partitions')->assertForbidden();
    $this->postJson('/activity/partitions/maintain')->assertForbidden();

    expect(roleKeyIn($staff, $this->alpha))->toBe(Role::STAFF);
});

/*
|--------------------------------------------------------------------------
| The activity log, inside a store
|--------------------------------------------------------------------------
*/

test('a store role with activity-view reads its own store\'s history and nothing else', function () {
    grantToStoreRole(Role::OWNER, ['activity-view']);
    $owner = createStoreMember($this->alpha, Role::OWNER);

    ActivityLog::record('screen.paired', Screen::factory()->create(['store_id' => $this->alpha->id, 'name' => 'Alpha Window']), 'Paired screen Alpha Window', $owner);
    ActivityLog::record('screen.paired', Screen::factory()->create(['store_id' => $this->beta->id, 'name' => 'Beta Window']), 'Paired screen Beta Window', $owner);
    ActivityLog::record('permission.created', null, 'Created permission report-export', $this->superAdmin);
    ActivityLog::record('profile.updated', $owner, 'Updated their own profile', $owner);

    $descriptions = collect($this->actingAs($owner)->withSession(['current_store_id' => $this->alpha->id])
        ->getJson('/activity/data')->assertOk()->json('logs'))->pluck('description');

    expect($descriptions->all())->toBe(['Paired screen Alpha Window']);

    $this->actingAs($owner)->withSession(['current_store_id' => $this->alpha->id])->get('/activity')
        ->assertOk()->assertSee('Activity in Alpha Mart')->assertDontSee('Run Yearly Maintenance');

    // The platform reads every store's history, and its own.
    expect($this->actingAs($this->superAdmin)->withSession([])->getJson('/activity/data')->json('total'))->toBe(4);
});

test('an entry belongs to the store its subject belongs to — or the store named for a delete; personal and platform work to none', function () {
    $owner = createStoreMember($this->alpha, Role::OWNER);
    $media = Media::factory()->create(['store_id' => $this->beta->id]);

    ActivityLog::record('media.updated', $media, 'Updated media', $owner);
    ActivityLog::record('store.updated', $this->alpha, 'Updated store', $owner);
    ActivityLog::record('member.removed', $owner, 'Removed somebody', $owner, storeId: $this->alpha->id);
    ActivityLog::record('password.changed', $owner, 'Changed their own password', $owner);
    ActivityLog::record('campaign.created', null, 'Created campaign', $this->superAdmin);

    expect(ActivityLog::orderBy('id')->pluck('store_id', 'action')->all())->toBe([
        'media.updated' => $this->beta->id,
        'store.updated' => $this->alpha->id,
        'member.removed' => $this->alpha->id,
        'password.changed' => null,
        'campaign.created' => null,
    ]);
});

/*
|--------------------------------------------------------------------------
| The sidebar follows the role
|--------------------------------------------------------------------------
*/

test('a store\'s sidebar offers Channels and the Activity Log only when the role carries them — never Accounts or the Stores page', function () {
    $owner = createStoreMember($this->alpha, Role::OWNER);

    $this->actingAs($owner)->withSession(['current_store_id' => $this->alpha->id])->get('/dashboard')->assertOk()
        ->assertDontSee('href="'.route('stores.view').'"', false)->assertDontSee('href="'.route('users.view').'"', false)
        ->assertDontSee('href="'.route('channels.view').'"', false)->assertDontSee('href="'.route('activity.view').'"', false);

    grantToStoreRole(Role::OWNER, ['store-view', 'user-view', 'channel-view', 'activity-view']);

    // View Stores is the Stores tab of Settings inside a store, and the accounts are the platform's: neither is a
    // page of a store's sidebar.
    $this->actingAs($owner->fresh())->withSession(['current_store_id' => $this->alpha->id])->get('/dashboard')->assertOk()
        ->assertDontSee('href="'.route('stores.view').'"', false)->assertDontSee('href="'.route('users.view').'"', false)
        ->assertSee('href="'.route('channels.view').'"', false)->assertSee('href="'.route('activity.view').'"', false);

    $this->actingAs($owner->fresh())->withSession(['current_store_id' => $this->alpha->id])->get('/stores')->assertForbidden();
});
