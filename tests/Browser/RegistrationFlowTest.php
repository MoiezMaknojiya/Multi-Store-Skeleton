<?php

namespace Tests\Browser;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class RegistrationFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    /** Client-side validation catches an empty form before any request leaves —
     *  fields go red with messages, and the page never navigates. */
    public function test_client_side_validation_blocks_an_empty_signup_before_any_request(): void
    {
        $this->seedSuperAdmin();

        $this->browse(function (Browser $browser) {
            $this->freshSession($browser);
            $browser->visit('/register')->waitFor('@register-form');
            $this->waitForAlpine($browser);

            $this->jsClick($browser, '[dusk="register-form"] button[type="submit"]');

            $browser->waitForText('First name is required.')
                ->assertSee('Store name is required.')
                ->assertPresent('[data-client-invalid]')
                ->assertPathIs('/register');
        });
    }

    /**
     * A brand-new customer signs themselves up: their details + their store in one
     * public form, becomes that store's Owner, and lands on their dashboard with the
     * store showing.
     */
    public function test_a_customer_signs_up_with_their_store_and_lands_on_their_dashboard(): void
    {
        $this->seedSuperAdmin(); // the Owner role every signup receives is put back if it is missing

        $this->browse(function (Browser $browser) {
            $this->freshSession($browser);

            $browser->visit('/login')
                ->waitForText('Create one')
                ->clickLink('Create one')
                ->waitFor('@register-form');

            $this->jsType($browser, '#first_name', 'Zara');
            $this->jsType($browser, '#last_name', 'Malik');
            $this->jsType($browser, '#phone', '9098097098');
            $this->jsType($browser, '#email', 'zara@example.com');
            $this->jsType($browser, '#password', 'password123');
            $this->jsType($browser, '#password_confirmation', 'password123');
            $this->jsType($browser, '#store_name', 'Zara Mart');
            $this->jsType($browser, '#street', '9 Mall Road');
            $this->jsType($browser, '#city', 'Austin');
            $browser->select('#state', 'TX');
            $this->jsType($browser, '#zip_code', '73301');

            // The new owner lands on their dashboard; their single store shows in
            // the header switcher (the role assignment itself is checked in the DB
            // below).
            $browser->press('Create Account')
                ->waitForLocation('/dashboard')
                ->waitForText('Zara Mart');

            $owner = User::where('email', 'zara@example.com')->firstOrFail();
            $store = Store::where('name', 'Zara Mart')->firstOrFail();
            $roleId = Role::starter(Role::OWNER)->id;

            $this->assertTrue(DB::table('store_user')->where([
                'user_id' => $owner->id,
                'store_id' => $store->id,
                'role_id' => $roleId,
            ])->exists());
        });
    }
}
