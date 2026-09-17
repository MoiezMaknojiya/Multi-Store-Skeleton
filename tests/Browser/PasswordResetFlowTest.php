<?php

namespace Tests\Browser;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * A shop owner who cannot get in, getting back in.
 *
 * The backend tests for this (tests/Feature/Auth/PasswordResetTest.php) use
 * Notification::fake(), which proves the application's logic but stops short of
 * the email itself. This walks the whole thing in a browser and follows the link
 * out of the REAL rendered message: with MAIL_MAILER=log the mail is written to
 * storage/logs/laravel.log, so the token in the test below is the same token a
 * customer would receive.
 *
 * What this still cannot prove is delivery — that an SMTP server accepts the
 * message and an inbox receives it. Nothing in a test suite can. That is what
 * `php artisan mail:test` is for, once the MAIL_* settings are real.
 */
class PasswordResetFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_an_owner_who_forgot_their_password_gets_back_in_through_the_emailed_link(): void
    {
        $this->seedSuperAdmin();
        $owner = User::factory()->create([
            'email' => 'owner@example.com',
            'password' => Hash::make('the-old-password'),
        ]);

        // Everything the app writes from here on belongs to this test.
        $logSizeBefore = $this->mailLogSize();

        $this->browse(function (Browser $browser) use ($owner, $logSizeBefore) {
            $this->freshSession($browser);

            /* ── 1. Locked out, asks for a link ──────────────────────────── */
            $browser->visit('/login')
                ->waitForText('Forgot password?')
                ->clickLink('Forgot password?')
                ->waitFor('@forgot-password-form', 10);

            $this->jsType($browser, '#email', 'owner@example.com');
            $this->jsClick($browser, '@forgot-password-submit');

            // Laravel answers the same way for a known and an unknown address, so
            // this message is not proof on its own — the email below is.
            $browser->waitForText('We have emailed', 15);

            /* ── 2. Opens the link that was actually emailed ─────────────── */
            $token = $this->tokenFromMailLog($logSizeBefore, 'reset-password');

            $browser->visit('/reset-password/'.$token.'?email='.urlencode($owner->email))
                ->waitFor('@reset-password-form', 10);

            /* ── 3. Chooses a new password ───────────────────────────────── */
            $this->jsType($browser, '#email', $owner->email);
            $this->jsType($browser, '#password', 'a-brand-new-password');
            $this->jsType($browser, '#password_confirmation', 'a-brand-new-password');
            $this->jsClick($browser, '@reset-password-submit');

            $browser->waitForLocation('/login', 15);

            /* ── 4. The new password works, and the old one does not ─────── */
            $owner->refresh();
            $this->assertTrue(Hash::check('a-brand-new-password', $owner->password));
            $this->assertFalse(Hash::check('the-old-password', $owner->password));

            $this->jsType($browser, '#email', $owner->email);
            $this->jsType($browser, '#password', 'a-brand-new-password');
            $this->jsClick($browser, '@login-submit');

            $browser->waitForLocation('/dashboard', 15);

            /* ── 5. The link is single use ───────────────────────────────── */
            // A reset email sitting in an inbox — or a forwarded one — must not be
            // a permanent key to the account.
            //
            // Signed out first, because the reset pages are guest-only: while the
            // owner is logged in the link just bounces them to their dashboard,
            // which would prove nothing about the token. This is the real case —
            // somebody opening that old email later.
            $this->freshSession($browser);

            $browser->visit('/reset-password/'.$token.'?email='.urlencode($owner->email))
                ->waitFor('@reset-password-form', 10);

            $this->jsType($browser, '#email', $owner->email);
            $this->jsType($browser, '#password', 'trying-the-link-twice');
            $this->jsType($browser, '#password_confirmation', 'trying-the-link-twice');
            $this->jsClick($browser, '@reset-password-submit');

            $browser->waitForText('token is invalid', 15);

            $owner->refresh();
            $this->assertFalse(Hash::check('trying-the-link-twice', $owner->password),
                'a used reset link changed the password a second time');
            $this->assertTrue(Hash::check('a-brand-new-password', $owner->password));
        });
    }
}
