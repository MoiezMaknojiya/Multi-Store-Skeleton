<?php

namespace Tests\Browser;

use App\Models\Role;
use App\Models\Store;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * A store changing hands on its Members page, and closing from Settings → Stores (docs/STORE-ORGANIZATION-SPEC.md
 * rules 11 and 22 — owner's rules, 2026-09-17: there is no handover of its own, and a deleted store goes for good).
 */
class StoreOwnershipFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_an_owner_makes_the_buyer_an_owner_on_the_members_page_and_the_buyer_deletes_the_store(): void
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $seller = $this->storeMember($store, Role::OWNER, 'seller@example.com');
        $buyer = $this->storeMember($store, Role::ADMIN, 'buyer@example.com');
        $cashier = Role::create(['name' => 'Alpha Cashier', 'store_id' => $store->id]);

        $this->browse(function (Browser $browser) use ($store, $seller, $buyer, $cashier) {
            /* ── 1. The seller makes the buyer an Owner ─────────────────── */
            $this->freshSession($browser);
            $browser->loginAs($seller);
            $this->switchToStore($browser, $store);

            // Settings has no handover of its own.
            $browser->visit('/settings/store');
            $this->waitForAlpine($browser);
            $browser->waitForText('Delete Store')->assertDontSee('Transfer Ownership');

            $browser->visit('/members');
            $this->waitForAlpine($browser);
            $browser->waitForText('buyer@example.com');
            $this->clickAndAwait($browser, '@change-role-'.$buyer->id, fn (Browser $b) => $b->waitFor('@change-role-form', 3));
            $browser->select('@change-role-select', (string) Role::owner()->id);
            $this->jsClick($browser, '@change-role-save');

            $browser->waitForText("{$buyer->name} is now Owner.")
                ->waitForTextIn('@member-role-'.$buyer->id, 'Owner');
            $this->waitForModalClosed($browser, '@change-role-form');

            /* ── 2. The buyer, an Owner now, changes the seller's role ──── */
            $this->freshSession($browser);
            $browser->loginAs($buyer);
            $this->switchToStore($browser, $store);
            $browser->visit('/members');
            $this->waitForAlpine($browser);
            $browser->waitForText('seller@example.com');
            $this->clickAndAwait($browser, '@change-role-'.$seller->id, fn (Browser $b) => $b->waitFor('@change-role-form', 3));
            $browser->select('@change-role-select', (string) Role::starter(Role::ADMIN)->id);
            $this->jsClick($browser, '@change-role-save');

            $browser->waitForText("{$seller->name} is now Admin.")
                ->waitForTextIn('@member-role-'.$seller->id, 'Admin');
            $this->waitForModalClosed($browser, '@change-role-form');
            $this->assertSame(Role::owner()->id, (int) DB::table('store_user')->where(['user_id' => $buyer->id, 'store_id' => $store->id])->value('role_id'));
            $this->assertSame(Role::starter(Role::ADMIN)->id, (int) DB::table('store_user')->where(['user_id' => $seller->id, 'store_id' => $store->id])->value('role_id'));

            /* ── 3. The new owner closes the store ──────────────────────── */
            $browser->visit('/settings/store');
            $this->waitForAlpine($browser);

            $this->clickAndAwait($browser, '@delete-store-button', fn (Browser $b) => $b->waitFor('@delete-store-form', 3));
            $this->jsType($browser, '@delete-store-name', 'Alpha Mart');
            $this->jsType($browser, '@delete-store-password', 'password');
            $browser->waitForReload(fn (Browser $b) => $this->jsClick($b, '@delete-store-confirm'));

            $browser->waitForLocation('/dashboard')->waitFor('@dashboard-empty');

            // Gone for good with the roles made in it; the people keep their accounts.
            $this->assertDatabaseMissing('stores', ['id' => $store->id]);
            $this->assertDatabaseMissing('roles', ['id' => $cashier->id]);
            $this->assertNotNull($seller->fresh());
            $this->assertNotNull($buyer->fresh());
        });
    }

    /** A mistyped name is caught before anything is sent, and the store is still there. */
    public function test_deleting_needs_the_name_typed_exactly(): void
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->storeMember($store, Role::OWNER);

        $this->browse(function (Browser $browser) use ($store, $owner) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToStore($browser, $store);
            $browser->visit('/settings/store');
            $this->waitForAlpine($browser);

            $this->clickAndAwait($browser, '@delete-store-button', fn (Browser $b) => $b->waitFor('@delete-store-form', 3));
            $this->recordFormSubmits($browser);
            $this->jsType($browser, '@delete-store-name', 'alpha mart');
            $this->jsType($browser, '@delete-store-password', 'password');
            $this->jsClick($browser, '@delete-store-confirm');

            // The server would say the very same words, so the words alone prove nothing. The
            // marker only the browser's own check puts on a field, and a submit the page kept to
            // itself, are what show it never left.
            $browser->waitForText('Type the store name exactly as it is shown.')
                ->assertPresent('#confirm_name[data-client-invalid]');
            $this->assertSame(['stopped'], $this->formSubmits($browser), 'the mistyped name was sent to the server');
            $this->assertNotNull(Store::find($store->id));
        });
    }
}
