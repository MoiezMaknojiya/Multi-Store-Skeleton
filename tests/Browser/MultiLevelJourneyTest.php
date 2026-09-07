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

class MultiLevelJourneyTest extends DuskTestCase
{
    use DatabaseMigrations;

    /** Click a permission's checkbox in the open role form by its visible label.
     *  Done via a JS click — WebDriver's positional clicks are flaky in headless. */
    private function checkPermission(Browser $browser, string $displayName): void
    {
        $browser->script(
            "for (const label of document.querySelectorAll('[dusk=\"role-form\"] label')) {"
            ."  if (label.textContent.includes('{$displayName}')) { label.click(); break; }"
            .'}'
        );
    }

    /** Open the role modal and wait until its async open (permission fetch + form
     *  reset) has fully finished — typing before that gets wiped by the reset. */
    private function openRoleModal(Browser $browser): void
    {
        $this->clickAndAwait($browser, '@add-role', function (Browser $b) {
            $b->waitUsing(4, 100, fn () => $b->script(
                'return (function () {'
                ." const el = document.querySelector('[dusk=\"role-form\"]');"
                .' if (!el || el.offsetParent === null || !window.Alpine) return false;'
                .' return window.Alpine.$data(el).openingModal === false;'
                .'})();'
            )[0]);
        });
    }

    /** Wait until the given store_user row actually exists (the assign call is async). */
    private function waitForAssignment(Browser $browser, int $userId, int $storeId, int $roleId): void
    {
        $browser->waitUsing(10, 150, fn () => DB::table('store_user')
            ->where(['user_id' => $userId, 'store_id' => $storeId, 'role_id' => $roleId])
            ->exists());
    }

    /** Fill and submit the Add User modal from the users page. */
    private function createUserThroughForm(Browser $browser, string $first, string $last, string $phone, string $email): void
    {
        $this->clickAndAwait($browser, '@add-user', fn (Browser $b) => $b->waitFor('@user-form', 3));
        $this->jsType($browser, '@user-first-name', $first);
        $this->jsType($browser, '@user-last-name', $last);
        $this->jsType($browser, '@user-phone', $phone);
        $this->jsType($browser, '@user-email', $email);
        $this->jsType($browser, '@user-password', 'password123');
        $this->jsType($browser, '@user-password-confirm', 'password123');
        $this->jsClick($browser, '@user-save');
        $browser->waitForText($email);
        $this->waitForModalClosed($browser, '@user-form');
    }

