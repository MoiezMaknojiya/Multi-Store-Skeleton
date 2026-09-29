<?php

use App\Models\Invitation;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Notifications\ConfirmNewEmailNotification;
use App\Notifications\DiskAlmostFullNotification;
use App\Notifications\InvitationNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| The emails this app sends
|--------------------------------------------------------------------------
|
| An invitation, a password reset, the link that confirms a new account's email (or a changed one) and the
| warning that the server's disk is almost full are the only messages that leave the server, and all but the
| last carry a link that is the ONLY copy of its token — so each one has to say who it is from, where it leads
| and when it stops working, in a page every inbox can draw: tables, inline rules, no images, and a plain-text
| twin beside the HTML for the clients (and filters) that prefer one.
|
| The tests render what the notification would actually send, rather than the template on its own, so a
| view renamed or a value left out of the data is caught here.
|
*/

/** The rendered HTML of a notification, exactly as the mailer would build it. */
function renderedMail(object $notification, ?object $notifiable = null): string
{
    return (string) $notification->toMail($notifiable ?? new AnonymousNotifiable)->render();
}

test('the invitation email carries the link, who sent it, the role and when it expires', function () {
    $store = Store::factory()->create(['name' => 'Alpha Mart']);
    $inviter = createStoreUser($store, ['member-invite'], 'Owner');
    $inviter->update(['first_name' => 'Moiez', 'last_name' => 'Ali']);
    $role = Role::owner();

    $invitation = Invitation::create([
        'store_id' => $store->id,
        'email' => 'sara@example.com',
        'role_id' => $role->id,
        'invited_by' => $inviter->id,
        'token_hash' => hash('sha256', 'plain-token'),
        'expires_at' => now()->addDays(Invitation::LIFETIME_DAYS),
    ]);

    config(['app.name' => 'Digital Lifts']);
    $html = renderedMail(new InvitationNotification($invitation->fresh(), 'plain-token'));

    expect($html)
        ->toContain('Digital Lifts')                              // the brand bar, not a stock heading
        ->toContain(route('invitations.show', 'plain-token'))     // the link, in the button and in words
        ->toContain('Moiez Ali')
        ->toContain('Alpha Mart')
        ->toContain($role->name)
        ->toContain($invitation->expires_at->toFormattedDayDateString())
        ->toContain('sara@example.com')
        ->toContain('Accept invitation')
        // Drawn the way email has to be drawn: a table, the brand bar, and every rule inline.
        ->toContain('<table')
        ->toContain('#2563eb')
        ->toContain('color-scheme')
        ->not->toContain('<img')                                  // nothing to block, nothing to miss
        // Never Laravel's stock template, whose fallback line reads exactly like this.
        ->not->toContain('having trouble clicking');
});

test('an invitation with no inviter still reads as a sentence', function () {
    $store = Store::factory()->create(['name' => 'Beta Store']);

    $invitation = Invitation::create([
        'store_id' => $store->id,
        'email' => 'owner@example.com',
        'role_id' => Role::owner()->id,
        'invited_by' => null,
        'token_hash' => hash('sha256', 'no-inviter'),
        'expires_at' => now()->addDays(Invitation::LIFETIME_DAYS),
    ]);

    expect(renderedMail(new InvitationNotification($invitation, 'no-inviter')))
        ->toContain('You have been invited to join')
        ->toContain('Beta Store');
});

test('the password reset email carries the link, the address and the minutes it lasts', function () {
    $user = User::factory()->create(['first_name' => 'Sara', 'last_name' => 'Khan', 'email' => 'sara@example.com']);
    $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

    $html = renderedMail(new ResetPasswordNotification('reset-token'), $user);

    expect($html)
        ->toContain(route('password.reset', ['token' => 'reset-token', 'email' => 'sara@example.com']))
        ->toContain('Sara Khan')
        ->toContain('sara@example.com')
        ->toContain((string) $minutes)
        ->toContain('Reset password')
        ->toContain('nothing has changed')                        // what to do when it was not you
        ->toContain('<table')
        ->not->toContain('<img')
        ->not->toContain('having trouble clicking');
});

test('both emails go out with a plain-text twin beside the HTML', function () {
    $store = Store::factory()->create();
    $invitation = Invitation::create([
        'store_id' => $store->id,
        'email' => 'sara@example.com',
        'role_id' => Role::owner()->id,
        'invited_by' => null,
        'token_hash' => hash('sha256', 'twin'),
        'expires_at' => now()->addDays(Invitation::LIFETIME_DAYS),
    ]);
    $user = User::factory()->create();

    $invite = (new InvitationNotification($invitation, 'twin'))->toMail(new AnonymousNotifiable);
    $reset = (new ResetPasswordNotification('twin-token'))->toMail($user);

    expect($invite->view)->toBe(['emails.invitation', 'emails.invitation-text'])
        ->and($reset->view)->toBe(['emails.password-reset', 'emails.password-reset-text']);

    // The text twin says the same things, without a tag in sight.
    $text = view('emails.invitation-text', $invite->viewData)->render();
    expect($text)->toContain(route('invitations.show', 'twin'))->not->toContain('<');
});

