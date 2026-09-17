<?php

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Public signup (docs/STORE-ORGANIZATION-SPEC.md rule 17)
|--------------------------------------------------------------------------
|
| One form: the account, the store, and the person's membership of it as its Owner. The role
| is never read from the request.
|
*/

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
    $this->get('/register')->assertOk()->assertSee('Your Store')->assertSee('Store Name');
});

test('signing up creates the account, the store, and makes the person its Owner', function () {
    $this->post('/register', validSignupPayload())->assertRedirect(route('dashboard'));
    $this->assertAuthenticated();

    $user = User::where('email', 'sana@example.com')->firstOrFail();
    $store = Store::where('name', 'Sana Superstore')->firstOrFail();

    expect($store->created_by)->toBe($user->id)
        ->and($store->country)->toBe('USA')   // not asked on the form
        ->and(roleKeyIn($user, $store))->toBe(Role::OWNER);

    $this->assertDatabaseHas('activity_logs', ['action' => 'user.registered']);
});

test('signup never takes a role from the request', function () {
    createSuperAdmin();
    $superAdminRole = Role::where('name', 'Super-Admin')->first();

    $this->post('/register', [...validSignupPayload(), 'role_id' => $superAdminRole->id]);

    $user = User::where('email', 'sana@example.com')->firstOrFail();
    expect($user->isSuperAdmin())->toBeFalse()
        ->and(DB::table('store_user')->where('user_id', $user->id)->pluck('role_id')->all())
        ->toBe([Role::starter(Role::OWNER)->id]);
});

test('without the Owner role, signup closes instead of guessing', function () {
    Role::where('key', Role::OWNER)->delete();

    $this->get('/register')->assertOk()->assertSee('Registration is not available right now.');
    $this->post('/register', validSignupPayload())->assertSessionHasErrors(['email']);

    $this->assertDatabaseMissing('users', ['email' => 'sana@example.com']);
    $this->assertDatabaseMissing('stores', ['name' => 'Sana Superstore']);
});

test('an invalid store half creates nothing — no half-registered state', function () {
    $payload = validSignupPayload();
    $payload['store_name'] = '';

    $this->post('/register', $payload)->assertSessionHasErrors(['store_name']);

    $this->assertDatabaseMissing('users', ['email' => 'sana@example.com']);
});

test('the email is kept lowercased, so capitals can never make a second account', function () {
    $this->post('/register', [...validSignupPayload(), 'email' => 'Sana@Example.COM'])->assertRedirect(route('dashboard'));

    expect(User::sole()->email)->toBe('sana@example.com');

    auth()->logout();
    $this->post('/register', [...validSignupPayload(), 'email' => 'SANA@example.com', 'store_name' => 'Second Shop'])
        ->assertSessionHasErrors('email');

    expect(User::count())->toBe(1);
});
