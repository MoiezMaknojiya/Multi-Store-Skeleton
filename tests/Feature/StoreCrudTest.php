<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;

test('guests cannot access any store endpoint', function () {
    $this->getJson('/stores/data')->assertUnauthorized();
});

test('a super admin sees every store', function () {
    Store::factory()->count(2)->create();
    $admin = createSuperAdmin(['store-view']);

    $response = $this->actingAs($admin)->getJson('/stores/data');

    $response->assertOk();
    $response->assertJsonCount(2, 'stores');
});

test('a user with a custom global role sees every store', function () {
    Store::factory()->create(['name' => 'Z Grocery']);
    Store::factory()->create(['name' => 'Other Store']);

    grantPermissions(['store-view']);
    $globalRole = Role::create(['name' => 'Global Admin', 'is_global' => true]);
    $globalRole->permissions()->sync(Permission::where('name', 'store-view')->pluck('id'));
    $user = User::factory()->create();
    $user->stores()->attach(0, ['role_id' => $globalRole->id]);

    $response = $this->actingAs($user)->getJson('/stores/data');

    $response->assertOk();
    $names = collect($response->json('stores'))->pluck('name');
    expect($names)->toContain('Z Grocery');
    expect($names)->toContain('Other Store');
});

test('a non-super-admin only sees stores they are assigned to', function () {
    $myStore = Store::factory()->create(['name' => 'My Store']);
    Store::factory()->create(['name' => 'Other Store']);

    $user = createStoreUser($myStore, ['store-view']);

    $response = $this->actingAs($user)
        ->withSession(['current_store_id' => $myStore->id])
        ->getJson('/stores/data');

    $response->assertOk();
    $names = collect($response->json('stores'))->pluck('name');
    expect($names)->toContain('My Store');
    expect($names)->not->toContain('Other Store');
});

