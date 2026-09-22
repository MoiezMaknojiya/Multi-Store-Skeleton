<?php

use App\Models\ActivityLog;
use App\Models\Invitation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Roles, from where the person stands (docs/STORE-ORGANIZATION-SPEC.md §2–4, rules 5–7)
|--------------------------------------------------------------------------
|
| The owner's rules (2026-09-17): nothing is built in but Super-Admin and the Owner mark. The super
| admin makes a role and says what it is for — a store role, offered in every store, or a platform
| role — and renames, changes and deletes any role but Super-Admin; the Owner role is never deleted.
| Stores make custom roles of their own, as before.
|
*/

beforeEach(function () {
    $this->store = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->owner = createStoreMember($this->store, Role::OWNER);
    $this->admin = createStoreMember($this->store, Role::ADMIN);
});

function rolesAs(User $user, ?Store $store = null)
{
    return $store
        ? test()->actingAs($user)->withSession(['current_store_id' => $store->id])
        : test()->actingAs($user);
}

function permissionIds(array $names): array
{
    return Permission::whereIn('name', $names)->pluck('id')->all();
}

test('inside a store: one list — the store roles to read, then the store’s own custom roles', function () {
    Role::create(['name' => 'Cashier', 'store_id' => $this->store->id]);
    Role::create(['name' => 'Elsewhere', 'store_id' => Store::factory()->create()->id]);
    Role::create(['name' => 'Ops', 'is_global' => true]);
    $elsewhere = Store::factory()->create();
    createStoreMember($elsewhere, Role::STAFF);   // a Staff member of ANOTHER store
    // An open invitation to Staff here, and two in that other store.
    Invitation::open($this->store, 'here@example.com', Role::starter(Role::STAFF), $this->owner);
    Invitation::open($elsewhere, 'there@example.com', Role::starter(Role::STAFF), $this->owner);
    Invitation::open($elsewhere, 'yonder@example.com', Role::starter(Role::STAFF), $this->owner);

    $rows = collect(rolesAs($this->admin, $this->store)->getJson('/roles/data')->assertOk()->json('roles'));

    expect($rows->pluck('name')->all())->toBe(['Owner', 'Admin', 'Staff', 'Viewer', 'Cashier'])
        ->and($rows->where('kind', 'store'))->toHaveCount(4)
        ->and($rows->where('kind', 'store')->every(fn ($role) => ! $role['can_edit'] && ! $role['can_delete']))->toBeTrue()
        ->and($rows->firstWhere('name', 'Owner')['is_owner_role'])->toBeTrue()
        ->and($rows->firstWhere('name', 'Cashier')['kind'])->toBe('custom')
        // Holders and open invitations count this store's alone.
        ->and($rows->firstWhere('name', 'Staff')['holders_count'])->toBe(0)
        ->and($rows->firstWhere('name', 'Admin')['holders_count'])->toBe(1)
        ->and($rows->firstWhere('name', 'Staff')['invitations_count'])->toBe(1);

    // Above the stores, every store counts.
    $superAdmin = createSuperAdmin();
    $platformRows = collect(rolesAs($superAdmin)->getJson('/roles/data')->assertOk()->json('roles'));
    expect($platformRows->firstWhere('name', 'Staff'))->holders_count->toBe(1)->invitations_count->toBe(3);
});

