<?php

use App\Models\Role;
use App\Models\Store;
use App\Models\User;

function validSignupPayload(): array
{
    return [
        'first_name' => 'Sana',
        'last_name' => 'Owner',
        'phone' => '3213214321',
        'email' => 'sana@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'store_name' => 'Sana Superstore',
        'street' => '7 High St',
        'suite' => null,
        'city' => 'Austin',
        'state' => 'TX',
        'zip_code' => '73301',
    ];
}

test('the registration screen renders with the store fields', function () {
    Role::create(['name' => 'Store Owner', 'is_signup_default' => true]);

    $response = $this->get('/register');

    $response->assertOk();
    $response->assertSee('Your Store');
    $response->assertSee('Store Name');
});

test('signing up creates the owner, their store, and the default-role assignment', function () {
    $admin = createSuperAdmin([]);
    $ownerRole = Role::create(['name' => 'Store Owner', 'is_signup_default' => true]);

    $response = $this->post('/register', validSignupPayload());

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticated();

    $user = User::where('email', 'sana@example.com')->firstOrFail();
    $store = Store::where('name', 'Sana Superstore')->firstOrFail();

    // Attributed to the super admin so they appear in the admin's listing.
    expect($user->created_by)->toBe($admin->id);
    // The store belongs to the new owner (cascade-delete attribution).
    expect($store->created_by)->toBe($user->id);
    // Country isn't asked on the form — it defaults to USA.
    expect($store->country)->toBe('USA');

    $this->assertDatabaseHas('store_user', [
        'user_id' => $user->id,
        'store_id' => $store->id,
        'role_id' => $ownerRole->id,
    ]);
    $this->assertDatabaseHas('activity_logs', ['action' => 'user.registered']);
});

test('signup never takes a role from the request — the server default always wins', function () {
    createSuperAdmin([]);
    $ownerRole = Role::create(['name' => 'Store Owner', 'is_signup_default' => true]);
    $superAdminRole = Role::where('name', 'Super-Admin')->first();

    // A malicious payload trying to smuggle a role_id in.
    $this->post('/register', [...validSignupPayload(), 'role_id' => $superAdminRole->id]);

    $user = User::where('email', 'sana@example.com')->firstOrFail();
    $this->assertDatabaseHas('store_user', ['user_id' => $user->id, 'role_id' => $ownerRole->id]);
    expect($user->isSuperAdmin())->toBeFalse();
});

test('signup is rejected gracefully when no default role is configured', function () {
    createSuperAdmin([]);

    $response = $this->post('/register', validSignupPayload());

    $response->assertSessionHasErrors(['email']);
    $this->assertDatabaseMissing('users', ['email' => 'sana@example.com']);
    $this->assertDatabaseMissing('stores', ['name' => 'Sana Superstore']);
});

test('a store-scoped role never serves as the signup default — signup closes instead', function () {
    createSuperAdmin([]);
    $store = Store::factory()->create();
    // Signup builds a BRAND-NEW store, so a role tied to an existing one must not
    // govern it. A mis-flagged role counts as "no default" — the safe failure.
    Role::create(['name' => 'Store Owner', 'is_signup_default' => true, 'store_id' => $store->id]);

    $this->get('/register')->assertOk()->assertSee('Registration is not available right now.');

    $this->post('/register', validSignupPayload())->assertSessionHasErrors(['email']);
    $this->assertDatabaseMissing('users', ['email' => 'sana@example.com']);
    $this->assertDatabaseMissing('stores', ['name' => 'Sana Superstore']);
});

test('an invalid store half creates nothing — no half-registered state', function () {
    createSuperAdmin([]);
    Role::create(['name' => 'Store Owner', 'is_signup_default' => true]);

    $payload = validSignupPayload();
    $payload['store_name'] = '';

    $this->post('/register', $payload)->assertSessionHasErrors(['store_name']);
    $this->assertDatabaseMissing('users', ['email' => 'sana@example.com']);
});
