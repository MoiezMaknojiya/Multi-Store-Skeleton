<?php

namespace Tests\Browser;

use App\Models\Role;
use App\Models\Store;
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
     * Staff and Viewers have no Members page and no Stores tab, so Profile → Your stores is where they leave a
     * store. A store's only Owner — on the Stores tab of Settings — is told why they cannot, instead of being
     * offered a button that fails.
     */
    public function test_a_member_without_a_team_page_leaves_a_store_from_the_profile(): void
    {
        $this->seedSuperAdmin();
        $alpha = Store::factory()->create(['name' => 'Alpha Mart']);
        $beta = Store::factory()->create(['name' => "Joe's Diner"]);
        $worker = $this->storeMember($alpha, Role::STAFF, 'worker@example.com');
        $worker->stores()->attach($beta->id, ['role_id' => Role::starter(Role::VIEWER)->id]);
        $soleOwner = $this->storeMember($beta, Role::OWNER, 'joe@example.com');

        $this->browse(function (Browser $browser) use ($worker, $soleOwner, $alpha, $beta) {
            $this->freshSession($browser);
            $browser->loginAs($worker)->visit('/profile');
            $this->waitForAlpine($browser);

            $browser->waitFor('@store-membership-'.$beta->id)
                ->assertSeeIn('@store-membership-'.$alpha->id, 'Staff')
                ->assertSeeIn('@store-membership-'.$beta->id, 'Viewer');

            $this->clickAndAwait($browser, '@leave-store-'.$beta->id, fn (Browser $b) => $b->waitFor('@leave-store-'.$beta->id.'-confirm', 3));
            $browser->waitForReload(fn (Browser $b) => $this->jsClick($b, '@leave-store-'.$beta->id.'-confirm'));

            $browser->waitForText("You left Joe's Diner.")
                ->assertMissing('@store-membership-'.$beta->id)
                ->assertPresent('@store-membership-'.$alpha->id);

            // The only Owner of a store gets the reason, not a Leave button.
            $this->freshSession($browser);
            $browser->loginAs($soleOwner);
            $this->switchToStore($browser, $beta);
            $browser->visit('/settings/store');
            $this->waitForAlpine($browser);
            $browser->waitFor('@store-membership-'.$beta->id)
                ->assertSeeIn('@store-membership-'.$beta->id, 'You are its only Owner')
                ->assertMissing('@leave-store-'.$beta->id);
        });

        $this->assertFalse(DB::table('store_user')->where(['user_id' => $worker->id, 'store_id' => $beta->id])->exists());
        $this->assertTrue(DB::table('store_user')->where(['user_id' => $worker->id, 'store_id' => $alpha->id])->exists());
    }
}
