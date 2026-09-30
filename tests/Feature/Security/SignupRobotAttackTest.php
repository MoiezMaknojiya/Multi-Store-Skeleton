<?php

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Robots at the sign-up form (owner, 2026-09-30)
|--------------------------------------------------------------------------
|
| Made-up names ("Wmkzprzh Ywgfqzw", stores like "dPyFetSjlZaDyeaHIESs") signed up on the live site all day,
| each one sending a confirmation email to somebody's real address. The form carries two traps
| (components/auth/robot-trap.blade.php): a field no person sees, and the sealed moment it was opened. A robot
| caught by either makes no account, no store, no log line and no email.
|
*/

beforeEach(function () {
    Notification::fake();
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

function robotSignup(array $extra = []): array
{
    return array_merge([
        'first_name' => 'Wmkzprzh',
        'last_name' => 'Ywgfqzw',
        'phone' => '3213214321',
        'email' => 'someone.real@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'store_name' => 'dPyFetSjlZaDyeaHIESs',
        'street' => '1 Main St',
        'city' => 'Nzqfqka',
        'state' => 'TX',
        'zip_code' => '73301',
    ], $extra);
}

/** Nothing of the robot's was written, and nobody was emailed. */
function expectNothingWritten(): void
{
    expect(User::where('email', 'someone.real@example.com')->exists())->toBeFalse()
        ->and(Store::where('name', 'dPyFetSjlZaDyeaHIESs')->exists())->toBeFalse();
    test()->assertGuest();
    Notification::assertNothingSent();
}

test('a robot that fills in the field no person sees is sent back, and nothing is written', function () {
    $this->post('/register', robotSignup(['website' => 'https://spam.example']))
        ->assertRedirect()
        ->assertSessionHasErrors(['form' => 'Please try again.']);

    expectNothingWritten();
    $this->assertDatabaseMissing('activity_logs', ['action' => 'user.registered']);
});

test('with the time trap on, a robot posting straight to the address (no sealed moment) is refused', function () {
    config(['signage.signup_min_seconds' => 3]);

    $this->post('/register', robotSignup())->assertSessionHasErrors('form');
    $this->post('/register', robotSignup(['form_started' => 'forged']))->assertSessionHasErrors('form');
    $this->post('/register', robotSignup(['form_started' => ['x']]))->assertSessionHasErrors('form');

    expectNothingWritten();
});

test('a form sent back sooner than a person can fill it in is refused; the same one sent later goes through', function () {
    config(['signage.signup_min_seconds' => 3]);
    Role::firstOrCreate(['key' => Role::OWNER], ['name' => 'Owner', 'is_global' => false]);

    $sealed = Crypt::encryptString((string) now()->getTimestamp());

    $this->post('/register', robotSignup(['form_started' => $sealed]))->assertSessionHasErrors('form');
    expectNothingWritten();

    // A person takes longer than three seconds over eleven fields.
    $this->travel(20)->seconds();

    $this->post('/register', robotSignup(['form_started' => $sealed]))->assertRedirect(route('verification.notice'));
    expect(User::where('email', 'someone.real@example.com')->exists())->toBeTrue();
});

test('a sealed moment older than the form lasts is a stale page or a replay, and is refused', function () {
    config(['signage.signup_min_seconds' => 3]);

    $sealed = Crypt::encryptString((string) now()->getTimestamp());
    $this->travel(3)->hours();

    $this->post('/register', robotSignup(['form_started' => $sealed]))->assertSessionHasErrors('form');
    expectNothingWritten();
});

test('the form a person opens carries both traps, and neither can be seen', function () {
    $html = $this->get('/register')->assertOk()->getContent();

    expect($html)->toContain('name="website"')
        ->and($html)->toContain('name="form_started"')
        // Out of sight, out of the Tab order and never filled in by the browser.
        ->and(preg_match('/<div class="hidden">\s*<label for="website">/', $html))->toBe(1)
        ->and($html)->toContain('tabindex="-1" autocomplete="off"');
});
