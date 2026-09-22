<?php

namespace Tests\Browser;

use App\Models\Role;
use App\Models\Screen;
use App\Models\Store;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Signing shops up to network advertising from the stores listing, in a real browser.
 *
 * The backend tests prove the endpoint. This proves the page can actually drive it:
 * that the tick boxes bind, that the count in the confirmation is the real number of
 * televisions and not `undefined`, and that the badges tell the truth afterwards.
 * None of that shows up in a status code.
 */
class NetworkAdsBulkStoresTest extends DuskTestCase
{
    use DatabaseMigrations;

    /** A shop with two televisions, both switched off, as every new shop starts. */
    private function shopWithTwoScreens(string $name): Store
    {
        $store = Store::factory()->create(['name' => $name]);
        Screen::factory()->count(2)->create(['store_id' => $store->id]);

        return $store;
    }

    /** The whole loop: tick two shops, switch advertising on, then off again. */
    public function test_the_owner_switches_two_shops_on_and_then_off_again(): void
    {
        $admin = $this->seedSuperAdmin();
        $alpha = $this->shopWithTwoScreens('Alpha Mart');
        $beta = $this->shopWithTwoScreens('Beta Deli');

        $this->browse(function (Browser $browser) use ($admin, $alpha, $beta) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/stores');
            $this->waitForAlpine($browser);

            // -- Before: both shops off, and the buttons refuse to do anything ----
            $browser->waitForText('Alpha Mart')
                ->assertSeeIn('@store-ads-'.$alpha->id, 'Off')
                ->assertSeeIn('@store-ads-'.$beta->id, 'Off')
                ->assertSee('tick the shops below')
                ->assertAttribute('@stores-ads-on', 'disabled', 'true');

            // -- Tick both shops --------------------------------------------------
            $this->jsClick($browser, '@select-store-'.$alpha->id);
            $this->jsClick($browser, '@select-store-'.$beta->id);
            $browser->waitForText('2 shops selected');

            // -- The confirmation states the real reach before it is pressed ------
            $this->jsClick($browser, '@stores-ads-on');
            $browser->waitForText('Switch advertising on for 2 shops?')
                ->assertSee('4 screens')
                ->assertSee('Any screen you had set apart by hand is switched too.');

            $this->jsClick($browser, '@confirm-store-ads');

            // -- After: every television in both shops carries advertising --------
            $browser->waitForTextIn('@store-ads-'.$alpha->id, 'On — all 2')
                ->assertSeeIn('@store-ads-'.$beta->id, 'On — all 2')
                // The selection empties itself on the refetch, so a second press
                // cannot land on shops the operator has stopped looking at.
                ->assertSee('tick the shops below');
        });

        $this->assertTrue($alpha->fresh()->accepts_network_ads);
        $this->assertTrue($beta->fresh()->accepts_network_ads);
        $this->assertSame(0, Screen::where('accepts_network_ads', false)->count());

        // -- And back off again, through the same two controls --------------------
        $this->browse(function (Browser $browser) use ($admin, $alpha) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/stores');
            $this->waitForAlpine($browser);
            $browser->waitForText('Alpha Mart');

            $this->jsClick($browser, '@select-all-stores');
            $this->jsClick($browser, '@stores-ads-off');

            // The whole count, not just the start of the sentence: it proves the page-wide
            // tick picked up both shops, and their four televisions, before anything is pressed.
            $browser->waitForText('Switch advertising off for 2 shops?')
                ->assertSee('4 screens');
            $this->jsClick($browser, '@confirm-store-ads');

            $browser->waitForTextIn('@store-ads-'.$alpha->id, 'Off');
        });

        $this->assertFalse($alpha->fresh()->accepts_network_ads);
        $this->assertSame(0, Screen::where('accepts_network_ads', true)->count());
    }

    /**
     * A shop switched on whose televisions are not all switched on must not look the
     * same as one that is — that difference is the whole point of the column.
     */
    public function test_a_shop_with_a_screen_held_back_reads_differently(): void
    {
        $admin = $this->seedSuperAdmin();
        $store = $this->shopWithTwoScreens('Gamma Grill');
        $store->update(['accepts_network_ads' => true]);
        $store->screens()->first()->update(['accepts_network_ads' => true]);

        $this->browse(function (Browser $browser) use ($admin, $store) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/stores');
            $this->waitForAlpine($browser);

            $browser->waitForText('Gamma Grill')
                ->assertSeeIn('@store-ads-'.$store->id, 'On — 1 of 2');
        });
    }

    /** A shopkeeper — even the Owner — never reaches the stores list, let alone its switch. */
    public function test_a_shopkeeper_sees_none_of_it(): void
    {
        $this->seedSuperAdmin();
        $store = $this->shopWithTwoScreens('Delta Bakery');
        $keeper = $this->storeMember($store, Role::OWNER, 'keeper@example.com');

        $this->browse(function (Browser $browser) use ($keeper, $store) {
            $this->freshSession($browser);
            $browser->loginAs($keeper);
            $this->switchToStore($browser, $store);

            // No link to the platform's stores list in a store's sidebar…
            $browser->visit('/dashboard');
            $this->waitForAlpine($browser);
            $browser->assertPresent('#main-sidebar a[href$="/screens"]')
                ->assertMissing('#main-sidebar a[href$="/stores"]');

            // …and opening it directly is refused.
            $browser->visit('/stores')
                ->assertSee('403')
                ->assertDontSee('Network advertising')
                ->assertMissing('@stores-ads-on');
        });
    }
}
