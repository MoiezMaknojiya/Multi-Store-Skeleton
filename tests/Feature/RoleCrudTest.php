<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;

test('guests cannot access any role endpoint', function () {
    $this->getJson('/roles/data')->assertUnauthorized();
});

test('a super admin sees every role regardless of who created it', function () {
    $admin = createSuperAdmin(['role-view']);
    Role::create(['name' => 'Owned By Someone Else', 'created_by' => null]);

    $response = $this->actingAs($admin)->getJson('/roles/data');

    $response->assertOk();
    expect(collect($response->json('roles'))->pluck('name'))->toContain('Owned By Someone Else');
});

test('a non-super-admin only sees roles they created', function () {
    $store = Store::factory()->create();
    $viewer = createStoreUser($store, ['role-view']);
    $otherUser = User::factory()->create();

    $ownRole = Role::create(['name' => 'My Role', 'created_by' => $viewer->id, 'store_id' => $store->id]);
    Role::create(['name' => 'Someone Elses Role', 'created_by' => $otherUser->id, 'store_id' => $store->id]);

    $response = $this->actingAs($viewer)
        ->withSession(['current_store_id' => $store->id])
        ->getJson('/roles/data');

    $response->assertOk();
    $names = collect($response->json('roles'))->pluck('name');
    expect($names)->toContain('My Role');
    expect($names)->not->toContain('Someone Elses Role');
});

