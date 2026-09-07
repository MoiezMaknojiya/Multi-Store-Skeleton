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

class CrossStoreSymmetricTest extends DuskTestCase
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

    private function createRoleThroughForm(Browser $browser, string $name, array $permissionLabels): Role
    {
        $browser->visit('/roles');
        $this->waitForAlpine($browser);
        $this->openRoleModal($browser);
        $this->jsType($browser, '@role-name', $name);
        foreach ($permissionLabels as $label) {
            $this->checkPermission($browser, $label);
        }
        $this->jsClick($browser, '@role-save');
        $browser->waitForText($name);
        $this->waitForModalClosed($browser, '@role-form');

        return Role::where('name', $name)->firstOrFail();
    }

    private function openAssignModal(Browser $browser, User $target): void
    {
        $this->clickAndAwait($browser, '@stores-user-'.$target->id, fn (Browser $b) => $b->waitFor('@assign-role', 3));
    }

    /** The role dropdown's option labels, once the AJAX list has painted.
     *  Index 0 is the "Select Role" placeholder. */
    private function assignRoleOptions(Browser $browser): array
    {
        $read = fn () => $browser->script(
            "return Array.from(document.querySelectorAll('[dusk=\"assign-role\"] option')).map(o => o.textContent.trim());"
        )[0];

        $browser->waitUsing(6, 150, fn () => count($read()) > 1);

        return array_slice($read(), 1);
    }

    /** Assign a role via the modal. For store users the UI always targets the
     *  CURRENT store (the store dropdown is global-users-only by design), so
     *  $store here is the store the browser is currently switched into. */
    private function assignStoreRole(Browser $browser, User $target, Store $store, Role $role): void
    {
        $this->openAssignModal($browser, $target);
        $this->selectAndAssign($browser, $target, $store, $role);
    }

    /** Finish an already-open assign modal. */
    private function selectAndAssign(Browser $browser, User $target, Store $store, Role $role): void
    {
        $browser->select('@assign-role', (string) $role->id);
        $this->clickAndAwait($browser, '@assign-button', function (Browser $b) use ($target, $store, $role) {
            $b->waitUsing(10, 150, fn () => DB::table('store_user')
                ->where(['user_id' => $target->id, 'store_id' => $store->id, 'role_id' => $role->id])
                ->exists());
        });
        $browser->script("window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));");
        $browser->pause(300);
    }

    private function loginAndSwitch(Browser $browser, User $user, Store $store): void
    {
        $this->freshSession($browser);
        $browser->loginAs($user);
        $this->switchToStore($browser, $store);
    }

    private function switchStore(Browser $browser, Store $store): void
    {
        $this->switchToStore($browser, $store);
    }

    /**
     * Symmetric cross-store many-to-many:
     *
     *   User A = OWNER of Store Alpha + MANAGER in Store Beta
     *   User B = OWNER of Store Beta  + MANAGER in Store Alpha
     *
     * Each owner builds their own role + child through the real UI; A's child is
     * cross-assigned into B's store; a grandchild goes one level deeper. Powers
     * must flip per store, and the created_by visibility walls must hold in every
     * direction.
     */
    public function test_symmetric_cross_store_owners_with_children_and_cross_assignments(): void
    {
        $admin = $this->seedSuperAdmin();

        $alpha = Store::factory()->create(['name' => 'Store Alpha']);
        $beta = Store::factory()->create(['name' => 'Store Beta']);
        $gamma = Store::factory()->create(['name' => 'Store Gamma']); // nobody is assigned here

        $ownerRole = Role::create(['name' => 'Owner Role', 'created_by' => $admin->id]);
        $ownerRole->permissions()->sync(Permission::whereIn('name', [
            'user-view', 'user-store', 'user-store-view', 'user-store-assign',
            'role-view', 'role-store', 'store-view',
        ])->pluck('id'));

        // A manager runs the store's people and its roles, but cannot CREATE
        // users there (no user-store). The roles they build belong to that store
        // only — which is what makes the cross-store isolation visible below.
        $managerRole = Role::create(['name' => 'Manager Role', 'created_by' => $admin->id]);
        $managerRole->permissions()->sync(Permission::whereIn('name', [
            'user-view', 'store-view', 'user-store-view', 'user-store-assign',
            'role-view', 'role-store',
        ])->pluck('id'));

        $userA = User::factory()->create(['email' => 'usera@example.com', 'created_by' => $admin->id]);
        $userB = User::factory()->create(['email' => 'userb@example.com', 'created_by' => $admin->id]);

        // The symmetric cross: each one owns their store and manages the other's.
        $userA->stores()->attach($alpha->id, ['role_id' => $ownerRole->id]);
        $userA->stores()->attach($beta->id, ['role_id' => $managerRole->id]);
        $userB->stores()->attach($beta->id, ['role_id' => $ownerRole->id]);
        $userB->stores()->attach($alpha->id, ['role_id' => $managerRole->id]);

        $this->browse(function (Browser $browser) use ($admin, $alpha, $beta, $userA, $userB) {
            // ── User A, Store Alpha (OWNER) ─────────────────────────────────
            $this->loginAndSwitch($browser, $userA, $alpha);
            // A belongs to Alpha + Beta — the selector lists both, never the
            // unassigned Gamma.
            $browser->visit('/select-store')->waitForText('Store Beta');
            $browser->assertSee('Store Alpha')->assertDontSee('Store Gamma');

            // Store listing is scoped to assigned stores only. Wait on 'Store Beta'
            // (NOT the current store) so we wait for the AJAX table to paint — the
            // current store's name is already in the header switcher, so waiting on
            // it would return before the table loaded.
            $browser->visit('/stores');
            $this->waitForAlpine($browser);
            $browser->waitForText('Store Beta')
                ->assertSee('Store Alpha')
                ->assertDontSee('Store Gamma');

            // As owner, A builds their own role and child user through the UI.
            $alphaStaff = $this->createRoleThroughForm($browser, 'Alpha Staff', ['View Users', 'Create Users']);
            $browser->assertDontSee('Manager Role'); // store users see only roles they created

            $browser->visit('/users');
            $this->waitForAlpine($browser);
            $this->createUserThroughForm($browser, 'Child', 'Alpha', '5551110001', 'childa@example.com');
            $childA = User::where('email', 'childa@example.com')->firstOrFail();

            $this->assignStoreRole($browser, $childA, $alpha, $alphaStaff);

            // ── User A, Store Beta (MANAGER) — powers flip ──────────────────
            $this->switchStore($browser, $beta);

            // Per-store role isolation: the role A built in Alpha does not exist
            // here. A cannot see it, edit it, or hand it out from inside Beta —
            // work done in one store stays in that store.
            $browser->visit('/roles');
            $this->waitForAlpine($browser);
            $browser->waitForText('No roles found.')
                ->assertDontSee('Alpha Staff');

            // So A builds a role that belongs to Beta.
            $betaCrew = $this->createRoleThroughForm($browser, 'Beta Crew', ['View Users']);
            $this->assertSame($beta->id, $betaCrew->store_id);

            // A's own people follow them here — deliberate, so A can staff their
            // other store without going to an admin.
            $browser->visit('/users');
            $this->waitForAlpine($browser);
            $browser->waitForText('childa@example.com')
                ->assertMissing('@add-user'); // no user-store in this store's role

            // Many-to-many cross: from INSIDE Beta (where A is a member with assign
            // rights) A puts their child into B's store too. The ROLE is the part
            // that cannot travel — only Beta's own roles are on the menu.
            $this->openAssignModal($browser, $childA);
            $options = $this->assignRoleOptions($browser);
            $this->assertContains('Beta Crew', $options);
            $this->assertNotContains('Alpha Staff', $options, 'A role created in Alpha must not be assignable from inside Beta');
            $this->selectAndAssign($browser, $childA, $beta, $betaCrew);

            // ── User B, Store Beta (OWNER) — the mirror image ───────────────
            $this->loginAndSwitch($browser, $userB, $beta);
            // B belongs to Beta + Alpha — the selector lists both, never Gamma.
            $browser->visit('/select-store')->waitForText('Store Alpha');
            $browser->assertSee('Store Beta')->assertDontSee('Store Gamma');

            $betaStaff = $this->createRoleThroughForm($browser, 'Beta Staff', ['View Users']);
            $browser->assertDontSee('Alpha Staff')  // A's role never leaks to B
                ->assertDontSee('Beta Crew');       // not even the one A made in B's own store

            $browser->visit('/users');
            $this->waitForAlpine($browser);
            // Visibility wall: B sees neither A nor A's child, even though they
            // all share the same two stores.
            $browser->assertDontSee('usera@example.com')->assertDontSee('childa@example.com');
            $this->createUserThroughForm($browser, 'Child', 'Beta', '5551110002', 'childb@example.com');
            $childB = User::where('email', 'childb@example.com')->firstOrFail();
            $this->assignStoreRole($browser, $childB, $beta, $betaStaff);

            // ── User B, Store Alpha (MANAGER) — same flip on the other side ─
            $this->switchStore($browser, $alpha);
            $browser->visit('/users');
            $this->waitForAlpine($browser);
            $browser->waitForText('childb@example.com')
                ->assertMissing('@add-user');

            // B's Beta role, however, is invisible from Alpha.
            $browser->visit('/roles');
            $this->waitForAlpine($browser);
            $browser->waitForText('No roles found.')
                ->assertDontSee('Beta Staff')
                ->assertDontSee('Alpha Staff');

            // ── Deep level: A's child spans both stores and goes deeper ─────
            $this->loginAndSwitch($browser, $childA, $alpha);
            $browser->visit('/select-store')->waitForText('Store Beta'); // child belongs to both stores

            $browser->visit('/users');
            $this->waitForAlpine($browser);
            $this->createUserThroughForm($browser, 'Grand', 'Alpha', '5551110003', 'grandchilda@example.com');
            $grandA = User::where('email', 'grandchilda@example.com')->firstOrFail();
            $browser->assertDontSee('childb@example.com')->assertDontSee('userb@example.com');

            // The child works from B's store too (cross-assigned there by A), and
            // their own people come along with them.
            $this->switchStore($browser, $beta);
            $browser->visit('/users');
            $this->waitForAlpine($browser);
            $browser->waitForText('grandchilda@example.com');

            // Their Beta role carries no role permissions, so the module is gone.
            $browser->visit('/roles')->waitForText('403');

            // ── Back to A: sees only their direct child, nothing deeper ─────
            $this->loginAndSwitch($browser, $userA, $alpha);
            $browser->visit('/users');
            $this->waitForAlpine($browser);
            $browser->waitForText('childa@example.com')
                ->assertDontSee('grandchilda@example.com')
                ->assertDontSee('childb@example.com')
                ->assertDontSee('userb@example.com');

            // ── The many-to-many truth table, straight from the pivot ───────
            $matrix = [
                [$userA->id, $alpha->id],
                [$userA->id, $beta->id],
                [$userB->id, $beta->id],
                [$userB->id, $alpha->id],
                [$childA->id, $alpha->id],
                [$childA->id, $beta->id],
                [$childB->id, $beta->id],
            ];
            foreach ($matrix as [$userId, $storeId]) {
                $this->assertTrue(
                    DB::table('store_user')->where(['user_id' => $userId, 'store_id' => $storeId])->exists(),
                    "Missing store_user row for user {$userId} in store {$storeId}"
                );
            }

            // Ownership chain: admin -> A -> childA -> grandchild; admin -> B -> childB.
            $this->assertSame($admin->id, $userA->created_by);
            $this->assertSame($userA->id, $childA->created_by);
            $this->assertSame($childA->id, $grandA->created_by);
            $this->assertSame($userB->id, $childB->created_by);
        });
    }
}
