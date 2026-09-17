<?php

namespace Tests\Browser;

use App\Models\Role;
use App\Models\Store;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Settings → Stores and the Members page's Leave, through the real pages.
 *
 * Every button here belongs to a PLAIN form — no AJAX — so a mistake shows up as a page that comes
 * back looking the same with an error nobody notices, which no backend test would catch. The cards
 * are each gated by their own permission (docs/STORE-ORGANIZATION-SPEC.md §I).
 */
class StoreSettingsFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_an_owner_saves_the_store_details_from_the_settings_tab(): void
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart', 'city' => 'Austin', 'state' => 'TX']);
        $owner = $this->storeMember($store, Role::OWNER);

        $this->browse(function (Browser $browser) use ($owner, $store) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToStore($browser, $store);

            $browser->visit('/settings/store');
            $this->waitForAlpine($browser);
            $browser->waitFor('@store-details-form')->assertSee('Store Details');

            $this->jsType($browser, '#name', 'Alpha Mart Downtown');
            $this->jsType($browser, '#street', '500 Congress Ave');
            $this->jsType($browser, '#city', 'Dallas');
            $browser->select('#state', 'TX');
            $this->jsType($browser, '#zip_code', '75001');
            $this->jsType($browser, '#country', 'USA');

            $browser->waitForReload(fn (Browser $b) => $this->jsClick($b, '@store-details-save'));

            $browser->assertSee('Alpha Mart Downtown');
            $this->assertSame('Alpha Mart Downtown', $store->fresh()->name);
            $this->assertSame('Dallas', $store->fresh()->city);
        });
    }

    public function test_a_member_allowed_to_create_a_store_opens_one_and_owns_it_at_once(): void
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        // View Stores shows the tab; Create store is the card. Nothing else is needed for this.
        $opener = $this->storeMember($store, ['store-view', 'store-store'], 'opener@example.com', 'Opener');

        $this->browse(function (Browser $browser) use ($opener, $store) {
            $this->freshSession($browser);
            $browser->loginAs($opener);
            $this->switchToStore($browser, $store);

            $browser->visit('/settings/store');
            $this->waitForAlpine($browser);

            // Create store lives in a modal on the "Your stores" card.
            $browser->waitFor('@your-stores');
            $this->clickAndAwait($browser, '@open-store-button', fn (Browser $b) => $b->waitFor('@open-store-form', 3));

            $this->jsType($browser, '@open-store-name', 'Beta Deli');
            $this->jsType($browser, '@open-store-street', '12 Elm St');
            $this->jsType($browser, '@open-store-suite', 'Unit 4');   // the one optional field
            $this->jsType($browser, '@open-store-city', 'Plano');
            $browser->select('@open-store-state', 'TX');
            $this->jsType($browser, '@open-store-zip', '75024');
            $this->jsType($browser, '@open-store-country', 'USA');

            $browser->waitForReload(fn (Browser $b) => $this->jsClick($b, '@open-store-confirm'));

            // The store exists, and the person who opened it holds the Owner role in it.
            $opened = Store::where('name', 'Beta Deli')->firstOrFail();
            $this->assertSame('Unit 4', $opened->suite);
            $this->assertSame(
                Role::owner()->id,
                DB::table('store_user')->where('user_id', $opener->id)->where('store_id', $opened->id)->value('role_id'),
                'whoever opens a store owns it at once'
            );

            // And the tab now lists both of their stores.
            $browser->visit('/settings/store');
            $this->waitForAlpine($browser);
            $browser->waitFor('@your-stores')
                ->assertSeeIn('@your-stores', 'Beta Deli')
                ->assertSeeIn('@your-stores', 'Alpha Mart');
        });
    }

    public function test_a_member_leaves_a_store_from_the_members_page(): void
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $this->storeMember($store, Role::OWNER);   // the store keeps its Owner
        $leaver = $this->storeMember($store, ['member-view'], 'leaver@example.com', 'Watcher');

        $this->browse(function (Browser $browser) use ($leaver, $store) {
            $this->freshSession($browser);
            $browser->loginAs($leaver);
            $this->switchToStore($browser, $store);

            $browser->visit('/members');
            $this->waitForAlpine($browser);
            $browser->waitForText('leaver@example.com');

            $this->clickAndAwait($browser, '@leave-store', fn (Browser $b) => $b->waitFor('@leave-store-confirm', 3));

            // Leaving is an AJAX call that then sends the browser wherever the server says — so wait
            // for the button to be gone with the page rather than for a form's own reload.
            $this->jsClick($browser, '@leave-store-confirm');
            $browser->waitUntilMissing('@leave-store-confirm', 8)->pause(500);

            $this->assertFalse(
                DB::table('store_user')->where('user_id', $leaver->id)->where('store_id', $store->id)->exists(),
                'leaving takes the membership row and nothing else'
            );
            // The store and its Owner are untouched.
            $this->assertSame(1, DB::table('store_user')->where('store_id', $store->id)->count());
        });
    }
}