test('a user with role-store can create a role with permissions', function () {
    $permission = Permission::create(['name' => 'store-view']);
    $user = createSuperAdmin(['role-store']);

    $response = $this->actingAs($user)->postJson('/roles', [
        'name' => 'Manager',
        'permissions' => [$permission->id],
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('roles', ['name' => 'Manager']);
    $role = Role::where('name', 'Manager')->first();
    expect($role->permissions()->pluck('permissions.id'))->toContain($permission->id);
});

test('creating a role requires at least one permission', function () {
    $user = createSuperAdmin(['role-store']);

    $response = $this->actingAs($user)->postJson('/roles', [
        'name' => 'Empty Role',
        'permissions' => [],
    ]);

    $response->assertJsonValidationErrors('permissions');
});

test('the same owner can create several roles with the same name, each with its own permissions', function () {
    // A store owner with multiple stores wants a "Cashier" in each — one with
    // fewer permissions, another with more. Names are not unique; the id is the
    // identity, and everything (assignments, permissions) joins on the id.
    grantPermissions(['role-store', 'store-view', 'user-view']);
    $store = Store::factory()->create();
    $owner = createStoreUser($store, ['role-store', 'store-view', 'user-view'], 'Owner Role');
    $storeView = Permission::where('name', 'store-view')->value('id');
    $userView = Permission::where('name', 'user-view')->value('id');

    $this->actingAs($owner)->withSession(['current_store_id' => $store->id])
        ->postJson('/roles', ['name' => 'Cashier', 'permissions' => [$storeView]])->assertOk();
    $this->actingAs($owner)->withSession(['current_store_id' => $store->id])
        ->postJson('/roles', ['name' => 'Cashier', 'permissions' => [$storeView, $userView]])->assertOk();

    $cashiers = Role::where('name', 'Cashier')->where('created_by', $owner->id)->get();
    expect($cashiers)->toHaveCount(2);
    // Same name, DIFFERENT permission sets — distinct roles keyed by id.
    expect($cashiers[0]->permissions()->count())->toBe(1);
    expect($cashiers[1]->permissions()->count())->toBe(2);
});

test('the Super-Admin role name is reserved — nobody can create or rename to it, in any case variant', function () {
    $permission = Permission::create(['name' => 'store-view']);
    $admin = createSuperAdmin(['role-store', 'role-update']);

    // Creating a look-alike is blocked (case-insensitively — MySQL's collation
    // would otherwise treat 'super-admin' as the real anchor name).
    // Trailing space included: MySQL's PAD-SPACE collation treats 'Super-Admin ' as
    // equal to the anchor, so the guard must reject it too (it trims before compare).
    foreach (['Super-Admin', 'super-admin', 'SUPER-ADMIN', 'Super-Admin '] as $name) {
        $this->actingAs($admin)->postJson('/roles', [
            'name' => $name,
            'permissions' => [$permission->id],
        ])->assertJsonValidationErrors('name');
    }

    // Renaming an existing role to the reserved name is blocked too.
    $role = Role::create(['name' => 'Harmless', 'created_by' => $admin->id]);
    $this->actingAs($admin)->putJson("/roles/{$role->id}", [
        'name' => 'super-admin',
        'permissions' => [$permission->id],
    ])->assertJsonValidationErrors('name');

    expect(Role::where('id', '!=', Role::firstOrCreate(['name' => 'Super-Admin'])->id)
        ->get()->filter(fn ($r) => strcasecmp($r->name, 'Super-Admin') === 0)->count())->toBe(0);
});

test('a user with role-update can rename a role and resync its permissions', function () {
    $oldPermission = Permission::create(['name' => 'store-view']);
    $newPermission = Permission::create(['name' => 'store-update']);
    $role = Role::create(['name' => 'Manager']);
    $role->permissions()->sync([$oldPermission->id]);

    $user = createSuperAdmin(['role-update']);

    $response = $this->actingAs($user)->putJson("/roles/{$role->id}", [
        'name' => 'Senior Manager',
        'permissions' => [$newPermission->id],
    ]);

    $response->assertOk();
    $role->refresh();
    expect($role->name)->toBe('Senior Manager');
    expect($role->permissions()->pluck('permissions.id')->all())->toBe([$newPermission->id]);
});

test('a global-role user sees store roles from any creator but no global roles', function () {
    $admin = createSuperAdmin([]);
    Role::create(['name' => 'Store Owner', 'created_by' => $admin->id]);

    grantPermissions(['role-view']);
    $globalRole = Role::create(['name' => 'Global Admin', 'is_global' => true, 'created_by' => $admin->id]);
    $globalRole->permissions()->sync(Permission::where('name', 'role-view')->pluck('id'));
    $actor = User::factory()->create(['created_by' => $admin->id]);
    $actor->stores()->attach(0, ['role_id' => $globalRole->id]);

    $response = $this->actingAs($actor)->getJson('/roles/data');

    $response->assertOk();
    $names = collect($response->json('roles'))->pluck('name');
    expect($names)->toContain('Store Owner');
    expect($names)->not->toContain('Super-Admin');
    expect($names)->not->toContain('Global Admin');
});

test('a global-role user can assign a super-admin-created store role to their user', function () {
    $admin = createSuperAdmin([]);
    $store = Store::factory()->create();
    $storeOwnerRole = Role::create(['name' => 'Store Owner', 'created_by' => $admin->id]);

    grantPermissions(['user-store-assign']);
    $globalRole = Role::create(['name' => 'Global Admin', 'is_global' => true]);
    $globalRole->permissions()->sync(Permission::where('name', 'user-store-assign')->pluck('id'));
    $actor = User::factory()->create(['created_by' => $admin->id]);
    $actor->stores()->attach(0, ['role_id' => $globalRole->id]);

    $target = User::factory()->create(['created_by' => $actor->id]);

    $response = $this->actingAs($actor)->postJson("/users/{$target->id}/stores", [
        'store_id' => $store->id,
        'role_id' => $storeOwnerRole->id,
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('store_user', [
        'user_id' => $target->id,
        'store_id' => $store->id,
        'role_id' => $storeOwnerRole->id,
    ]);
});

test('a global-role user cannot modify a role they can see but did not create', function () {
    $admin = createSuperAdmin([]);
    $storeOwnerRole = Role::create(['name' => 'Store Owner', 'created_by' => $admin->id]);

    $perms = grantPermissions(['role-view', 'role-update', 'role-destroy', 'store-view']);
    $globalRole = Role::create(['name' => 'Global Admin', 'is_global' => true]);
    $globalRole->permissions()->sync($perms->pluck('id'));
    $actor = User::factory()->create(['created_by' => $admin->id]);
    $actor->stores()->attach(0, ['role_id' => $globalRole->id]);

    $storeView = Permission::where('name', 'store-view')->first();

    $this->actingAs($actor)->putJson("/roles/{$storeOwnerRole->id}", [
        'name' => 'Hijacked',
        'permissions' => [$storeView->id],
    ])->assertForbidden();

    $this->actingAs($actor)->deleteJson("/roles/{$storeOwnerRole->id}")->assertForbidden();

    $this->assertDatabaseHas('roles', ['id' => $storeOwnerRole->id, 'name' => 'Store Owner']);
});

test('a super admin can create a global role', function () {
    $admin = createSuperAdmin(['role-store']);
    $permission = Permission::firstOrCreate(['name' => 'user-view']);

    $response = $this->actingAs($admin)->postJson('/roles', [
        'name' => 'Global Admin',
        'permissions' => [$permission->id],
        'is_global' => true,
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('roles', ['name' => 'Global Admin', 'is_global' => true]);
});

test('a non-super-admin cannot create a global role even by sending the flag', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['role-store', 'store-view']);
    $held = Permission::where('name', 'store-view')->first();

    $response = $this->actingAs($actor)
        ->withSession(['current_store_id' => $store->id])
        ->postJson('/roles', [
            'name' => 'Sneaky Global',
            'permissions' => [$held->id],
            'is_global' => true,
        ]);

    $response->assertOk();
    $this->assertDatabaseHas('roles', ['name' => 'Sneaky Global', 'is_global' => false]);
});

test('changing where a role applies is blocked while it is assigned to users', function () {
    $admin = createSuperAdmin(['role-update']);
    $permission = Permission::firstOrCreate(['name' => 'user-view']);
    $role = Role::create(['name' => 'Global Admin', 'is_global' => true, 'created_by' => $admin->id]);
    $holder = User::factory()->create();
    $holder->stores()->attach(0, ['role_id' => $role->id]);

    $response = $this->actingAs($admin)->putJson("/roles/{$role->id}", [
        'name' => 'Global Admin',
        'permissions' => [$permission->id],
        'is_global' => false,
    ]);

    $response->assertStatus(422);
    $this->assertDatabaseHas('roles', ['id' => $role->id, 'is_global' => true]);
});

test('a user cannot create a role with permissions they do not hold themselves', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['role-store']);
    $forbidden = Permission::firstOrCreate(['name' => 'user-destroy']);

    $response = $this->actingAs($actor)
        ->withSession(['current_store_id' => $store->id])
        ->postJson('/roles', [
            'name' => 'Sneaky Role',
            'permissions' => [$forbidden->id],
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['permissions']);
    $this->assertDatabaseMissing('roles', ['name' => 'Sneaky Role']);
});

test('a user can create a role with permissions they do hold', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['role-store', 'store-view']);
    $held = Permission::where('name', 'store-view')->first();

    $response = $this->actingAs($actor)
        ->withSession(['current_store_id' => $store->id])
        ->postJson('/roles', [
            'name' => 'Viewer Role',
            'permissions' => [$held->id],
        ]);

    $response->assertOk();
    $this->assertDatabaseHas('roles', ['name' => 'Viewer Role', 'created_by' => $actor->id]);
});

test('roles are isolated per store: a role made in one store is invisible and unchangeable from another', function () {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();

    // One owner working in BOTH stores, with role rights in each.
    $actor = createStoreUser($storeA, ['role-view', 'role-store', 'role-update', 'role-destroy'], 'Owner A');
    $roleB = Role::create(['name' => 'Owner B', 'created_by' => $actor->id, 'store_id' => $storeB->id]);
    $roleB->permissions()->sync(Permission::whereIn('name', ['role-view', 'role-store', 'role-update', 'role-destroy'])->pluck('id'));
    $actor->stores()->attach($storeB->id, ['role_id' => $roleB->id]);

    // A role created while working in store A belongs to store A.
    $alphaRole = Role::create(['name' => 'Cashier', 'created_by' => $actor->id, 'store_id' => $storeA->id]);

    // In A's context it is listed and editable.
    $namesInA = collect($this->actingAs($actor)->withSession(['current_store_id' => $storeA->id])
        ->getJson('/roles/data')->assertOk()->json('roles'))->pluck('name');
    expect($namesInA)->toContain('Cashier');

    // In B's context it is NOT listed…
    $this->flushSession();
    $namesInB = collect($this->actingAs($actor)->withSession(['current_store_id' => $storeB->id])
        ->getJson('/roles/data')->assertOk()->json('roles'))->pluck('name');
    expect($namesInB)->not->toContain('Cashier');

    // …and cannot be read, edited or deleted from there.
    $this->actingAs($actor)->withSession(['current_store_id' => $storeB->id])
        ->getJson("/roles/{$alphaRole->id}/permissions")->assertNotFound();

    $this->actingAs($actor)->withSession(['current_store_id' => $storeB->id])
        ->putJson("/roles/{$alphaRole->id}", ['name' => 'Hacked', 'permissions' => [Permission::firstOrCreate(['name' => 'role-view'])->id]])
        ->assertNotFound();

    $this->actingAs($actor)->withSession(['current_store_id' => $storeB->id])
        ->deleteJson("/roles/{$alphaRole->id}")->assertNotFound();

    $this->assertDatabaseHas('roles', ['id' => $alphaRole->id, 'name' => 'Cashier']);
});

test('a role created while working in a store is stamped with that store', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['role-store', 'store-view']);

    $this->actingAs($actor)
        ->withSession(['current_store_id' => $store->id])
        ->postJson('/roles', [
            'name' => 'Cashier',
            'permissions' => [Permission::firstOrCreate(['name' => 'store-view'])->id],
        ])->assertOk();

    $this->assertDatabaseHas('roles', ['name' => 'Cashier', 'created_by' => $actor->id, 'store_id' => $store->id]);
});

