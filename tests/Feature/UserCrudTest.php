<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

test('guests cannot access any user endpoint', function () {
    $this->getJson('/users/data')->assertUnauthorized();
});

test('the users listing runs a bounded number of queries no matter how many rows', function () {
    $admin = createSuperAdmin(['user-view']);
    User::factory()->count(30)->create();

    DB::enableQueryLog();
    $this->actingAs($admin)->getJson('/users/data?per_page=100')->assertOk();
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Before the batching fix this was ~2 queries per row (60+); now it is a
    // small constant. The margin covers session/auth/gate boot queries.
    expect($queryCount)->toBeLessThan(15);
});

test('the users list never includes the viewer themselves', function () {
    $admin = createSuperAdmin(['user-view']);
    User::factory()->create();

    $response = $this->actingAs($admin)->getJson('/users/data');

    $response->assertOk();
    expect(collect($response->json('users'))->pluck('id'))->not->toContain($admin->id);
});

test('a super admin only sees other super admins that they themselves created', function () {
    $admin = createSuperAdmin(['user-view']);
    $superAdminRole = Role::where('name', 'Super-Admin')->first();

    // Super admin promoted BY the viewer — should be visible to them.
    $promoted = User::factory()->create(['created_by' => $admin->id]);
    $promoted->stores()->attach(0, ['role_id' => $superAdminRole->id]);

    // Unrelated super admin — should be hidden from the viewer.
    $unrelated = createSuperAdmin([]);

    // Regular user — always visible to a super admin.
    $regular = User::factory()->create();

    $response = $this->actingAs($admin)->getJson('/users/data');

    $response->assertOk();
    $ids = collect($response->json('users'))->pluck('id');
    expect($ids)->toContain($promoted->id);
    expect($ids)->toContain($regular->id);
    expect($ids)->not->toContain($unrelated->id);

    // And the promoted super admin cannot see their promoter in the listing.
    $promotedIds = collect($this->actingAs($promoted)->getJson('/users/data')->json('users'))->pluck('id');
    expect($promotedIds)->not->toContain($admin->id);
    expect($promotedIds)->toContain($regular->id);
});

test('a non-super-admin only sees users they created, in every store they work in', function () {
    $store = Store::factory()->create();
    $viewer = createStoreUser($store, ['user-view']);
    $otherCreator = User::factory()->create();

    $ownUser = User::factory()->create(['created_by' => $viewer->id]);
    User::factory()->create(['created_by' => $otherCreator->id]);

    $response = $this->actingAs($viewer)
        ->withSession(['current_store_id' => $store->id])
        ->getJson('/users/data');

    $response->assertOk();
    $ids = collect($response->json('users'))->pluck('id');
    expect($ids)->toContain($ownUser->id);
    expect($ids->count())->toBe(1);
});

test('a store user staffs their own second store — their people follow them, the role does not', function () {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();

    // One person working in BOTH stores, with assign rights in each.
    $actor = createStoreUser($storeA, ['user-view', 'user-store-assign'], 'Owner A');
    $roleInB = Role::create(['name' => 'Manager B', 'created_by' => $actor->id, 'store_id' => $storeB->id]);
    $roleInB->permissions()->sync(Permission::whereIn('name', ['user-view', 'user-store-assign'])->pluck('id'));
    $actor->stores()->attach($storeB->id, ['role_id' => $roleInB->id]);

    $roleInA = Role::create(['name' => 'Cashier A', 'created_by' => $actor->id, 'store_id' => $storeA->id]);
    $child = User::factory()->create(['created_by' => $actor->id]);
    $child->stores()->attach($storeA->id, ['role_id' => $roleInA->id]);

    // From inside B the actor still sees their own person — people are not
    // store-scoped, so nobody has to go to an admin to staff their other store.
    $ids = collect($this->actingAs($actor)->withSession(['current_store_id' => $storeB->id])
        ->getJson('/users/data')->assertOk()->json('users'))->pluck('id');
    expect($ids)->toContain($child->id);

    // …and can place them in B with B's OWN role.
    $this->flushSession();
    $this->actingAs($actor)->withSession(['current_store_id' => $storeB->id])
        ->postJson("/users/{$child->id}/stores", ['role_id' => $roleInB->id, 'store_id' => $storeB->id])
        ->assertOk();

    // But store A's role is invisible from B, so it can never be used there.
    $this->flushSession();
    $this->actingAs($actor)->withSession(['current_store_id' => $storeB->id])
        ->postJson("/users/{$child->id}/stores", ['role_id' => $roleInA->id, 'store_id' => $storeB->id])
        ->assertNotFound();
});