test('the email that confirms a new account carries its signed link, the address, the minutes and the week', function () {
    $user = User::factory()->unverified()->create(['first_name' => 'Zebulon', 'email' => 'sana@example.com']);
    config(['app.name' => 'Digital Lifts']);

    $mail = (new VerifyEmailNotification)->toMail($user);
    $html = (string) $mail->render();
    $link = $mail->viewData['url'];

    expect($mail->view)->toBe(['emails.verify-email', 'emails.verify-email-text'])
        ->and($mail->subject)->toBe('Confirm your email for Digital Lifts')
        ->and($link)->toStartWith(url("/verify-email/{$user->id}/".sha1('sana@example.com')))
        ->toContain('expires=')->toContain('signature=');

    expect($html)
        ->toContain(e($link))
        ->not->toContain('Zebulon')                               // no text anybody typed but the address
        ->toContain('sana@example.com')
        ->toContain(VerifyEmailNotification::minutes().' minutes')
        ->toContain(User::UNVERIFIED_DAYS.' days')
        ->toContain('Confirm my email')
        ->toContain("If you didn't")
        ->toContain('<table')
        ->toContain('#2563eb')
        ->not->toContain('<img')
        ->not->toContain('having trouble clicking');
});

test('the email that confirms a changed address goes to the new one and says the old one stays until then', function () {
    $user = User::factory()->create(['first_name' => 'Zebulon', 'email' => 'ali@example.com']);
    $user->forceFill(['pending_email' => 'ali@newshop.com'])->save();

    $mail = (new ConfirmNewEmailNotification($user))->toMail(new AnonymousNotifiable);
    $html = (string) $mail->render();

    expect($mail->view)->toBe(['emails.confirm-new-email', 'emails.confirm-new-email-text'])
        ->and($mail->viewData['url'])->toStartWith(url("/confirm-email/{$user->id}/".sha1('ali@newshop.com')));

    expect($html)
        ->toContain('ali@newshop.com')
        ->not->toContain('Zebulon')                               // it goes to whatever address was typed
        ->not->toContain('ali@example.com')                       // nor does it tell a stranger the account's address
        ->toContain('keeps its old address')
        ->toContain('Confirm new email')
        ->toContain('<table')
        ->not->toContain('<img');
});

test('the disk warning says what is left, what is kept free and how often it is sent', function () {
    $mail = (new DiskAlmostFullNotification((int) (3.5 * 1024 ** 3), 5 * 1024 ** 3))->toMail(User::factory()->create());

    expect($mail->view)->toBe(['emails.disk-almost-full', 'emails.disk-almost-full-text'])
        ->and((string) $mail->render())
        ->toContain('3.5 GB')
        ->toContain('5 GB')
        ->toContain('once every 6 hours')
        ->toContain('Screens keep playing')
        ->not->toContain('<img');
});

test('every plain-text twin prints its link and its words as they are, never HTML-escaped', function () {
    // A signed link carries "&" between its parameters: escaped, it reads "&amp;" and the link is broken.
    $user = User::factory()->unverified()->create(['first_name' => "Sa'ra", 'email' => 'sara@example.com']);
    $user->forceFill(['pending_email' => 'sara@newshop.com'])->save();
    $store = Store::factory()->create(['name' => 'Ali & Sons']);
    $invitation = Invitation::create([
        'store_id' => $store->id, 'email' => 'sara@example.com', 'role_id' => Role::owner()->id, 'invited_by' => null,
        'token_hash' => hash('sha256', 'text-twin'), 'expires_at' => now()->addDays(Invitation::LIFETIME_DAYS),
    ]);

    foreach ([
        (new VerifyEmailNotification)->toMail($user),
        (new ConfirmNewEmailNotification($user))->toMail(new AnonymousNotifiable),
        (new ResetPasswordNotification('reset-token'))->toMail($user),
        (new InvitationNotification($invitation, 'text-twin'))->toMail(new AnonymousNotifiable),
        (new DiskAlmostFullNotification(1024 ** 3, 5 * 1024 ** 3))->toMail($user),
    ] as $mail) {
        $text = view($mail->view[1], $mail->viewData)->render();

        expect($text)->toContain($mail->viewData['url'])
            ->not->toContain('&amp;')
            ->not->toContain('&#039;')
            ->not->toStartWith("\n");
    }

    expect(view('emails.invitation-text', (new InvitationNotification($invitation, 'text-twin'))->toMail(new AnonymousNotifiable)->viewData)->render())
        ->toContain('Ali & Sons');
    expect(view('emails.password-reset-text', (new ResetPasswordNotification('reset-token'))->toMail($user)->viewData)->render())
        ->toContain("Hello Sa'ra");
});

test('the reset email is the one the forgot-password form sends', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'sara@example.com']);

    $this->post('/forgot-password', ['email' => 'sara@example.com'])->assertSessionHasNoErrors();

    Notification::assertSentTo($user, ResetPasswordNotification::class);
});
