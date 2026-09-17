<?php

namespace Tests\Browser;

use App\Models\Invitation;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The whole life of a team member through the real pages (docs/STORE-ORGANIZATION-SPEC.md):
 * invited from Members, joined from the link in the email that was actually sent, given
 * another role, removed.
 */
class TeamInvitationFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_an_owner_invites_a_person_who_joins_from_the_email_and_is_then_managed(): void
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->storeMember($store, Role::OWNER, 'owner@example.com');

        $this->browse(function (Browser $browser) use ($owner, $store) {
            /* ── 1. The Owner invites someone ───────────────────────────── */
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToStore($browser, $store);
            $browser->visit('/members');
            $this->waitForAlpine($browser);
            $browser->waitForText('owner@example.com');

            $logSizeBefore = $this->mailLogSize();

            $this->clickAndAwait($browser, '@invite-member', fn (Browser $b) => $b->waitFor('@invite-form', 3));
            $this->jsType($browser, '@invite-email', 'new.hire@example.com');
            $browser->select('@invite-role', (string) Role::starter(Role::STAFF)->id);
            $this->jsClick($browser, '@invite-send');

            // The page moves to the invitations tab and reloads it: the new one is listed.
            $browser->waitForText('Invitation sent to new.hire@example.com.')
                ->waitForTextIn('@invitations-table', 'new.hire@example.com');

            /* ── 2. The person opens the emailed link, signed out ────────── */
            $token = $this->tokenFromMailLog($logSizeBefore, 'invitations');

            $this->freshSession($browser);
            $browser->visit('/invitations/'.$token)
                ->waitFor('@invitation-register-form')
                ->assertSee('Join Alpha Mart')
                ->assertSeeIn('@invitation-role', 'Staff');

            $this->jsType($browser, '#first_name', 'New');
            $this->jsType($browser, '#last_name', 'Hire');
            $this->jsType($browser, '#phone', '5551234567');
            $this->jsType($browser, '#password', 'Str0ng-Password!');
            $this->jsType($browser, '#password_confirmation', 'Str0ng-Password!');
            $this->jsClick($browser, '@invitation-register');

            $browser->waitForLocation('/dashboard')->waitForText('Welcome to Alpha Mart!');

            $hire = User::where('email', 'new.hire@example.com')->firstOrFail();
            $this->assertNotNull($hire->email_verified_at, 'opening the emailed link proves the inbox');
            $this->assertSame(0, Invitation::count(), 'an accepted invitation is used up');

            /* ── 3. The Owner changes their role, then removes them ──────── */
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToStore($browser, $store);
            $browser->visit('/members');
            $this->waitForAlpine($browser);
            $browser->waitForText('new.hire@example.com')
                ->assertSeeIn('@member-role-'.$hire->id, 'Staff');

            $this->clickAndAwait($browser, '@change-role-'.$hire->id, fn (Browser $b) => $b->waitFor('@change-role-form', 3));
            $browser->select('@change-role-select', (string) Role::starter(Role::ADMIN)->id);
            $this->jsClick($browser, '@change-role-save');

            $browser->waitForText('New Hire is now Admin.')
                ->waitForTextIn('@member-role-'.$hire->id, 'Admin');
            $this->waitForModalClosed($browser, '@change-role-form');

            // Removing somebody is a big delete: it asks for the password first.
            $this->clickAndAwait($browser, '@remove-member-'.$hire->id, fn (Browser $b) => $b->waitFor('@remove-member-confirm', 3));
            $this->jsClick($browser, '@remove-member-confirm');
            $browser->waitForText('Password is required.');
            $this->jsType($browser, '@remove-member-password', 'password');
            $this->jsClick($browser, '@remove-member-confirm');

            $browser->waitForText('New Hire was removed from Alpha Mart.')
                ->waitUntilMissing('@member-row-'.$hire->id);

            $this->assertFalse(DB::table('store_user')->where(['user_id' => $hire->id, 'store_id' => $store->id])->exists());
            $this->assertNotNull($hire->fresh(), 'removing a member never deletes their account');
        });
    }

    /** The account form on a link checks itself before anything is sent, like every other form. */
    public function test_the_account_form_on_a_link_catches_mistakes_before_sending(): void
    {
        $this->seedSuperAdmin();
        $token = str_repeat('v', 64);
        Invitation::factory()->withToken($token)->create(['email' => 'careful@example.com']);

        $this->browse(function (Browser $browser) use ($token) {
            $this->freshSession($browser);
            $browser->visit('/invitations/'.$token)->waitFor('@invitation-register-form');
            $this->waitForAlpine($browser);

            $this->jsType($browser, '#phone', '123');
            $this->jsType($browser, '#password', 'Str0ng-Password!');
            $this->jsType($browser, '#password_confirmation', 'Different-Password!');
            $browser->script("document.querySelector('[dusk=\"invitation-register-form\"]').requestSubmit();");

            $browser->waitForText('First name is required.')
                ->assertSee('Phone must be exactly 10 digits.')
                ->assertSee('Password confirmation does not match.')
                ->assertPresent('[data-client-invalid]')
                ->assertPathIs('/invitations/'.$token);
        });

        $this->assertDatabaseMissing('users', ['email' => 'careful@example.com']);
    }

    /** A link that has expired says so, and offers nothing to fill in. */
    public function test_an_expired_link_explains_itself(): void
    {
        $this->seedSuperAdmin();
        $token = str_repeat('x', 64);
        Invitation::factory()->expired()->withToken($token)->create(['email' => 'late@example.com']);

        $this->browse(function (Browser $browser) use ($token) {
            $this->freshSession($browser);
            $browser->visit('/invitations/'.$token)
                ->waitFor('@invitation-invalid')
                ->assertSee('This invitation is no longer valid')
                ->assertMissing('@invitation-register-form');
        });
    }
}