test('a user with user-store can create a new user', function () {
    $admin = createSuperAdmin(['user-store']);

    $response = $this->actingAs($admin)->postJson('/users', [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'phone' => '1234567890',
        'email' => 'jane@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('users', ['email' => 'jane@example.com', 'created_by' => $admin->id]);
});

test('creating a user rejects an email with an embedded space', function () {
    $admin = createSuperAdmin(['user-store']);

    $response = $this->actingAs($admin)->postJson('/users', [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'phone' => '1234567890',
        'email' => 'jane doe@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertJsonValidationErrors('email');
});

test('creating a user rejects a quoted-local-part email containing a space (RFC-valid but not deliverable)', function () {
    $admin = createSuperAdmin(['user-store']);

    $response = $this->actingAs($admin)->postJson('/users', [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'phone' => '1234567890',
        'email' => '"jane doe"@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertJsonValidationErrors('email');
    $response->assertJsonFragment(['email' => ['Email cannot contain spaces.']]);
});

test('creating a user validates phone as exactly 10 digits', function () {
    $admin = createSuperAdmin(['user-store']);

    $response = $this->actingAs($admin)->postJson('/users', [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'phone' => '123',
        'email' => 'jane@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertJsonValidationErrors('phone');
});

test('creating a user requires a unique email', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $admin = createSuperAdmin(['user-store']);

    $response = $this->actingAs($admin)->postJson('/users', [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'phone' => '1234567890',
        'email' => 'taken@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertJsonValidationErrors('email');
});

test('creating a user requires a password', function () {
    $admin = createSuperAdmin(['user-store']);

    $response = $this->actingAs($admin)->postJson('/users', [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'phone' => '1234567890',
        'email' => 'jane@example.com',
    ]);

    $response->assertJsonValidationErrors('password');
});

test('creating a user requires a password of at least 8 characters', function () {
    $admin = createSuperAdmin(['user-store']);

    $response = $this->actingAs($admin)->postJson('/users', [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'phone' => '1234567890',
        'email' => 'jane@example.com',
        'password' => 'short',
        'password_confirmation' => 'short',
    ]);

    $response->assertJsonValidationErrors('password');
});

test('creating a user rejects a mismatched password confirmation', function () {
    $admin = createSuperAdmin(['user-store']);

    $response = $this->actingAs($admin)->postJson('/users', [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'phone' => '1234567890',
        'email' => 'jane@example.com',
        'password' => 'password123',
        'password_confirmation' => 'different123',
    ]);

    $response->assertJsonValidationErrors('password');
});

test('a user with user-update can change another users password', function () {
    $admin = createSuperAdmin(['user-update']);
    $target = User::factory()->create();
    $originalPassword = $target->password;

    $response = $this->actingAs($admin)->putJson("/users/{$target->id}", [
        'first_name' => $target->first_name,
        'last_name' => $target->last_name,
        'phone' => $target->phone,
        'email' => $target->email,
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ]);

    $response->assertOk();
    $target->refresh();
    expect($target->password)->not->toBe($originalPassword);
    expect(Hash::check('newpassword123', $target->password))->toBeTrue();
});

test('a user with user-update can update another user without touching their password', function () {
    $admin = createSuperAdmin(['user-update']);
    $target = User::factory()->create(['first_name' => 'Old']);
    $originalPassword = $target->password;

    $response = $this->actingAs($admin)->putJson("/users/{$target->id}", [
        'first_name' => 'New',
        'last_name' => $target->last_name,
        'phone' => $target->phone,
        'email' => $target->email,
    ]);

    $response->assertOk();
    $target->refresh();
    expect($target->first_name)->toBe('New');
    expect($target->password)->toBe($originalPassword);
});

test('a user cannot edit their own account through this endpoint', function () {
    $admin = createSuperAdmin(['user-update']);

    $response = $this->actingAs($admin)->putJson("/users/{$admin->id}", [
        'first_name' => 'New',
        'last_name' => $admin->last_name,
        'phone' => $admin->phone,
        'email' => $admin->email,
    ]);

    $response->assertForbidden();
});

test('a user with user-destroy can delete another user', function () {
    $admin = createSuperAdmin(['user-destroy']);
    $target = User::factory()->create();

    $response = $this->actingAs($admin)->deleteJson("/users/{$target->id}");

    $response->assertOk();
    $this->assertDatabaseMissing('users', ['id' => $target->id]);
});

test('a user cannot delete their own account through this endpoint', function () {
    $admin = createSuperAdmin(['user-destroy']);

    $response = $this->actingAs($admin)->deleteJson("/users/{$admin->id}");

    $response->assertForbidden();
    $this->assertDatabaseHas('users', ['id' => $admin->id]);
});

test('a user with user-store-assign can assign another user to a store with a role', function () {
    $admin = createSuperAdmin(['user-store-assign']);
    $target = User::factory()->create();
    $store = Store::factory()->create();
    $role = Role::create(['name' => 'Cashier']);

    $response = $this->actingAs($admin)->postJson("/users/{$target->id}/stores", [
        'store_id' => $store->id,
        'role_id' => $role->id,
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('store_user', ['user_id' => $target->id, 'store_id' => $store->id, 'role_id' => $role->id]);
});

test('assigning a user to a store they are already assigned to fails', function () {
    $admin = createSuperAdmin(['user-store-assign']);
    $store = Store::factory()->create();
    $role = Role::create(['name' => 'Cashier']);
    $target = createStoreUser($store, [], 'Existing Role');

    $response = $this->actingAs($admin)->postJson("/users/{$target->id}/stores", [
        'store_id' => $store->id,
        'role_id' => $role->id,
    ]);

    $response->assertStatus(422);
});

test('assigning the Super-Admin role needs no store and lands on the global sentinel row', function () {
    $admin = createSuperAdmin(['user-store-assign']);
    $superAdminRole = Role::where('name', 'Super-Admin')->first();
    $target = User::factory()->create();

    $response = $this->actingAs($admin)->postJson("/users/{$target->id}/stores", [
        'role_id' => $superAdminRole->id,
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('store_user', [
        'user_id' => $target->id,
        'store_id' => 0,
        'role_id' => $superAdminRole->id,
    ]);
    expect($target->isSuperAdmin())->toBeTrue();
});

test('assigning any non-Super-Admin role without a store is rejected', function () {
    $admin = createSuperAdmin(['user-store-assign']);
    $role = Role::create(['name' => 'Cashier']);
    $target = User::factory()->create();

    $response = $this->actingAs($admin)->postJson("/users/{$target->id}/stores", [
        'role_id' => $role->id,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['store_id']);
    $this->assertDatabaseMissing('store_user', ['user_id' => $target->id, 'role_id' => $role->id]);
});

test('assigning the Super-Admin role ignores any store that was sent along', function () {
    $admin = createSuperAdmin(['user-store-assign']);
    $superAdminRole = Role::where('name', 'Super-Admin')->first();
    $store = Store::factory()->create();
    $target = User::factory()->create();

    $response = $this->actingAs($admin)->postJson("/users/{$target->id}/stores", [
        'store_id' => $store->id,
        'role_id' => $superAdminRole->id,
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('store_user', ['user_id' => $target->id, 'store_id' => 0]);
    $this->assertDatabaseMissing('store_user', ['user_id' => $target->id, 'store_id' => $store->id]);
});

test('a super admin cannot be assigned to a store', function () {
    $admin = createSuperAdmin(['user-store-assign']);
    $store = Store::factory()->create();
    $role = Role::create(['name' => 'Cashier']);
    $superAdminRole = Role::where('name', 'Super-Admin')->first();
    $otherSuperAdmin = User::factory()->create(['created_by' => $admin->id]);
    $otherSuperAdmin->stores()->attach(0, ['role_id' => $superAdminRole->id]);

    $response = $this->actingAs($admin)->postJson("/users/{$otherSuperAdmin->id}/stores", [
        'store_id' => $store->id,
        'role_id' => $role->id,
    ]);

    $response->assertStatus(422);
});

test('the assign option lists load with only the user-store-assign permission', function () {
    $store = Store::factory()->create(['name' => 'Option Store']);
    $admin = createSuperAdmin(['user-store-assign']);
    Role::create(['name' => 'Cashier', 'created_by' => $admin->id]);

    $stores = $this->actingAs($admin)->getJson('/users/assignable-stores');
    $stores->assertOk();
    expect(collect($stores->json('stores'))->pluck('name'))->toContain('Option Store');

    $roles = $this->actingAs($admin)->getJson('/users/assignable-roles');
    $roles->assertOk();
    expect(collect($roles->json('roles'))->pluck('name'))->toContain('Cashier');
});

test('the assign option lists are forbidden without user-store-assign', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['user-view']);

    $this->actingAs($actor)
        ->withSession(['current_store_id' => $store->id])
        ->getJson('/users/assignable-stores')->assertForbidden();

    $this->actingAs($actor)
        ->withSession(['current_store_id' => $store->id])
        ->getJson('/users/assignable-roles')->assertForbidden();
});

test('assignable stores are scoped: store users get their own, global users get all', function () {
    $storeA = Store::factory()->create(['name' => 'Mine']);
    $storeB = Store::factory()->create(['name' => 'Not Mine']);
    $actor = createStoreUser($storeA, ['user-store-assign']);

    $response = $this->actingAs($actor)
        ->withSession(['current_store_id' => $storeA->id])
        ->getJson('/users/assignable-stores');

    $response->assertOk();
    $names = collect($response->json('stores'))->pluck('name');
    expect($names)->toContain('Mine');
    expect($names)->not->toContain('Not Mine');
});

test('a non-super-admin can assign their own user within their own store', function () {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    $actor = createStoreUser($storeA, ['user-store-assign']);
    $role = Role::create(['name' => 'Cashier', 'created_by' => $actor->id, 'store_id' => $storeA->id]);
    $target = User::factory()->create(['created_by' => $actor->id]);

    $response = $this->actingAs($actor)
        ->withSession(['current_store_id' => $storeA->id])
        ->postJson("/users/{$target->id}/stores", [
            'store_id' => $storeA->id,
            'role_id' => $role->id,
        ]);

    $response->assertOk();
    $this->assertDatabaseHas('store_user', ['user_id' => $target->id, 'store_id' => $storeA->id]);
});

test('a non-super-admin cannot assign a user to a store they do not belong to', function () {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    $actor = createStoreUser($storeA, ['user-store-assign']);
    $role = Role::create(['name' => 'Cashier', 'created_by' => $actor->id, 'store_id' => $storeA->id]);
    $target = User::factory()->create(['created_by' => $actor->id]);

    $response = $this->actingAs($actor)
        ->withSession(['current_store_id' => $storeA->id])
        ->postJson("/users/{$target->id}/stores", [
            'store_id' => $storeB->id,
            'role_id' => $role->id,
        ]);

    $response->assertForbidden();
    $this->assertDatabaseMissing('store_user', ['user_id' => $target->id, 'store_id' => $storeB->id]);
});

test('assigning a custom global role needs no store and lands on the sentinel row', function () {
    $admin = createSuperAdmin(['user-store-assign']);
    $globalRole = Role::create(['name' => 'Global Admin', 'is_global' => true, 'created_by' => $admin->id]);
    $target = User::factory()->create(['created_by' => $admin->id]);

    $response = $this->actingAs($admin)->postJson("/users/{$target->id}/stores", [
        'role_id' => $globalRole->id,
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('store_user', [
        'user_id' => $target->id,
        'store_id' => 0,
        'role_id' => $globalRole->id,
    ]);
    // A custom global role does NOT make the holder a super admin.
    expect($target->isSuperAdmin())->toBeFalse();
});

test('a user with a global role has its permissions without selecting a store', function () {
    grantPermissions(['user-view']);
    $globalRole = Role::create(['name' => 'Global Admin', 'is_global' => true]);
    $globalRole->permissions()->sync([Permission::where('name', 'user-view')->value('id')]);
    $holder = User::factory()->create();
    $holder->stores()->attach(0, ['role_id' => $globalRole->id]);

    $this->actingAs($holder)->getJson('/users/data')->assertOk();
});

test('a non-super-admin cannot assign a custom global role', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['user-store-assign']);
    $globalRole = Role::create(['name' => 'Global Admin', 'is_global' => true, 'created_by' => $actor->id]);
    $target = User::factory()->create(['created_by' => $actor->id]);

    $response = $this->actingAs($actor)
        ->withSession(['current_store_id' => $store->id])
        ->postJson("/users/{$target->id}/stores", [
            'role_id' => $globalRole->id,
        ]);

    $response->assertForbidden();
    $this->assertDatabaseMissing('store_user', ['user_id' => $target->id, 'store_id' => 0]);
});

test('a global-role user can assign their user to any store', function () {
    $store = Store::factory()->create();
    grantPermissions(['user-store-assign']);
    $globalRole = Role::create(['name' => 'Global Admin', 'is_global' => true]);
    $globalRole->permissions()->sync(Permission::where('name', 'user-store-assign')->pluck('id'));
    $actor = User::factory()->create();
    $actor->stores()->attach(0, ['role_id' => $globalRole->id]);

    $storeRole = Role::create(['name' => 'Cashier', 'created_by' => $actor->id]);
    $target = User::factory()->create(['created_by' => $actor->id]);

    $response = $this->actingAs($actor)->postJson("/users/{$target->id}/stores", [
        'store_id' => $store->id,
        'role_id' => $storeRole->id,
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('store_user', ['user_id' => $target->id, 'store_id' => $store->id]);
});

test('a global-role user cannot hand out global roles despite sitting on the sentinel row', function () {
    grantPermissions(['user-store-assign']);
    $globalRole = Role::create(['name' => 'Global Admin', 'is_global' => true]);
    $globalRole->permissions()->sync(Permission::where('name', 'user-store-assign')->pluck('id'));
    $actor = User::factory()->create();
    $actor->stores()->attach(0, ['role_id' => $globalRole->id]);

    $target = User::factory()->create(['created_by' => $actor->id]);

    $response = $this->actingAs($actor)->postJson("/users/{$target->id}/stores", [
        'role_id' => $globalRole->id,
    ]);

    $response->assertForbidden();
    $this->assertDatabaseMissing('store_user', ['user_id' => $target->id, 'store_id' => 0]);
});

test('a global-role user cannot be assigned to a store — the tiers stay separate', function () {
    $admin = createSuperAdmin(['user-store-assign']);
    $store = Store::factory()->create();
    $storeRole = Role::create(['name' => 'Cashier', 'created_by' => $admin->id]);
    $globalRole = Role::create(['name' => 'Global Admin', 'is_global' => true, 'created_by' => $admin->id]);

    $globalUser = User::factory()->create(['created_by' => $admin->id]);
    $globalUser->stores()->attach(0, ['role_id' => $globalRole->id]);

    $response = $this->actingAs($admin)->postJson("/users/{$globalUser->id}/stores", [
        'store_id' => $store->id,
        'role_id' => $storeRole->id,
    ]);

    $response->assertStatus(422);
    $this->assertDatabaseMissing('store_user', ['user_id' => $globalUser->id, 'store_id' => $store->id]);
});

test('a store-assigned user cannot take a global role — the tiers stay separate', function () {
    $admin = createSuperAdmin(['user-store-assign']);
    $store = Store::factory()->create();
    $storeRole = Role::create(['name' => 'Cashier', 'created_by' => $admin->id]);
    $globalRole = Role::create(['name' => 'Global Admin', 'is_global' => true, 'created_by' => $admin->id]);

    $storeUser = User::factory()->create(['created_by' => $admin->id]);
    $storeUser->stores()->attach($store->id, ['role_id' => $storeRole->id]);

    $response = $this->actingAs($admin)->postJson("/users/{$storeUser->id}/stores", [
        'role_id' => $globalRole->id,
    ]);

    $response->assertStatus(422);
    $this->assertDatabaseMissing('store_user', ['user_id' => $storeUser->id, 'store_id' => 0]);
});

test('a store role invisible to the actor cannot be assigned even by raw id', function () {
    // The dropdown only OFFERS visible roles; this proves the backend enforces
    // the same rule when the id is posted directly.
    $admin = createSuperAdmin([]);
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['user-store-assign']);
    $foreignRole = Role::create(['name' => 'Foreign Powerful Role', 'created_by' => $admin->id]);
    $target = User::factory()->create(['created_by' => $actor->id]);

    $this->actingAs($actor)
        ->withSession(['current_store_id' => $store->id])
        ->postJson("/users/{$target->id}/stores", [
            'role_id' => $foreignRole->id,
            'store_id' => $store->id,
        ])->assertNotFound();

    $this->assertDatabaseMissing('store_user', ['user_id' => $target->id, 'store_id' => $store->id]);

    // A global user, by contrast, still CAN assign a super-admin-created store
    // role (they see all non-global roles) — the check must not over-tighten.
    grantPermissions(['user-store-assign']);
    $globalRole = Role::create(['name' => 'Global Assigner', 'is_global' => true]);
    $globalRole->permissions()->sync(Permission::where('name', 'user-store-assign')->pluck('id'));
    $globalActor = User::factory()->create();
    $globalActor->stores()->attach(0, ['role_id' => $globalRole->id]);
    $globalTarget = User::factory()->create(['created_by' => $globalActor->id]);

    $this->flushSession(); // the store context from the first half must not leak
    $this->actingAs($globalActor)->postJson("/users/{$globalTarget->id}/stores", [
        'role_id' => $foreignRole->id,
        'store_id' => $store->id,
    ])->assertOk();
});

test('assigning a user to a soft-deleted store is rejected (no phantom assignment)', function () {
    $admin = createSuperAdmin(['user-store-assign']);
    $store = Store::factory()->create();
    $role = Role::create(['name' => 'Cashier', 'created_by' => $admin->id]);
    $target = User::factory()->create(['created_by' => $admin->id]);

    $store->delete(); // soft-deleted — exists:stores,id still passes, so the controller must guard

    $this->actingAs($admin)->postJson("/users/{$target->id}/stores", [
        'role_id' => $role->id,
        'store_id' => $store->id,
    ])->assertStatus(422);

    $this->assertDatabaseMissing('store_user', ['user_id' => $target->id, 'store_id' => $store->id]);
});

test('a non-super-admin cannot hand out the Super-Admin role', function () {
    $storeA = Store::factory()->create();
    $actor = createStoreUser($storeA, ['user-store-assign']);
    $superAdminRole = Role::firstOrCreate(['name' => 'Super-Admin']);
    $target = User::factory()->create(['created_by' => $actor->id]);

    $response = $this->actingAs($actor)
        ->withSession(['current_store_id' => $storeA->id])
        ->postJson("/users/{$target->id}/stores", [
            'role_id' => $superAdminRole->id,
        ]);

    $response->assertForbidden();
    $this->assertDatabaseMissing('store_user', ['user_id' => $target->id, 'store_id' => 0]);
    expect($target->isSuperAdmin())->toBeFalse();
});

test('per-store isolation: a user in two stores cannot manage the other store\'s assignments from the wrong context', function () {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();

    // Actor belongs to BOTH stores and holds assign+unassign in each.
    $actor = createStoreUser($storeA, ['user-store-assign', 'user-store-unassign'], 'Manager A');
    $roleB = Role::create(['name' => 'Owner B', 'created_by' => $actor->id]);
    $roleB->permissions()->sync(Permission::whereIn('name', ['user-store-assign', 'user-store-unassign'])->pluck('id'));
    $actor->stores()->attach($storeB->id, ['role_id' => $roleB->id]);

    $roleA = Role::create(['name' => 'Cashier A', 'created_by' => $actor->id, 'store_id' => $storeA->id]);
    $target = User::factory()->create(['created_by' => $actor->id]);
    $target->stores()->attach($storeB->id, ['role_id' => $roleB->id]);

    // In A's context: removing the target's B assignment is refused.
    $this->actingAs($actor)->withSession(['current_store_id' => $storeA->id])
        ->deleteJson("/users/{$target->id}/stores/{$storeB->id}")
        ->assertForbidden();
    $this->assertDatabaseHas('store_user', ['user_id' => $target->id, 'store_id' => $storeB->id]);

    // In A's context: assigning INTO B is refused too.
    $other = User::factory()->create(['created_by' => $actor->id]);
    $this->actingAs($actor)->withSession(['current_store_id' => $storeA->id])
        ->postJson("/users/{$other->id}/stores", ['role_id' => $roleA->id, 'store_id' => $storeB->id])
        ->assertForbidden();
    $this->assertDatabaseMissing('store_user', ['user_id' => $other->id, 'store_id' => $storeB->id]);

    // Switching INTO B makes the same removal legitimate.
    $this->flushSession();
    $this->actingAs($actor)->withSession(['current_store_id' => $storeB->id])
        ->deleteJson("/users/{$target->id}/stores/{$storeB->id}")
        ->assertOk();
    $this->assertDatabaseMissing('store_user', ['user_id' => $target->id, 'store_id' => $storeB->id]);
});

test('a non-super-admin cannot remove a user from a store they do not belong to', function () {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    $actor = createStoreUser($storeA, ['user-store-unassign']);
    $role = Role::create(['name' => 'Cashier']);
    $target = User::factory()->create(['created_by' => $actor->id]);
    $target->stores()->attach($storeB->id, ['role_id' => $role->id]);

    $response = $this->actingAs($actor)
        ->withSession(['current_store_id' => $storeA->id])
        ->deleteJson("/users/{$target->id}/stores/{$storeB->id}");

    $response->assertForbidden();
    $this->assertDatabaseHas('store_user', ['user_id' => $target->id, 'store_id' => $storeB->id]);
});

test('deleting a user cascades to everything they created, recursively', function () {
    $admin = createSuperAdmin(['user-destroy']);

    // The owner and their whole world.
    $owner = User::factory()->create(['created_by' => $admin->id]);
    $manager = User::factory()->create(['created_by' => $owner->id]);
    $worker = User::factory()->create(['created_by' => $manager->id]);

    $ownStore = Store::factory()->create(['name' => 'Owner Mart', 'created_by' => $owner->id]);
    $unassignedRole = Role::create(['name' => 'Cleaner', 'created_by' => $owner->id]);
    $sharedRole = Role::create(['name' => 'Shared Role', 'created_by' => $owner->id]);

    // The shared role is also held by a SURVIVOR outside the owner's subtree.
    $survivorStore = Store::factory()->create();
    $survivor = User::factory()->create(['created_by' => $admin->id]);
    $survivor->stores()->attach($survivorStore->id, ['role_id' => $sharedRole->id]);

    $this->actingAs($admin)->deleteJson("/users/{$owner->id}")->assertOk();

    // The whole subtree is gone, hard.
    $this->assertDatabaseMissing('users', ['id' => $owner->id]);
    $this->assertDatabaseMissing('users', ['id' => $manager->id]);
    $this->assertDatabaseMissing('users', ['id' => $worker->id]);

    // Their store is deleted (soft, like every store deletion).
    $this->assertSoftDeleted('stores', ['id' => $ownStore->id]);

    // Their unassigned role is gone; the role a survivor still holds stays alive.
    $this->assertDatabaseMissing('roles', ['id' => $unassignedRole->id]);
    $this->assertDatabaseHas('roles', ['id' => $sharedRole->id, 'created_by' => null]);
    $this->assertDatabaseHas('store_user', ['user_id' => $survivor->id, 'role_id' => $sharedRole->id]);

    // And the cascade is audited.
    $this->assertDatabaseHas('activity_logs', ['action' => 'user.deleted', 'actor_id' => $admin->id]);
});

test('a user removed from their current store loses its permissions on the next request', function () {
    $store = Store::factory()->create();
    $user = createStoreUser($store, ['user-view']);

    // Works while assigned.
    $this->actingAs($user)
        ->withSession(['current_store_id' => $store->id])
        ->getJson('/users/data')->assertOk();

    // Assignment removed while the session still points at that store.
    $user->stores()->detach($store->id);

    // fresh() models the "next request": in production every request resolves a
    // new User instance, so the per-request permission memo starts empty.
    $this->actingAs($user->fresh())
        ->withSession(['current_store_id' => $store->id])
        ->getJson('/users/data')->assertForbidden();
});

test('a user assigned to two stores has a different role and permissions in each', function () {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();

    $managerPerms = grantPermissions(['user-view', 'user-store']);
    $managerRole = Role::create(['name' => 'Manager']);
    $managerRole->permissions()->sync($managerPerms->pluck('id'));

    $viewerRole = Role::create(['name' => 'Viewer']);
    $viewerRole->permissions()->sync($managerPerms->where('name', 'user-view')->pluck('id'));

    $user = User::factory()->create();
    $user->stores()->attach($storeA->id, ['role_id' => $managerRole->id]);
    $user->stores()->attach($storeB->id, ['role_id' => $viewerRole->id]);

    session(['current_store_id' => $storeA->id]);
    expect($user->hasPermissionInCurrentStore('user-store'))->toBeTrue();
    expect($user->currentRole()->name)->toBe('Manager');

    session(['current_store_id' => $storeB->id]);
    expect($user->hasPermissionInCurrentStore('user-store'))->toBeFalse();
    expect($user->hasPermissionInCurrentStore('user-view'))->toBeTrue();
    expect($user->currentRole()->name)->toBe('Viewer');
});

test('a user cannot update a user they did not create', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['user-update']);
    $target = User::factory()->create(['first_name' => 'Untouched']);

    $response = $this->actingAs($actor)
        ->withSession(['current_store_id' => $store->id])
        ->putJson("/users/{$target->id}", [
            'first_name' => 'Hacked',
            'last_name' => $target->last_name,
            'phone' => $target->phone,
            'email' => $target->email,
        ]);

    $response->assertNotFound();
    $this->assertDatabaseHas('users', ['id' => $target->id, 'first_name' => 'Untouched']);
});

test('a user cannot delete a user they did not create', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['user-destroy']);
    $target = User::factory()->create();

    $response = $this->actingAs($actor)
        ->withSession(['current_store_id' => $store->id])
        ->deleteJson("/users/{$target->id}");

    $response->assertNotFound();
    $this->assertDatabaseHas('users', ['id' => $target->id]);
});

test('a promoted super admin cannot edit or demote the admin who promoted them', function () {
    $admin = createSuperAdmin(['user-update', 'user-store-unassign']);
    $superAdminRole = Role::where('name', 'Super-Admin')->first();
    $promoted = User::factory()->create(['created_by' => $admin->id]);
    $promoted->stores()->attach(0, ['role_id' => $superAdminRole->id]);

    $updateResponse = $this->actingAs($promoted)->putJson("/users/{$admin->id}", [
        'first_name' => 'Hacked',
        'last_name' => $admin->last_name,
        'phone' => $admin->phone,
        'email' => $admin->email,
    ]);
    $updateResponse->assertNotFound();

    $demoteResponse = $this->actingAs($promoted)->deleteJson("/users/{$admin->id}/stores/0");
    $demoteResponse->assertNotFound();
    $this->assertDatabaseHas('store_user', ['user_id' => $admin->id, 'store_id' => 0]);
});

test('a super admin\'s global assignment shows in the assignments list', function () {
    $admin = createSuperAdmin(['user-store-view']);
    $superAdminRole = Role::where('name', 'Super-Admin')->first();
    $otherSuperAdmin = User::factory()->create(['created_by' => $admin->id]);
    $otherSuperAdmin->stores()->attach(0, ['role_id' => $superAdminRole->id]);

    $response = $this->actingAs($admin)->getJson("/users/{$otherSuperAdmin->id}/stores");

    $response->assertOk();
    $response->assertJsonFragment([
        'store_id' => 0,
        'store_name' => 'Global (All Stores)',
        'role_name' => 'Super-Admin',
    ]);
});

test('a super admin can remove the global assignment of a super admin they created', function () {
    $admin = createSuperAdmin(['user-store-unassign']);
    $superAdminRole = Role::where('name', 'Super-Admin')->first();
    $otherSuperAdmin = User::factory()->create(['created_by' => $admin->id]);
    $otherSuperAdmin->stores()->attach(0, ['role_id' => $superAdminRole->id]);

    $response = $this->actingAs($admin)->deleteJson("/users/{$otherSuperAdmin->id}/stores/0");

    $response->assertOk();
    $this->assertDatabaseMissing('store_user', ['user_id' => $otherSuperAdmin->id, 'store_id' => 0]);
    expect($otherSuperAdmin->isSuperAdmin())->toBeFalse();
});

test('a super admin cannot remove their own global assignment', function () {
    $admin = createSuperAdmin(['user-store-unassign']);

    $response = $this->actingAs($admin)->deleteJson("/users/{$admin->id}/stores/0");

    $response->assertStatus(422);
    $this->assertDatabaseHas('store_user', ['user_id' => $admin->id, 'store_id' => 0]);
});

test('a non-super-admin cannot remove a global Super-Admin assignment', function () {
    $store = Store::factory()->create();
    $user = createStoreUser($store, ['user-store-unassign']);
    $superAdmin = createSuperAdmin([]);

    $response = $this->actingAs($user)
        ->withSession(['current_store_id' => $store->id])
        ->deleteJson("/users/{$superAdmin->id}/stores/0");

    $response->assertForbidden();
    $this->assertDatabaseHas('store_user', ['user_id' => $superAdmin->id, 'store_id' => 0]);
});

test('a user with user-store-unassign can remove a store assignment', function () {
    $admin = createSuperAdmin(['user-store-unassign']);
    $store = Store::factory()->create();
    $target = createStoreUser($store, [], 'Cashier');

    $response = $this->actingAs($admin)->deleteJson("/users/{$target->id}/stores/{$store->id}");

    $response->assertOk();
    $this->assertDatabaseMissing('store_user', ['user_id' => $target->id, 'store_id' => $store->id]);
});

test('a user with user-store-view can list a users store assignments with role names', function () {
    $admin = createSuperAdmin(['user-store-view']);
    $store = Store::factory()->create(['name' => 'Main Store']);
    $target = createStoreUser($store, [], 'Cashier');

    $response = $this->actingAs($admin)->getJson("/users/{$target->id}/stores");

    $response->assertOk();
    $response->assertJsonFragment(['store_name' => 'Main Store', 'role_name' => 'Cashier']);
});
