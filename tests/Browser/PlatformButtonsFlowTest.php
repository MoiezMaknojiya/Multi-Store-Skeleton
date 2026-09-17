<?php

namespace Tests\Browser;

use App\Models\Invitation;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The platform buttons no other browser test presses: giving an ownerless store an owner, taking a
 * platform role away (a big delete, so the password), and revoking an invitation from the store's
 * own Members page. Each writes something, so each is worth pressing for real.
 */
class PlatformButtonsFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_the_platform_gives_an_ownerless_store_an_owner(): void
    {
        $admin = $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Gamma Shop']);   // nobody in it at all

        $this->browse(function (Browser $browser) use ($admin, $store) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/stores');
            $this->waitForAlpine($browser);
            $browser->waitForText('Gamma Shop');

            $this->clickAndAwait($browser, '@invite-owner-'.$store->id, fn (Browser $b) => $b->waitFor('@invite-owner-form', 3));
            $this->jsType($browser, '@invite-owner-email', 'newowner@example.com');
            $this->jsClick($browser, '@invite-owner-send');

            $browser->waitForText('newowner@example.com');

            $invitation = Invitation::where('email', 'newowner@example.com')->firstOrFail();
            $this->assertSame($store->id, $invitation->store_id);
            $this->assertSame(Role::owner()->id, $invitation->role_id, 'the invitation is for the Owner role');

            // Offered only while the store has no Owner: once it does, the button is gone.
            $owner = $this->storeMember($store, Role::OWNER, 'owner@example.com');
            $browser->visit('/stores');
            $this->waitForAlpine($browser);
            $browser->waitForText('Gamma Shop')->assertMissing('@invite-owner-'.$store->id);
            $this->assertTrue($owner->exists);
        });
    }

    public function test_taking_a_platform_role_asks_for_the_password_and_then_takes_it(): void
    {
        $primary = $this->seedSuperAdmin();
        // The seeded admin gets its password from the environment, so say what it is here instead.
        $primary->forceFill(['password' => Hash::make('Str0ng-Password!')])->save();
        $support = User::factory()->create(['email' => 'support@example.com']);
        $supportRole = Role::create(['name' => 'Support', 'is_global' => true]);
        $support->stores()->attach(0, ['role_id' => $supportRole->id]);

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

            // A wrong password changes nothing…
            $this->jsType($browser, '@remove-platform-role-password', 'not-the-password');
            $this->jsClick($browser, '@remove-platform-role-confirm');
            $browser->pause(700);
            $this->assertTrue(DB::table('store_user')->where('user_id', $support->id)->where('store_id', 0)->exists());

            // …the right one does.
            $this->jsType($browser, '@remove-platform-role-password', 'Str0ng-Password!');
            $this->jsClick($browser, '@remove-platform-role-confirm');
            $browser->waitUntilMissing('@remove-platform-role-confirm', 8);

            $this->assertFalse(
                DB::table('store_user')->where('user_id', $support->id)->where('store_id', 0)->exists(),
                'the platform membership is gone, and the account stays'
            );
            $this->assertNotNull(User::find($support->id));
        });
    }

    public function test_an_invitation_is_revoked_from_the_members_page(): void
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->storeMember($store, Role::OWNER);
        [$invitation] = Invitation::open($store, 'waiting@example.com', Role::starter(Role::STAFF), $owner);

        $this->browse(function (Browser $browser) use ($owner, $store, $invitation) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToStore($browser, $store);

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
}
