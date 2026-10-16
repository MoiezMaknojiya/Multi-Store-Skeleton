<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Notifications\ApproveEmailChangeNotification;
use App\Notifications\ConfirmNewEmailNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/*
|--------------------------------------------------------------------------
| A changed email is approved from the old address, then confirmed from the new one
|--------------------------------------------------------------------------
|
| The industry's way (owner's rule, 2026-09-29, with email verification; and 2026-10-15 — "email badalne per email
| verification ho jese sub karte ha", as Discord and X do): a confirmed account keeps its address until the change is
| done (users.pending_email), so a typo can never lock anybody out — and the change is first approved by a link to the
| address it has NOW (users.pending_email_approved_at), and only then does the new address get the link that makes it.
| An account that has not confirmed yet is simply correcting the address it signed up with, and the link goes to the
| right one.
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

/** The link sent to the address the account has now, approving the change to $new (the one waiting, by default). */
function approvalLink(User $user, ?string $new = null, ?string $current = null, int $minutes = 60): string
{
    return URL::temporarySignedRoute('email.approve', now()->addMinutes($minutes), [
        'id' => $user->id,
        'hash' => ApproveEmailChangeNotification::hashOf($current ?? $user->email, $new ?? (string) $user->pending_email),
    ]);
}

test('a confirmed account keeps its address until the change is approved from it and confirmed from the new one', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ali@example.com']);
    $this->actingAs($user);

    $this->patch('/profile', profileWithEmail($user, 'ali@newshop.com'))->assertRedirect('/profile');

    // Step 1: the approval goes to the address the account has, and nothing to the new one yet.
    expect($user->fresh())->email->toBe('ali@example.com')->pending_email->toBe('ali@newshop.com')
        ->pending_email_approved_at->toBeNull()
        ->and($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Notification::assertSentTo($user, ApproveEmailChangeNotification::class,
        fn ($notification, array $channels, User $to) => $to->routeNotificationFor('mail') === 'ali@example.com');
    Notification::assertSentOnDemandTimes(ConfirmNewEmailNotification::class, 0);

    // The panel stays open meanwhile, and the profile says which step is waiting.
    $this->get('/dashboard')->assertOk();
    $this->get('/profile')->assertSee('Step 1 of 2:')->assertSee('ali@newshop.com')->assertSee('No email? Press Send the Link Again.');

    // The new address's link does nothing before the change is approved.
    $this->get(newEmailLink($user->fresh()))->assertRedirect('/profile');
    expect($user->fresh()->email)->toBe('ali@example.com');
    $this->get('/profile')->assertSee('Approve the change from ali@example.com first');

    // Approved from the old address: now the new one gets its link.
    $this->get(approvalLink($user->fresh()))->assertRedirect('/profile');

    expect($user->fresh())->email->toBe('ali@example.com')->pending_email_approved_at->not->toBeNull()
        ->and(ActivityLog::where('action', 'account.email_change_approved')->count())->toBe(1);
    Notification::assertSentOnDemand(ConfirmNewEmailNotification::class,
        fn ($notification, array $channels, AnonymousNotifiable $to) => $to->routes['mail'] === 'ali@newshop.com');
    $this->get('/profile')->assertSee('Step 2 of 2:')->assertSee('to finish.');

    $this->get(newEmailLink($user->fresh()))->assertRedirect('/profile');

    expect($user->fresh())->email->toBe('ali@newshop.com')->pending_email->toBeNull()->pending_email_approved_at->toBeNull()
        ->and(ActivityLog::where('action', 'account.email_changed')->count())->toBe(1);
    $this->get('/profile')->assertSee('Your email is now ali@newshop.com.');
});

test('the approval opened again, or twice at once, sends the new address one link', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ali@example.com']);
    $user->forceFill(['pending_email' => 'ali@newshop.com'])->save();
    $this->actingAs($user);
    $link = approvalLink($user);

    $this->get($link)->assertRedirect('/profile');
    $this->get($link)->assertRedirect('/profile');

    Notification::assertSentOnDemandTimes(ConfirmNewEmailNotification::class, 1);
    expect(ActivityLog::where('action', 'account.email_change_approved')->count())->toBe(1);
});

