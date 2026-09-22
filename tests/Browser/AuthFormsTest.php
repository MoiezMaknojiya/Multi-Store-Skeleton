<?php

namespace Tests\Browser;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The browser's own check on the sign-in pages that are not the registration form: sign in, "forgot
 * password" and the new-password page the emailed link opens (resources/js/pages/auth-forms.js).
 *
 * An obvious mistake is caught before anything leaves the browser, and marked the way the server marks
 * its own errors: a red border, and the message under the field. What proves it was the browser that
 * caught it — and not the server, on a page that came back looking just the same — is the mark only the
 * browser's check leaves on a field, and a submit the page kept to itself (recordFormSubmits): nothing
 * was sent, so the page was never replaced.
 *
 * The email used is "someone@nowhere": the browser's own check for an email field lets it through (no dot
 * is needed after the @ there), so it is this page's check that has to stop it.
 */
class AuthFormsTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_sign_in_stops_a_malformed_email_and_a_missing_password_in_the_browser(): void
    {
        $this->browse(function (Browser $browser) {
            $this->freshSession($browser);
            $this->openForm($browser, '/login');

            $this->jsType($browser, 'input[name="email"]', 'someone@nowhere');
            $this->jsClick($browser, '@login-submit');

            $browser->waitForText('Email must be a valid email address.')
                ->assertSee('Password is required.')
                ->assertPresent('input[name="email"][data-client-invalid]')
                ->assertPresent('input[name="password"][data-client-invalid]');

            $this->assertSame(['stopped'], $this->formSubmits($browser), 'the sign-in form was sent to the server');
            $browser->assertPathIs('/login')->assertGuest();
        });
    }

    public function test_forgot_password_stops_an_empty_or_malformed_email_in_the_browser(): void
    {
        $this->browse(function (Browser $browser) {
            $this->freshSession($browser);
            $this->openForm($browser, '/forgot-password');

            // Nothing typed at all.
            $this->jsClick($browser, '@forgot-password-submit');
            $browser->waitForText('Email is required.')
                ->assertPresent('input[name="email"][data-client-invalid]');

            // Then an address that is not one. The first message gives way to the second rather than
            // piling up under the field.
            $this->jsType($browser, 'input[name="email"]', 'someone@nowhere');
            $this->jsClick($browser, '@forgot-password-submit');
            $browser->waitForText('Email must be a valid email address.')
                ->assertDontSee('Email is required.')
                ->assertPresent('input[name="email"][data-client-invalid]');

            $this->assertSame(['stopped', 'stopped'], $this->formSubmits($browser), 'a reset link was asked for anyway');
            $browser->assertPathIs('/forgot-password');
        });
    }

    public function test_the_new_password_page_stops_a_bad_email_a_short_password_and_a_mismatch_in_the_browser(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);

        $this->browse(function (Browser $browser) use ($owner) {
            $this->freshSession($browser);

            // The page the emailed link opens. Nothing is sent from it below, so the token it
            // carries is never even looked at.
            $this->openForm($browser, '/reset-password/a-link-token?email='.urlencode($owner->email));

            // A malformed address, and a password too short to be allowed.
            $this->jsType($browser, 'input[name="email"]', 'someone@nowhere');
            $this->jsType($browser, 'input[name="password"]', 'short');
            $this->jsType($browser, 'input[name="password_confirmation"]', 'short');
            $this->jsClick($browser, '@reset-password-submit');

            $browser->waitForText('Email must be a valid email address.')
                ->assertSee('Password must be at least 8 characters.')
                ->assertPresent('input[name="email"][data-client-invalid]')
                ->assertPresent('input[name="password"][data-client-invalid]');

            // Then a good address and a long enough password, typed differently the second time.
            $this->jsType($browser, 'input[name="email"]', $owner->email);
            $this->jsType($browser, 'input[name="password"]', 'a-brand-new-password');
            $this->jsType($browser, 'input[name="password_confirmation"]', 'a-brand-new-passwort');
            $this->jsClick($browser, '@reset-password-submit');

            $browser->waitForText('Password confirmation does not match.')
                ->assertDontSee('Email must be a valid email address.')
                ->assertMissing('input[name="email"][data-client-invalid]');

            $this->assertSame(['stopped', 'stopped'], $this->formSubmits($browser), 'the new password was sent to the server');
            $browser->assertPathBeginsWith('/reset-password/');
        });

        // The factory's password, untouched.
        $this->assertTrue(Hash::check('password', $owner->fresh()->password), 'the password changed');
    }

    /**
     * Open a sign-in page and wait for its form's own Alpine component — the submit check lives
     * there, and a submit pressed before it is wired would go straight to the server — then start
     * recording what becomes of its submits.
     */
    private function openForm(Browser $browser, string $path): void
    {
        $browser->visit($path)
            ->waitUntil("!!(document.querySelector('form[x-data]') && document.querySelector('form[x-data]')._x_dataStack)");

        $this->recordFormSubmits($browser);
    }
}