test('on the platform the super admin renames and changes a store role — in every store at once', function () {
    $superAdmin = createSuperAdmin();
    $staff = Role::starter(Role::STAFF);

    $rows = collect(rolesAs($superAdmin)->getJson('/roles/data')->assertOk()->json('roles'));
    expect($rows->pluck('name')->take(5)->all())->toBe(['Super-Admin', 'Owner', 'Admin', 'Staff', 'Viewer'])
        ->and($rows->firstWhere('name', 'Super-Admin'))->can_edit->toBeFalse()->can_delete->toBeFalse()
        ->and($rows->firstWhere('name', 'Owner'))->can_edit->toBeTrue()->can_delete->toBeFalse()
        ->and($rows->firstWhere('name', 'Viewer'))->can_edit->toBeTrue()->can_delete->toBeTrue()
        // Admin is held in Alpha Mart: Delete is still offered, and says it must be unassigned first.
        ->and($rows->firstWhere('name', 'Admin')['can_delete'])->toBeTrue();

    rolesAs($superAdmin)->deleteJson('/roles/'.Role::starter(Role::ADMIN)->id, ['password' => 'password'])
        ->assertStatus(422)->assertJsonPath('message', 'Please unassign Admin from everyone first: 1 person still holds it.');
    expect(Role::starter(Role::ADMIN))->not->toBeNull();

    // The checklist offers only what a store role can hold — nothing greyed out.
    $offered = collect(rolesAs($superAdmin)->getJson("/roles/assignable?role={$staff->id}")->assertOk()->json());
    expect($offered->pluck('name')->sort()->values()->all())
        ->toBe(Permission::pluck('name')->filter(fn (string $name) => Permission::belongsToStores($name))->sort()->values()->all())
        ->and($offered->first())->not->toHaveKey('unavailable');

    rolesAs($superAdmin)->putJson("/roles/{$staff->id}", ['name' => 'Cashier', 'permissions' => permissionIds(['screen-view', 'screen-store', 'media-view'])])
        ->assertOk()
        ->assertJsonPath('message', 'Role Cashier updated in every store.');

    $staff->refresh();
    expect($staff->name)->toBe('Cashier')
        ->and($staff->permissions()->pluck('name')->sort()->values()->all())->toBe(['media-view', 'screen-store', 'screen-view'])
        ->and($staff->description())->toBe('Allows View Screens, Pair Screens and View Media Library.')
        ->and(ActivityLog::where('action', 'role.updated')->value('description'))->toBe('Updated store role Cashier (was Staff), in every store');

    // What cannot work inside one store never goes on a store role.
    rolesAs($superAdmin)->putJson("/roles/{$staff->id}", ['name' => 'Cashier', 'permissions' => permissionIds(['activity-destroy', 'screen-view'])])
        ->assertStatus(422)->assertJsonValidationErrors(['permissions' => 'A store role cannot hold Delete Old Activity Logs: it works above the stores only.']);

    // Super-Admin is never changed.
    rolesAs($superAdmin)->putJson('/roles/'.Role::superAdminId(), ['name' => 'Boss', 'permissions' => permissionIds(['screen-view'])])->assertForbidden();
});

test('the Owner role is renamed like any other, and never deleted; an unheld store role is', function () {
    $superAdmin = createSuperAdmin();
    $ownerRole = Role::owner();
    $viewer = Role::starter(Role::VIEWER);

    rolesAs($superAdmin)->putJson("/roles/{$ownerRole->id}", ['name' => 'Proprietor', 'permissions' => $ownerRole->permissions()->pluck('permissions.id')->all()])
        ->assertOk();
    expect($ownerRole->fresh())->name->toBe('Proprietor')->isOwner()->toBeTrue()
        ->and(roleKeyIn($this->owner, $this->store))->toBe(Role::OWNER);

    rolesAs($superAdmin)->deleteJson("/roles/{$ownerRole->id}", ['password' => 'password'])
        ->assertForbidden()->assertJsonPath('message', 'The Owner role is never deleted: it is how a store has an owner. Rename it or change what it allows instead.');

    rolesAs($superAdmin)->deleteJson("/roles/{$viewer->id}", ['password' => 'password'])->assertOk();
    expect(Role::find($viewer->id))->toBeNull()->and(Role::find($ownerRole->id))->not->toBeNull();
});

