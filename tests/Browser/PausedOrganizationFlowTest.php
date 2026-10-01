<?php

namespace Tests\Browser;

use App\Models\Organization;
use App\Models\Role;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * An organization the platform pauses, as its people live it (owner, 2026-09-30): a page left open when it is paused sends
 * them to the page that says why at its next request; another organization of theirs opens from the menu at the top; and
 * when the platform turns it back on, its pages open again.
 */
class PausedOrganizationFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_a_page_open_when_the_organization_is_paused_leads_to_why_and_the_organization_comes_back_when_turned_on(): void
    {
        $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $beta = Organization::factory()->create(['name' => 'Beta Mart']);
        $owner = $this->organizationMember($alpha);
        $owner->organizations()->attach($beta->id, ['role_id' => Role::owner()->id]);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $beta) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToOrganization($browser, $alpha);
            $browser->visit('/media');
            $this->waitForAlpine($browser);
            $browser->waitUntilMissingText('Loading...', 10);

            // The platform pauses it while the page is open…
            $alpha->update(['is_active' => false]);

            // …and the next thing the page asks for says so and leads to the dashboard.
            $this->jsType($browser, '@crud-search', 'poster');
            $browser->waitForText('Alpha Mart is paused. Contact support to turn it back on.', 10)
                ->waitForLocation('/dashboard', 10)
                ->waitFor('@dashboard-paused')
                ->assertSeeIn('@dashboard-paused', 'Alpha Mart is paused')
                ->assertSeeIn('@dashboard-paused', 'Choose Another Organization');

            // The menu offers none of its pages.
            $browser->assertMissing('a[href$="/screens"]')->assertMissing('a[href$="/media"]');

            // Another of their organizations opens from the switcher at the top.
            $browser->click('@organization-switcher')->waitFor('@organization-switch-'.$beta->id);
            $browser->waitForReload(fn (Browser $b) => $b->click('@organization-switch-'.$beta->id));
            $browser->visit('/screens')->assertPathIs('/screens');

            // Turned back on: the paused organization's pages open again.
            $alpha->update(['is_active' => true]);
            $this->switchToOrganization($browser, $alpha);
            $browser->visit('/media')->assertPathIs('/media');
            $this->waitForAlpine($browser);
            $browser->assertMissing('@dashboard-paused');
        });
    }

    public function test_the_platform_pauses_an_organization_from_its_page_and_sees_it_said(): void
    {
        $admin = $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);

        $this->browse(function (Browser $browser) use ($admin, $alpha) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/organizations');
            $this->waitForAlpine($browser);
            $browser->waitForText('Alpha Mart');

            $this->clickAndAwait($browser, '@edit-organization-'.$alpha->id, fn (Browser $b) => $b->waitFor('@organization-active', 3));
            $browser->assertSee('Off pauses the organization: its people cannot open it, and its screens keep playing.');
            $browser->uncheck('@organization-active');
            $this->jsClick($browser, '@organization-save');

            $browser->waitForText('Alpha Mart is paused.', 10);
            $browser->waitUsing(10, 200, fn () => $alpha->fresh()->is_active === false);
            $browser->waitForTextIn('table', 'Paused');
        });
    }
}
