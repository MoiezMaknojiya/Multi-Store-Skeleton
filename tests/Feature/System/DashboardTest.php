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
    // The number on the Total Stores card itself — a bare "3" turns up all over a page.
    expect($response->getContent())->toMatch('/>\s*3\s*<\/div>\s*<div[^>]*>\s*Total Stores\s*</');
});

test('the store selection page runs the same number of queries for ten stores as for two', function () {
    $user = User::factory()->create();
    $join = fn (int $count) => Store::factory()->count($count)->create()->each(
        fn (Store $store) => $user->stores()->attach($store->id, ['role_id' => Role::owner()->id])
    );

    // A fresh instance each time, so both requests start with nothing remembered about the person.
    $queriesFor = function () use ($user): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user->fresh())->get('/select-store')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $join(2);
    $two = $queriesFor();

    $join(8);
    $ten = $queriesFor();

    // Role names for every card come from one batched query, not one per store — so eight more
    // stores cost nothing. A ceiling could not catch one query per store; only equality can.
    expect($user->stores()->count())->toBe(10)
        ->and($two)->toBeGreaterThan(0)
        ->and($ten)->toBe($two);
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

test('a name that starts with a letter of more than one byte gives its avatar that whole letter', function () {
    // substr() cut the first BYTE of "علی", which is half a character: every page then carried an invalid
    // UTF-8 byte in the header, the sidebar and the store picker (a "�" on screen).
    $store = Store::factory()->create();
    $person = createStoreUser($store, ['store-view'], 'Staff');
    $person->update(['first_name' => 'علی', 'last_name' => 'Khan']);

    foreach (['/dashboard', '/profile'] as $page) {
        $html = $this->actingAs($person->fresh())->withSession(['current_store_id' => $store->id])->get($page)->assertOk()->getContent();

        expect(mb_check_encoding($html, 'UTF-8'))->toBeTrue("{$page} is not valid UTF-8")
            ->and($html)->toContain('ع');
    }
});
