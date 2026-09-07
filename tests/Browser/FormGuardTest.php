<?php

namespace Tests\Browser;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class FormGuardTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_frontend_validation_blocks_an_invalid_phone_before_any_request(): void
    {
        $admin = $this->seedSuperAdmin();

        $this->browse(function (Browser $browser) {
            $this->freshSession($browser);
            $browser->loginAs(User::where('email', 'admin@gmail.com')->first())
                ->visit('/users')
                ->click('@add-user')
                ->waitFor('@user-form')
                ->type('@user-first-name', 'Bad')
                ->type('@user-last-name', 'Phone')
                ->type('@user-phone', '123')
                ->type('@user-email', 'badphone@example.com')
                ->type('@user-password', 'password123')
                ->type('@user-password-confirm', 'password123')
                ->press('@user-save')
                ->waitForText('Phone must be exactly 10 digits.');
        });

        $this->assertDatabaseMissing('users', ['email' => 'badphone@example.com']);
    }

    public function test_frontend_validation_catches_a_password_mismatch(): void
    {
        $this->seedSuperAdmin();

        $this->browse(function (Browser $browser) {
            $this->freshSession($browser);
            $browser->loginAs(User::where('email', 'admin@gmail.com')->first())
                ->visit('/users')
                ->click('@add-user')
                ->waitFor('@user-form')
                ->type('@user-first-name', 'Mis')
                ->type('@user-last-name', 'Match')
                ->type('@user-phone', '1234567890')
                ->type('@user-email', 'mismatch@example.com')
                ->type('@user-password', 'password123')
                ->type('@user-password-confirm', 'different456')
                ->press('@user-save')
                ->waitForText('Password confirmation does not match.');
        });

        $this->assertDatabaseMissing('users', ['email' => 'mismatch@example.com']);
    }

    public function test_a_role_needs_at_least_one_permission_before_any_request(): void
    {
        $this->seedSuperAdmin();

        $this->browse(function (Browser $browser) {
            $this->freshSession($browser);
            $browser->loginAs(User::where('email', 'admin@gmail.com')->first())
                ->visit('/roles')
                ->click('@add-role')
                ->waitFor('@role-form')
                ->type('@role-name', 'Empty Role')
                ->press('@role-save')
                ->waitForText('Please select at least one permission.');
        });

        $this->assertDatabaseMissing('roles', ['name' => 'Empty Role']);
    }

    public function test_renaming_the_super_admin_role_shows_the_error_instead_of_failing_silently(): void
    {
        $this->seedSuperAdmin();

        $this->browse(function (Browser $browser) {
            $this->freshSession($browser);
            $browser->loginAs(User::where('email', 'admin@gmail.com')->first())->visit('/roles');
            $this->waitForAlpine($browser);
            $browser->waitForText('Super-Admin');

            // Fresh seed has exactly one role row — its Edit button opens the modal.
            $this->clickAndAwait($browser, '.btn-row-neutral', function (Browser $b) {
                $b->waitUsing(4, 100, fn () => $b->script(
                    'return (function () {'
                    ." const el = document.querySelector('[dusk=\"role-form\"]');"
                    .' if (!el || el.offsetParent === null || !window.Alpine) return false;'
                    .' return window.Alpine.$data(el).openingModal === false;'
                    .'})();'
                )[0]);
            });

            $this->jsType($browser, '@role-name', 'Boss');
            $this->jsClick($browser, '@role-save');

            // The server's guard message must actually SHOW (toast), not vanish.
            $browser->waitForText('The Super-Admin role is a system role and cannot be renamed.');
        });

        $this->assertDatabaseHas('roles', ['name' => 'Super-Admin']);
    }

    public function test_double_submitting_the_user_form_creates_exactly_one_user(): void
    {
        $this->seedSuperAdmin();

        $this->browse(function (Browser $browser) {
            $this->freshSession($browser);
            $browser->loginAs(User::where('email', 'admin@gmail.com')->first())
                ->visit('/users')
                ->click('@add-user')
                ->waitFor('@user-form')
                ->type('@user-first-name', 'Double')
                ->type('@user-last-name', 'Click')
                ->type('@user-phone', '5556667777')
                ->type('@user-email', 'doubleclick@example.com')
                ->type('@user-password', 'password123')
                ->type('@user-password-confirm', 'password123');

            // Two synchronous submits — exactly what a double click produces.
            $browser->script("const f = document.querySelector('[dusk=\"user-form\"]'); f.requestSubmit(); f.requestSubmit();");

            $browser->waitForText('doubleclick@example.com');
        });

        $this->assertSame(1, User::where('email', 'doubleclick@example.com')->count());
    }
}
