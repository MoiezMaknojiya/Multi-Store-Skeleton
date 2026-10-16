<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Notifications\ApproveEmailChangeNotification;
use App\Notifications\ConfirmNewEmailNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

/*
|--------------------------------------------------------------------------
| Email verification, attacked
|--------------------------------------------------------------------------
|
| Owner's rule, 2026-09-29: a signup confirms its email before it may use the server's space, and an address
| holds one account. Every link is signed, names one account and one address, and opens nothing by itself; the
| sweep in HttpSurfaceSweepTest walks every route as an account that has not confirmed. Here: forged and
| transplanted links, a replay after the address changed, the mail the links go out in, and somebody who signs
| up with another person's address and keeps the session open.
|
*/

/** A link as the app signs one: $route, for $user, naming $email. */
function emailLink(string $route, User $user, string $email, int $minutes = 60): string
{
    return URL::temporarySignedRoute($route, now()->addMinutes($minutes), ['id' => $user->id, 'hash' => sha1($email)]);
}

test('a link cannot be bent to another account, another address or a later expiry', function () {
    $victim = User::factory()->unverified()->create(['email' => 'victim@example.com']);
    $attacker = User::factory()->unverified()->create(['email' => 'attacker@example.com']);
    $own = emailLink('verification.verify', $attacker, 'attacker@example.com');
    $this->actingAs($attacker);

    foreach ([
        str_replace("/verify-email/{$attacker->id}/", "/verify-email/{$victim->id}/", $own),        // the victim's id
        str_replace(sha1('attacker@example.com'), sha1('victim@example.com'), $own),                // the victim's address
        preg_replace('/expires=\d+/', 'expires=9999999999', $own),                                  // a later expiry
        strtok($own, '?'),                                                                           // no signature
        preg_replace('/signature=[^&]+/', 'signature[]=x', $own),                                   // shapes where one value goes
        preg_replace('/expires=\d+/', 'expires[]=1', $own),
    ] as $forged) {
        $this->get($forged)->assertRedirect(route('verification.notice'));
    }

    expect($victim->fresh()->hasVerifiedEmail())->toBeFalse()
        ->and($attacker->fresh()->hasVerifiedEmail())->toBeFalse();

    // An id that is not a number is no route at all.
    $this->get('/verify-email/1e1/'.sha1('attacker@example.com'))->assertNotFound();
});

test("a signup link's signature opens no other route: not the one that changes an email", function () {
    $user = User::factory()->create(['email' => 'ali@example.com']);
    $user->forceFill(['pending_email' => 'ali@newshop.com'])->save();
    $this->actingAs($user);

    $transplanted = str_replace('/verify-email/', '/confirm-email/', emailLink('verification.verify', $user, 'ali@newshop.com'));
    $this->get($transplanted)->assertRedirect(route('profile.edit'));

    // Nor does a confirmation's signature approve a change, or an approval's confirm one (owner, 2026-10-15).
    $approval = URL::temporarySignedRoute('email.approve', now()->addHour(), [
        'id' => $user->id, 'hash' => ApproveEmailChangeNotification::hashOf('ali@example.com', 'ali@newshop.com'),
    ]);
    $this->get(str_replace('/confirm-email/', '/approve-email/', emailLink('email.confirm', $user, 'ali@newshop.com')))->assertRedirect(route('profile.edit'));
    $this->get(str_replace('/approve-email/', '/confirm-email/', $approval))->assertRedirect(route('profile.edit'));

    expect($user->fresh())->email->toBe('ali@example.com')->pending_email->toBe('ali@newshop.com')->pending_email_approved_at->toBeNull();
});

