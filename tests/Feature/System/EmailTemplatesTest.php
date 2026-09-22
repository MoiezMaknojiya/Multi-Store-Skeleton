<?php

use App\Models\Invitation;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| The two emails this app sends
|--------------------------------------------------------------------------
|
| An invitation and a password reset are the only messages that leave the server, and both carry a link
| that is the ONLY copy of its token — so each one has to say who it is from, where it leads and when it
| stops working, in a page every inbox can draw: tables, inline rules, no images, and a plain-text twin
| beside the HTML for the clients (and filters) that prefer one.
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

test('the reset email is the one the forgot-password form sends', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'sara@example.com']);

    $this->post('/forgot-password', ['email' => 'sara@example.com'])->assertSessionHasNoErrors();

    Notification::assertSentTo($user, ResetPasswordNotification::class);
});
