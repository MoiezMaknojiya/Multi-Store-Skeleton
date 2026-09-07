<?php

namespace Tests\Browser;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class AuthFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_a_user_can_log_in_through_the_form_and_log_out(): void
    {
        $this->seedSuperAdmin();

        $this->browse(function (Browser $browser) {
            $this->freshSession($browser);
            $browser->visit('/login')
                ->type('email', 'admin@gmail.com')
                ->type('password', 'test')
                ->press('Sign In')
                ->waitForLocation('/dashboard')
                ->assertPathIs('/dashboard')
                ->click('@user-menu')
                ->waitFor('@logout-button')
                ->click('@logout-button')
                ->waitForLocation('/login')
                ->assertGuest();
        });
    }

    public function test_a_wrong_password_is_rejected_on_the_login_form(): void
    {
        $this->seedSuperAdmin();

        $this->browse(function (Browser $browser) {
            $this->freshSession($browser);
            $browser->visit('/login')
                ->type('email', 'admin@gmail.com')
                ->type('password', 'wrong-password')
                ->press('Sign In')
                ->waitForText('These credentials do not match our records.')
                ->assertPathIs('/login')
                ->assertGuest();
        });
    }
}
