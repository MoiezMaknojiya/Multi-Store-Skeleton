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

class DeepChainMultiStoreTest extends DuskTestCase
{
    use DatabaseMigrations;

    /** Click a permission's checkbox in the open role form by its visible label. */
    private function checkPermission(Browser $browser, string $displayName): void
    {
        $browser->script(
            "for (const label of document.querySelectorAll('[dusk=\"role-form\"] label')) {"
            ."  if (label.textContent.includes('{$displayName}')) { label.click(); break; }"
            .'}'
        );
    }

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

    /** Assign the given role to a user via the modal (non-super-admin: current store). */
    private function assignRole(Browser $browser, User $target, Role $role, int $expectedStoreId): void
    {
        $this->clickAndAwait($browser, '@stores-user-'.$target->id, fn (Browser $b) => $b->waitFor('@assign-role', 3));
        $browser->select('@assign-role', (string) $role->id);
        $this->clickAndAwait($browser, '@assign-button', function (Browser $b) use ($target, $expectedStoreId, $role) {
            $b->waitUsing(10, 150, fn () => DB::table('store_user')
                ->where(['user_id' => $target->id, 'store_id' => $expectedStoreId, 'role_id' => $role->id])
                ->exists());
        });
        // Close the modal so nothing overlays the page afterwards.
        $browser->script("window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));");
        $browser->pause(300);
    }

    private function loginAndSwitch(Browser $browser, User $user, Store $store): void
    {
        $this->freshSession($browser);
        $browser->loginAs($user);
        $this->switchToStore($browser, $store);
    }

