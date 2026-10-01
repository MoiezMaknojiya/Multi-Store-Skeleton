<?php

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Public signup (docs/ORGANIZATION-SPEC.md rule 17)
|--------------------------------------------------------------------------
|
| One form: the account, the organization, and the person's membership of it as its Owner. The role
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
        'organization_name' => 'Sana Superstore',
        'street' => '7 High St',
        'suite' => null,
        'city' => 'Austin',
        'state' => 'TX',
        'zip_code' => '73301',
    ];
}

test('the registration screen renders with the organization fields', function () {
    $this->get('/register')->assertOk()->assertSee('Your Organization')->assertSee('Organization Name');
});

test('signing up creates the account, the organization, and makes the person its Owner', function () {
    // …and asks for the email to be confirmed before anything else (owner's rule, 2026-09-29).
    $this->post('/register', validSignupPayload())->assertRedirect(route('verification.notice'));
    $this->assertAuthenticated();

    $user = User::where('email', 'sana@example.com')->firstOrFail();
    $organization = Organization::where('name', 'Sana Superstore')->firstOrFail();

    expect($organization->created_by)->toBe($user->id)
        ->and($organization->country)->toBe('USA')   // not asked on the form
        ->and(roleKeyIn($user, $organization))->toBe(Role::OWNER);

    $this->assertDatabaseHas('activity_logs', ['action' => 'user.registered']);
});

test('signup never takes a role from the request', function () {
    createSuperAdmin();
    $superAdminRole = Role::where('name', 'Super-Admin')->first();

    $this->post('/register', [...validSignupPayload(), 'role_id' => $superAdminRole->id]);

    $user = User::where('email', 'sana@example.com')->firstOrFail();
    expect($user->isSuperAdmin())->toBeFalse()
        ->and(DB::table('organization_user')->where('user_id', $user->id)->pluck('role_id')->all())
        ->toBe([Role::starter(Role::OWNER)->id]);
});

test('without the Owner role, signup closes instead of guessing', function () {
    Role::where('key', Role::OWNER)->delete();

    $this->get('/register')->assertOk()->assertSee('Registration is not available right now.');
    $this->post('/register', validSignupPayload())->assertSessionHasErrors(['email']);

    $this->assertDatabaseMissing('users', ['email' => 'sana@example.com']);
    $this->assertDatabaseMissing('organizations', ['name' => 'Sana Superstore']);
});

test('an invalid organization half creates nothing — no half-registered state', function () {
    $payload = validSignupPayload();
    $payload['organization_name'] = '';

    $this->post('/register', $payload)->assertSessionHasErrors(['organization_name']);

    $this->assertDatabaseMissing('users', ['email' => 'sana@example.com']);
});

test('the email is kept lowercased, so capitals can never make a second account', function () {
    $this->post('/register', [...validSignupPayload(), 'email' => 'Sana@Example.COM'])->assertRedirect(route('verification.notice'));

    expect(User::sole()->email)->toBe('sana@example.com');

    auth()->logout();
    $this->post('/register', [...validSignupPayload(), 'email' => 'SANA@example.com', 'organization_name' => 'Second Organization'])
        ->assertSessionHasErrors('email');

    expect(User::count())->toBe(1);
});

test('an email longer than its column is refused as a message, not a database error', function () {
    // MySQL's strict mode refuses a value longer than the column (255); SQLite never checks, so only the
    // rule can say it — before, a 300-character address was a 500 on the real database.
    $this->from('/register')->post('/register', [
        ...validSignupPayload(),
        'email' => str_repeat('a', 250).'@example.com',
    ])->assertSessionHasErrors('email');

    expect(User::count())->toBe(0);
});
