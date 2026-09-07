<?php

namespace Tests\Browser;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class MultiStoreUserTest extends DuskTestCase
{
    use DatabaseMigrations;

    /**
     * One user assigned to two stores with a different role in each: both stores
     * show on their dashboard, and switching stores switches their powers —
     * Manager in Store A (can add users), Viewer in Store B (cannot).
     */
    public function test_one_user_in_two_stores_gets_a_different_role_per_store(): void
    {
        $admin = $this->seedSuperAdmin();

        $storeA = Store::factory()->create(['name' => 'Alpha Store']);
        $storeB = Store::factory()->create(['name' => 'Beta Store']);

        $managerRole = Role::create(['name' => 'Manager', 'created_by' => $admin->id]);
        $managerRole->permissions()->sync(Permission::whereIn('name', ['user-view', 'user-store'])->pluck('id'));

        $viewerRole = Role::create(['name' => 'Viewer', 'created_by' => $admin->id]);
        $viewerRole->permissions()->sync(Permission::whereIn('name', ['user-view'])->pluck('id'));

        $worker = User::factory()->create(['email' => 'multi-store@example.com', 'created_by' => $admin->id]);
        $worker->stores()->attach($storeA->id, ['role_id' => $managerRole->id]);
        $worker->stores()->attach($storeB->id, ['role_id' => $viewerRole->id]);

        $this->browse(function (Browser $browser) use ($worker, $storeA, $storeB) {
            $this->freshSession($browser);

            // -- The selection page lists BOTH stores, each with its own role ----
            $browser->loginAs($worker)->visit('/select-store');
            $browser->waitForText('Alpha Store')
                ->assertSee('Beta Store')
                ->assertSee('Manager')
                ->assertSee('Viewer');

            // -- In Store A they are a Manager: the Add User button exists -------
            $this->switchToStore($browser, $storeA);
            $browser->visit('/users');
            $this->waitForAlpine($browser);
            $browser->waitForText('No users found.')
                ->assertPresent('@add-user');

            // -- Switch to Store B: same user, now only a Viewer — no Add User ---
            $this->switchToStore($browser, $storeB);
            $browser->visit('/users');
            $this->waitForAlpine($browser);
            $browser->waitForText('No users found.')
                ->assertMissing('@add-user');
        });
    }

    /**
     * The store selection page is a focused, sidebar-less picker; and once inside a
     * store, the HEADER switcher (not a dashboard link) changes the active store.
     */
    public function test_the_selection_page_has_no_sidebar_and_the_header_switcher_changes_stores(): void
    {
        $admin = $this->seedSuperAdmin();

        $storeA = Store::factory()->create(['name' => 'Alpha Store']);
        $storeB = Store::factory()->create(['name' => 'Beta Store']);
        $managerRole = Role::create(['name' => 'Manager', 'created_by' => $admin->id]);
        $managerRole->permissions()->sync(Permission::whereIn('name', ['user-view', 'user-store'])->pluck('id'));
        $viewerRole = Role::create(['name' => 'Viewer', 'created_by' => $admin->id]);
        $viewerRole->permissions()->sync(Permission::whereIn('name', ['user-view'])->pluck('id'));

        $worker = User::factory()->create(['email' => 'switcher@example.com', 'created_by' => $admin->id]);
        $worker->stores()->attach($storeA->id, ['role_id' => $managerRole->id]);
        $worker->stores()->attach($storeB->id, ['role_id' => $viewerRole->id]);

        $this->browse(function (Browser $browser) use ($worker, $storeA, $storeB) {
            $this->freshSession($browser);

            // -- The picker is a focused page: no sidebar nav --------------------
            $browser->loginAs($worker)->visit('/select-store');
            $browser->waitForText('Select a Store')
                ->assertMissing('#main-sidebar')
                ->assertSee('Alpha Store')
                ->assertSee('Beta Store');

            // Pick Store A (Manager) → the full app shell with its sidebar is back.
            $this->switchToStore($browser, $storeA);
            $browser->visit('/users');
            $this->waitForAlpine($browser);
            $browser->assertPresent('#main-sidebar')
                ->assertPresent('@store-switcher')
                ->assertPresent('@add-user');

            // -- Switch to Store B from the HEADER dropdown (Viewer: no Add User) -
            $this->jsClick($browser, '@store-switcher');
            $browser->waitForReload(fn (Browser $b) => $this->jsClick($b, '@store-switch-'.$storeB->id));
            $browser->visit('/users');
            $this->waitForAlpine($browser);
            $browser->assertMissing('@add-user');
        });
    }

    /**
     * The admin's assignment modal shows both stores for a multi-store user, and
     * removing one store keeps the other intact.
     */
    public function test_the_assignments_modal_lists_both_stores_and_can_remove_just_one(): void
    {
        $admin = $this->seedSuperAdmin();

        $storeA = Store::factory()->create(['name' => 'Alpha Store']);
        $storeB = Store::factory()->create(['name' => 'Beta Store']);
        $role = Role::create(['name' => 'Manager', 'created_by' => $admin->id]);
        $role->permissions()->sync(Permission::whereIn('name', ['user-view'])->pluck('id'));

        $worker = User::factory()->create(['email' => 'multi-store@example.com', 'created_by' => $admin->id]);
        $worker->stores()->attach($storeA->id, ['role_id' => $role->id]);
        $worker->stores()->attach($storeB->id, ['role_id' => $role->id]);

        $this->browse(function (Browser $browser) use ($admin, $worker, $storeA, $storeB) {
            $this->freshSession($browser);

            $browser->loginAs($admin)->visit('/users');
            $this->waitForAlpine($browser);
            $browser->waitForText('multi-store@example.com');

            // Both assignments visible in the modal.
            $this->clickAndAwait($browser, '@stores-user-'.$worker->id, fn (Browser $b) => $b->waitFor('@assign-role', 3));
            $browser->waitForText('Alpha Store')
                ->assertSee('Beta Store');

            // Remove only Store A; Store B must survive.
            $browser->script(
                "for (const btn of document.querySelectorAll('button')) {"
                ." if (btn.textContent.trim() === 'Remove' && btn.closest('tr') && btn.closest('tr').textContent.includes('Alpha Store')) { btn.click(); break; }"
                .'}'
            );
            $browser->waitForText('Are you sure you want to delete');
            $this->jsClick($browser, '@confirm-store-removal-confirm');

            $browser->waitUsing(10, 150, fn () => ! DB::table('store_user')
                ->where(['user_id' => $worker->id, 'store_id' => $storeA->id])->exists());

            $this->assertDatabaseHas('store_user', ['user_id' => $worker->id, 'store_id' => $storeB->id]);
        });
    }
}
