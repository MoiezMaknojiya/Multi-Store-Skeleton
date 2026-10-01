<?php

namespace Tests\Browser;

use App\Models\Organization;
use App\Models\Role;
use App\Models\Screen;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Signing organizations up to network advertising from the organizations listing, in a real browser.
 *
 * The backend tests prove the endpoint. This proves the page can actually drive it:
 * that the tick boxes bind, that the count in the confirmation is the real number of
 * televisions and not `undefined`, and that the badges tell the truth afterwards.
 * None of that shows up in a status code.
 */
class NetworkAdsBulkOrganizationsTest extends DuskTestCase
{
    use DatabaseMigrations;

    /** An organization with two televisions, both switched off, as every new organization starts. */
    private function organizationWithTwoScreens(string $name): Organization
    {
        $organization = Organization::factory()->create(['name' => $name]);
        Screen::factory()->count(2)->create(['organization_id' => $organization->id]);

        return $organization;
    }

    /** The whole loop: tick two organizations, switch advertising on, then off again. */
    public function test_the_owner_switches_two_organizations_on_and_then_off_again(): void
    {
        $admin = $this->seedSuperAdmin();
        $alpha = $this->organizationWithTwoScreens('Alpha Mart');
        $beta = $this->organizationWithTwoScreens('Beta Deli');

        $this->browse(function (Browser $browser) use ($admin, $alpha, $beta) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/organizations');
            $this->waitForAlpine($browser);

            // -- Before: both organizations off, and the buttons refuse to do anything ----
            $browser->waitForText('Alpha Mart')
                ->assertSeeIn('@organization-ads-'.$alpha->id, 'Off')
                ->assertSeeIn('@organization-ads-'.$beta->id, 'Off')
                ->assertSee('tick the organizations below')
                ->assertAttribute('@organizations-ads-on', 'disabled', 'true');

            // -- Tick both organizations --------------------------------------------------
            $this->jsClick($browser, '@select-organization-'.$alpha->id);
            $this->jsClick($browser, '@select-organization-'.$beta->id);
            $browser->waitForText('2 organizations selected');

            // -- The confirmation states the real reach before it is pressed ------
            $this->jsClick($browser, '@organizations-ads-on');
            $browser->waitForText('Switch advertising on for 2 organizations?')
                ->assertSee('4 screens')
                ->assertSee('Any screen you had set apart by hand is switched too.');

            $this->jsClick($browser, '@confirm-organization-ads');

            // -- After: every television in both organizations carries advertising --------
            $browser->waitForTextIn('@organization-ads-'.$alpha->id, 'On — all 2')
                ->assertSeeIn('@organization-ads-'.$beta->id, 'On — all 2')
                // The selection empties itself on the refetch, so a second press
                // cannot land on organizations the operator has stopped looking at.
                ->assertSee('tick the organizations below');
        });

        $this->assertTrue($alpha->fresh()->accepts_network_ads);
        $this->assertTrue($beta->fresh()->accepts_network_ads);
        $this->assertSame(0, Screen::where('accepts_network_ads', false)->count());

        // -- And back off again, through the same two controls --------------------
        $this->browse(function (Browser $browser) use ($admin, $alpha) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/organizations');
            $this->waitForAlpine($browser);
            $browser->waitForText('Alpha Mart');

            $this->jsClick($browser, '@select-all-organizations');
            $this->jsClick($browser, '@organizations-ads-off');

            // The whole count, not just the start of the sentence: it proves the page-wide
            // tick picked up both organizations, and their four televisions, before anything is pressed.
            $browser->waitForText('Switch advertising off for 2 organizations?')
                ->assertSee('4 screens');
            $this->jsClick($browser, '@confirm-organization-ads');

            $browser->waitForTextIn('@organization-ads-'.$alpha->id, 'Off');
        });

        $this->assertFalse($alpha->fresh()->accepts_network_ads);
        $this->assertSame(0, Screen::where('accepts_network_ads', true)->count());
    }

    /**
     * An organization switched on whose televisions are not all switched on must not look the
     * same as one that is — that difference is the whole point of the column.
     */
    public function test_an_organization_with_a_screen_held_back_reads_differently(): void
    {
        $admin = $this->seedSuperAdmin();
        $organization = $this->organizationWithTwoScreens('Gamma Grill');
        $organization->update(['accepts_network_ads' => true]);
        $organization->screens()->first()->update(['accepts_network_ads' => true]);

        $this->browse(function (Browser $browser) use ($admin, $organization) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/organizations');
            $this->waitForAlpine($browser);

            $browser->waitForText('Gamma Grill')
                ->assertSeeIn('@organization-ads-'.$organization->id, 'On — 1 of 2');
        });
    }

    /** An organization member — even the Owner — never reaches the organizations list, let alone its switch. */
    public function test_an_organization_member_sees_none_of_it(): void
    {
        $this->seedSuperAdmin();
        $organization = $this->organizationWithTwoScreens('Delta Bakery');
        $keeper = $this->organizationMember($organization, Role::OWNER, 'keeper@example.com');

        $this->browse(function (Browser $browser) use ($keeper, $organization) {
            $this->freshSession($browser);
            $browser->loginAs($keeper);
            $this->switchToOrganization($browser, $organization);

            // No link to the platform's organizations list in an organization's sidebar…
            $browser->visit('/dashboard');
            $this->waitForAlpine($browser);
            $browser->assertPresent('#main-sidebar a[href$="/screens"]')
                ->assertMissing('#main-sidebar a[href$="/organizations"]');

            // …and opening it directly is refused.
            $browser->visit('/organizations')
                ->assertSee('403')
                ->assertDontSee('Network advertising')
                ->assertMissing('@organizations-ads-on');
        });
    }
}
