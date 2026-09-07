<?php

use App\Models\Role;
use App\Models\Store;
use App\Models\User;

function validOnboardPayload(int $roleId): array
{
    return [
        'first_name' => 'Olivia',
        'last_name' => 'Owner',
        'phone' => '1112223333',
        'email' => 'newowner@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'store_name' => 'Fresh Mart',
        'street' => '12 Market St',
        'suite' => null,
        'city' => 'Austin',
        'state' => 'TX',
        'zip_code' => '73301',
        'country' => 'USA',
        'role_id' => $roleId,
    ];
}

test('onboarding creates the owner, their store, and the assignment in one call', function () {
    $admin = createSuperAdmin(['user-store', 'store-store', 'user-store-assign']);
    $ownerRole = Role::create(['name' => 'Store Owner', 'created_by' => $admin->id]);

    $response = $this->actingAs($admin)->postJson('/users/onboard', validOnboardPayload($ownerRole->id));

    $response->assertOk();
    $owner = User::where('email', 'newowner@example.com')->firstOrFail();
    $store = Store::where('name', 'Fresh Mart')->firstOrFail();
    expect($owner->created_by)->toBe($admin->id);
    // The store is attributed to the OWNER, so deleting the owner cascades it away.
    expect($store->created_by)->toBe($owner->id);
    $this->assertDatabaseHas('store_user', [
        'user_id' => $owner->id,
        'store_id' => $store->id,
        'role_id' => $ownerRole->id,
    ]);
});

test('deleting an onboarded owner cascades their store away too', function () {
    $admin = createSuperAdmin(['user-store', 'store-store', 'user-store-assign', 'user-destroy']);
    $ownerRole = Role::create(['name' => 'Store Owner', 'created_by' => $admin->id]);

    $this->actingAs($admin)->postJson('/users/onboard', validOnboardPayload($ownerRole->id))->assertOk();
    $owner = User::where('email', 'newowner@example.com')->firstOrFail();
    $store = Store::where('name', 'Fresh Mart')->firstOrFail();

    $this->actingAs($admin)->deleteJson("/users/{$owner->id}")->assertOk();

    $this->assertDatabaseMissing('users', ['id' => $owner->id]);
    $this->assertSoftDeleted('stores', ['id' => $store->id]);
});

test('a global admin with the three permissions can onboard too', function () {
    $admin = createSuperAdmin([]);
    $perms = grantPermissions(['user-store', 'store-store', 'user-store-assign']);
    $globalRole = Role::create(['name' => 'Global Admin', 'is_global' => true, 'created_by' => $admin->id]);
    $globalRole->permissions()->sync($perms->pluck('id'));
    $actor = User::factory()->create(['created_by' => $admin->id]);
    $actor->stores()->attach(0, ['role_id' => $globalRole->id]);

    $ownerRole = Role::create(['name' => 'Store Owner', 'created_by' => $actor->id]);

    $this->actingAs($actor)->postJson('/users/onboard', validOnboardPayload($ownerRole->id))->assertOk();
    $this->assertDatabaseHas('users', ['email' => 'newowner@example.com']);
});

test('onboarding requires all three permissions', function () {
    $admin = createSuperAdmin(['user-store', 'store-store']); // missing user-store-assign
    $ownerRole = Role::create(['name' => 'Store Owner', 'created_by' => $admin->id]);

    $this->actingAs($admin)->postJson('/users/onboard', validOnboardPayload($ownerRole->id))->assertForbidden();
    $this->assertDatabaseMissing('users', ['email' => 'newowner@example.com']);
    $this->assertDatabaseMissing('stores', ['name' => 'Fresh Mart']);
});

test('onboarding rejects a global role for the owner', function () {
    $admin = createSuperAdmin(['user-store', 'store-store', 'user-store-assign']);
    $globalRole = Role::create(['name' => 'Global Admin', 'is_global' => true, 'created_by' => $admin->id]);

    $response = $this->actingAs($admin)->postJson('/users/onboard', validOnboardPayload($globalRole->id));

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['role_id']);
    $this->assertDatabaseMissing('stores', ['name' => 'Fresh Mart']);
});

test('a validation failure creates nothing — no half-onboarded state', function () {
    $admin = createSuperAdmin(['user-store', 'store-store', 'user-store-assign']);
    $ownerRole = Role::create(['name' => 'Store Owner', 'created_by' => $admin->id]);

    $payload = validOnboardPayload($ownerRole->id);
    $payload['store_name'] = ''; // invalid store half

    $this->actingAs($admin)->postJson('/users/onboard', $payload)->assertStatus(422);
    $this->assertDatabaseMissing('users', ['email' => 'newowner@example.com']);
    $this->assertDatabaseMissing('stores', ['name' => 'Fresh Mart']);
});

test('onboarding rejects a role that belongs to an existing store', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['user-store', 'store-store', 'user-store-assign']);
    // A role the actor CAN see — but it was built inside their own store, and
    // onboarding creates a brand-new one. Roles never travel between stores.
    $storeScopedRole = Role::create(['name' => 'Store Owner', 'created_by' => $actor->id, 'store_id' => $store->id]);

    $response = $this->actingAs($actor)
        ->withSession(['current_store_id' => $store->id])
        ->postJson('/users/onboard', validOnboardPayload($storeScopedRole->id));

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['role_id']);
    $this->assertDatabaseMissing('users', ['email' => 'newowner@example.com']);
    $this->assertDatabaseMissing('stores', ['name' => 'Fresh Mart']);
});

test('onboarding rejects a role the actor cannot see', function () {
    $admin = createSuperAdmin([]);
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['user-store', 'store-store', 'user-store-assign']);
    $othersRole = Role::create(['name' => 'Store Owner', 'created_by' => $admin->id]);

    $this->actingAs($actor)
        ->withSession(['current_store_id' => $store->id])
        ->postJson('/users/onboard', validOnboardPayload($othersRole->id))
        ->assertNotFound();
});
