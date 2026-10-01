<?php

namespace Tests\Browser;

use App\Models\Organization;
use App\Models\Role;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Settings → Organizations and the Members page's Leave, through the real pages.
 *
 * Every button on Settings → Organizations belongs to a PLAIN form — no AJAX — so a mistake shows up as a
 * page that comes back looking the same with an error nobody notices, which no backend test would
 * catch. The cards are each gated by their own permission (docs/ORGANIZATION-SPEC.md §I).
 * Leave, on the Members page, is the one AJAX call here: it asks the server, then goes wherever
 * the server says.
 */
class OrganizationSettingsFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_an_owner_saves_the_organization_details_from_the_settings_tab(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart', 'city' => 'Austin', 'state' => 'TX']);
        $owner = $this->organizationMember($organization, Role::OWNER);

        $this->browse(function (Browser $browser) use ($owner, $organization) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToOrganization($browser, $organization);

            $browser->visit('/settings/organization');
            $this->waitForAlpine($browser);
            $browser->waitFor('@organization-details-form')->assertSee('Organization Details');

            $this->jsType($browser, '#name', 'Alpha Mart Downtown');
            $this->jsType($browser, '#street', '500 Congress Ave');
            $this->jsType($browser, '#city', 'Dallas');
            $browser->select('#state', 'TX');
            $this->jsType($browser, '#zip_code', '75001');
            $this->jsType($browser, '#country', 'USA');

            $browser->waitForReload(fn (Browser $b) => $this->jsClick($b, '@organization-details-save'));

            $browser->assertSee('Alpha Mart Downtown');
            $this->assertSame('Alpha Mart Downtown', $organization->fresh()->name);
            $this->assertSame('Dallas', $organization->fresh()->city);
        });
    }

    public function test_a_member_allowed_to_create_an_organization_opens_one_and_owns_it_at_once(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        // View Organizations shows the tab; Create Organizations puts the Create organization button on its "Your organizations"
        // card. Nothing else is needed for this.
        $opener = $this->organizationMember($organization, ['organization-view', 'organization-store'], 'opener@example.com', 'Opener');

        $this->browse(function (Browser $browser) use ($opener, $organization) {
            $this->freshSession($browser);
            $browser->loginAs($opener);
            $this->switchToOrganization($browser, $organization);

            $browser->visit('/settings/organization');
            $this->waitForAlpine($browser);

            // Create organization lives in a modal on the "Your organizations" card.
            $browser->waitFor('@your-organizations');
            $this->clickAndAwait($browser, '@open-organization-button', fn (Browser $b) => $b->waitFor('@open-organization-form', 3));

            $this->jsType($browser, '@open-organization-name', 'Beta Deli');
            $this->jsType($browser, '@open-organization-street', '12 Elm St');
            $this->jsType($browser, '@open-organization-suite', 'Unit 4');   // the one optional field
            $this->jsType($browser, '@open-organization-city', 'Plano');
            $browser->select('@open-organization-state', 'TX');
            $this->jsType($browser, '@open-organization-zip', '75024');
            $this->jsType($browser, '@open-organization-country', 'USA');

            $browser->waitForReload(fn (Browser $b) => $this->jsClick($b, '@open-organization-confirm'));

            // The organization exists, and the person who opened it holds the Owner role in it.
            $opened = Organization::where('name', 'Beta Deli')->firstOrFail();
            $this->assertSame('Unit 4', $opened->suite);
            $this->assertSame(
                Role::owner()->id,
                DB::table('organization_user')->where('user_id', $opener->id)->where('organization_id', $opened->id)->value('role_id'),
                'whoever opens an organization owns it at once'
            );

            // And the tab now lists both of their organizations.
            $browser->visit('/settings/organization');
            $this->waitForAlpine($browser);
            $browser->waitFor('@your-organizations')
                ->assertSeeIn('@your-organizations', 'Beta Deli')
                ->assertSeeIn('@your-organizations', 'Alpha Mart');
        });
    }

    public function test_a_member_leaves_an_organization_from_the_members_page(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $this->organizationMember($organization, Role::OWNER);   // the organization keeps its Owner
        $leaver = $this->organizationMember($organization, ['member-view'], 'leaver@example.com', 'Watcher');

        $this->browse(function (Browser $browser) use ($leaver, $organization) {
            $this->freshSession($browser);
            $browser->loginAs($leaver);
            $this->switchToOrganization($browser, $organization);

            $browser->visit('/members');
            $this->waitForAlpine($browser);
            $browser->waitForText('leaver@example.com');

            $this->clickAndAwait($browser, '@leave-organization', fn (Browser $b) => $b->waitFor('@leave-organization-confirm', 3));

            // Leaving is an AJAX call that then sends the browser wherever the server says — the
            // dashboard (MemberController::leave) — so wait for that address rather than for a
            // form's own reload. The browser only goes there once the server has answered.
            $this->jsClick($browser, '@leave-organization-confirm');
            $browser->waitForLocation('/dashboard', 8);

            $this->assertFalse(
                DB::table('organization_user')->where('user_id', $leaver->id)->where('organization_id', $organization->id)->exists(),
                'leaving takes the membership row and nothing else'
            );
            // The organization and its Owner are untouched.
            $this->assertSame(1, DB::table('organization_user')->where('organization_id', $organization->id)->count());
        });
    }
}