test('an approval opened with the email budget spent approves nothing, and works once the budget allows', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ali@example.com']);
    $user->forceFill(['pending_email' => 'ali@newshop.com'])->save();
    $this->actingAs($user);
    $link = approvalLink($user);

    // Three approvals asked for in a minute spend the account's budget (User::LINKS_PER_ACCOUNT).
    foreach (range(1, User::LINKS_PER_ACCOUNT['minute'][0]) as $ask) {
        $this->post('/profile/email/resend')->assertRedirect('/profile');
    }

    // Approved now, the new address's link would be refused: so nothing is approved, and the page never says "approved".
    $this->get($link)->assertRedirect('/profile');
    expect($user->fresh()->pending_email_approved_at)->toBeNull()
        ->and(ActivityLog::where('action', 'account.email_change_approved')->count())->toBe(0);
    Notification::assertSentOnDemandTimes(ConfirmNewEmailNotification::class, 0);
    $this->get('/profile')->assertSee('Too many emails asked for.')->assertSee('Step 1 of 2:')->assertDontSee('Step 2 of 2:');

    // The same link, a minute later, approves the change and sends the new address its link.
    $this->travel(61)->seconds();
    $this->get($link)->assertRedirect('/profile');
    expect($user->fresh()->pending_email_approved_at)->not->toBeNull();
    Notification::assertSentOnDemandTimes(ConfirmNewEmailNotification::class, 1);
});

test('a change whose approval never went out is told to send it, never that it was sent', function () {
    Notification::fake();
    // Waiting from before the approval step, as a change typed before 2026-10-15 is: no approval was ever emailed.
    $user = User::factory()->create(['email' => 'ali@example.com']);
    $user->forceFill(['pending_email' => 'ali@newshop.com'])->save();
    $this->actingAs($user);

    $this->get('/profile')->assertSee('Step 1 of 2:')->assertSee('No email? Press Send the Link Again.')->assertDontSee('we sent');

    // The new address's link, sent before the approval step, says what to do instead of pointing at an email that never came.
    $this->get(newEmailLink($user))->assertRedirect('/profile');
    $this->get('/profile')->assertSee('Approve the change from ali@example.com first. No email there? Press Send the Link Again below.')
        ->assertDontSee('we sent');
    expect($user->fresh()->email)->toBe('ali@example.com');

    // And Send the Link Again sends the approval.
    $this->post('/profile/email/resend')->assertRedirect('/profile');
    Notification::assertSentToTimes($user, ApproveEmailChangeNotification::class, 1);
});

test('a mail server that refuses the new address its link leaves the step saying how to send it again', function () {
    Exceptions::fake();
    Mail::extend('refusing', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('550 refused');
        }

        public function __toString(): string
        {
            return 'refusing://';
        }
    });
    config(['mail.mailers.refusing' => ['transport' => 'refusing'], 'mail.default' => 'refusing']);
    $user = User::factory()->create(['email' => 'ali@example.com']);
    $user->forceFill(['pending_email' => 'ali@newshop.com'])->save();
    $this->actingAs($user);

    $this->get(approvalLink($user))->assertRedirect('/profile');

    // Approved — the person did open the link — and the page says the email could not go, with what to press.
    expect($user->fresh()->pending_email_approved_at)->not->toBeNull();
    $this->get('/profile')->assertSee('The email could not be sent just now.')
        ->assertSee('Step 2 of 2:')->assertSee('No email? Press Send the Link Again.')->assertDontSee('we sent');
    Exceptions::assertReported(fn (TransportException $e) => str_contains($e->getMessage(), '550 refused'));
});

test('an account that has not confirmed yet simply corrects its address, and the link goes to the right one', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create(['email' => 'sana@exmaple.com']);
    $this->actingAs($user);

    $this->patch('/profile', profileWithEmail($user, 'sana@example.com'))->assertRedirect('/profile');

    expect($user->fresh())->email->toBe('sana@example.com')->pending_email->toBeNull()
        ->and($user->fresh()->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo($user->fresh(), VerifyEmailNotification::class);
    Notification::assertNotSentTo($user, ApproveEmailChangeNotification::class);
    $this->get('/profile')->assertSee('A new link is on its way to sana@example.com.');
});

test('a change can be given up, or the link of the step it stands at sent again', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ali@example.com']);
    $user->forceFill(['pending_email' => 'ali@newshop.com'])->save();
    $this->actingAs($user);

    // Waiting for its approval: the approval goes again, to the address the account has.
    $this->post('/profile/email/resend')->assertRedirect('/profile');
    Notification::assertSentToTimes($user, ApproveEmailChangeNotification::class, 1);
    Notification::assertSentOnDemandTimes(ConfirmNewEmailNotification::class, 0);

    // Approved: the new address's link goes again.
    $user->forceFill(['pending_email_approved_at' => now()])->save();
    $this->post('/profile/email/resend')->assertRedirect('/profile');
    Notification::assertSentOnDemandTimes(ConfirmNewEmailNotification::class, 1);

    $this->delete('/profile/email')->assertRedirect('/profile');

    expect($user->fresh())->email->toBe('ali@example.com')->pending_email->toBeNull()->pending_email_approved_at->toBeNull();

    // An old link to the address given up confirms nothing, nor does its approval.
    $this->get(newEmailLink($user, 'ali@newshop.com'))->assertRedirect('/profile');
    $this->get(approvalLink($user, 'ali@newshop.com'))->assertRedirect('/profile');
    expect($user->fresh())->email->toBe('ali@example.com')->pending_email_approved_at->toBeNull();
});