test('a store-scoped role cannot be assigned in a different store', function () {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    $admin = createSuperAdmin(['user-store-assign']);

    $alphaRole = Role::create(['name' => 'Alpha Cashier', 'created_by' => $admin->id, 'store_id' => $storeA->id]);
    $target = User::factory()->create(['created_by' => $admin->id]);

    // Even a super admin cannot place store A's role inside store B.
    $this->actingAs($admin)->postJson("/users/{$target->id}/stores", [
        'role_id' => $alphaRole->id,
        'store_id' => $storeB->id,
    ])->assertStatus(422);

    $this->assertDatabaseMissing('store_user', ['user_id' => $target->id, 'store_id' => $storeB->id]);
});

test('flipping a role to global clears the store it was built in', function () {
    $store = Store::factory()->create();
    $admin = createSuperAdmin(['role-update']);
    $storeRole = Role::create(['name' => 'Cashier', 'created_by' => $admin->id, 'store_id' => $store->id]);

    // A global role spans every store, so it must not stay pinned to one.
    $this->actingAs($admin)->putJson("/roles/{$storeRole->id}", [
        'name' => 'Cashier',
        'permissions' => [Permission::firstOrCreate(['name' => 'store-view'])->id],
        'is_global' => true,
    ])->assertOk();

    $this->assertDatabaseHas('roles', ['id' => $storeRole->id, 'is_global' => true, 'store_id' => null]);
});

