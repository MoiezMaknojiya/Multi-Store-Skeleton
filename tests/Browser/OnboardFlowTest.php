<?php

namespace Tests\Browser;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class OnboardFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    /**
     * The one-step customer flow: owner details + store details in a single form,
     * with the owner role pre-selected — one save creates all three records.
     */
    public function test_an_owner_and_their_store_are_onboarded_in_one_form(): void
    {
        $this->seedSuperAdmin();
        // The seeder already provides the "Store Owner" signup-default role.
        $ownerRole = Role::where('is_signup_default', true)->firstOrFail();

        $this->browse(function (Browser $browser) use ($ownerRole) {
            $this->freshSession($browser);

            $browser->loginAs(User::where('email', 'admin@gmail.com')->first())->visit('/users');
            $this->waitForAlpine($browser);

            $this->clickAndAwait($browser, '@onboard-button', fn (Browser $b) => $b->waitFor('@onboard-form', 3));

            // The owner role arrives pre-selected by default.
            $selected = $browser->script("return document.querySelector('[dusk=\"onboard-role\"]').value;")[0];
            $this->assertSame((string) $ownerRole->id, (string) $selected);

            $this->jsType($browser, '@onboard-first-name', 'Nadia');
            $this->jsType($browser, '@onboard-last-name', 'Owner');
            $this->jsType($browser, '@onboard-phone', '7778889999');
            $this->jsType($browser, '@onboard-email', 'nadia@example.com');
            $this->jsType($browser, '@onboard-password', 'password123');
            $this->jsType($browser, '@onboard-password-confirm', 'password123');
            $this->jsType($browser, '@onboard-store-name', 'Nadia Mart');
            $this->jsType($browser, '@onboard-street', '5 Bazaar Rd');
            $this->jsType($browser, '@onboard-city', 'Austin');
            $browser->select('@onboard-state', 'TX');
            $this->jsType($browser, '@onboard-zip', '73301');

            $this->jsClick($browser, '@onboard-save');
            $browser->waitForText('nadia@example.com');

            $owner = User::where('email', 'nadia@example.com')->firstOrFail();
            $store = Store::where('name', 'Nadia Mart')->firstOrFail();
            $this->assertTrue(DB::table('store_user')->where([
                'user_id' => $owner->id,
                'store_id' => $store->id,
                'role_id' => $ownerRole->id,
            ])->exists());
        });
    }
}