test('a new address typed again starts the approval over, and the links of the earlier change do nothing', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ali@example.com']);
    $user->forceFill(['pending_email' => 'ali@newshop.com', 'pending_email_approved_at' => now()])->save();
    $this->actingAs($user);

    $this->patch('/profile', profileWithEmail($user, 'ali@othershop.com'))->assertRedirect('/profile');

    expect($user->fresh())->pending_email->toBe('ali@othershop.com')->pending_email_approved_at->toBeNull();
    Notification::assertSentToTimes($user, ApproveEmailChangeNotification::class, 1);

    // The approval and the confirmation of the earlier change are worthless now, though signed and in time.
    $this->get(approvalLink($user, 'ali@newshop.com'))->assertRedirect('/profile');
    $this->get('/profile')->assertSee('That link was for another change of email.');
    $this->get(newEmailLink($user, 'ali@newshop.com'))->assertRedirect('/profile');

    // Typed again, the same address starts over too: its earlier approval is not carried.
    $user->forceFill(['pending_email_approved_at' => now()])->save();
    $this->patch('/profile', profileWithEmail($user, 'ali@othershop.com'))->assertRedirect('/profile');
    expect($user->fresh())->email->toBe('ali@example.com')->pending_email_approved_at->toBeNull();
    $this->get(newEmailLink($user->fresh()))->assertRedirect('/profile');
    expect($user->fresh()->email)->toBe('ali@example.com');
    Notification::assertSentOnDemandTimes(ConfirmNewEmailNotification::class, 0);
});

test('a link that is not right changes nothing and says why', function () {
    $user = User::factory()->create(['email' => 'ali@example.com']);
    $user->forceFill(['pending_email' => 'ali@newshop.com', 'pending_email_approved_at' => now()])->save();
    $other = User::factory()->create();
    $this->actingAs($user);

    foreach ([
        [newEmailLink($user, null, -1), 'That link has expired or is not complete.'],
        [URL::temporarySignedRoute('email.confirm', now()->addHour(), ['id' => $other->id, 'hash' => sha1('ali@newshop.com')]), 'That link is for another account.'],
        [newEmailLink($user, 'someone@else.com'), 'That link was sent to an address this account is not using now.'],
        [approvalLink($user, null, null, -1), 'That link has expired or is not complete.'],
        [URL::temporarySignedRoute('email.approve', now()->addHour(), ['id' => $other->id, 'hash' => ApproveEmailChangeNotification::hashOf('ali@example.com', 'ali@newshop.com')]), 'That link is for another account.'],
        [approvalLink($user, null, 'ali@old-address.com'), 'That link was for another change of email.'],
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
    $this->get('/profile')->assertSee('Step 1 of 2:')->assertDontSee("window.toast('", false);

    $this->post('/profile/email/resend');
    $this->get('/profile')->assertSee('A new link is on its way.')->assertDontSee("window.toast('", false);

    $this->get(approvalLink($user->fresh()));
    $this->get('/profile')->assertSee('Step 2 of 2:')->assertDontSee("window.toast('", false);

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
    $user->forceFill(['pending_email' => 'shared@example.com', 'pending_email_approved_at' => now()])->save();
    User::factory()->create(['email' => 'shared@example.com']);
    $this->actingAs($user);

    $this->get(newEmailLink($user))->assertRedirect('/profile');

    expect($user->fresh())->email->toBe('ali@example.com')->pending_email->toBeNull()->pending_email_approved_at->toBeNull();
    $this->get('/profile')->assertSee('shared@example.com is used by another account now, so your email stays ali@example.com.');
});

test('an address taken at the very moment of the change is not written after all when the change is given up', function () {
    $user = User::factory()->create(['email' => 'ali@example.com']);
    $user->forceFill(['pending_email' => 'ali@newshop.com', 'pending_email_approved_at' => now()])->save();
    $this->actingAs($user);

    // The unique index refuses the first save, as MySQL does when another account takes the address at that moment.
    $saves = [];
    User::updating(function (User $saving) use (&$saves) {
        $saves[] = array_keys($saving->getDirty());

        if (count($saves) === 1) {
            throw new QueryException('mysql', 'update users …', [], new Exception('Duplicate entry for key users_email_unique'));
        }
    });

    $this->get(newEmailLink($user))->assertRedirect('/profile');

    expect($user->fresh())->email->toBe('ali@example.com')->pending_email->toBeNull()
        ->and($saves[1] ?? [])->not->toContain('email');
    $this->get('/profile')->assertSee('ali@newshop.com is used by another account now, so your email stays ali@example.com.');
});
