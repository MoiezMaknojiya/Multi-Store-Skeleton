<?php

namespace Tests\Browser;

use App\Models\Organization;
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

    /**
     * A session that ended under an open page — signed out in another tab, a long break, a password reset: the next
     * thing the page asks for is refused, and it says so once and goes to the sign-in, never a page of empty lists
     * with "Unauthenticated." in a corner (core/bootstrap.js).
     */
    public function test_a_session_that_ended_under_an_open_page_says_so_and_leads_to_sign_in(): void
    {
        $admin = $this->seedSuperAdmin();
        Organization::factory()->create(['name' => 'Alpha Mart']);

        $this->browse(function (Browser $browser) use ($admin) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/organizations');
            $this->waitForAlpine($browser);
            // The list has arrived: an answer still on its way would bring the session's cookie back with it.
            $browser->waitForText('Alpha Mart');

            // The session goes, as signing out in another tab makes it go; then the page asks for its list again.
            $browser->driver->manage()->deleteAllCookies();
            $this->jsType($browser, '@crud-search', 'alpha');

            $browser->waitForText('Your session has ended. Sign in again to carry on.')
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