test('the super admin says what a new role is for: a store role for every store, or a platform role', function () {
    $superAdmin = createSuperAdmin();

    rolesAs($superAdmin)->postJson('/roles', ['name' => 'Floor Lead', 'permissions' => permissionIds(['screen-view'])])
        ->assertStatus(422)->assertJsonValidationErrors(['type' => 'Choose what this role is for.']);
    // Until the type is known there is no list to compare a name with: only the type is refused.
    rolesAs($superAdmin)->postJson('/roles', ['name' => 'Owner', 'type' => 'everywhere', 'permissions' => permissionIds(['screen-view'])])
        ->assertStatus(422)->assertJsonValidationErrors('type')->assertJsonMissingValidationErrors('name');

    rolesAs($superAdmin)->postJson('/roles', ['name' => 'Floor Lead', 'type' => 'store', 'permissions' => permissionIds(['screen-view', 'channel-view'])])
        ->assertCreated();
    rolesAs($superAdmin)->postJson('/roles', ['name' => 'Support', 'type' => 'platform', 'permissions' => permissionIds(['user-view', 'activity-destroy'])])
        ->assertCreated();

    expect(Role::firstWhere('name', 'Floor Lead'))->is_global->toBeFalse()->store_id->toBeNull()
        ->and(Role::firstWhere('name', 'Support')->is_global)->toBeTrue()
        ->and(ActivityLog::where('action', 'role.created')->orderBy('id')->pluck('description')->all())
        ->toBe(['Created store role Floor Lead, offered in every store', 'Created platform role Support']);

    // A store role is offered in every store's pickers; a store role never holds what works above the stores.
    expect(Role::availableInStore(Store::factory()->create()->id)->pluck('name'))->toContain('Floor Lead')->not->toContain('Support');
    foreach (['activity-destroy', 'user-view'] as $name) {
        rolesAs($superAdmin)->postJson('/roles', ['name' => 'Auditor', 'type' => 'store', 'permissions' => permissionIds([$name])])
            ->assertStatus(422)->assertJsonValidationErrors('permissions');
    }

    // Each kind is offered its own permissions: the accounts are the platform's (owner's rule, 2026-09-17).
    $forStore = collect(rolesAs($superAdmin)->getJson('/roles/assignable?type=store')->json())->pluck('name');
    $forPlatform = collect(rolesAs($superAdmin)->getJson('/roles/assignable?type=platform')->json())->pluck('name');
    expect($forStore)->toContain('channel-view')->not->toContain('user-view')->not->toContain('activity-destroy')
        ->and($forPlatform)->toContain('activity-destroy', 'user-view')->not->toContain('permission-view');
});

test('a member creates a custom role only from store permissions they hold', function () {
    $supervisor = createStoreUser($this->store, ['role-store', 'screen-view', 'media-view'], 'Supervisor');

    rolesAs($supervisor, $this->store)->postJson('/roles', ['name' => 'Helper', 'permissions' => permissionIds(['screen-view'])])
        ->assertCreated();
    expect(Role::where('name', 'Helper')->value('store_id'))->toBe($this->store->id);

    rolesAs($supervisor, $this->store)->postJson('/roles', ['name' => 'Uploader', 'permissions' => permissionIds(['media-store'])])
        ->assertStatus(422)->assertJsonValidationErrors('permissions');

    rolesAs($this->owner, $this->store)->postJson('/roles', ['name' => 'Snoop', 'permissions' => permissionIds(['activity-view', 'screen-view'])])
        ->assertStatus(422)->assertJsonValidationErrors('permissions');

    expect(Role::whereIn('name', ['Uploader', 'Snoop'])->exists())->toBeFalse();
});