test('a store-scoped role cannot be made the signup default', function () {
    $store = Store::factory()->create();
    $admin = createSuperAdmin(['role-update']);
    $storeRole = Role::create(['name' => 'Cashier', 'created_by' => $admin->id, 'store_id' => $store->id]);

    // Signup creates a brand-new store, so its role must belong to none. Like the
    // other flag guards this is not an error — the flag is silently forced false.
    $this->actingAs($admin)->putJson("/roles/{$storeRole->id}", [
        'name' => 'Cashier',
        'permissions' => [Permission::firstOrCreate(['name' => 'store-view'])->id],
        'is_signup_default' => true,
    ])->assertOk();

    $this->assertDatabaseHas('roles', ['id' => $storeRole->id, 'is_signup_default' => false]);
});

test('a user cannot sneak extra permissions into their own role via update', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['role-update']);
    $forbidden = Permission::firstOrCreate(['name' => 'permission-destroy']);
    $ownRole = Role::create(['name' => 'My Team Role', 'created_by' => $actor->id, 'store_id' => $store->id]);

    $response = $this->actingAs($actor)
        ->withSession(['current_store_id' => $store->id])
        ->putJson("/roles/{$ownRole->id}", [
            'name' => 'My Team Role',
            'permissions' => [$forbidden->id],
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['permissions']);
    expect($ownRole->fresh()->permissions()->pluck('name'))->not->toContain('permission-destroy');
});

