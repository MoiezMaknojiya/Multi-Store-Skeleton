<?php

namespace Tests\Browser;

use App\Models\Role;
use App\Models\Organization;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class MultiOrganizationUserTest extends DuskTestCase
{
    use DatabaseMigrations;

    /**
     * One person in two organizations with a different role in each: both organizations show on the
     * selection page, and switching organizations switches their powers — Admin in Alpha (the team
     * page, with its Invite button), Staff in Beta (no team page at all).
     */
    public function test_one_person_in_two_organizations_gets_a_different_role_in_each(): void
    {
        $this->seedSuperAdmin();

        $organizationA = Organization::factory()->create(['name' => 'Alpha Organization']);
        $organizationB = Organization::factory()->create(['name' => 'Beta Organization']);
        $worker = $this->organizationMember($organizationA, Role::ADMIN, 'multi-organization@example.com');
        $worker->organizations()->attach($organizationB->id, ['role_id' => Role::starter(Role::STAFF)->id]);

        $this->browse(function (Browser $browser) use ($worker, $organizationA, $organizationB) {
            $this->freshSession($browser);

            // -- The selection page lists BOTH organizations, each with its own role ----
            $browser->loginAs($worker)->visit('/select-organization');
            $browser->waitForText('Alpha Organization')
                ->assertSee('Beta Organization')
                ->assertSee('Admin')
                ->assertSee('Staff');

            // -- In Alpha they are an Admin: the team page, with Invite ----------
            $this->switchToOrganization($browser, $organizationA);
            $browser->visit('/members');
            $this->waitForAlpine($browser);
            $browser->waitForText('multi-organization@example.com')
                ->assertPresent('@invite-member');

            // -- In Beta, only Staff: the team page is not theirs ----------------
            // The switch itself first: the header names the organization being worked in, so the
            // refusal below is Beta's answer and not Alpha's still standing.
            $this->switchToOrganization($browser, $organizationB);
            $browser->visit('/dashboard');
            $this->waitForAlpine($browser);
            $browser->assertSeeIn('@organization-switcher', 'Beta Organization');

            $browser->visit('/members')->assertSee('403')->assertMissing('@invite-member');
        });
    }

    /**
     * The organization selection page is a focused, sidebar-less picker; and once inside an
     * organization, the HEADER switcher (not a dashboard link) changes the active organization.
     */
    public function test_the_selection_page_has_no_sidebar_and_the_header_switcher_changes_organizations(): void
    {
        $this->seedSuperAdmin();

        $organizationA = Organization::factory()->create(['name' => 'Alpha Organization']);
        $organizationB = Organization::factory()->create(['name' => 'Beta Organization']);
        $worker = $this->organizationMember($organizationA, Role::ADMIN, 'switcher@example.com');
        $worker->organizations()->attach($organizationB->id, ['role_id' => Role::starter(Role::STAFF)->id]);

        $this->browse(function (Browser $browser) use ($worker, $organizationA, $organizationB) {
            $this->freshSession($browser);

            // -- The picker is a focused page: no sidebar nav --------------------
            $browser->loginAs($worker)->visit('/select-organization');
            $browser->waitForText('Choose an organization')
                ->assertMissing('#main-sidebar')
                ->assertSee('Alpha Organization')
                ->assertSee('Beta Organization');

            // -- The WHOLE card picks the organization, not just the words at the bottom.
            //    Asking the browser what it would hit is the only honest check —
            //    the overlay that does this is invisible, so nothing about the
            //    rendered text would reveal it had stopped working.
            $corners = $browser->script(<<<JS
                const button = document.querySelector('[dusk="switch-organization-{$organizationA->id}"]');
                const card = button.closest('.relative');
                const r = card.getBoundingClientRect();
                // Just inside each rounded corner: a point in the corner's cut-away is page, not card.
                const i = parseFloat(getComputedStyle(card).borderTopLeftRadius || '0') * 0.3 + 4;

                return [
                    [r.x + i, r.y + i],                          // top-left
                    [r.x + r.width - i, r.y + i],                // top-right
                    [r.x + r.width / 2, r.y + r.height / 2],     // dead centre
                    [r.x + i, r.y + r.height - i],               // bottom-left
                ].map(([x, y]) => document.elementFromPoint(x, y) === button);
            JS)[0];

            $this->assertSame([true, true, true, true], $corners,
                'part of the organization card is not clickable');

            // Pick Alpha (Admin) → the full app shell with its sidebar is back.
            $this->switchToOrganization($browser, $organizationA);
            $browser->visit('/members');
            $this->waitForAlpine($browser);
            $browser->assertPresent('#main-sidebar')
                ->assertPresent('@organization-switcher')
                ->assertPresent('@invite-member');

            // -- Switch to Beta from the HEADER dropdown (Staff: no team page) ---
            $browser->assertSeeIn('@organization-switcher', 'Alpha Organization');
            $this->jsClick($browser, '@organization-switcher');
            $browser->waitForReload(fn (Browser $b) => $this->jsClick($b, '@organization-switch-'.$organizationB->id));

            // The header now names Beta — the switch took, rather than the button merely vanishing.
            $browser->assertPathIs('/dashboard')
                ->assertSeeIn('@organization-switcher', 'Beta Organization');
            $browser->visit('/members')->assertSee('403')->assertMissing('@invite-member');
        });
    }

    /** The team page shows the people of the organization being worked in, never the other one. */
    public function test_the_members_page_lists_only_the_current_organization_s_team(): void
    {
        $this->seedSuperAdmin();

        $organizationA = Organization::factory()->create(['name' => 'Alpha Organization']);
        $organizationB = Organization::factory()->create(['name' => 'Beta Organization']);
        $owner = $this->organizationMember($organizationA, Role::OWNER, 'owner@example.com');
        $owner->organizations()->attach($organizationB->id, ['role_id' => Role::starter(Role::OWNER)->id]);
        $this->organizationMember($organizationA, Role::STAFF, 'alpha.staff@example.com');
        $this->organizationMember($organizationB, Role::STAFF, 'beta.staff@example.com');

        $this->browse(function (Browser $browser) use ($owner, $organizationA, $organizationB) {
            $this->freshSession($browser);
            $browser->loginAs($owner);

            $this->switchToOrganization($browser, $organizationA);
            $browser->visit('/members');
            $this->waitForAlpine($browser);
            $browser->waitForText('alpha.staff@example.com')->assertDontSee('beta.staff@example.com');

            $this->switchToOrganization($browser, $organizationB);
            $browser->visit('/members');
            $this->waitForAlpine($browser);
            $browser->waitForText('beta.staff@example.com')->assertDontSee('alpha.staff@example.com');
        });
    }
}
