<?php

namespace Tests\Browser;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The platform's side through the real pages: an organization created for a customer whose owner
 * then accepts from the email, and a platform role built on the Roles page.
 */
class PlatformConsoleFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_the_platform_creates_an_organization_and_its_owner_accepts_from_the_email(): void
    {
        $admin = $this->seedSuperAdmin();

        $this->browse(function (Browser $browser) use ($admin) {
            /* ── 1. The super admin creates the organization ───────────────────── */
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/organizations');
            $this->waitForAlpine($browser);

            $logSizeBefore = $this->mailLogSize();

            $this->clickAndAwait($browser, '@add-organization', fn (Browser $b) => $b->waitFor('@organization-form', 3));
            $this->jsType($browser, '@organization-name', 'Gamma Grocers');
            $this->jsType($browser, '@organization-street', '9 Elm St');
            $this->jsType($browser, '@organization-city', 'Houston');
            $browser->select('@organization-state', 'TX');
            $this->jsType($browser, '@organization-zip', '77001');
            $this->jsType($browser, '@organization-owner-email', 'gina@example.com');
            $this->jsClick($browser, '@organization-save');

            $browser->waitForText('Organization created. An invitation to own it was sent to gina@example.com.');

            // Nobody is in the organization until the invitation is accepted, so it is still offered an owner.
            $organization = Organization::where('name', 'Gamma Grocers')->firstOrFail();
            $browser->waitForTextIn('@organization-members-'.$organization->id, '0')
                ->assertVisible('@invite-owner-'.$organization->id);

            /* ── 2. The owner accepts from the emailed link ─────────────── */
            $token = $this->tokenFromMailLog($logSizeBefore, 'invitations');

            $this->freshSession($browser);
            $browser->visit('/invitations/'.$token)
                ->waitFor('@invitation-register-form')
                ->assertSee('Join Gamma Grocers')
                ->assertSeeIn('@invitation-role', 'Owner');

            $this->jsType($browser, '#first_name', 'Gina');
            $this->jsType($browser, '#last_name', 'Grocer');
            $this->jsType($browser, '#phone', '5559876543');
            $this->jsType($browser, '#password', 'Str0ng-Password!');
            $this->jsType($browser, '#password_confirmation', 'Str0ng-Password!');
            $this->jsClick($browser, '@invitation-register');

            // In as the Owner: the platform's pages are not theirs, and the organization is the Organizations tab of their
            // Settings — the name at the foot of the sidebar.
            $browser->waitForLocation('/dashboard')
                ->waitForText('Welcome to Gamma Grocers!')
                ->assertMissing('#main-sidebar a[href$="/organizations"]')
                ->assertMissing('#main-sidebar a[href$="/settings/organization"]');

            $this->jsClick($browser, '@sidebar-settings');
            $browser->waitForLocation('/profile')->waitFor('@settings-tab-organization')
                ->assertSeeIn('@settings-tab-organization', 'Organizations');
            $this->jsClick($browser, '@settings-tab-organization');
            // An organization changes hands on its Members page: Settings has no handover of its own.
            $browser->waitForLocation('/settings/organization')
                ->waitForText('Organization Details')
                ->assertSee('Delete Organization')
                ->assertDontSee('Transfer Ownership')
                ->assertSeeIn('@your-organizations', 'Gamma Grocers');

            /* ── 3. The organizations list counts the new member ───────────────── */
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/organizations');
            $this->waitForAlpine($browser);
            $browser->waitForTextIn('@organization-members-'.$organization->id, '1')
                ->assertMissing('@invite-owner-'.$organization->id);   // it has an Owner now
        });
    }

    public function test_a_super_admin_makes_roles_for_the_platform_and_for_every_organization(): void
    {
        $admin = $this->seedSuperAdmin();
        $this->organizationMember(Organization::factory()->create(), Role::STAFF, 'held@example.com');   // somebody holds Staff

        $this->browse(function (Browser $browser) use ($admin) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/roles');
            $this->waitForAlpine($browser);

            // One list: Super-Admin (never edited), the Owner role (marked, renamable, never deleted), the other
            // organization roles (ordinary roles now: deleted while nobody holds them), then the platform team's roles.
            $superAdminId = Role::superAdminId();
            $ownerId = Role::owner()->id;
            $staff = Role::starter(Role::STAFF);
            $browser->waitFor('@role-row-'.$ownerId)
                ->assertSeeIn('@role-row-'.$ownerId, 'Owner')
                ->assertPresent('@role-row-'.$superAdminId)
                ->assertMissing('@edit-role-'.$superAdminId)
                ->assertVisible('@edit-role-'.$ownerId)
                ->assertMissing('@delete-role-'.$ownerId)
                ->assertVisible('@delete-role-'.$staff->id);

            // A role somebody still holds offers Delete too, and says at once why it cannot go yet — before any password.
            $this->jsClick($browser, '@delete-role-'.$staff->id);
            $browser->waitForText('Please unassign Staff from everyone first: 1 person still holds it.')
                ->assertMissing('@delete-role-password');
            $this->assertNotNull(Role::find($staff->id));

            // -- A platform role: the form offers only what it can hold — the catalogue is not even listed ----
            $this->clickAndAwait($browser, '@create-role', fn (Browser $b) => $b->waitFor('@role-type-platform', 5));
            $this->jsClick($browser, '@role-type-platform');
            $browser->waitFor('@permission-activity-destroy', 5)
                ->assertMissing('@permission-permission-view')
                ->assertEnabled('@role-name');

            $this->jsType($browser, '@role-name', 'Support');
            $this->jsClick($browser, '@permission-user-view');
            $this->jsClick($browser, '@permission-organization-view');
            $this->jsClick($browser, '@role-save');

            $browser->waitForText('Role Support created.');

            $role = Role::where('name', 'Support')->firstOrFail();
            $this->assertTrue($role->is_global);
            $this->assertNull($role->organization_id);
            $this->assertEqualsCanonicalizing(['organization-view', 'user-view'], $role->permissions->pluck('name')->all());

            // -- An organization role, offered in every organization: only what works inside an organization is listed ----
            $this->waitForModalClosed($browser, '@role-form');
            $this->clickAndAwait($browser, '@create-role', fn (Browser $b) => $b->waitFor('@permission-screen-view', 5));
            $browser->assertScript("document.querySelector('[dusk=\"role-type-organization\"]').checked", true)
                ->assertMissing('@permission-activity-destroy')
                ->assertMissing('@permission-permission-view')
                ->assertSeeIn('@permission-group-channel', 'This organization only');

            $this->jsType($browser, '@role-name', 'Shift Lead');
            $this->jsClick($browser, '@permission-screen-view');
            $this->jsClick($browser, '@permission-channel-view');
            $this->jsClick($browser, '@role-save');

            $browser->waitForText('Role Shift Lead created.');

            $lead = Role::where('name', 'Shift Lead')->firstOrFail();
            $this->assertFalse($lead->is_global);
            $this->assertNull($lead->organization_id);
            $this->assertEqualsCanonicalizing(['channel-view', 'screen-view'], $lead->permissions->pluck('name')->all());

            // -- The super admin renames Staff and lets it pair screens — in every organization at once ----
            $this->waitForModalClosed($browser, '@role-form');
            $this->clickAndAwait($browser, '@edit-role-'.$staff->id, fn (Browser $b) => $b->waitFor('@permission-screen-store', 5));
            $browser->assertEnabled('@role-name')
                ->assertInputValue('@role-name', 'Staff')
                ->assertMissing('@role-type-organization')
                ->assertMissing('@permission-activity-destroy');

            $this->jsType($browser, '@role-name', 'Crew');
            $this->jsClick($browser, '@permission-screen-store');
            $this->jsClick($browser, '@role-save');
            $browser->waitForText('Role Crew updated in every organization.')
                ->waitForTextIn('@role-name-'.$staff->id, 'Crew');

            $this->assertSame('Crew', $staff->fresh()->name);
            $this->assertContains('screen-store', $staff->fresh()->permissions->pluck('name')->all());
        });
    }

    /**
     * From the Users page the super admin puts anybody in any organization with a role — straight in, nobody invited —
     * and the organization's only Owner is never taken out.
     */
    public function test_the_super_admin_puts_a_person_in_an_organization_with_a_role(): void
    {
        $admin = $this->seedSuperAdmin(); // SEED_ADMIN_PASSWORD is "test" (phpunit.dusk.xml)
        $beta = Organization::factory()->create(['name' => 'Beta Deli']);
        $casey = User::factory()->create(['first_name' => 'Casey', 'last_name' => 'Keeper', 'email' => 'casey@example.com']);

        $this->browse(function (Browser $browser) use ($admin, $beta, $casey) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/organizations');
            $this->waitForAlpine($browser);
            $browser->waitForTextIn('@organization-members-'.$beta->id, '0')
                ->assertVisible('@invite-owner-'.$beta->id);

            /* ── 1. Users → Organizations: Casey goes into Beta Deli as its Owner ─ */
            $browser->visit('/users');
            $this->waitForAlpine($browser);
            $browser->waitFor('@manage-organizations-'.$casey->id);
            $this->clickAndAwait($browser, '@manage-organizations-'.$casey->id, fn (Browser $b) => $b->waitFor('@assign-organization', 5));
            $browser->assertSeeIn('@manage-organizations', 'Not in any organization yet.');

            $ownerId = Role::owner()->id;
            $browser->select('@assign-organization', (string) $beta->id)
                ->waitUntil("!document.querySelector('[dusk=\"assign-role\"]').disabled && !!document.querySelector('[dusk=\"assign-role\"] option[value=\"{$ownerId}\"]')")
                ->select('@assign-role', (string) $ownerId);
            $this->jsClick($browser, '@assign-organization-save');

            $browser->waitForText('Casey Keeper is now Owner in Beta Deli.')
                ->waitFor('@membership-'.$beta->id)
                ->assertSelected('@membership-role-'.$beta->id, (string) $ownerId);
            $this->assertSame($ownerId, $casey->organizations()->whereKey($beta->id)->first()->pivot->role_id);

            /* ── 2. The only Owner stays: taking Casey out is refused ────── */
            $this->jsClick($browser, '@membership-remove-'.$beta->id);
            $browser->waitFor('@remove-membership-form');
            $this->jsType($browser, '@remove-membership-password', 'test');
            $this->jsClick($browser, '@remove-membership-confirm');
            $browser->waitForText('Casey Keeper is the only Owner of Beta Deli. Make someone else an Owner first.');
            $this->assertNotNull($casey->organizations()->whereKey($beta->id)->first());

            /* ── 3. The Organizations list counts the member — and offers no owner to an organization that has one ── */
            $browser->visit('/organizations');
            $this->waitForAlpine($browser);
            $browser->waitForTextIn('@organization-members-'.$beta->id, '1')
                ->assertMissing('@invite-owner-'.$beta->id);
        });
    }

    /**
     * A platform team member, invited from the Users page, works above every organization — with exactly
     * what their platform role gives and not a step further.
     */
    public function test_a_platform_team_member_joins_from_the_email_and_works_above_the_organizations(): void
    {
        $admin = $this->seedSuperAdmin();
        Organization::factory()->create(['name' => 'Alpha Mart']);
        Organization::factory()->create(['name' => 'Beta Deli']);

        $support = Role::create(['name' => 'Support', 'is_global' => true]);
        $support->permissions()->sync(Permission::whereIn('name', ['organization-view', 'user-view'])->pluck('id'));

        $this->browse(function (Browser $browser) use ($admin, $support) {
            /* ── 1. The super admin invites them ────────────────────────── */
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/users');
            $this->waitForAlpine($browser);

            $logSizeBefore = $this->mailLogSize();

            $this->clickAndAwait($browser, '@invite-platform-member', fn (Browser $b) => $b->waitFor('@invite-platform-form', 3));
            $this->jsType($browser, '@invite-platform-email', 'support@example.com');
            $browser->select('@invite-platform-role', (string) $support->id);
            $this->jsClick($browser, '@invite-platform-send');

            $browser->waitForText('Invitation sent to support@example.com.')
                ->waitForTextIn('@platform-invitations', 'support@example.com');

            /* ── 2. They join from the emailed link ─────────────────────── */
            $token = $this->tokenFromMailLog($logSizeBefore, 'invitations');

            $this->freshSession($browser);
            $browser->visit('/invitations/'.$token)
                ->waitFor('@invitation-register-form')
                ->assertSeeIn('@invitation-role', 'Support');

            $this->jsType($browser, '#first_name', 'Sam');
            $this->jsType($browser, '#last_name', 'Support');
            $this->jsType($browser, '#phone', '5550001111');
            $this->jsType($browser, '#password', 'Str0ng-Password!');
            $this->jsType($browser, '#password_confirmation', 'Str0ng-Password!');
            $this->jsClick($browser, '@invitation-register');

            $browser->waitForLocation('/dashboard')
                ->waitForText('Welcome to the '.config('app.name').' team!');

            /* ── 3. Every organization, and nothing their role does not give ───── */
            $browser->assertPresent('#main-sidebar a[href$="/organizations"]')
                ->assertMissing('#main-sidebar a[href$="/permissions"]');

            // Their Settings is their profile alone: a platform account has no organization of its own to set up.
            $browser->visit('/profile')
                ->waitFor('@settings-tab-profile')
                ->assertMissing('@settings-tab-organization');

            $browser->visit('/organizations');
            $this->waitForAlpine($browser);
            $browser->waitForText('Alpha Mart')
                ->assertSee('Beta Deli')
                ->assertMissing('@add-organization');   // organization-view only: looking, not creating

            $browser->visit('/roles')->assertSee('403');
        });
    }

    /**
     * An organization, an account and a role are big deletes: each modal asks for the super admin's password
     * and deletes once it is right — and the organization's, taken first, says so when the password is
     * missing or wrong and deletes nothing until it is right.
     */
    public function test_big_deletes_on_the_platform_ask_for_the_password(): void
    {
        $admin = $this->seedSuperAdmin(); // SEED_ADMIN_PASSWORD is "test" (phpunit.dusk.xml)
        $organization = Organization::factory()->create(['name' => 'Doomed Deli']);
        $customer = User::factory()->create(['first_name' => 'Casey', 'last_name' => 'Customer']);
        $support = Role::create(['name' => 'Support', 'is_global' => true]);
        $support->permissions()->sync(Permission::whereIn('name', ['organization-view'])->pluck('id'));

        $this->browse(function (Browser $browser) use ($admin, $organization, $customer, $support) {
            $this->freshSession($browser);

            /* ── An organization: the name typed, then the password ─────────────── */
            $browser->loginAs($admin)->visit('/organizations');
            $this->waitForAlpine($browser);
            $browser->waitFor('@delete-organization-'.$organization->id);
            $this->clickAndAwait($browser, '@delete-organization-'.$organization->id, fn (Browser $b) => $b->waitFor('@delete-organization-password', 3));

            // The name to type stands out in the sentence that asks for it (owner, 2026-10-01).
            $browser->assertSeeIn('@delete-organization-typed-name', 'Doomed Deli');
            $this->assertSame('600', (string) $browser->script('return getComputedStyle(document.querySelector(\'[dusk="delete-organization-typed-name"]\')).fontWeight;')[0]);

            $this->jsType($browser, '@delete-organization-name', 'Doomed Deli');
            $this->jsClick($browser, '@delete-organization-confirm');
            $browser->waitForText('Password is required.');

            $this->jsType($browser, '@delete-organization-password', 'not-the-password');
            $this->jsClick($browser, '@delete-organization-confirm');
            $browser->waitForText('The password is incorrect.');
            $this->assertNotNull(Organization::find($organization->id));

            $this->jsType($browser, '@delete-organization-password', 'test');
            $this->jsClick($browser, '@delete-organization-confirm');
            $browser->waitForText('Doomed Deli was deleted.');
            $this->assertDatabaseMissing('organizations', ['id' => $organization->id]);

            /* ── An account ─────────────────────────────────────────────── */
            $browser->visit('/users');
            $this->waitForAlpine($browser);
            $browser->waitFor('@delete-account-'.$customer->id);
            $this->clickAndAwait($browser, '@delete-account-'.$customer->id, fn (Browser $b) => $b->waitFor('@delete-account-password', 3));
            $this->jsType($browser, '@delete-account-password', 'test');
            $this->jsClick($browser, '@delete-account-confirm');
            $browser->waitForText("Casey Customer's account was deleted.");
            $this->assertNull(User::find($customer->id));

            /* ── A platform role ────────────────────────────────────────── */
            $browser->visit('/roles');
            $this->waitForAlpine($browser);
            $browser->waitFor('@delete-role-'.$support->id);
            $this->clickAndAwait($browser, '@delete-role-'.$support->id, fn (Browser $b) => $b->waitFor('@delete-role-password', 3));
            $this->jsType($browser, '@delete-role-password', 'test');
            $this->jsClick($browser, '@confirm-role-deletion-confirm');
            $browser->waitForText('Role Support deleted.');
            $this->assertNull(Role::find($support->id));
        });
    }

    /** Somebody in no organization yet is told how to get in — and offered nothing else. */
    public function test_an_account_without_an_organization_sees_how_to_join(): void
    {
        $this->seedSuperAdmin();
        $loner = User::factory()->create(['email' => 'loner@example.com']);

        $this->browse(function (Browser $browser) use ($loner) {
            $this->freshSession($browser);
            $browser->loginAs($loner)->visit('/dashboard')
                ->waitFor('@dashboard-empty')
                ->assertSeeIn('@dashboard-empty', 'loner@example.com')
                ->assertMissing('#main-sidebar a[href$="/screens"]')
                ->assertMissing('#main-sidebar a[href$="/members"]')
                ->assertMissing('#main-sidebar a[href$="/organizations"]');
        });
    }

    /** Owner, 2026-10-07: "haan Platform Team aur Organization Members bana do, sorting bhi". */
    public function test_the_super_admin_sees_the_platform_team_or_the_organization_members_and_sorts_them(): void
    {
        $admin = $this->seedSuperAdmin();
        $admin->forceFill(['first_name' => 'Zara', 'last_name' => 'Admin', 'created_at' => now()->subDays(30)])->save();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->organizationMember($organization, Role::OWNER, 'bilal@example.com');
        $owner->forceFill(['first_name' => 'Bilal', 'last_name' => 'Owner', 'created_at' => now()->subDays(10)])->save();
        User::factory()->create(['first_name' => 'Ayesha', 'last_name' => 'New', 'email' => 'ayesha@example.com', 'created_at' => now()->subDay()]);

        $this->browse(function (Browser $browser) use ($admin) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/users');
            $this->waitForAlpine($browser);
            $browser->waitForText('Ayesha');

            $names = fn (): array => $browser->script("return [...document.querySelectorAll('[dusk^=\"account-row-\"] td:first-child span[x-text=\"item.name\"]')].map((name) => name.textContent.trim())")[0];
            $shows = function (array $expected) use ($browser, $names): void {
                $browser->waitUsing(5, 100, fn () => $names() === $expected);
            };

            // It opens on every account, newest first, each tab saying how many it holds.
            $shows(['Ayesha New', 'Bilal Owner', 'Zara Admin']);
            $browser->assertSeeIn('@accounts-count-all', '3')->assertSeeIn('@accounts-count-platform', '1')->assertSeeIn('@accounts-count-organization', '2')
                ->assertAttribute('@accounts-tab-all', 'aria-pressed', 'true');

            $this->jsClick($browser, '@accounts-tab-platform');
            $shows(['Zara Admin']);
            $browser->assertAttribute('@accounts-tab-platform', 'aria-pressed', 'true')->assertAttribute('@accounts-tab-all', 'aria-pressed', 'false');

            $this->jsClick($browser, '@accounts-tab-organization');
            $shows(['Ayesha New', 'Bilal Owner']);

            // Account sorts A to Z, then Z to A; Joined goes back to newest first.
            $this->jsClick($browser, '@sort-name');
            $shows(['Ayesha New', 'Bilal Owner']);
            $this->jsClick($browser, '@sort-name');
            $shows(['Bilal Owner', 'Ayesha New']);

            $this->jsClick($browser, '@accounts-tab-all');
            $shows(['Zara Admin', 'Bilal Owner', 'Ayesha New']);
            $this->jsClick($browser, '@sort-joined');
            $shows(['Ayesha New', 'Bilal Owner', 'Zara Admin']);
            $this->jsClick($browser, '@sort-joined');
            $shows(['Zara Admin', 'Bilal Owner', 'Ayesha New']);

            // A search the open tab holds nothing for says so, and Clear Search brings the tab's accounts back.
            $browser->type('@crud-search', 'Zara');
            $shows(['Zara Admin']);
            $this->jsClick($browser, '@accounts-tab-organization');
            $browser->waitFor('@table-no-match')->assertSeeIn('@table-no-match', 'Nothing matches');
            $this->jsClick($browser, '@table-clear-search');
            $shows(['Bilal Owner', 'Ayesha New']);
            $browser->assertAttribute('@accounts-tab-organization', 'aria-pressed', 'true');
        });
    }
}
