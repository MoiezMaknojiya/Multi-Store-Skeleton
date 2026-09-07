<?php

namespace Tests\Browser;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
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
}