test('a user with store-store can create a store', function () {
    $user = createSuperAdmin(['store-store']);

    $response = $this->actingAs($user)->postJson('/stores', [
        'name' => 'New Store',
        'street' => '123 Main St',
        'city' => 'Austin',
        'state' => 'TX',
        'zip_code' => '78701',
        'country' => 'USA',
        'is_active' => true,
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('stores', ['name' => 'New Store']);
    expect($response->json('store.slug'))->not->toBeNull();
});

test('creating a store validates required address fields', function () {
    $user = createSuperAdmin(['store-store']);

    $response = $this->actingAs($user)->postJson('/stores', ['name' => 'Incomplete Store']);

    $response->assertJsonValidationErrors(['street', 'city', 'state', 'zip_code', 'country']);
});

test('creating a store requires a 2-letter state code', function () {
    $user = createSuperAdmin(['store-store']);

    $response = $this->actingAs($user)->postJson('/stores', [
        'name' => 'New Store',
        'street' => '123 Main St',
        'city' => 'Austin',
        'state' => 'Texas',
        'zip_code' => '78701',
        'country' => 'USA',
    ]);

    $response->assertJsonValidationErrors('state');
});

test('creating a store rejects a 2-letter code that is not one of the 50 US states', function () {
    $user = createSuperAdmin(['store-store']);

    $response = $this->actingAs($user)->postJson('/stores', [
        'name' => 'New Store',
        'street' => '123 Main St',
        'city' => 'Austin',
        'state' => 'DC',
        'zip_code' => '78701',
        'country' => 'USA',
    ]);

    $response->assertJsonValidationErrors('state');
});

test('creating a store accepts a real US state code', function () {
    $user = createSuperAdmin(['store-store']);

    $response = $this->actingAs($user)->postJson('/stores', [
        'name' => 'New Store',
        'street' => '123 Main St',
        'city' => 'Austin',
        'state' => 'TX',
        'zip_code' => '78701',
        'country' => 'USA',
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('stores', ['state' => 'TX']);
});

test('creating a store rejects a non-numeric zip code', function () {
    $user = createSuperAdmin(['store-store']);

    $response = $this->actingAs($user)->postJson('/stores', [
        'name' => 'New Store',
        'street' => '123 Main St',
        'city' => 'Austin',
        'state' => 'TX',
        'zip_code' => '78701-abc!',
        'country' => 'USA',
    ]);

    $response->assertJsonValidationErrors('zip_code');
    $response->assertJsonFragment(['zip_code' => ['Zip code can only contain numbers.']]);
});

test('creating a store accepts a purely numeric zip code', function () {
    $user = createSuperAdmin(['store-store']);

    $response = $this->actingAs($user)->postJson('/stores', [
        'name' => 'New Store',
        'street' => '123 Main St',
        'city' => 'Austin',
        'state' => 'TX',
        'zip_code' => '78701',
        'country' => 'USA',
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('stores', ['zip_code' => '78701']);
});

test('a global-role user with store-store can create a store', function () {
    grantPermissions(['store-store']);
    $globalRole = Role::create(['name' => 'Global Admin', 'is_global' => true]);
    $globalRole->permissions()->sync(Permission::where('name', 'store-store')->pluck('id'));
    $actor = User::factory()->create();
    $actor->stores()->attach(0, ['role_id' => $globalRole->id]);

    $response = $this->actingAs($actor)->postJson('/stores', [
        'name' => 'Customer Store',
        'street' => '9 New St',
        'city' => 'Austin',
        'state' => 'TX',
        'zip_code' => '73301',
        'country' => 'USA',
        'is_active' => true,
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('stores', ['name' => 'Customer Store']);
});

test('a store user cannot update a store they are not assigned to', function () {
    $myStore = Store::factory()->create();
    $otherStore = Store::factory()->create(['name' => 'Untouched']);
    $actor = createStoreUser($myStore, ['store-update']);

    $response = $this->actingAs($actor)
        ->withSession(['current_store_id' => $myStore->id])
        ->putJson("/stores/{$otherStore->id}", [
            'name' => 'Hijacked',
            'street' => '1 St',
            'city' => 'Austin',
            'state' => 'TX',
            'zip_code' => '73301',
            'country' => 'USA',
        ]);

    $response->assertNotFound();
    $this->assertDatabaseHas('stores', ['id' => $otherStore->id, 'name' => 'Untouched']);
});

test('permissions are per-store: a manager cannot mutate another store they belong to with a lesser role', function () {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create(['name' => 'B Untouched']);

    // Actor manages A (store-update + store-destroy) and is a plain member of B.
    $actor = createStoreUser($storeA, ['store-update', 'store-destroy'], 'Manager A');
    $viewerB = Role::create(['name' => 'Viewer B']);
    $actor->stores()->attach($storeB->id, ['role_id' => $viewerB->id]);

    // While in context A, acting on B is blocked (404) — A's permissions don't reach B.
    $this->actingAs($actor)->withSession(['current_store_id' => $storeA->id])
        ->putJson("/stores/{$storeB->id}", [
            'name' => 'Hacked', 'street' => $storeB->street, 'city' => $storeB->city,
            'state' => $storeB->state, 'zip_code' => $storeB->zip_code, 'country' => $storeB->country,
        ])->assertNotFound();

    $this->actingAs($actor)->withSession(['current_store_id' => $storeA->id])
        ->deleteJson("/stores/{$storeB->id}")->assertNotFound();

    $this->assertDatabaseHas('stores', ['id' => $storeB->id, 'name' => 'B Untouched', 'deleted_at' => null]);
});

test('a store user can update the store they are assigned to', function () {
    $myStore = Store::factory()->create();
    $actor = createStoreUser($myStore, ['store-update']);

    $response = $this->actingAs($actor)
        ->withSession(['current_store_id' => $myStore->id])
        ->putJson("/stores/{$myStore->id}", [
            'name' => 'Renamed Store',
            'street' => $myStore->street,
            'city' => $myStore->city,
            'state' => $myStore->state,
            'zip_code' => $myStore->zip_code,
            'country' => $myStore->country,
        ]);

    $response->assertOk();
    $this->assertDatabaseHas('stores', ['id' => $myStore->id, 'name' => 'Renamed Store']);
});

test('a store user cannot delete a store they are not assigned to', function () {
    $myStore = Store::factory()->create();
    $otherStore = Store::factory()->create();
    $actor = createStoreUser($myStore, ['store-destroy']);

    $response = $this->actingAs($actor)
        ->withSession(['current_store_id' => $myStore->id])
        ->deleteJson("/stores/{$otherStore->id}");

    $response->assertNotFound();
    $this->assertDatabaseHas('stores', ['id' => $otherStore->id, 'deleted_at' => null]);
});

test('a user with store-update can update a store', function () {
    $store = Store::factory()->create(['name' => 'Old Name']);
    $user = createSuperAdmin(['store-update']);

    $response = $this->actingAs($user)->putJson("/stores/{$store->id}", [
        'name' => 'New Name',
        'street' => $store->street,
        'city' => $store->city,
        'state' => $store->state,
        'zip_code' => $store->zip_code,
        'country' => $store->country,
        'is_active' => false,
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('stores', ['id' => $store->id, 'name' => 'New Name', 'is_active' => false]);
});

test('deleting a store soft-deletes it and detaches assigned users', function () {
    $store = Store::factory()->create();
    $assignedUser = createStoreUser($store, []);
    $admin = createSuperAdmin(['store-destroy']);

    $response = $this->actingAs($admin)->deleteJson("/stores/{$store->id}");

    $response->assertOk();
    $this->assertDatabaseMissing('stores', ['id' => $store->id, 'deleted_at' => null]);
    $this->assertSoftDeleted('stores', ['id' => $store->id]);
    $this->assertDatabaseMissing('store_user', ['store_id' => $store->id, 'user_id' => $assignedUser->id]);
});

test('a user assigned to a store can switch into it', function () {
    $store = Store::factory()->create();
    $user = createStoreUser($store, []);

    $response = $this->actingAs($user)->post('/stores/switch', ['store_id' => $store->id]);

    $response->assertRedirect();
    $this->assertSame($store->id, session('current_store_id'));
});

test('a user cannot switch into a store they do not belong to', function () {
    $store = Store::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->post('/stores/switch', ['store_id' => $store->id])->assertForbidden();
});

test('a super admin cannot switch into a real store directly, even one they are somehow assigned to', function () {
    $store = Store::factory()->create();
    $admin = createSuperAdmin();
    $admin->stores()->attach($store->id, ['role_id' => Role::where('name', 'Super-Admin')->value('id')]);

    $response = $this->actingAs($admin)->post('/stores/switch', ['store_id' => $store->id]);

    $response->assertForbidden();
    expect(session('current_store_id'))->toBeNull();
});
