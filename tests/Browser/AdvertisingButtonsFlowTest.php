<?php

namespace Tests\Browser;

use App\Models\Campaign;
use App\Models\Role;
use App\Models\Screen;
use App\Models\Store;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The two advertising buttons no other browser test presses: a campaign saved from the Campaigns page,
 * and the shop's own "we accept network advertising" switch on its Screens page — a place, not a
 * permission (only somebody working inside the shop sees it).
 */
class AdvertisingButtonsFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_the_platform_saves_a_campaign_and_it_appears_in_the_list(): void
    {
        $admin = $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart', 'accepts_network_ads' => true]);
        Screen::factory()->withToken('ads-token')->create(['store_id' => $store->id, 'name' => 'Deli TV', 'accepts_network_ads' => true]);

        $this->browse(function (Browser $browser) use ($admin, $store) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/campaigns');
            $this->waitForAlpine($browser);

            $this->clickAndAwait($browser, '@add-campaign', fn (Browser $b) => $b->waitFor('@campaign-form', 3));

            $this->jsType($browser, '@campaign-name', 'Winter Cola');
            $this->jsType($browser, '@campaign-advertiser', 'GAMA Wholesale');
            $browser->attach('@campaign-file', $this->fixtureImage('advert-winter.png', 20, 90, 200))
                ->waitFor('@campaign-seconds');
            $this->jsType($browser, '@campaign-seconds', '10');
            $this->jsType($browser, '@campaign-starts-on', now()->toDateString());
            $this->jsType($browser, '@campaign-ends-on', now()->addWeek()->toDateString());

            // The shop that agreed is offered; picking it is how the campaign reaches a screen.
            $browser->waitFor('@campaign-store-all-'.$store->id);
            $this->jsClick($browser, '@campaign-store-all-'.$store->id);

            $this->jsClick($browser, '@campaign-save');

            $browser->waitUsing(20, 250, fn () => Campaign::where('name', 'Winter Cola')->exists());
            $campaign = Campaign::firstWhere('name', 'Winter Cola');

            $this->assertSame('GAMA Wholesale', $campaign->advertiser_name);
            $this->assertSame(10, $campaign->duration_seconds);
            $browser->waitFor('@campaign-name-'.$campaign->id)
                ->assertSeeIn('@campaign-name-'.$campaign->id, 'Winter Cola');
        });
    }

    public function test_the_shops_advertising_switch_exists_only_inside_an_impersonated_session(): void
    {
        $admin = $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart', 'accepts_network_ads' => false]);
        $owner = $this->storeMember($store, Role::OWNER);
        Screen::factory()->create(['store_id' => $store->id, 'name' => 'Counter TV']);

        $this->browse(function (Browser $browser) use ($admin, $owner, $store) {
            // The shopkeeper never sees it: whether a shop carries advertising was agreed in the
            // deal, so it is the platform's setting and not something to flip on a Tuesday.
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToStore($browser, $store);
            $browser->visit('/screens');
            $this->waitForAlpine($browser);
            $browser->waitForText('Counter TV')->assertMissing('@network-ads-panel');

            // The super admin reaches it the only way there is: logged in as one of the shop's people.
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/users');
            $this->waitForAlpine($browser);
            $browser->waitForText($owner->email);
            $browser->waitForReload(fn (Browser $b) => $this->jsClick($b, '@impersonate-'.$owner->id));

            // Impersonating clears the store the admin had selected, so pick the shop up again.
            $this->switchToStore($browser, $store);
            $browser->visit('/screens');
            $this->waitForAlpine($browser);
            $browser->waitFor('@store-ads-toggle');

            // On… (the shop's own flag is the thing that matters; the button's wording is Alpine's.)
            // clickAndAwait, because a click that lands before Alpine has wired the button does
            // nothing at all — and under a full suite it sometimes does land that early.
            $this->clickAndAwait($browser, '@store-ads-toggle', fn (Browser $b) => $b->waitUsing(4, 200, fn () => $store->fresh()->accepts_network_ads === true));

            // …and off again, which is the half a shopkeeper needs to be able to trust.
            $this->clickAndAwait($browser, '@store-ads-toggle', fn (Browser $b) => $b->waitUsing(4, 200, fn () => $store->fresh()->accepts_network_ads === false));
        });
    }
}