test('a link to an address the account has given up confirms nothing, though it is signed and in time', function () {
    Notification::fake();

    // Signed up with a typo and corrected it: the link that went to the typo is worthless now.
    $user = User::factory()->unverified()->create(['email' => 'sana@exmaple.com']);
    $toTheTypo = emailLink('verification.verify', $user, 'sana@exmaple.com');
    $this->actingAs($user)->patch('/profile', [
        'first_name' => $user->first_name, 'last_name' => $user->last_name, 'phone' => $user->phone, 'email' => 'sana@example.com',
    ]);

    $this->get($toTheTypo)->assertRedirect(route('verification.notice'));
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('an address holds one account: signing up with a taken one is refused, confirmed or not', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    User::factory()->unverified()->create(['email' => 'waiting@example.com']);

    foreach (['taken@example.com', 'waiting@example.com', 'TAKEN@example.com'] as $email) {
        $this->post('/register', [
            'first_name' => 'Sana', 'last_name' => 'Owner', 'phone' => '3213214321', 'email' => $email,
            'password' => 'password123', 'password_confirmation' => 'password123', 'organization_name' => 'Another Organization',
            'street' => '7 High St', 'city' => 'Austin', 'state' => 'TX', 'zip_code' => '73301',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    expect(User::count())->toBe(2);
});

test('an account cannot be used to fill inboxes: three emails a minute and twenty an hour, whichever door asks', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create(['email' => 'sana@example.com']);
    $this->actingAs($user);
    $profile = fn (string $email): array => ['first_name' => $user->first_name, 'last_name' => $user->last_name, 'phone' => $user->phone, 'email' => $email];

    // "Send it again" from both pages, then a new address typed on the profile: three emails, three doors.
    $this->from('/verify-email')->post('/verify-email/resend')->assertRedirect('/verify-email');
    $this->from('/profile')->post('/verify-email/resend')->assertRedirect('/profile');
    $this->patch('/profile', $profile('someone1@example.com'))->assertRedirect('/profile');

    // The fourth is refused at every door — a new address before anything is saved, the reason under its field.
    // (No session assertion before the page: with JSON sessions it marshals the error bag a second time in
    // memory, and the page after it then finds the bag empty — a test's artefact, never a browser's.)
    $this->patch('/profile', $profile('someone2@example.com'))->assertRedirect('/profile');
    expect($user->fresh()->email)->toBe('someone1@example.com');
    $this->get('/profile')->assertSee('Too many emails asked for. Try again in');
    $this->from('/verify-email')->post('/verify-email/resend')->assertRedirect('/verify-email');
    $this->get('/verify-email')->assertSee('Too many emails asked for. Try again in');

    // Spaced a minute apart, the hour still ends it at twenty.
    foreach (range(4, 20) as $ask) {
        $this->travel(61)->seconds();
        $this->from('/verify-email')->post('/verify-email/resend');
    }
    $this->travel(61)->seconds();
    $this->from('/verify-email')->post('/verify-email/resend');
    $this->get('/verify-email')->assertSee('Too many emails asked for. Try again in');

    Notification::assertSentToTimes($user, VerifyEmailNotification::class, 20);
});

test('nor can one visitor, by making account after account: thirty emails an hour from one address', function () {
    Notification::fake();
    $accounts = User::factory()->unverified()->count(11)->create();

    // Ten accounts, three emails each — every account inside its own budget.
    foreach ($accounts->take(10) as $account) {
        foreach (range(1, 3) as $ask) {
            $this->actingAs($account)->from('/verify-email')->post('/verify-email/resend');
        }
    }

    // The eleventh account has asked for nothing, and is refused all the same.
    $this->actingAs($accounts->last())->from('/verify-email')->post('/verify-email/resend')->assertRedirect('/verify-email');
    $this->get('/verify-email')->assertSee('Too many emails asked for. Try again in');

    Notification::assertSentToTimes($accounts->first(), VerifyEmailNotification::class, 3);
    Notification::assertNotSentTo($accounts->last(), VerifyEmailNotification::class);

    // From another address, the eleventh gets its link: the cap is the visitor's, not the account's.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->actingAs($accounts->last())->from('/verify-email')->post('/verify-email/resend');
    Notification::assertSentToTimes($accounts->last(), VerifyEmailNotification::class, 1);
});

test('somebody who signs up with your address and keeps the session open loses it when you reset the password', function () {
    config(['session.driver' => 'database']);

    // The squatter signs up with the victim's address and stays signed in on their own machine.
    $squatted = User::factory()->unverified()->create(['email' => 'victim@example.com', 'password' => Hash::make('squatter-pass')]);
    DB::table('sessions')->insert(['id' => 'squatter-session', 'user_id' => $squatted->id, 'payload' => '', 'last_activity' => now()->timestamp]);
    DB::table('sessions')->insert(['id' => 'somebody-else', 'user_id' => null, 'payload' => '', 'last_activity' => now()->timestamp]);

    // The victim asks for a reset link — it comes to THEIR inbox — and sets a password of their own.
    $this->post('/reset-password', [
        'token' => Password::createToken($squatted), 'email' => 'victim@example.com',
        'password' => 'Victim!2345678', 'password_confirmation' => 'Victim!2345678',
    ])->assertRedirect(route('login'));

    // The squatter's session is gone, the address is the victim's — confirmed by the link that came to it — and
    // the squatter's password opens nothing.
    expect(DB::table('sessions')->where('user_id', $squatted->id)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'somebody-else')->exists())->toBeTrue()
        ->and($squatted->fresh()->hasVerifiedEmail())->toBeTrue()
        ->and(ActivityLog::where('action', 'account.verified')->sole()->description)->toContain('password-reset link');

    $this->post('/login', ['email' => 'victim@example.com', 'password' => 'squatter-pass'])->assertSessionHasErrors();
    $this->assertGuest();
});

test('only a super admin viewing as the account confirms it for them: a session that says otherwise confirms nothing', function () {
    $customer = User::factory()->unverified()->create();
    $other = User::factory()->unverified()->create();
    $admin = createSuperAdmin();
    $staff = createPlatformUser(['user-view']);
    $gone = createSuperAdmin();
    $goneId = $gone->id;
    $gone->delete();
    $demoted = createSuperAdmin();
    DB::table('organization_user')->where('user_id', $demoted->id)->delete();

    $sessions = [
        'a platform account that is not a super admin started it' => ['impersonating_original_id' => $staff->id, 'impersonating_user_id' => $customer->id],
        'it names another account as the one viewed' => ['impersonating_original_id' => $admin->id, 'impersonating_user_id' => $other->id],
        'the super admin who started it is gone' => ['impersonating_original_id' => $goneId, 'impersonating_user_id' => $customer->id],
        'the super admin who started it is a super admin no more' => ['impersonating_original_id' => $demoted->id, 'impersonating_user_id' => $customer->id],
        'only half of it is there' => ['impersonating_user_id' => $customer->id],
    ];

    foreach ($sessions as $case => $session) {
        $this->actingAs($customer)->withSession($session)->get('/verify-email')->assertOk()->assertDontSee('Confirm Email and Continue');
        expect($this->actingAs($customer)->withSession($session)->post('/verify-email/confirm-for-them')->status())->toBe(403, $case);
    }

    // A guest is sent to sign in; asking with GET is no way in either.
    auth()->logout();
    $this->flushSession()->post('/verify-email/confirm-for-them')->assertRedirect(route('login'));
    $this->get('/verify-email/confirm-for-them')->assertStatus(405);

    expect($customer->fresh()->hasVerifiedEmail())->toBeFalse()
        ->and(ActivityLog::where('action', 'account.verified')->count())->toBe(0);
});

test('an open session alone cannot move an account to another inbox, and so cannot take it over by "forgot password"', function () {
    Notification::fake();
    $victim = User::factory()->create(['email' => 'victim@example.com']);

    // Somebody at the victim's open session (a shared computer, a stolen cookie) types an address of their own.
    $this->actingAs($victim)->patch('/profile', [
        'first_name' => $victim->first_name, 'last_name' => $victim->last_name, 'phone' => $victim->phone, 'email' => 'attacker@evil.test',
    ])->assertRedirect('/profile');

    // Nothing reaches the attacker's inbox: the approval goes to the victim's own, which the attacker does not have.
    Notification::assertSentOnDemandTimes(ConfirmNewEmailNotification::class, 0);
    Notification::assertSentTo($victim, ApproveEmailChangeNotification::class,
        fn ($notification, array $channels, User $to) => $to->routeNotificationFor('mail') === 'victim@example.com');

    // Even a link to the new address signed by the app itself does nothing while the change is not approved.
    $this->get(emailLink('email.confirm', $victim, 'attacker@evil.test'))->assertRedirect(route('profile.edit'));
    expect($victim->fresh())->email->toBe('victim@example.com')->pending_email_approved_at->toBeNull();

    // So "forgot password" for the attacker's address finds no account, and only the victim's password opens it.
    auth()->logout();
    $this->post('/forgot-password', ['email' => 'attacker@evil.test'])->assertRedirect();
    Notification::assertNotSentTo($victim, ResetPasswordNotification::class);
    $this->post('/login', ['email' => 'attacker@evil.test', 'password' => 'password'])->assertSessionHasErrors();
    $this->assertGuest();
});

test('somebody viewing as the person through Log In As may start a change of email but never finish it', function () {
    Notification::fake();
    $admin = createSuperAdmin();
    $customer = User::factory()->create(['email' => 'customer@example.com']);
    $viewing = ['impersonating_original_id' => $admin->id, 'impersonating_user_id' => $customer->id];

    $this->actingAs($customer)->withSession($viewing)->patch('/profile', [
        'first_name' => $customer->first_name, 'last_name' => $customer->last_name, 'phone' => $customer->phone, 'email' => 'admin-inbox@example.com',
    ])->assertRedirect('/profile');

    // The approval goes to the customer's own inbox …
    Notification::assertSentTo($customer, ApproveEmailChangeNotification::class);
    Notification::assertSentOnDemandTimes(ConfirmNewEmailNotification::class, 0);

    // … and its link, or the new address's, opened while viewing as them, does nothing, however it was come by.
    $customer->refresh();
    $this->actingAs($customer)->withSession($viewing)->get(URL::temporarySignedRoute('email.approve', now()->addHour(), [
        'id' => $customer->id, 'hash' => ApproveEmailChangeNotification::hashOf('customer@example.com', 'admin-inbox@example.com'),
    ]))->assertRedirect(route('profile.edit'));
    $this->get('/profile')->assertSee('Only the person can approve or confirm a change of their email');
    expect($customer->fresh()->pending_email_approved_at)->toBeNull();

    $customer->forceFill(['pending_email_approved_at' => now()])->save();
    $this->actingAs($customer)->withSession($viewing)->get(emailLink('email.confirm', $customer, 'admin-inbox@example.com'))
        ->assertRedirect(route('profile.edit'));
    expect($customer->fresh()->email)->toBe('customer@example.com')
        ->and(ActivityLog::whereIn('action', ['account.email_change_approved', 'account.email_changed'])->count())->toBe(0);

    // The person, signed in themselves, finishes it.
    $this->flushSession()->actingAs($customer->fresh())->get(emailLink('email.confirm', $customer, 'admin-inbox@example.com'))
        ->assertRedirect(route('profile.edit'));
    expect($customer->fresh()->email)->toBe('admin-inbox@example.com');
});

test('somebody viewing as an account not confirmed yet cannot correct its email to an inbox of their own', function () {
    Notification::fake();
    $admin = createSuperAdmin();
    $customer = User::factory()->unverified()->create(['first_name' => 'Nida', 'last_name' => 'Khan', 'email' => 'customer@example.com']);
    $viewing = ['impersonating_original_id' => $admin->id, 'impersonating_user_id' => $customer->id];
    $form = fn (string $email, string $firstName = 'Nida') => ['first_name' => $firstName, 'last_name' => 'Khan', 'phone' => $customer->phone, 'email' => $email];

    // The page does not offer it: the email is read-only and says whose it is to correct, and "Check your inbox" sends
    // nobody to the profile to change it.
    $profile = $this->actingAs($customer)->withSession($viewing)->get('/profile')->assertOk()
        ->assertSee('Only Nida Khan can correct this email, signed in themselves.');
    expect($profile->getContent())->toMatch('/<input id="email"[^>]*\sreadonly="readonly"/');
    $this->get('/verify-email')->assertOk()->assertDontSee('Wrong address?');

    // Sent by hand, the correction is refused: corrected, the address would be taken at once, then "Confirm Email and
    // Continue" would confirm it and "forgot password" would hand over the account.
    $this->from('/profile')->patch('/profile', $form('admin-inbox@example.com'))
        ->assertRedirect('/profile')
        ->assertSessionHasErrors(['email' => 'Only Nida Khan can correct their email, signed in themselves: not by Log In As.']);
    expect($customer->fresh())->email->toBe('customer@example.com')->pending_email->toBeNull();
    Notification::assertNothingSent();

    // The rest of the profile still saves, and the address confirmed for them is the person's own.
    $this->patch('/profile', $form('customer@example.com', 'Nidaa'))->assertRedirect('/profile')->assertSessionHasNoErrors();
    $this->post(route('verification.confirm-for-them'))->assertRedirect(route('dashboard'));
    expect($customer->fresh())->first_name->toBe('Nidaa')->email->toBe('customer@example.com')
        ->and($customer->fresh()->hasVerifiedEmail())->toBeTrue();

    // The person, signed in themselves, corrects an unconfirmed address as before.
    $own = User::factory()->unverified()->create(['first_name' => 'Sana', 'last_name' => 'Ali', 'email' => 'sana@exmaple.com']);
    $this->flushSession()->actingAs($own)->get('/profile')->assertDontSee('can correct this email, signed in themselves.');
    $this->patch('/profile', ['first_name' => 'Sana', 'last_name' => 'Ali', 'phone' => $own->phone, 'email' => 'sana@example.com'])
        ->assertRedirect('/profile')->assertSessionHasNoErrors();
    expect($own->fresh()->email)->toBe('sana@example.com');
    $this->get('/verify-email')->assertSee('Wrong address?');
});