    /**
     * The full super-admin journey through the UI: two stores, a role built from
     * the permission checklist, users created / edited / deleted, and a store
     * assignment through the modal.
     */
    public function test_super_admin_builds_stores_roles_and_users_end_to_end(): void
    {
        $admin = $this->seedSuperAdmin();

        $this->browse(function (Browser $browser) use ($admin) {
            $this->freshSession($browser);

            // -- Stores: create two through the form ------------------------------
            $browser->loginAs($admin)->visit('/stores');
            $this->waitForAlpine($browser);

            foreach ([['Store One', '1 Main St', 'Austin', '73301'], ['Store Two', '2 Side St', 'Dallas', '75001']] as [$name, $street, $city, $zip]) {
                $this->clickAndAwait($browser, '@add-store', fn (Browser $b) => $b->waitFor('@store-form', 3));
                $this->jsType($browser, '@store-name', $name);
                $this->jsType($browser, '@store-street', $street);
                $this->jsType($browser, '@store-city', $city);
                $browser->select('@store-state', 'TX');
                $this->jsType($browser, '@store-zip', $zip);
                $this->jsClick($browser, '@store-save');
                $browser->waitForText($name);
                $this->waitForModalClosed($browser, '@store-form');
            }

            $this->assertDatabaseHas('stores', ['name' => 'Store One']);
            $this->assertDatabaseHas('stores', ['name' => 'Store Two']);

            // -- Role: build "Owner" from the permission checklist ----------------
            $browser->visit('/roles');
            $this->waitForAlpine($browser);
            $this->openRoleModal($browser);
            $this->jsType($browser, '@role-name', 'Owner');
            $this->checkPermission($browser, 'View Users');
            $this->checkPermission($browser, 'Create Users');
            $this->checkPermission($browser, 'View User Store Assignments');
            $this->checkPermission($browser, 'Assign User to Store');
            $this->checkPermission($browser, 'View Roles');
            $this->checkPermission($browser, 'Create Roles');
            $this->jsClick($browser, '@role-save');
            $browser->waitForText('Owner');
            $this->waitForModalClosed($browser, '@role-form');

            $ownerRole = Role::where('name', 'Owner')->firstOrFail();
            $this->assertGreaterThanOrEqual(6, $ownerRole->permissions()->count());

            // -- Users: create the store owner through the form -------------------
            $browser->visit('/users');
            $this->waitForAlpine($browser);
            $this->createUserThroughForm($browser, 'Olivia', 'Owner', '1112223333', 'owner@example.com');

            $owner = User::where('email', 'owner@example.com')->firstOrFail();
            $this->assertSame($admin->id, $owner->created_by);

            // -- Assign the owner to Store One through the modal ------------------
            $storeOne = Store::where('name', 'Store One')->firstOrFail();
            $this->clickAndAwait($browser, '@stores-user-'.$owner->id, fn (Browser $b) => $b->waitFor('@assign-role', 3));
            $browser->select('@assign-store', (string) $storeOne->id)
                ->select('@assign-role', (string) $ownerRole->id);
            $this->clickAndAwait($browser, '@assign-button',
                fn (Browser $b) => $this->waitForAssignment($b, $owner->id, $storeOne->id, $ownerRole->id));

            // -- Edit, then create-and-delete a throwaway user --------------------
            $browser->visit('/users');
            $this->waitForAlpine($browser);
            $browser->waitForText('owner@example.com');
            $this->clickAndAwait($browser, '@edit-user-'.$owner->id, fn (Browser $b) => $b->waitFor('@user-form', 3));
            $this->jsType($browser, '@user-first-name', 'Olive');
            $this->jsClick($browser, '@user-save');
            $browser->waitForText('Olive');
            $this->waitForModalClosed($browser, '@user-form');

            $this->createUserThroughForm($browser, 'Temp', 'User', '9998887777', 'temp@example.com');

            $temp = User::where('email', 'temp@example.com')->firstOrFail();
            $this->clickAndAwait($browser, '@delete-user-'.$temp->id,
                fn (Browser $b) => $b->waitForText('Are you sure you want to delete', 3));
            $this->jsClick($browser, '@confirm-user-deletion-confirm');
            $browser->waitUntilMissingText('temp@example.com');

            $this->assertDatabaseMissing('users', ['email' => 'temp@example.com']);
        });
    }