test('only one role can be the signup default — setting a new one clears the old one', function () {
    $admin = createSuperAdmin(['role-store']);
    $permission = Permission::firstOrCreate(['name' => 'user-view']);
    $old = Role::create(['name' => 'Old Default', 'is_signup_default' => true, 'created_by' => $admin->id]);

    $this->actingAs($admin)->postJson('/roles', [
        'name' => 'New Default',
        'permissions' => [$permission->id],
        'is_signup_default' => true,
    ])->assertOk();

    $this->assertDatabaseHas('roles', ['name' => 'New Default', 'is_signup_default' => true]);
    $this->assertDatabaseHas('roles', ['id' => $old->id, 'is_signup_default' => false]);
});

test('a non-super-admin cannot mark a role as the signup default', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['role-store', 'store-view']);
    $held = Permission::where('name', 'store-view')->first();

    $this->actingAs($actor)
        ->withSession(['current_store_id' => $store->id])
        ->postJson('/roles', [
            'name' => 'Sneaky Default',
            'permissions' => [$held->id],
            'is_signup_default' => true,
        ])->assertOk();

    $this->assertDatabaseHas('roles', ['name' => 'Sneaky Default', 'is_signup_default' => false]);
});

test('the Super-Admin role cannot be renamed — it anchors the authorization system', function () {
    $admin = createSuperAdmin(['role-update']);
    $superAdminRole = Role::where('name', 'Super-Admin')->firstOrFail();
    $permission = Permission::firstOrCreate(['name' => 'user-view']);

    $response = $this->actingAs($admin)->putJson("/roles/{$superAdminRole->id}", [
        'name' => 'Boss',
        'permissions' => [$permission->id],
    ]);

    $response->assertStatus(422);
    $this->assertDatabaseHas('roles', ['id' => $superAdminRole->id, 'name' => 'Super-Admin']);
});

test('a user cannot update a role they did not create', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['role-update']);
    $othersRole = Role::create(['name' => 'Someone Elses Role']);
    $permission = Permission::firstOrCreate(['name' => 'store-view']);

    $response = $this->actingAs($actor)
        ->withSession(['current_store_id' => $store->id])
        ->putJson("/roles/{$othersRole->id}", [
            'name' => 'Hijacked Role',
            'permissions' => [$permission->id],
        ]);

    $response->assertNotFound();
    $this->assertDatabaseHas('roles', ['id' => $othersRole->id, 'name' => 'Someone Elses Role']);
});

test('a user cannot delete a role they did not create', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['role-destroy']);
    $othersRole = Role::create(['name' => 'Someone Elses Role']);

    $response = $this->actingAs($actor)
        ->withSession(['current_store_id' => $store->id])
        ->deleteJson("/roles/{$othersRole->id}");

    $response->assertNotFound();
    $this->assertDatabaseHas('roles', ['id' => $othersRole->id]);
});

test('a user with role-destroy can delete a role', function () {
    $role = Role::create(['name' => 'Temp Role']);
    $user = createSuperAdmin(['role-destroy']);

    $response = $this->actingAs($user)->deleteJson("/roles/{$role->id}");

    $response->assertOk();
    $this->assertDatabaseMissing('roles', ['id' => $role->id]);
});

test('a role currently assigned to a user cannot be deleted', function () {
    $store = Store::factory()->create();
    $role = Role::create(['name' => 'Cashier']);
    $assignedUser = User::factory()->create();
    $assignedUser->stores()->attach($store->id, ['role_id' => $role->id]);

    $admin = createSuperAdmin(['role-destroy']);

    $response = $this->actingAs($admin)->deleteJson("/roles/{$role->id}");

    $response->assertStatus(422);
    $response->assertJsonPath('message', 'This role is assigned to one or more users and cannot be deleted. Reassign or remove those users first.');
    $this->assertDatabaseHas('roles', ['id' => $role->id]);
});

