<?php

namespace Tests\Browser;

use App\Models\ActivityLog;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The platform buttons no other browser test presses: giving an ownerless organization an owner, taking a
 * platform role away (a big delete, so the password), and revoking an invitation from the organization's
 * own Members page. Each writes something, so each is worth pressing for real.
 */
class PlatformButtonsFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_the_platform_gives_an_ownerless_organization_an_owner(): void
    {
        $admin = $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Gamma Organization']);   // nobody in it at all

        $this->browse(function (Browser $browser) use ($admin, $organization) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/organizations');
            $this->waitForAlpine($browser);
            $browser->waitForText('Gamma Organization');

            $this->clickAndAwait($browser, '@invite-owner-'.$organization->id, fn (Browser $b) => $b->waitFor('@invite-owner-form', 3));
            $this->jsType($browser, '@invite-owner-email', 'newowner@example.com');
            $this->jsClick($browser, '@invite-owner-send');

            $browser->waitForText('newowner@example.com');

            $invitation = Invitation::where('email', 'newowner@example.com')->firstOrFail();
            $this->assertSame($organization->id, $invitation->organization_id);
            $this->assertSame(Role::owner()->id, $invitation->role_id, 'the invitation is for the Owner role');

            // Offered only while the organization has no Owner: once it does, the button is gone.
            $this->organizationMember($organization, Role::OWNER, 'owner@example.com');
            $browser->visit('/organizations');
            $this->waitForAlpine($browser);
            $browser->waitForText('Gamma Organization')->assertMissing('@invite-owner-'.$organization->id);
        });
    }

    public function test_taking_a_platform_role_asks_for_the_password_and_then_takes_it(): void
    {
        $primary = $this->seedSuperAdmin(); // SEED_ADMIN_PASSWORD is "test" (phpunit.dusk.xml)
        $support = User::factory()->create(['email' => 'support@example.com']);
        $supportRole = Role::create(['name' => 'Support', 'is_global' => true]);
        $support->organizations()->attach(0, ['role_id' => $supportRole->id]);

        $this->browse(function (Browser $browser) use ($primary, $support) {
            $this->freshSession($browser);
            $browser->loginAs($primary)->visit('/users');
            $this->waitForAlpine($browser);
            $browser->waitForText('support@example.com');

            $this->clickAndAwait(
                $browser,
                '@remove-platform-role-'.$support->id,
                fn (Browser $b) => $b->waitFor('@remove-platform-role-confirm', 3)
            );

            // A wrong password changes nothing — and the server's refusal comes back under the field.
            $this->jsType($browser, '@remove-platform-role-password', 'not-the-password');
            $this->jsClick($browser, '@remove-platform-role-confirm');
            $browser->waitForText('The password is incorrect.');
            $this->assertTrue(DB::table('organization_user')->where('user_id', $support->id)->where('organization_id', 0)->exists());

            // …the right one does.
            $this->jsType($browser, '@remove-platform-role-password', 'test');
            $this->jsClick($browser, '@remove-platform-role-confirm');
            $browser->waitUntilMissing('@remove-platform-role-confirm', 8);

            $this->assertFalse(
                DB::table('organization_user')->where('user_id', $support->id)->where('organization_id', 0)->exists(),
                'the platform membership is gone, and the account stays'
            );
            $this->assertNotNull(User::find($support->id));
        });
    }

    public function test_an_invitation_is_revoked_from_the_members_page(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->organizationMember($organization, Role::OWNER);
        [$invitation] = Invitation::open($organization, 'waiting@example.com', Role::starter(Role::STAFF), $owner);

        $this->browse(function (Browser $browser) use ($owner, $organization, $invitation) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToOrganization($browser, $organization);

            $browser->visit('/members');
            $this->waitForAlpine($browser);
            $this->clickAndAwait($browser, '@tab-invitations', fn (Browser $b) => $b->waitForTextIn('@invitations-table', 'waiting@example.com'));

            $this->clickAndAwait(
                $browser,
                '@revoke-invitation-'.$invitation->id,
                fn (Browser $b) => $b->waitFor('@revoke-invitation-confirm', 3)
            );
            $this->jsClick($browser, '@revoke-invitation-confirm');

            $browser->waitUntilMissing('@revoke-invitation-confirm', 8);
            $this->assertNull(Invitation::find($invitation->id), 'revoking deletes the row, so the link stops working');
        });
    }

    public function test_copy_invitation_link_makes_one_link_for_a_double_press_copies_it_and_shows_it(): void
    {
        // Owner, 2026-11-04: a customer driving for eight hours could not open the email, so the super admin sends the link
        // another way. A real mouse's double press (Chrome counts the second as clickCount 2) must make one link, the one
        // shown and copied must be the live one, and the dialog must stay open on it.
        $admin = $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Road Mart']);

        $this->browse(function (Browser $browser) use ($admin, $organization) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/organizations');
            $this->waitForAlpine($browser);
            $browser->waitForText('Road Mart');
            $tools = new ChromeDevToolsDriver($browser->driver);
            $tools->execute('Browser.grantPermissions', ['permissions' => ['clipboardReadWrite', 'clipboardSanitizedWrite'], 'origin' => rtrim(config('app.url'), '/')]);

            $this->clickAndAwait($browser, '@invite-owner-'.$organization->id, fn (Browser $b) => $b->waitFor('@invite-owner-form', 3));
            $browser->type('@invite-owner-email', 'driver@example.com');

            $at = $browser->script("const r = document.querySelector('[dusk=\"invite-owner-copy-link\"]').getBoundingClientRect(); return [Math.round(r.left + r.width / 2), Math.round(r.top + r.height / 2)];")[0];
            $tools->execute('Input.dispatchMouseEvent', ['type' => 'mouseMoved', 'x' => $at[0], 'y' => $at[1]]);
            foreach ([1, 2] as $press) {
                foreach (['mousePressed', 'mouseReleased'] as $type) {
                    $tools->execute('Input.dispatchMouseEvent', ['type' => $type, 'x' => $at[0], 'y' => $at[1], 'button' => 'left', 'buttons' => $type === 'mousePressed' ? 1 : 0, 'clickCount' => $press]);
                }
                usleep(120000);
            }

            $browser->waitFor('@invite-owner-link', 8)->waitForText('Invitation link copied.', 5);
            $link = $browser->value('@invite-owner-link');
            $this->assertMatchesRegularExpression('#/invitations/[A-Za-z0-9]{64}\z#', $link);

            // One link was made, and it is the live one: the one the invitation keeps the hash of.
            $invitation = Invitation::where('email', 'driver@example.com')->sole();
            $this->assertSame(Invitation::hashToken(substr($link, -64)), $invitation->token_hash);
            $this->assertSame(1, ActivityLog::where('action', 'organization.owner_link_copied')->count(), 'a double press made two links');

            // The clipboard holds it, and the dialog is still open on it.
            $this->assertSame($link, $browser->driver->executeAsyncScript('const done = arguments[0]; navigator.clipboard.readText().then(done, () => done(null));'));
            $browser->assertVisible('@invite-owner-close')->assertMissing('@invite-owner-send')->assertMissing('@invite-owner-copy-link');

            $browser->click('@invite-owner-copy-again')->waitForTextIn('@invite-owner-copy-again', 'Copied', 3);
            $browser->click('@invite-owner-close')->waitUntilMissing('@invite-owner-link', 5);

            // Opened again it asks afresh, and pressed from the keyboard the keyboard lands on Copy Link, never dropped
            // with the button it pressed. The new link is the live one; the first stops working.
            $this->clickAndAwait($browser, '@invite-owner-'.$organization->id, fn (Browser $b) => $b->waitFor('@invite-owner-form', 3));
            $browser->assertVisible('@invite-owner-copy-link')->assertMissing('@invite-owner-link')->assertInputValue('@invite-owner-email', '');
            $browser->type('@invite-owner-email', 'driver@example.com');
            $browser->script("document.querySelector('[dusk=\"invite-owner-copy-link\"]').focus();");
            $browser->keys('@invite-owner-copy-link', '{enter}');
            $browser->waitFor('@invite-owner-link', 8)->waitUsing(5, 100, fn () => $browser->value('@invite-owner-link') !== $link);
            $browser->waitUntil("document.activeElement?.getAttribute('dusk') === 'invite-owner-copy-again'", 3, 'the keyboard was dropped with the button it pressed');
            $this->assertSame(Invitation::hashToken(substr($browser->value('@invite-owner-link'), -64)), $invitation->fresh()->token_hash);
            $this->assertSame(2, ActivityLog::where('action', 'organization.owner_link_copied')->count());
        });
    }
}
