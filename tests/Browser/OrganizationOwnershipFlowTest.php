<?php

namespace Tests\Browser;

use App\Models\Role;
use App\Models\Organization;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * An organization changing hands on its Members page, and closing from Settings → Organizations (docs/ORGANIZATION-SPEC.md
 * rules 11 and 22 — owner's rules, 2026-09-17: there is no handover of its own, and a deleted organization goes for good).
 */
class OrganizationOwnershipFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_an_owner_makes_the_buyer_an_owner_on_the_members_page_and_the_buyer_deletes_the_organization(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $seller = $this->organizationMember($organization, Role::OWNER, 'seller@example.com');
        $buyer = $this->organizationMember($organization, Role::ADMIN, 'buyer@example.com');
        $cashier = Role::create(['name' => 'Alpha Cashier', 'organization_id' => $organization->id]);

        $this->browse(function (Browser $browser) use ($organization, $seller, $buyer, $cashier) {
            /* ── 1. The seller makes the buyer an Owner ─────────────────── */
            $this->freshSession($browser);
            $browser->loginAs($seller);
            $this->switchToOrganization($browser, $organization);

            // Settings has no handover of its own.
            $browser->visit('/settings/organization');
            $this->waitForAlpine($browser);
            $browser->waitForText('Delete Organization')->assertDontSee('Transfer Ownership');

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
            $this->switchToOrganization($browser, $organization);
            $browser->visit('/members');
            $this->waitForAlpine($browser);
            $browser->waitForText('seller@example.com');
            $this->clickAndAwait($browser, '@change-role-'.$seller->id, fn (Browser $b) => $b->waitFor('@change-role-form', 3));
            $browser->select('@change-role-select', (string) Role::starter(Role::ADMIN)->id);
            $this->jsClick($browser, '@change-role-save');

            $browser->waitForText("{$seller->name} is now Admin.")
                ->waitForTextIn('@member-role-'.$seller->id, 'Admin');
            $this->waitForModalClosed($browser, '@change-role-form');
            $this->assertSame(Role::owner()->id, (int) DB::table('organization_user')->where(['user_id' => $buyer->id, 'organization_id' => $organization->id])->value('role_id'));
            $this->assertSame(Role::starter(Role::ADMIN)->id, (int) DB::table('organization_user')->where(['user_id' => $seller->id, 'organization_id' => $organization->id])->value('role_id'));

            /* ── 3. The new owner closes the organization ──────────────────────── */
            $browser->visit('/settings/organization');
            $this->waitForAlpine($browser);

            $this->clickAndAwait($browser, '@delete-organization-button', fn (Browser $b) => $b->waitFor('@delete-organization-form', 3));
            // The name to type stands out in the sentence that asks for it (owner, 2026-10-01).
            $browser->assertSeeIn('@confirm_name-typed-name', 'Alpha Mart');
            $this->jsType($browser, '@delete-organization-name', 'Alpha Mart');
            $this->jsType($browser, '@delete-organization-password', 'password');
            $browser->waitForReload(fn (Browser $b) => $this->jsClick($b, '@delete-organization-confirm'));

            $browser->waitForLocation('/dashboard')->waitFor('@dashboard-empty');

            // Gone for good with the roles made in it; the people keep their accounts.
            $this->assertDatabaseMissing('organizations', ['id' => $organization->id]);
            $this->assertDatabaseMissing('roles', ['id' => $cashier->id]);
            $this->assertNotNull($seller->fresh());
            $this->assertNotNull($buyer->fresh());
        });
    }

    /** A mistyped name is caught before anything is sent, and the organization is still there. */
    public function test_deleting_needs_the_name_typed_exactly(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->organizationMember($organization, Role::OWNER);

        $this->browse(function (Browser $browser) use ($organization, $owner) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToOrganization($browser, $organization);
            $browser->visit('/settings/organization');
            $this->waitForAlpine($browser);

            $this->clickAndAwait($browser, '@delete-organization-button', fn (Browser $b) => $b->waitFor('@delete-organization-form', 3));
            $this->recordFormSubmits($browser);
            $this->jsType($browser, '@delete-organization-name', 'alpha mart');
            $this->jsType($browser, '@delete-organization-password', 'password');
            $this->jsClick($browser, '@delete-organization-confirm');

            // The server would say the very same words, so the words alone prove nothing. The
            // marker only the browser's own check puts on a field, and a submit the page kept to
            // itself, are what show it never left.
            $browser->waitForText('Type the organization name exactly as it is shown.')
                ->assertPresent('#confirm_name[data-client-invalid]');
            $this->assertSame(['stopped'], $this->formSubmits($browser), 'the mistyped name was sent to the server');
            $this->assertNotNull(Organization::find($organization->id));
        });
    }
}
