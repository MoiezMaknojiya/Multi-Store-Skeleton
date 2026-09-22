<?php

namespace Tests\Browser;

use App\Models\Invitation;
use App\Models\Role;
use App\Models\Store;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The frontend guards on the AJAX forms: client-side validation that stops an obvious mistake
 * before any request leaves, server errors that are SHOWN rather than swallowed, and the
 * in-flight flag that turns a double click into one request.
 */
class FormGuardTest extends DuskTestCase
{
    use DatabaseMigrations;

    /** An Owner signed in to their store, on the given page with Alpine ready. */
    private function ownerOn(Browser $browser, string $path): void
    {
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->storeMember($store, Role::OWNER);

        $this->freshSession($browser);
        $browser->loginAs($owner);
        $this->switchToStore($browser, $store);
        $browser->visit($path);
        $this->waitForAlpine($browser);
    }

    private function openInviteForm(Browser $browser): void
    {
        // The role list arrives with the team data — wait for it before opening the form.
        $browser->waitForText('owner@example.com');
        $this->clickAndAwait($browser, '@invite-member', fn (Browser $b) => $b->waitFor('@invite-form', 3));
    }

    public function test_the_invite_form_blocks_a_malformed_email_before_any_request(): void
    {
        $this->seedSuperAdmin();

        $this->browse(function (Browser $browser) {
            $this->ownerOn($browser, '/members');
            $this->openInviteForm($browser);

            // No dot in the domain: the browser's own email check lets this through, ours must not.
            $this->jsType($browser, '@invite-email', 'someone@nowhere');
            $browser->select('@invite-role', (string) Role::starter(Role::STAFF)->id);
            $this->jsClick($browser, '@invite-send');

            $browser->waitForText('Email must be a valid email address.')
                ->assertVisible('@invite-form');
        });

        $this->assertSame(0, Invitation::count());
    }

    public function test_the_invite_form_needs_a_role(): void
    {
        $this->seedSuperAdmin();

        $this->browse(function (Browser $browser) {
            $this->ownerOn($browser, '/members');
            $this->openInviteForm($browser);

            $this->jsType($browser, '@invite-email', 'new.hire@example.com');
            $this->jsClick($browser, '@invite-send');

            $browser->waitForText('Role is required.');
        });

        $this->assertSame(0, Invitation::count());
    }

    public function test_a_custom_role_needs_at_least_one_permission_before_any_request(): void
    {
        $this->seedSuperAdmin();

        $this->browse(function (Browser $browser) {
            $this->ownerOn($browser, '/roles');
            $browser->waitFor('@role-row-'.Role::owner()->id);

            $this->clickAndAwait($browser, '@create-role', fn (Browser $b) => $b->waitFor('@permission-screen-view', 5));

            // The server refuses an empty role in the very same words, so the message alone cannot
            // say which of the two stopped it — the count of what left the page can.
            $this->countRequests($browser, 'POST', '/roles');

            $this->jsType($browser, '@role-name', 'Empty Role');
            $this->jsClick($browser, '@role-save');

            $browser->waitForText('Choose at least one permission.');
            $this->assertSame(0, $this->requestsCounted($browser), 'the empty role was sent to the server');
        });

        $this->assertDatabaseMissing('roles', ['name' => 'Empty Role']);
    }

    /** A rule only the server knows must still reach the person, under the field it is about. */
    public function test_a_store_role_name_is_refused_with_the_reason_shown(): void
    {
        $this->seedSuperAdmin();

        $this->browse(function (Browser $browser) {
            $this->ownerOn($browser, '/roles');
            $browser->waitFor('@role-row-'.Role::owner()->id);

            $this->clickAndAwait($browser, '@create-role', fn (Browser $b) => $b->waitFor('@permission-screen-view', 5));
            $this->jsType($browser, '@role-name', 'owner');
            $this->jsClick($browser, '@permission-screen-view');
            $this->jsClick($browser, '@role-save');

            $browser->waitForTextIn('@role-form', 'There is already a store role called Owner, and every store has it. Choose another name.');
        });

        $this->assertSame(1, Role::whereRaw('lower(name) = ?', ['owner'])->count());
    }

    public function test_double_submitting_the_invite_form_sends_exactly_one_request(): void
    {
        $this->seedSuperAdmin();

        $this->browse(function (Browser $browser) {
            $this->ownerOn($browser, '/members');
            $this->openInviteForm($browser);

            $this->jsType($browser, '@invite-email', 'double@example.com');
            $browser->select('@invite-role', (string) Role::starter(Role::STAFF)->id);

            // Count what actually leaves the browser — the server would refuse a second
            // invitation for the same email anyway, so the database alone cannot prove the guard.
            $this->countRequests($browser, 'POST', '/members/invitations');

            // Two synchronous submits — exactly what a double click produces.
            $browser->script("const f = document.querySelector('[dusk=\"invite-form\"]'); f.requestSubmit(); f.requestSubmit();");

            $browser->waitForText('Invitation sent to double@example.com.');
            $this->assertSame(1, $this->requestsCounted($browser));
        });

        $this->assertSame(1, Invitation::where('email', 'double@example.com')->count());
    }
}
