<?php

use App\Models\ActivityLog;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/*
|--------------------------------------------------------------------------
| A new account confirms its email before anything else
|--------------------------------------------------------------------------
|
| Owner's rule, 2026-09-29 ("we will do email verification when anyone will create account"): public signup
| creates the account and its organization as before, and sends a link to the address. Until the link is opened the
| account can open "Check your inbox" and its profile — nothing that uses the server's space. Every account
| made before the rule counts as confirmed; an invitation's link confirms too, since it came to that inbox.
|
*/

/** A signup form, filled in. */
function newCustomer(array $overrides = []): array
{
    return array_replace([
        'first_name' => 'Sana', 'last_name' => 'Owner', 'phone' => '3213214321', 'email' => 'sana@example.com',
        'password' => 'password123', 'password_confirmation' => 'password123', 'organization_name' => 'Sana Superstore',
        'street' => '7 High St', 'suite' => null, 'city' => 'Austin', 'state' => 'TX', 'zip_code' => '73301',
    ], $overrides);
}

/** The link the email carries — signed, for this account and this address. */
function confirmLink(User $user, ?string $email = null, int $minutes = 60): string
{
    return URL::temporarySignedRoute('verification.verify', now()->addMinutes($minutes), [
        'id' => $user->id, 'hash' => sha1($email ?? $user->email),
    ]);
}

test('signing up sends the link, and opens nothing but the page that says so and the profile', function () {
    Notification::fake();

    $this->post('/register', newCustomer())->assertRedirect(route('verification.notice'));

    $user = User::where('email', 'sana@example.com')->sole();
    expect($user->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo($user, VerifyEmailNotification::class);

    // Every page of the panel sends it back to "Check your inbox" — and a request that wants JSON is refused.
    foreach (['/dashboard', '/select-organization', '/media', '/screens', '/builder', '/channels', '/members'] as $page) {
        $this->get($page)->assertRedirect(route('verification.notice'));
    }
    $this->postJson('/media', [])->assertForbidden();
    $this->getJson('/media/data')->assertForbidden();

    $this->get('/verify-email')->assertOk()
        ->assertSee('Check your inbox')
        ->assertSee('sana@example.com')
        ->assertSee(now()->addDays(User::UNVERIFIED_DAYS)->toFormattedDayDateString());
    $this->get('/profile')->assertOk();
});

test('the link confirms the address and opens the panel', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(confirmLink($user))->assertRedirect(route('dashboard'));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue()
        ->and(ActivityLog::where('action', 'account.verified')->count())->toBe(1);

    $this->get('/dashboard')->assertOk();
    $this->get('/verify-email')->assertRedirect(route('dashboard'));
});

test('a link that is not right says why on the page, and confirms nothing', function () {
    $user = User::factory()->unverified()->create(['email' => 'sana@example.com']);
    $other = User::factory()->unverified()->create();
    $this->actingAs($user);

    $tampered = str_replace('signature=', 'signature=0', confirmLink($user));

    foreach ([
        [confirmLink($user, null, -1), 'That link has expired or is not complete.'],
        [$tampered, 'That link has expired or is not complete.'],
        [URL::route('verification.verify', ['id' => $user->id, 'hash' => sha1($user->email)]), 'That link has expired or is not complete.'],
        [confirmLink($other), 'That link is for another account.'],
        [confirmLink($user, 'old@example.com'), 'That link was sent to an address this account is not using now.'],
    ] as [$link, $said]) {
        $this->get($link)->assertRedirect(route('verification.notice'));
        $this->get('/verify-email')->assertSee($said);
    }

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse()
        ->and($other->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('the link alone signs nobody in', function () {
    $user = User::factory()->unverified()->create();

    $this->get(confirmLink($user))->assertRedirect(route('login'));

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    $this->assertGuest();
});

test('another link can be sent, a few times a minute — never a loop filling an inbox', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();
    $this->actingAs($user);

    $this->from('/verify-email')->post('/verify-email/resend')->assertRedirect('/verify-email');
    $this->get('/verify-email')->assertSee('A new link is on its way to '.$user->email.'.');

    foreach (range(2, 3) as $time) {
        $this->from('/verify-email')->post('/verify-email/resend')->assertRedirect('/verify-email');
    }
    $this->from('/verify-email')->post('/verify-email/resend')->assertRedirect('/verify-email');
    $this->get('/verify-email')->assertSee('Too many emails asked for. Try again in');

    Notification::assertSentToTimes($user, VerifyEmailNotification::class, 3);
});

test('a mail server that refuses is said on the page; the signup stands', function () {
    // A mailer nobody can reach, as a refusing mail server looks from here.
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);

    $this->post('/register', newCustomer())->assertRedirect(route('verification.notice'));

    expect(User::where('email', 'sana@example.com')->exists())->toBeTrue();
    $this->get('/verify-email')->assertSee('The email could not be sent just now.');
});

test('an account made through an invitation counts as confirmed', function () {
    // An invitation's link came to the inbox itself: accepting it confirms the account's address.
    $organization = Organization::factory()->create(['name' => 'Beta Deli']);
    $signedUp = User::factory()->unverified()->create(['email' => 'sara@example.com']);
    Invitation::create([
        'organization_id' => $organization->id, 'email' => 'sara@example.com', 'role_id' => Role::owner()->id,
        'token_hash' => Invitation::hashToken($token = str_repeat('b', 64)), 'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($signedUp)->post("/invitations/{$token}/accept")->assertRedirect(route('dashboard'));

    expect($signedUp->fresh()->hasVerifiedEmail())->toBeTrue();
});