    /**
     * The whole thing at once, four levels deep with multi-store in the middle:
     *
     *   Admin -> Owner (in BOTH stores) -> Manager (assigned to BOTH stores by the
     *   owner, one store at a time) -> Worker -> fourth-level user.
     *
     * Every user is created through the real UI by the level above it, and the
     * visibility ladder holds at every level. Users may span stores; roles never
     * do — each store gets its own role, built inside that store.
     */
    public function test_a_four_level_chain_where_the_middle_manager_spans_two_stores(): void
    {
        $admin = $this->seedSuperAdmin();

        $north = Store::factory()->create(['name' => 'North Store']);
        $south = Store::factory()->create(['name' => 'South Store']);

        // Level 1: the owner, assigned to BOTH stores by the admin.
        $ownerRole = Role::create(['name' => 'Owner', 'created_by' => $admin->id]);
        $ownerRole->permissions()->sync(Permission::whereIn('name', [
            'user-view', 'user-store', 'user-store-view', 'user-store-assign', 'role-view', 'role-store',
        ])->pluck('id'));
        $owner = User::factory()->create(['email' => 'owner@example.com', 'created_by' => $admin->id]);
        $owner->stores()->attach($north->id, ['role_id' => $ownerRole->id]);
        $owner->stores()->attach($south->id, ['role_id' => $ownerRole->id]);

        $this->browse(function (Browser $browser) use ($admin, $owner, $north, $south) {
            // -- Level 2: the owner (in North) builds a Manager role and user -----
            $this->loginAndSwitch($browser, $owner, $north);

            $browser->visit('/roles');
            $this->waitForAlpine($browser);
            $this->openRoleModal($browser);
            $this->jsType($browser, '@role-name', 'Manager');
            $this->checkPermission($browser, 'View Users');
            $this->checkPermission($browser, 'Create Users');
            $this->checkPermission($browser, 'View User Store Assignments');
            $this->checkPermission($browser, 'Assign User to Store');
            $this->checkPermission($browser, 'View Roles');
            $this->checkPermission($browser, 'Create Roles');
            $this->jsClick($browser, '@role-save');
            $browser->waitForText('Manager');
            $this->waitForModalClosed($browser, '@role-form');
            $managerRole = Role::where('name', 'Manager')->firstOrFail();
            $this->assertSame($north->id, $managerRole->store_id);

            $browser->visit('/users');
            $this->waitForAlpine($browser);
            $this->createUserThroughForm($browser, 'Mid', 'Manager', '2001002000', 'manager@example.com');
            $manager = User::where('email', 'manager@example.com')->firstOrFail();
            $this->assignRole($browser, $manager, $managerRole, $north->id);

            // -- The owner switches to South and puts the SAME manager there too —
            //    the level-2 user ends up spanning both stores. Roles do NOT
            //    travel with them: the North "Manager" role does not exist in
            //    South, so the owner must build a South role first -------------
            $this->switchToStore($browser, $south);
            $browser->visit('/roles');
            $this->waitForAlpine($browser);
            $browser->waitForText('No roles found.')  // the North role is invisible here
                ->assertDontSee('Manager');

            $this->openRoleModal($browser);
            $this->jsType($browser, '@role-name', 'South Manager');
            $this->checkPermission($browser, 'View Users');
            $this->checkPermission($browser, 'Create Users');
            $this->checkPermission($browser, 'View User Store Assignments');
            $this->checkPermission($browser, 'Assign User to Store');
            $this->checkPermission($browser, 'View Roles');
            $this->checkPermission($browser, 'Create Roles');
            $this->jsClick($browser, '@role-save');
            $browser->waitForText('South Manager');
            $this->waitForModalClosed($browser, '@role-form');
            $southManagerRole = Role::where('name', 'South Manager')->firstOrFail();
            $this->assertSame($south->id, $southManagerRole->store_id);

            // The owner's own people follow them into their other store, so the
            // manager is listed here and can be given South's role.
            $browser->visit('/users');
            $this->waitForAlpine($browser);
            $browser->waitForText('manager@example.com');
            $this->assignRole($browser, $manager, $southManagerRole, $south->id);

            // -- Level 3: the manager belongs to BOTH stores (the selector lists
            //    them both), and works from North -------------------------------
            $this->loginAndSwitch($browser, $manager, $north);
            $browser->visit('/select-store');
            $browser->waitForText('North Store')->assertSee('South Store');

            $browser->visit('/roles');
            $this->waitForAlpine($browser);
            $this->openRoleModal($browser);
            // The manager's checklist only offers what the manager holds.
            $browser->within('@role-form', fn (Browser $form) => $form->assertDontSee('Delete Users'));
            $this->jsType($browser, '@role-name', 'Worker');
            $this->checkPermission($browser, 'View Users');
            $this->checkPermission($browser, 'Create Users');
            $this->jsClick($browser, '@role-save');
            $browser->waitForText('Worker');
            $this->waitForModalClosed($browser, '@role-form');
            $workerRole = Role::where('name', 'Worker')->firstOrFail();

            $browser->visit('/users');
            $this->waitForAlpine($browser);
            $this->createUserThroughForm($browser, 'Wade', 'Worker', '3001003000', 'worker@example.com');
            $worker = User::where('email', 'worker@example.com')->firstOrFail();
            $this->assignRole($browser, $worker, $workerRole, $north->id);

            // -- Level 4: the worker creates a user of their own ------------------
            $this->loginAndSwitch($browser, $worker, $north);
            $browser->visit('/users');
            $this->waitForAlpine($browser);
            $this->createUserThroughForm($browser, 'Level', 'Four', '4001004000', 'level4@example.com');
            $level4 = User::where('email', 'level4@example.com')->firstOrFail();

            // -- The chain is exactly admin -> owner -> manager -> worker -> L4 ---
            $this->assertSame($admin->id, $owner->created_by);
            $this->assertSame($owner->id, $manager->created_by);
            $this->assertSame($manager->id, $worker->created_by);
            $this->assertSame($worker->id, $level4->created_by);

            // -- Visibility ladder: every level sees ONLY its direct children -----
            // The worker (still logged in) sees only level 4.
            $browser->visit('/users');
            $this->waitForAlpine($browser);
            $browser->waitForText('level4@example.com')
                ->assertDontSee('manager@example.com')
                ->assertDontSee('owner@example.com');

            // The manager sees only the worker.
            $this->loginAndSwitch($browser, $manager, $north);
            $browser->visit('/users');
            $this->waitForAlpine($browser);
            $browser->waitForText('worker@example.com')
                ->assertDontSee('level4@example.com')
                ->assertDontSee('owner@example.com');

            // The owner sees only the manager.
            $this->loginAndSwitch($browser, $owner, $north);
            $browser->visit('/users');
            $this->waitForAlpine($browser);
            $browser->waitForText('manager@example.com')
                ->assertDontSee('worker@example.com')
                ->assertDontSee('level4@example.com');
        });
    }
}
