<?php

namespace Tests\Browser;

use App\Models\Channel;
use App\Models\Role;
use App\Models\Organization;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The owner's rules of 2026-09-16 and 2026-09-17, through the real pages: the super admin makes an organization role
 * carrying platform permissions on the Roles page, gives it to one person from the Users page (Organizations) — and
 * that person then works with Channels, the Activity Log and the Organizations tab of Settings inside their own organization alone.
 */
class OrganizationScopedAccessFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_the_super_admin_gives_one_person_platform_permissions_that_reach_their_own_organization_alone(): void
    {
        $admin = $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $beta = Organization::factory()->create(['name' => 'Beta Deli']);
        $this->organizationMember($alpha, Role::OWNER, 'olive@example.com');
        $sam = $this->organizationMember($alpha, Role::STAFF, 'sam@example.com');
        Channel::factory()->create(['name' => 'Beta Specials', 'organization_id' => $beta->id]);
        $gama = Channel::factory()->create(['name' => 'GAMA']);

        $this->browse(function (Browser $browser) use ($admin, $alpha, $sam, $gama) {
            /* ── 1. Roles → Create role → Organization role: offered in every organization, with what works inside an organization ── */
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/roles');
            $this->waitForAlpine($browser);

            $this->clickAndAwait($browser, '@create-role', fn (Browser $b) => $b->waitFor('@permission-channel-store', 5));
            $browser->assertScript("document.querySelector('[dusk=\"role-type-organization\"]').checked", true)
                ->assertMissing('@permission-activity-destroy')
                ->assertMissing('@permission-permission-view')
                ->assertSeeIn('@permission-group-activity', 'This organization only');

            $this->jsType($browser, '@role-name', 'Area Manager');
            foreach (['screen-view', 'organization-view', 'channel-view', 'channel-store', 'activity-view'] as $permission) {
                $this->jsClick($browser, '@permission-'.$permission);
            }
            $this->jsClick($browser, '@role-save');
            $browser->waitForText('Role Area Manager created.');

            $role = Role::where('name', 'Area Manager')->firstOrFail();
            $this->assertNull($role->organization_id);
            $this->assertFalse($role->is_global);

            /* ── 2. Users → Organizations: Sam becomes Area Manager in Alpha Mart ───────────────────── */
            $browser->visit('/users');
            $this->waitForAlpine($browser);
            $browser->waitFor('@manage-organizations-'.$sam->id);
            $this->clickAndAwait($browser, '@manage-organizations-'.$sam->id, fn (Browser $b) => $b->waitFor('@membership-role-'.$alpha->id, 5));

            $browser->select('@membership-role-'.$alpha->id, (string) $role->id);
            $this->jsClick($browser, '@membership-save-'.$alpha->id);
            $browser->waitForText("{$sam->name} is now Area Manager in Alpha Mart.");
            $this->assertSame($role->id, $sam->organizations()->whereKey($alpha->id)->first()->pivot->role_id);

            /* ── 3. Sam works inside Alpha Mart, and nowhere else ───────────────────────────── */
            $this->freshSession($browser);
            $browser->loginAs($sam)->visit('/dashboard');
            // View Organizations inside an organization is the Organizations tab of Settings, never the platform's Organizations page.
            $browser->waitFor('#main-sidebar')
                ->assertMissing('#main-sidebar a[href$="/organizations"]')
                ->assertPresent('#main-sidebar a[href$="/channels"]')
                ->assertPresent('#main-sidebar a[href$="/activity"]')
                ->assertMissing('#main-sidebar a[href$="/users"]')
                ->assertPresent('@sidebar-settings');

            // Channels: the organization's own, made here; the platform's listed to look at (owner, 2026-09-19) — nothing
            // on it to change; another organization's never.
            $browser->visit('/channels');
            $this->waitForAlpine($browser);
            $browser->waitForText('Channels of Alpha Mart');
            $this->clickAndAwait($browser, '@add-channel', fn (Browser $b) => $b->waitFor('@channel-form', 3));
            $this->jsType($browser, '@channel-name', 'Alpha Lunch Deals');
            $this->jsClick($browser, '@channel-save');
            $browser->waitForText('Alpha Lunch Deals')
                ->assertDontSee('Beta Specials')
                ->assertSeeIn('@channel-name-'.$gama->id, 'GAMA')
                ->assertVisible('@channel-from-platform-'.$gama->id)
                ->assertMissing('@edit-channel-'.$gama->id)
                ->assertMissing('@delete-channel-'.$gama->id);
            $this->assertSame($alpha->id, Channel::where('name', 'Alpha Lunch Deals')->firstOrFail()->organization_id);

            // Settings → Organizations: the organizations Sam belongs to, the details to read, and no Create organization without organization-store.
            $this->jsClick($browser, '@sidebar-settings');
            $browser->waitForLocation('/profile')->waitFor('@settings-tab-organization');
            $this->jsClick($browser, '@settings-tab-organization');
            $browser->waitForLocation('/settings/organization')
                ->waitFor('@your-organizations')
                ->assertSeeIn('@your-organizations', 'Alpha Mart')
                ->assertDontSee('Beta Deli')
                ->assertPresent('@organization-details-readonly')
                ->assertMissing('@open-organization-button');

            // Activity: this organization's history — the channel just made, the role Sam was given.
            $browser->visit('/activity');
            $this->waitForAlpine($browser);
            $browser->waitForText('Activity in Alpha Mart')
                ->waitForText('Created channel Alpha Lunch Deals')
                ->assertSee('from Staff to Area Manager')
                ->assertDontSee('Run Yearly Maintenance');

            // The platform's Organizations page stays shut, whatever the role holds.
            $browser->visit('/organizations')->assertSee('403');
        });
    }
}
