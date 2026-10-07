<?php

namespace Tests\Browser;

use App\Models\Organization;
use App\Models\Role;
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
                ->assertSee('Organization name is required.')
                ->assertPresent('[data-client-invalid]')
                ->assertPathIs('/register');
        });
    }

    /**
     * A brand-new customer signs themselves up: their details + their organization in one
     * public form, and becomes that organization's Owner. The panel waits for their email
     * (owner's rule, 2026-09-29): "Check your inbox" says where the link went, the
     * dashboard sends them back there, and the link from the email opens their
     * dashboard with the organization showing.
     */
    public function test_a_customer_signs_up_confirms_their_email_and_lands_on_their_dashboard(): void
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
            $this->jsType($browser, '#organization_name', 'Zara Mart');
            $this->jsType($browser, '#street', '9 Mall Road');
            $this->jsType($browser, '#city', 'Austin');
            $browser->select('#state', 'TX');
            $this->jsType($browser, '#zip_code', '73301');

            // "Check your inbox", naming the address the link went to.
            $logSizeBefore = $this->mailLogSize();
            $browser->press('Create Account')
                ->waitForLocation('/verify-email')
                ->assertSeeIn('@verify-email-address', 'zara@example.com');

            // Nothing of the panel opens before the link does.
            $browser->visit('/dashboard')->waitForLocation('/verify-email');

            // The link from the email: the new owner lands on their dashboard, told so; their
            // single organization shows in the header switcher (the role assignment itself is checked
            // in the DB below).
            $browser->visit($this->linkFromMailLog($logSizeBefore, 'verify-email'))
                ->waitForLocation('/dashboard')
                ->waitForText('Your email is confirmed. Welcome!')
                ->waitForText('Zara Mart');

            $owner = User::where('email', 'zara@example.com')->firstOrFail();
            $this->assertTrue($owner->hasVerifiedEmail());
            $organization = Organization::where('name', 'Zara Mart')->firstOrFail();
            $roleId = Role::starter(Role::OWNER)->id;

            $this->assertTrue(DB::table('organization_user')->where([
                'user_id' => $owner->id,
                'organization_id' => $organization->id,
                'role_id' => $roleId,
            ])->exists());
        });
    }

    /**
     * A super admin's "Log In As" an account that has not confirmed lands on "Check your inbox", where a button only
     * they see confirms the email and opens the panel (owner, 2026-10-06). The customer, signed in themselves, never
     * sees it.
     */
    public function test_a_super_admin_logged_in_as_a_customer_who_has_not_confirmed_confirms_it_and_goes_on(): void
    {
        $admin = $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Smart Stop']);
        $customer = $this->organizationMember($organization, Role::OWNER, 'tosif@example.com');
        $customer->forceFill(['email_verified_at' => null])->save();

        $this->browse(function (Browser $browser) use ($admin, $customer) {
            $this->freshSession($browser);
            $browser->loginAs($customer)->visit('/verify-email')
                ->waitFor('@verify-email-page')
                ->assertMissing('@verify-email-confirm-for-them');

            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/users');
            $this->waitForAlpine($browser);
            $browser->waitForText($customer->email);
            $browser->waitForReload(fn (Browser $b) => $this->jsClick($b, '@impersonate-'.$customer->id));
            $browser->waitForLocation('/verify-email')
                ->assertVisible('@verify-email-impersonating')
                ->assertSeeIn('@verify-email-confirm-box', 'As a super admin');

            // One press: confirmed, and on into the panel, still viewing as the customer. (By script, as Log In As
            // above: a pointer's click that lands while the page is still settling can miss the button.)
            $browser->waitForReload(fn (Browser $b) => $this->jsClick($b, '@verify-email-confirm-for-them'))
                ->waitForLocation('/dashboard')
                ->waitForText('tosif@example.com is confirmed.');

            $this->assertTrue($customer->fresh()->hasVerifiedEmail());
        });
    }
}
