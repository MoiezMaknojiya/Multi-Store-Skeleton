<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Notifications\ConfirmNewEmailNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/*
|--------------------------------------------------------------------------
| A changed email is confirmed by a link to the new address
|--------------------------------------------------------------------------
|
| The industry's way (owner's rule, 2026-09-29, with email verification): a confirmed account keeps its address
| until the new one's link is opened (users.pending_email), so a typo can never lock anybody out; an account that
| has not confirmed yet is simply correcting the address it signed up with, and the link goes to the right one.
|
*/

/** The Profile form with a new email in it. */
function profileWithEmail(User $user, string $email): array
{
    return ['first_name' => $user->first_name, 'last_name' => $user->last_name, 'phone' => $user->phone, 'email' => $email];
}

/** The link sent to the new address. */
function newEmailLink(User $user, ?string $email = null, int $minutes = 60): string
{
    return URL::temporarySignedRoute('email.confirm', now()->addMinutes($minutes), [
        'id' => $user->id, 'hash' => sha1($email ?? (string) $user->pending_email),
    ]);
}

test('a confirmed account keeps its address until the link sent to the new one is opened', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ali@example.com']);
    $this->actingAs($user);

    $this->patch('/profile', profileWithEmail($user, 'ali@newshop.com'))->assertRedirect('/profile');

    expect($user->fresh())->email->toBe('ali@example.com')->pending_email->toBe('ali@newshop.com')
        ->and($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Notification::assertSentOnDemand(ConfirmNewEmailNotification::class,
        fn ($notification, array $channels, AnonymousNotifiable $to) => $to->routes['mail'] === 'ali@newshop.com');

    // The panel stays open meanwhile, and the profile says what is waiting.
    $this->get('/dashboard')->assertOk();
    $this->get('/profile')->assertSee('We sent a link to')->assertSee('ali@newshop.com');

    $this->get(newEmailLink($user->fresh()))->assertRedirect('/profile');

    expect($user->fresh())->email->toBe('ali@newshop.com')->pending_email->toBeNull()
        ->and(ActivityLog::where('action', 'account.email_changed')->count())->toBe(1);
    $this->get('/profile')->assertSee('Your email is now ali@newshop.com.');
});

test('an account that has not confirmed yet simply corrects its address, and the link goes to the right one', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create(['email' => 'sana@exmaple.com']);
    $this->actingAs($user);

    $this->patch('/profile', profileWithEmail($user, 'sana@example.com'))->assertRedirect('/profile');

    expect($user->fresh())->email->toBe('sana@example.com')->pending_email->toBeNull()
        ->and($user->fresh()->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo($user->fresh(), VerifyEmailNotification::class);
    $this->get('/profile')->assertSee('A new link is on its way to sana@example.com.');
});

test('a change can be given up, or its link sent again', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ali@example.com']);
    $user->forceFill(['pending_email' => 'ali@newshop.com'])->save();
    $this->actingAs($user);

    $this->post('/profile/email/resend')->assertRedirect('/profile');
    Notification::assertSentOnDemandTimes(ConfirmNewEmailNotification::class, 1);

    $this->delete('/profile/email')->assertRedirect('/profile');

    expect($user->fresh())->email->toBe('ali@example.com')->pending_email->toBeNull();

    // An old link to the address given up confirms nothing.
    $this->get(newEmailLink($user, 'ali@newshop.com'))->assertRedirect('/profile');
    expect($user->fresh()->email)->toBe('ali@example.com');
});

test('a link that is not right changes nothing and says why', function () {
    $user = User::factory()->create(['email' => 'ali@example.com']);
    $user->forceFill(['pending_email' => 'ali@newshop.com'])->save();
    $other = User::factory()->create();
    $this->actingAs($user);

    foreach ([
        [newEmailLink($user, null, -1), 'That link has expired or is not complete.'],
        [URL::temporarySignedRoute('email.confirm', now()->addHour(), ['id' => $other->id, 'hash' => sha1('ali@newshop.com')]), 'That link is for another account.'],
        [newEmailLink($user, 'someone@else.com'), 'That link was sent to an address this account is not using now.'],
    ] as [$link, $said]) {
        $this->get($link)->assertRedirect('/profile');
        $this->get('/profile')->assertSee($said);
    }

    expect($user->fresh())->email->toBe('ali@example.com')->pending_email->toBe('ali@newshop.com');
});

test('the profile says each step in its own words, never as a toast of its key', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ali@example.com']);
    $this->actingAs($user);

    // The shared toasts turn any other flash into a toast (components/toasts.blade.php): these keys are the page's own.
    $this->patch('/profile', profileWithEmail($user, 'ali@newshop.com'));
    $this->get('/profile')->assertSee('We sent a link to')->assertDontSee("window.toast('", false);

    $this->post('/profile/email/resend');
    $this->get('/profile')->assertSee('A new link is on its way.')->assertDontSee("window.toast('", false);

    $this->delete('/profile/email');
    $this->get('/profile')->assertSee('Your email stays ali@example.com.')->assertDontSee("window.toast('", false);
});

test('an account not confirmed yet is told so on its profile, and can send the link again from there', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create(['email' => 'sana@example.com']);
    $this->actingAs($user);

    $this->get('/profile')->assertSee('Your email is not confirmed yet.')->assertSee('sana@example.com');

    $this->from('/profile')->post('/verify-email/resend')->assertRedirect('/profile');
    $this->get('/profile')
        ->assertSee('A new link is on its way to sana@example.com.')
        ->assertDontSee('Your email is not confirmed yet.')
        ->assertDontSee("window.toast('", false);
    Notification::assertSentToTimes($user, VerifyEmailNotification::class, 1);

    // A confirmed account is never told it is not.
    $this->actingAs(User::factory()->create())->get('/profile')->assertDontSee('Your email is not confirmed yet.');
});

test('an address another account took meanwhile is not taken from it', function () {
    $user = User::factory()->create(['email' => 'ali@example.com']);
    $user->forceFill(['pending_email' => 'shared@example.com'])->save();
    User::factory()->create(['email' => 'shared@example.com']);
    $this->actingAs($user);

    $this->get(newEmailLink($user))->assertRedirect('/profile');

    expect($user->fresh())->email->toBe('ali@example.com')->pending_email->toBeNull();
    $this->get('/profile')->assertSee('shared@example.com is used by another account now, so your email stays ali@example.com.');
});
