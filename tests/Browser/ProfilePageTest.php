<?php

namespace Tests\Browser;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ProfilePageTest extends DuskTestCase
{
    use DatabaseMigrations;

    /**
     * The profile forms validate on the client first — like every other form — so
     * an obvious mistake shows an inline error instead of a full page reload.
     * `data-client-invalid` is set only by the client validator, so its presence
     * proves the submit was handled in the browser (no server round trip).
     */
    public function test_profile_forms_show_client_side_required_errors_without_reloading(): void
    {
        $user = User::factory()->create(['first_name' => 'Original', 'email' => 'me@example.com']);

        $this->browse(function (Browser $browser) use ($user) {
            $this->freshSession($browser);
            $browser->loginAs($user)->visit('/profile');
            $this->waitForAlpine($browser);

            // -- Profile information: clearing a required field blocks the submit --
            $this->jsType($browser, 'input[name="first_name"]', '');
            $browser->script("document.querySelector('input[name=\"first_name\"]').closest('form').requestSubmit();");
            $browser->waitForText('First name is required.')
                ->assertPresent('input[name="first_name"][data-client-invalid]')
                ->assertPathIs('/profile'); // no reload/redirect

            // -- Update password: empty current password is caught client-side ---
            $browser->script("document.querySelector('input[name=\"current_password\"]').closest('form').requestSubmit();");
            $browser->waitForText('Current password is required.')
                ->assertPresent('input[name="current_password"][data-client-invalid]');

            // -- Delete account: open the modal, submit with no password ----------
            $browser->script("[...document.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Delete Account').click();");
            $browser->waitForText('Are you sure you want to delete your account?');
            // #password is unique to the delete modal (the update form uses a
            // different id), so submit that form specifically.
            $browser->script("document.querySelector('#password').closest('form').requestSubmit();");
            $browser->waitForText('Password is required.')
                ->assertPresent('#password[data-client-invalid]');
        });
    }

    /**
     * A changed email is confirmed by a link to the NEW address (owner's rule, 2026-09-29): the profile says it
     * is waiting and keeps the old one meanwhile, and the link from the email changes it.
     */
    public function test_a_changed_email_waits_for_the_link_sent_to_it(): void
    {
        $user = User::factory()->create(['first_name' => 'Sara', 'email' => 'sara@example.com']);

        $this->browse(function (Browser $browser) use ($user) {
            $this->freshSession($browser);
            $browser->loginAs($user)->visit('/profile');
            $this->waitForAlpine($browser);

            $logSizeBefore = $this->mailLogSize();
            $this->jsType($browser, 'input[name="email"]', 'sara@newshop.com');
            $browser->script("document.querySelector('input[name=\"email\"]').closest('form').requestSubmit();");

            // Waiting: the new address is named, and the field still holds the old one.
            $browser->waitFor('@profile-email-pending')
                ->assertSeeIn('@profile-email-pending', 'sara@newshop.com')
                ->assertInputValue('input[name="email"]', 'sara@example.com');
            $this->assertSame('sara@example.com', $user->fresh()->email);

            $browser->visit($this->linkFromMailLog($logSizeBefore, 'confirm-email'))
                ->waitFor('@profile-email-changed')
                ->assertSeeIn('@profile-email-changed', 'Your email is now sara@newshop.com.')
                ->assertMissing('@profile-email-pending')
                ->assertInputValue('input[name="email"]', 'sara@newshop.com');
            $this->assertSame('sara@newshop.com', $user->fresh()->email);
        });
    }

    /**
     * Staff and Viewers have no Members page and no Organizations tab, so Profile → Your organizations is where they leave an
     * organization. An organization's only Owner — on the Organizations tab of Settings — is told why they cannot, instead of being
     * offered a button that fails.
     */
    public function test_a_member_without_a_team_page_leaves_an_organization_from_the_profile(): void
    {
        $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $beta = Organization::factory()->create(['name' => "Joe's Diner"]);
        $worker = $this->organizationMember($alpha, Role::STAFF, 'worker@example.com');
        $worker->organizations()->attach($beta->id, ['role_id' => Role::starter(Role::VIEWER)->id]);
        $soleOwner = $this->organizationMember($beta, Role::OWNER, 'joe@example.com');

        $this->browse(function (Browser $browser) use ($worker, $soleOwner, $alpha, $beta) {
            $this->freshSession($browser);
            $browser->loginAs($worker)->visit('/profile');
            $this->waitForAlpine($browser);

            $browser->waitFor('@organization-membership-'.$beta->id)
                ->assertSeeIn('@organization-membership-'.$alpha->id, 'Staff')
                ->assertSeeIn('@organization-membership-'.$beta->id, 'Viewer');

            $this->clickAndAwait($browser, '@leave-organization-'.$beta->id, fn (Browser $b) => $b->waitFor('@leave-organization-'.$beta->id.'-confirm', 3));
            $browser->waitForReload(fn (Browser $b) => $this->jsClick($b, '@leave-organization-'.$beta->id.'-confirm'));

            $browser->waitForText("You left Joe's Diner.")
                ->assertMissing('@organization-membership-'.$beta->id)
                ->assertPresent('@organization-membership-'.$alpha->id);

            // The only Owner of an organization gets the reason, not a Leave button.
            $this->freshSession($browser);
            $browser->loginAs($soleOwner);
            $this->switchToOrganization($browser, $beta);
            $browser->visit('/settings/organization');
            $this->waitForAlpine($browser);
            $browser->waitFor('@organization-membership-'.$beta->id)
                ->assertSeeIn('@organization-membership-'.$beta->id, 'You are its only Owner')
                ->assertMissing('@leave-organization-'.$beta->id);
        });

        $this->assertFalse(DB::table('organization_user')->where(['user_id' => $worker->id, 'organization_id' => $beta->id])->exists());
        $this->assertTrue(DB::table('organization_user')->where(['user_id' => $worker->id, 'organization_id' => $alpha->id])->exists());
    }
}
