<?php

namespace Tests\Browser;

use App\Models\Role;
use App\Models\Store;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class MultiStoreUserTest extends DuskTestCase
{
    use DatabaseMigrations;

    /**
     * One person in two stores with a different role in each: both stores show on the
     * selection page, and switching stores switches their powers — Admin in Alpha (the team
     * page, with its Invite button), Staff in Beta (no team page at all).
     */
    public function test_one_person_in_two_stores_gets_a_different_role_in_each(): void
    {
        $this->seedSuperAdmin();

        $storeA = Store::factory()->create(['name' => 'Alpha Store']);
        $storeB = Store::factory()->create(['name' => 'Beta Store']);
        $worker = $this->storeMember($storeA, Role::ADMIN, 'multi-store@example.com');
        $worker->stores()->attach($storeB->id, ['role_id' => Role::starter(Role::STAFF)->id]);

        $this->browse(function (Browser $browser) use ($worker, $storeA, $storeB) {
            $this->freshSession($browser);

            // -- The selection page lists BOTH stores, each with its own role ----
            $browser->loginAs($worker)->visit('/select-store');
            $browser->waitForText('Alpha Store')
                ->assertSee('Beta Store')
                ->assertSee('Admin')
                ->assertSee('Staff');

            // -- In Alpha they are an Admin: the team page, with Invite ----------
            $this->switchToStore($browser, $storeA);
            $browser->visit('/members');
            $this->waitForAlpine($browser);
            $browser->waitForText('multi-store@example.com')
                ->assertPresent('@invite-member');

            // -- In Beta, only Staff: the team page is not theirs ----------------
            // The switch itself first: the header names the store being worked in, so the
            // refusal below is Beta's answer and not Alpha's still standing.
            $this->switchToStore($browser, $storeB);
            $browser->visit('/dashboard');
            $this->waitForAlpine($browser);
            $browser->assertSeeIn('@store-switcher', 'Beta Store');

            $browser->visit('/members')->assertSee('403')->assertMissing('@invite-member');
        });
    }

    /**
     * The store selection page is a focused, sidebar-less picker; and once inside a
     * store, the HEADER switcher (not a dashboard link) changes the active store.
     */
    public function test_the_selection_page_has_no_sidebar_and_the_header_switcher_changes_stores(): void
    {
        $this->seedSuperAdmin();

        $storeA = Store::factory()->create(['name' => 'Alpha Store']);
        $storeB = Store::factory()->create(['name' => 'Beta Store']);
        $worker = $this->storeMember($storeA, Role::ADMIN, 'switcher@example.com');
        $worker->stores()->attach($storeB->id, ['role_id' => Role::starter(Role::STAFF)->id]);

        $this->browse(function (Browser $browser) use ($worker, $storeA, $storeB) {
            $this->freshSession($browser);

            // -- The picker is a focused page: no sidebar nav --------------------
            $browser->loginAs($worker)->visit('/select-store');
            $browser->waitForText('Select a Store')
                ->assertMissing('#main-sidebar')
                ->assertSee('Alpha Store')
                ->assertSee('Beta Store');

            // -- The WHOLE card picks the store, not just the words at the bottom.
            //    Asking the browser what it would hit is the only honest check —
            //    the overlay that does this is invisible, so nothing about the
            //    rendered text would reveal it had stopped working.
            $corners = $browser->script(<<<JS
                const button = document.querySelector('[dusk="switch-store-{$storeA->id}"]');
                const card = button.closest('.relative');
                const r = card.getBoundingClientRect();

                return [
                    [r.x + 4, r.y + 4],                          // top-left
                    [r.x + r.width - 4, r.y + 4],                // top-right
                    [r.x + r.width / 2, r.y + r.height / 2],     // dead centre
                    [r.x + 4, r.y + r.height - 4],               // bottom-left
                ].map(([x, y]) => document.elementFromPoint(x, y) === button);
            JS)[0];

            $this->assertSame([true, true, true, true], $corners,
                'part of the store card is not clickable');

            // Pick Alpha (Admin) → the full app shell with its sidebar is back.
            $this->switchToStore($browser, $storeA);
            $browser->visit('/members');
            $this->waitForAlpine($browser);
            $browser->assertPresent('#main-sidebar')
                ->assertPresent('@store-switcher')
                ->assertPresent('@invite-member');

            // -- Switch to Beta from the HEADER dropdown (Staff: no team page) ---
            $browser->assertSeeIn('@store-switcher', 'Alpha Store');
            $this->jsClick($browser, '@store-switcher');
            $browser->waitForReload(fn (Browser $b) => $this->jsClick($b, '@store-switch-'.$storeB->id));

            // The header now names Beta — the switch took, rather than the button merely vanishing.
            $browser->assertPathIs('/dashboard')
                ->assertSeeIn('@store-switcher', 'Beta Store');
            $browser->visit('/members')->assertSee('403')->assertMissing('@invite-member');
        });
    }

    /** The team page shows the people of the store being worked in, never the other one. */
    public function test_the_members_page_lists_only_the_current_store_s_team(): void
    {
        $this->seedSuperAdmin();

        $storeA = Store::factory()->create(['name' => 'Alpha Store']);
        $storeB = Store::factory()->create(['name' => 'Beta Store']);
        $owner = $this->storeMember($storeA, Role::OWNER, 'owner@example.com');
        $owner->stores()->attach($storeB->id, ['role_id' => Role::starter(Role::OWNER)->id]);
        $this->storeMember($storeA, Role::STAFF, 'alpha.staff@example.com');
        $this->storeMember($storeB, Role::STAFF, 'beta.staff@example.com');

        $this->browse(function (Browser $browser) use ($owner, $storeA, $storeB) {
            $this->freshSession($browser);
            $browser->loginAs($owner);

            $this->switchToStore($browser, $storeA);
            $browser->visit('/members');
            $this->waitForAlpine($browser);
            $browser->waitForText('alpha.staff@example.com')->assertDontSee('beta.staff@example.com');

            $this->switchToStore($browser, $storeB);
            $browser->visit('/members');
            $this->waitForAlpine($browser);
            $browser->waitForText('beta.staff@example.com')->assertDontSee('alpha.staff@example.com');
        });
    }
}
