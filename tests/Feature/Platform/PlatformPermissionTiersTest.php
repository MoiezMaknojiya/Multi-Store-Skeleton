<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| What reaches across every shop stays above the shops
|--------------------------------------------------------------------------
|
| The owner's rules (2026-09-16). Reading the activity log is a permission a platform role
| holds for every store, and a store's role for its own store's entries alone. Deleting its
| old years drops every store's history at once, so that stays above the stores: the super
| admin may hand it to a platform user, never to a store's role. The permission catalogue
| belongs to the super admin alone, never delegated.
|
| Each rule holds twice: RoleController refuses the permission on a role it does not fit
| (a store's role holds only what works inside a store; no role but Super-Admin holds the
| catalogue), and the routes hold a tier lock — so a row that reached the wrong role some
| other way (written before the rule, or straight into the database) still opens nothing.
|
*/

/** A store-less user on a platform role carrying exactly these permissions. */
function platformGlobalUser(array $permissions): User
{
    return createPlatformUser($permissions, 'Ops');
}

beforeEach(function () {
    $this->admin = createSuperAdmin(['role-view', 'role-store', 'role-update', 'activity-view', 'activity-destroy', 'permission-view']);
});

/*
|--------------------------------------------------------------------------
| The activity log: on a role
|--------------------------------------------------------------------------
*/

test('a super admin may give the activity log — reading and deleting — to a platform role', function () {
    $this->actingAs($this->admin)->postJson('/roles', [
        'name' => 'Auditor',
        'type' => 'platform',
        'permissions' => grantPermissions(['activity-view', 'activity-destroy'])->pluck('id')->all(),
    ])->assertCreated();

    $role = Role::firstWhere('name', 'Auditor');
    expect($role->is_global)->toBeTrue()
        ->and($role->permissions->pluck('name')->sort()->values()->all())->toBe(['activity-destroy', 'activity-view']);
});

test("deleting old years never goes on a store's role, however it is asked — not even by the super admin", function () {
    $store = Store::factory()->create();
    $owner = createStoreMember($store, Role::OWNER);

    // The Owner holds neither, so can give neither.
    foreach (['activity-view', 'activity-destroy'] as $name) {
        $this->actingAs($owner)->withSession(['current_store_id' => $store->id])->postJson('/roles', [
            'name' => 'Nosy Cashier',
            'permissions' => Permission::whereIn('name', ['screen-view', $name])->pluck('id')->all(),
        ])->assertStatus(422)->assertJsonValidationErrors('permissions');
    }

    // The super admin may give a store role the reading, never the deleting.
    $staff = Role::starter(Role::STAFF);
    $this->actingAs($this->admin)->putJson("/roles/{$staff->id}", [
        'name' => 'Staff',
        'permissions' => Permission::whereIn('name', ['screen-view', 'activity-destroy'])->pluck('id')->all(),
    ])->assertStatus(422)->assertJsonValidationErrors(['permissions' => 'A store role cannot hold Delete Old Activity Logs: it works above the stores only.']);

    expect(Role::where('name', 'Nosy Cashier')->exists())->toBeFalse()
        ->and($staff->fresh()->permissions->pluck('name'))->not->toContain('activity-destroy');
});

/*
|--------------------------------------------------------------------------
| The activity log: at the door
|--------------------------------------------------------------------------
*/

test("a store role reads its own store's history — and maintenance stays shut even if the deleting reached it", function () {
    $store = Store::factory()->create();
    $legacy = createStoreUser($store, ['activity-view', 'activity-destroy']);

    $this->actingAs($legacy)->withSession(['current_store_id' => $store->id]);

    $this->get('/activity')->assertOk()->assertDontSee('Run Yearly Maintenance');
    $this->getJson('/activity/data')->assertOk();
    $this->getJson('/activity/partitions')->assertForbidden();
    $this->postJson('/activity/partitions/maintain')->assertForbidden();

    $this->get('/dashboard')->assertOk()->assertSee('href="'.route('activity.view').'"', false);
    $this->assertDatabaseMissing('activity_logs', ['action' => 'activity.maintenance']);
});

test('a platform user trusted with reading reads it, but cannot delete from it', function () {
    $reader = platformGlobalUser(['activity-view']);

    $this->actingAs($reader)->get('/activity')->assertOk()->assertDontSee('Run Yearly Maintenance');
    $this->actingAs($reader)->getJson('/activity/data')->assertOk();
    $this->actingAs($reader)->postJson('/activity/partitions/maintain')->assertForbidden();

    $this->actingAs($reader)->get('/dashboard')->assertOk()->assertSee(route('activity.view'));
});

test('a platform user trusted with deleting runs the yearly maintenance', function () {
    $keeper = platformGlobalUser(['activity-view', 'activity-destroy']);

    $this->actingAs($keeper)->get('/activity')->assertOk()->assertSee('Run Yearly Maintenance');
    $this->actingAs($keeper)->postJson('/activity/partitions/maintain')->assertOk();

    $this->assertDatabaseHas('activity_logs', ['action' => 'activity.maintenance', 'actor_id' => $keeper->id]);
});

/*
|--------------------------------------------------------------------------
| The permission catalogue: the super admin's alone
|--------------------------------------------------------------------------
*/

test('the permission catalogue goes on no role but Super-Admin — not a platform one, not a store one', function () {
    $catalogue = grantPermissions(['permission-view'])->pluck('id')->all();

    foreach (['platform', 'store'] as $type) {
        $this->actingAs($this->admin)->postJson('/roles', ['name' => 'Ops', 'type' => $type, 'permissions' => $catalogue])
            ->assertStatus(422)->assertJsonValidationErrors(['permissions' => 'Permission management belongs to the Super-Admin role alone.']);
    }

    $store = Store::factory()->create();
    $owner = createStoreMember($store, Role::OWNER);
    $this->actingAs($owner)->withSession(['current_store_id' => $store->id])
        ->postJson('/roles', ['name' => 'Cashier', 'permissions' => $catalogue])
        ->assertStatus(422)->assertJsonValidationErrors('permissions');

    expect(Role::whereIn('name', ['Ops', 'Cashier'])->exists())->toBeFalse();
});

test('anybody else holding it — platform user or store user — opens nothing', function () {
    $target = Permission::firstWhere('name', 'permission-view');

    // A platform user: the one a stray row would most plausibly reach.
    $ops = platformGlobalUser(['permission-view', 'permission-store', 'permission-update']);

    $this->actingAs($ops)->get('/permissions')->assertForbidden();
    $this->actingAs($ops)->getJson('/permissions/data')->assertForbidden();
    $this->actingAs($ops)->postJson('/permissions', ['name' => 'anything'])->assertForbidden();
    // The escalation itself: renaming a permission they hold into one they do not.
    $this->actingAs($ops)->putJson("/permissions/{$target->id}", ['name' => 'store-destroy'])->assertForbidden();
    $this->actingAs($ops)->get('/dashboard')->assertOk()->assertDontSee(route('permissions.view'));

    // A store user, with their shop selected.
    $store = Store::factory()->create();
    $cashier = createStoreUser($store, ['permission-view', 'permission-update']);

    $this->actingAs($cashier)->withSession(['current_store_id' => $store->id])
        ->getJson('/permissions/data')->assertForbidden();

    expect($target->fresh()->name)->toBe('permission-view');
    expect(Permission::where('name', 'anything')->exists())->toBeFalse();
});

test('the super admin still runs the catalogue', function () {
    $this->actingAs($this->admin)->get('/permissions')->assertOk();
    $this->actingAs($this->admin)->getJson('/permissions/data')->assertOk();
    $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertSee(route('permissions.view'));
});