test('a role can be deleted once no user is assigned to it anymore', function () {
    $store = Store::factory()->create();
    $role = Role::create(['name' => 'Cashier']);
    $assignedUser = User::factory()->create();
    $assignedUser->stores()->attach($store->id, ['role_id' => $role->id]);
    $assignedUser->stores()->detach($store->id);

    $admin = createSuperAdmin(['role-destroy']);

    $response = $this->actingAs($admin)->deleteJson("/roles/{$role->id}");

    $response->assertOk();
    $this->assertDatabaseMissing('roles', ['id' => $role->id]);
});

test('deleting the Super-Admin role is blocked while any admin holds it, even via the store_id = 0 sentinel', function () {
    $admin = createSuperAdmin(['role-destroy']);
    $superAdminRole = Role::where('name', 'Super-Admin')->first();

    $response = $this->actingAs($admin)->deleteJson("/roles/{$superAdminRole->id}");

    $response->assertStatus(422);
    $this->assertDatabaseHas('roles', ['id' => $superAdminRole->id]);
});

test('a user without role-destroy cannot delete a role', function () {
    $role = Role::create(['name' => 'Temp Role']);
    $user = createSuperAdmin([]);

    $this->actingAs($user)->deleteJson("/roles/{$role->id}")->assertForbidden();
    $this->assertDatabaseHas('roles', ['id' => $role->id]);
});

test('assignable permissions only include the permissions the current role holds', function () {
    $allowed = Permission::create(['name' => 'store-view']);
    $notAllowed = Permission::create(['name' => 'store-destroy']);
    $store = Store::factory()->create();
    $user = createStoreUser($store, ['store-view', 'role-view'], 'Limited Role');

    $response = $this->actingAs($user)
        ->withSession(['current_store_id' => $store->id])
        ->getJson('/roles/assignable');

    $response->assertOk();
    $names = collect($response->json())->pluck('name');
    expect($names)->toContain('store-view');
    expect($names)->not->toContain('store-destroy');
});

test('a super admin can assign every permission', function () {
    Permission::create(['name' => 'store-view']);
    Permission::create(['name' => 'store-destroy']);
    $admin = createSuperAdmin(['role-view']);

    $response = $this->actingAs($admin)->getJson('/roles/assignable');

    $response->assertOk();
    expect(collect($response->json())->pluck('name'))->toContain('store-view', 'store-destroy');
});

test('reading a role\'s permissions is scoped — a role you cannot see returns 404 (no IDOR)', function () {
    $admin = createSuperAdmin([]);
    $foreignRole = Role::create(['name' => 'Foreign Role', 'created_by' => $admin->id]);
    $foreignRole->permissions()->sync(Permission::create(['name' => 'store-view'])->id);

    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['role-view']);

    // The store user holds role-view but did not create $foreignRole → not visible → 404.
    $this->actingAs($actor)
        ->withSession(['current_store_id' => $store->id])
        ->getJson("/roles/{$foreignRole->id}/permissions")
        ->assertNotFound();
});

test('a user with role-view can list the permissions assigned to a role', function () {
    $permission = Permission::create(['name' => 'store-view', 'label' => 'View Stores']);
    $role = Role::create(['name' => 'Manager']);
    $role->permissions()->sync([$permission->id]);

    $user = createSuperAdmin(['role-view']);

    $response = $this->actingAs($user)->getJson("/roles/{$role->id}/permissions");

    $response->assertOk();
    $response->assertJsonFragment(['id' => $permission->id, 'name' => 'store-view', 'label' => 'View Stores']);
});

test('a user without role-view cannot list the permissions assigned to a role', function () {
    $role = Role::create(['name' => 'Manager']);
    $user = createSuperAdmin([]);

    $this->actingAs($user)->getJson("/roles/{$role->id}/permissions")->assertForbidden();
});