    /**
     * The multi-level chain: owner -> manager -> worker, each created by the level
     * above through the UI, with visibility staying one level deep in each listing.
     */
    public function test_the_owner_manager_worker_chain_and_its_visibility_boundaries(): void
    {
        $admin = $this->seedSuperAdmin();

        // Base data straight through models: a store and its assigned owner.
        $store = Store::factory()->create(['name' => 'Chain Store']);
        $ownerRole = Role::create(['name' => 'Owner', 'created_by' => $admin->id]);
        $ownerRole->permissions()->sync(
            Permission::whereIn('name', [
                'user-view', 'user-store', 'user-store-view', 'user-store-assign',
                'role-view', 'role-store',
            ])->pluck('id')
        );
        $owner = User::factory()->create(['email' => 'chain-owner@example.com', 'created_by' => $admin->id]);
        $owner->stores()->attach($store->id, ['role_id' => $ownerRole->id]);

        $this->browse(function (Browser $browser) use ($owner, $store) {
            $this->freshSession($browser);

            // -- Owner switches into their store (single store → auto-selected) ---
            $browser->loginAs($owner);
            $this->switchToStore($browser, $store);

            // -- Owner builds a "Manager" role; the checklist only offers the
            //    owner's own permissions, and the global checkbox is hidden ------
            $browser->visit('/roles');
            $this->waitForAlpine($browser);
            $this->openRoleModal($browser);
            $browser->assertMissing('@role-global')
                ->within('@role-form', fn (Browser $form) => $form->assertDontSee('Delete Permissions'));
            $this->jsType($browser, '@role-name', 'Manager');
            $this->checkPermission($browser, 'View Users');
            $this->checkPermission($browser, 'Create Users');
            $this->jsClick($browser, '@role-save');
            $browser->waitForText('Manager');
            $this->waitForModalClosed($browser, '@role-form');

            $managerRole = Role::where('name', 'Manager')->firstOrFail();
            $this->assertSame($owner->id, $managerRole->created_by);
            $this->assertFalse((bool) $managerRole->is_global);

            // -- Owner creates the manager and assigns them within the store;
            //    the store dropdown never appears for a non-super-admin ----------
            $browser->visit('/users');
            $this->waitForAlpine($browser);
            $this->createUserThroughForm($browser, 'Manny', 'Manager', '2223334444', 'manager@example.com');

            $manager = User::where('email', 'manager@example.com')->firstOrFail();
            $this->clickAndAwait($browser, '@stores-user-'.$manager->id, fn (Browser $b) => $b->waitFor('@assign-role', 3));
            $browser->assertMissing('@assign-store')
                ->select('@assign-role', (string) $managerRole->id);
            $this->clickAndAwait($browser, '@assign-button',
                fn (Browser $b) => $this->waitForAssignment($b, $manager->id, $store->id, $managerRole->id));

            // -- The manager logs in, switches in, and creates a worker under
            //    themselves — level three of the chain ---------------------------
            $this->freshSession($browser);
            $browser->loginAs($manager);
            $this->switchToStore($browser, $store);

            $browser->visit('/users');
            $this->waitForAlpine($browser);
            $this->createUserThroughForm($browser, 'Wally', 'Worker', '3334445555', 'worker@example.com');

            $worker = User::where('email', 'worker@example.com')->firstOrFail();
            $this->assertSame($manager->id, $worker->created_by);

            // -- Visibility: each level only sees the level it created ------------
            // The manager sees the worker but neither the owner nor the admin.
            $browser->visit('/users');
            $this->waitForAlpine($browser);
            $browser->waitForText('worker@example.com')
                ->assertDontSee('chain-owner@example.com')
                ->assertDontSee('admin@gmail.com');

            // The owner sees the manager but not the worker two levels down.
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToStore($browser, $store);
            $browser->visit('/users');
            $this->waitForAlpine($browser);
            $browser->waitForText('manager@example.com')
                ->assertDontSee('worker@example.com')
                ->assertDontSee('admin@gmail.com');
        });
    }

    /**
     * A global role built and assigned through the UI: no store is asked for, the
     * assignment lands on the global sentinel, and its permissions work with no
     * store selected — without making the holder a super admin.
     */
    public function test_a_global_role_is_assigned_without_a_store_and_works_system_wide(): void
    {
        $admin = $this->seedSuperAdmin();

        $this->browse(function (Browser $browser) use ($admin) {
            $this->freshSession($browser);

            // -- Build the global role with the checkbox --------------------------
            $browser->loginAs($admin)->visit('/roles');
            $this->waitForAlpine($browser);
            $this->openRoleModal($browser);
            $this->jsType($browser, '@role-name', 'Global Admin');
            $this->jsClick($browser, '@role-global');
            $this->checkPermission($browser, 'View Users');
            $this->checkPermission($browser, 'Create Users');
            $this->jsClick($browser, '@role-save');
            $browser->waitForText('Global Admin');
            $this->waitForModalClosed($browser, '@role-form');

            $globalRole = Role::where('name', 'Global Admin')->firstOrFail();
            $this->assertTrue((bool) $globalRole->is_global);

            // -- Create the deputy and assign the global role: the store dropdown
            //    disappears and the "no store needed" hint appears ----------------
            $browser->visit('/users');
            $this->waitForAlpine($browser);
            $this->createUserThroughForm($browser, 'Dee', 'Deputy', '4445556666', 'deputy@example.com');

            $deputy = User::where('email', 'deputy@example.com')->firstOrFail();
            $this->clickAndAwait($browser, '@stores-user-'.$deputy->id, fn (Browser $b) => $b->waitFor('@assign-role', 3));
            $browser->select('@assign-role', (string) $globalRole->id)
                ->waitForText('This role is global — no store needed.');
            $this->clickAndAwait($browser, '@assign-button',
                fn (Browser $b) => $this->waitForAssignment($b, $deputy->id, 0, $globalRole->id));
            $browser->waitForText('Global (All Stores)');

            $this->assertFalse($deputy->isSuperAdmin());

            // -- The deputy's permissions work with no store selected -------------
            $this->freshSession($browser);
            $browser->loginAs($deputy)->visit('/users');
            $this->waitForAlpine($browser);
            $browser->waitForText('No users found.')
                ->assertDontSee('admin@gmail.com');
        });
    }
}