test('a name has to be new to every list the role appears in', function () {
    // Inside a store: nothing its list already has — the store roles are in every store's list.
    foreach (['Owner', 'admin', ' STAFF ', 'Viewer'] as $name) {
        rolesAs($this->owner, $this->store)->postJson('/roles', ['name' => $name, 'permissions' => permissionIds(['screen-view'])])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    Role::create(['name' => 'Cashier', 'store_id' => $this->store->id]);
    rolesAs($this->owner, $this->store)->postJson('/roles', ['name' => 'cashier', 'permissions' => permissionIds(['screen-view'])])
        ->assertStatus(422)->assertJsonValidationErrors(['name' => 'There is already a role called Cashier. Choose another name.']);

    // Above the stores, a store role may not take a name any store's custom role has. Asked while Alpha
    // Mart alone has one: with two, which store the message names would depend on row order.
    $superAdmin = createSuperAdmin();
    rolesAs($superAdmin)->postJson('/roles', ['name' => 'Cashier', 'type' => 'store', 'permissions' => permissionIds(['screen-view'])])
        ->assertStatus(422)->assertJsonValidationErrors(['name' => 'Alpha Mart already has a custom role called Cashier. Choose another name.']);

    // Another store may use the same custom name.
    $beta = Store::factory()->create();
    $betaOwner = createStoreMember($beta, Role::OWNER);
    rolesAs($betaOwner, $beta)->postJson('/roles', ['name' => 'Cashier', 'permissions' => permissionIds(['screen-view'])])->assertCreated();

    // A platform role only has to stand apart from the platform roles.
    rolesAs($superAdmin)->postJson('/roles', ['name' => 'Cashier', 'type' => 'platform', 'permissions' => permissionIds(['screen-view'])])->assertCreated();
});

/*
 * MySQL's utf8mb4_0900_ai_ci collation reads "Súper-Admin" as "Super-Admin". A look-alike that got
 * past the name check would have made every holder a super admin wherever the name was compared in
 * SQL — so the check folds accents and punctuation, and the anchor compares the exact name in PHP.
 */
test('a look-alike of a taken name is refused on create and on rename', function () {
    $role = Role::create(['name' => 'Cashier', 'store_id' => $this->store->id]);
    $role->permissions()->sync(permissionIds(['screen-view']));

    foreach (['Súper-Admin', 'SUPER ADMIN', 'super_admin'] as $name) {
        rolesAs($this->owner, $this->store)->postJson('/roles', ['name' => $name, 'permissions' => permissionIds(['screen-view'])])
            ->assertStatus(422)->assertJsonValidationErrors(['name' => 'That name belongs to the Super-Admin role. Choose another.']);

        rolesAs($this->owner, $this->store)->putJson("/roles/{$role->id}", ['name' => $name, 'permissions' => permissionIds(['screen-view'])])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    foreach (['Ówner', 'O-W-N-E-R', 'Ädmin'] as $name) {
        rolesAs($this->owner, $this->store)->postJson('/roles', ['name' => $name, 'permissions' => permissionIds(['screen-view'])])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    expect($role->fresh()->name)->toBe('Cashier');

    // Names that merely contain another role's word stay allowed.
    rolesAs($this->owner, $this->store)->postJson('/roles', ['name' => 'Store Admin Assistant', 'permissions' => permissionIds(['screen-view'])])
        ->assertCreated();
});

test('only the exact Super-Admin role makes a super admin — a look-alike on the platform row grants nothing', function () {
    $superAdmin = createSuperAdmin();
    $impostor = User::factory()->create();
    $impostor->stores()->attach(0, ['role_id' => Role::create(['name' => 'super-admin', 'is_global' => true])->id]);

    expect($impostor->isSuperAdmin())->toBeFalse()
        ->and($superAdmin->isSuperAdmin())->toBeTrue()
        ->and(User::primarySuperAdminId())->toBe($superAdmin->id)
        ->and(Role::find(Role::superAdminId())->name)->toBe('Super-Admin');

    $this->actingAs($impostor)->get('/permissions')->assertForbidden();
});

test('any member holding role-update manages the store’s roles — whoever made them', function () {
    $role = Role::create(['name' => 'Cashier', 'store_id' => $this->store->id, 'created_by' => $this->owner->id]);
    $role->permissions()->sync(permissionIds(['screen-view']));

    rolesAs($this->admin, $this->store)->putJson("/roles/{$role->id}", ['name' => 'Till', 'permissions' => permissionIds(['screen-view', 'media-view'])])
        ->assertOk();

    expect($role->fresh()->name)->toBe('Till')
        ->and(ActivityLog::where('action', 'role.updated')->exists())->toBeTrue();
});

test('another store’s roles, and roles above your reach, cannot be touched', function () {
    $foreign = Role::create(['name' => 'Foreign', 'store_id' => Store::factory()->create()->id]);
    rolesAs($this->owner, $this->store)->deleteJson("/roles/{$foreign->id}")->assertNotFound();

    $powerful = Role::create(['name' => 'Manager', 'store_id' => $this->store->id]);
    $powerful->permissions()->sync(permissionIds(['member-invite', 'screen-view']));
    $supervisor = createStoreUser($this->store, ['role-update', 'role-destroy', 'screen-view'], 'Supervisor');

    rolesAs($supervisor, $this->store)->putJson("/roles/{$powerful->id}", ['name' => 'Weakened', 'permissions' => permissionIds(['screen-view'])])
        ->assertForbidden();
    rolesAs($supervisor, $this->store)->deleteJson("/roles/{$powerful->id}")->assertForbidden();

    expect($powerful->fresh()->name)->toBe('Manager');
});

test('a role still held cannot be deleted; a store never changes the store roles', function () {
    $role = Role::create(['name' => 'Cashier', 'store_id' => $this->store->id]);
    $role->permissions()->sync(permissionIds(['screen-view']));
    User::factory()->create()->stores()->attach($this->store->id, ['role_id' => $role->id]);

    // Refused for that reason, before any password is asked for.
    rolesAs($this->owner, $this->store)->deleteJson("/roles/{$role->id}")
        ->assertStatus(422)->assertJsonPath('message', 'Please unassign Cashier from everyone first: 1 person still holds it.');
    rolesAs($this->owner, $this->store)->deleteJson('/roles/'.Role::starter(Role::STAFF)->id)
        ->assertForbidden()->assertJsonPath('message', 'Store roles are changed by the super admin, for every store at once.');
    rolesAs($this->owner, $this->store)->putJson('/roles/'.Role::starter(Role::VIEWER)->id, ['name' => 'Peeker', 'permissions' => permissionIds(['screen-view'])])
        ->assertForbidden();

    expect(Role::starter(Role::VIEWER)->name)->toBe('Viewer');
});

test('on the platform the super admin looks after the custom roles stores made — any store', function () {
    $superAdmin = createSuperAdmin();
    $beta = Store::factory()->create(['name' => 'Beta Deli']);
    $cashier = Role::create(['name' => 'Cashier', 'store_id' => $beta->id]);
    $cashier->permissions()->sync(permissionIds(['screen-view']));

    $row = collect(rolesAs($superAdmin)->getJson('/roles/data')->json('roles'))->firstWhere('id', $cashier->id);
    expect($row)->kind->toBe('custom')->store_name->toBe('Beta Deli')->can_edit->toBeTrue()->can_delete->toBeTrue();

    rolesAs($superAdmin)->putJson("/roles/{$cashier->id}", ['name' => 'Till', 'permissions' => permissionIds(['screen-view', 'media-view'])])->assertOk();
    expect($cashier->fresh()->name)->toBe('Till')
        ->and(ActivityLog::where('action', 'role.updated')->value('description'))->toBe('Updated role Till (was Cashier) in Beta Deli, from the platform');

    rolesAs($superAdmin)->deleteJson("/roles/{$cashier->id}", ['password' => 'password'])->assertOk();
    expect(Role::find($cashier->id))->toBeNull()
        ->and(ActivityLog::where('action', 'role.deleted')->value('store_id'))->toBe($beta->id);
});

test('a platform role that is not Super-Admin has no roles page', function () {
    $support = createPlatformUser(['role-view', 'role-store']);

    rolesAs($support)->getJson('/roles/data')->assertForbidden();
});

test('the permissions offered for a role are the ones that can go on it from here', function () {
    // Inside a store: store permissions the actor holds.
    $supervisor = createStoreUser($this->store, ['role-view', 'screen-view', 'media-view'], 'Supervisor');
    $names = collect(rolesAs($supervisor, $this->store)->getJson('/roles/assignable')->assertOk()->json())->pluck('name')->sort()->values()->all();
    expect($names)->toBe(['media-view', 'role-view', 'screen-view']);

    // On the platform, a platform role: everything but the permission catalogue — which is not even listed.
    $admin = createSuperAdmin(['role-view']);
    $names = collect(rolesAs($admin)->getJson('/roles/assignable?type=platform')->assertOk()->json())->pluck('name');
    expect($names)->toContain('activity-view', 'channel-view', 'member-invite')->not->toContain('permission-view');
});

test('deleting a custom role revokes its pending invitations, and says so', function () {
    $role = Role::create(['name' => 'Cashier', 'store_id' => $this->store->id]);
    $role->permissions()->sync(permissionIds(['screen-view']));
    Invitation::open($this->store, 'one@example.com', $role, $this->owner);
    Invitation::open($this->store, 'two@example.com', $role, $this->owner);

    $listed = collect(rolesAs($this->owner, $this->store)->getJson('/roles/data')->json('roles'))->firstWhere('id', $role->id);
    expect($listed['invitations_count'])->toBe(2);

    rolesAs($this->owner, $this->store)->deleteJson("/roles/{$role->id}", ['password' => 'password'])
        ->assertOk()
        ->assertJsonPath('message', 'Role Cashier deleted. 2 pending invitations to it were revoked.');

    expect(Invitation::count())->toBe(0)
        ->and(ActivityLog::where('action', 'role.deleted')->value('description'))->toBe('Deleted role Cashier. 2 pending invitations to it were revoked.');
});
