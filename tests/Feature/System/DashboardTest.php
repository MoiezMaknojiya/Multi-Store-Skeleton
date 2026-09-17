<?php

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('guests are redirected to login', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('super admin dashboard shows the real store count', function () {
    Store::factory()->count(3)->create();

    $admin = createSuperAdmin();

    $response = $this->actingAs($admin)->get('/dashboard');

    $response->assertOk();
    $response->assertViewHas('totalStores', 3);
    $response->assertSee('3');
});

test('the store selection page runs a bounded number of queries however many stores the user has', function () {
    $role = Role::create(['name' => 'Owner']);
    $user = User::factory()->create();
    Store::factory()->count(8)->create()->each(
        fn ($store) => $user->stores()->attach($store->id, ['role_id' => $role->id])
    );

    DB::enableQueryLog();
    $this->actingAs($user)->get('/select-store')->assertOk();
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Role names for every card come from one batched query, not one per store.
    expect($queryCount)->toBeLessThan(15);
});

test('a user with a custom global role sees the global stats dashboard, not the empty store list', function () {
    Store::factory()->count(2)->create();
    $globalRole = Role::create(['name' => 'Global Admin', 'is_global' => true]);
    $user = User::factory()->create();
    $user->stores()->attach(0, ['role_id' => $globalRole->id]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertSee('Total Stores');
    $response->assertDontSee("You're not a member of any store yet", false);
});

test('a global user always gets the global stats dashboard — the global tier wins', function () {
    // Tiers are meant to be exclusive; even if a stray store row exists, holding a
    // global role keeps the user on the global stats view (never the store context
    // or the selector).
    $store = Store::factory()->create(['name' => 'Attached Store']);
    $globalRole = Role::create(['name' => 'Global Admin', 'is_global' => true]);
    $storeRole = Role::create(['name' => 'Manager']);
    $user = User::factory()->create();
    $user->stores()->attach(0, ['role_id' => $globalRole->id]);
    $user->stores()->attach($store->id, ['role_id' => $storeRole->id]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertViewHas('view', 'global');
    $response->assertSee('Total Stores');
});

test('super admin dashboard reflects zero stores when none exist', function () {
    $admin = createSuperAdmin();

    $response = $this->actingAs($admin)->get('/dashboard');

    $response->assertOk();
    $response->assertViewHas('totalStores', 0);
});

test('a single-store user is auto-selected into that store (smart default)', function () {
    $store = Store::factory()->create(['name' => 'My Store']);
    $user = createStoreUser($store, []);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertViewHas('view', 'store');
    // The single store was written into session context without any manual pick.
    expect(session('current_store_id'))->toBe($store->id);
});

test('a multi-store user with no store chosen is sent to the selection page', function () {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'Owner']);
    $storeA = Store::factory()->create(['name' => 'First Store']);
    $storeB = Store::factory()->create(['name' => 'Second Store']);
    $user->stores()->attach($storeA->id, ['role_id' => $role->id]);
    $user->stores()->attach($storeB->id, ['role_id' => $role->id]);

    $this->actingAs($user)->get('/dashboard')->assertRedirect(route('stores.select'));

    $response = $this->actingAs($user)->get('/select-store');
    $response->assertOk();
    $response->assertSee('First Store');
    $response->assertSee('Second Store');
});

test('the selection page redirects away when there is nothing to pick', function () {
    // A single-store user does not need the picker.
    $store = Store::factory()->create();
    $single = createStoreUser($store, []);
    $this->actingAs($single)->get('/select-store')->assertRedirect(route('dashboard'));

    // A global user has no store to pick either.
    $globalRole = Role::create(['name' => 'Global Admin', 'is_global' => true]);
    $global = User::factory()->create();
    $global->stores()->attach(0, ['role_id' => $globalRole->id]);
    $this->actingAs($global)->get('/select-store')->assertRedirect(route('dashboard'));
});
