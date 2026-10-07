<?php

namespace Tests\Browser;

use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The other half of the invitation link: somebody who ALREADY has an account.
 *
 * TeamInvitationFlowTest follows the person who registers from the link; this follows the one who is
 * asked to sign in first (the link alone never logs anybody in — docs/ORGANIZATION-SPEC.md §E)
 * and then accepts, and the one who declines.
 */
class InvitationAcceptFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_an_existing_account_is_asked_to_sign_in_and_then_accepts(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->organizationMember($organization, Role::OWNER);
        $joiner = User::factory()->create(['email' => 'joiner@example.com', 'first_name' => 'Jo', 'last_name' => 'Iner']);

        [$invitation, $token] = Invitation::open($organization, 'joiner@example.com', Role::starter(Role::STAFF), $owner);

        $this->browse(function (Browser $browser) use ($joiner, $organization, $invitation, $token) {
            // Signed out, the link offers a way in — and nothing else. No account is joined by opening it.
            $this->freshSession($browser);
            $browser->visit('/invitations/'.$token)
                ->waitFor('@invitation-page')
                ->assertSee('Alpha Mart')
                ->assertVisible('@invitation-login')
                ->assertMissing('@invitation-register-form');

            $this->assertFalse(DB::table('organization_user')->where('user_id', $joiner->id)->exists());

            // Signed in as the person the link was written for, the page offers Accept.
            $browser->loginAs($joiner)->visit('/invitations/'.$token)
                ->waitFor('@invitation-accept')
                ->assertSee('joiner@example.com');

            $browser->waitForReload(fn (Browser $b) => $this->jsClick($b, '@invitation-accept'));
            $browser->waitForLocation('/dashboard')->waitForText('Welcome to Alpha Mart!');

            $this->assertSame(
                Role::starter(Role::STAFF)->id,
                DB::table('organization_user')->where('user_id', $joiner->id)->where('organization_id', $organization->id)->value('role_id'),
                'accepting gives exactly the role the invitation named'
            );
            $this->assertNull(Invitation::find($invitation->id), 'an accepted invitation is used up');

        });
    }

    public function test_a_link_can_be_declined_and_leaves_nothing_behind(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->organizationMember($organization, Role::OWNER);
        $invitee = User::factory()->create(['email' => 'nothanks@example.com']);

        [$invitation, $token] = Invitation::open($organization, 'nothanks@example.com', Role::starter(Role::STAFF), $owner);

        $this->browse(function (Browser $browser) use ($invitee, $organization, $invitation, $token) {
            $this->freshSession($browser);
            $browser->loginAs($invitee)->visit('/invitations/'.$token)
                ->waitFor('@invitation-decline');

            // It asks first: one tap shows the question, the second declines.
            $this->jsClick($browser, '@invitation-decline');
            $browser->waitFor('@invitation-decline-confirm');
            $browser->waitForReload(fn (Browser $b) => $this->jsClick($b, '@invitation-decline-confirm'));

            $this->assertNull(Invitation::find($invitation->id), 'a declined invitation is gone, not left waiting');
            $this->assertFalse(
                DB::table('organization_user')->where('user_id', $invitee->id)->where('organization_id', $organization->id)->exists(),
                'declining joins nobody'
            );

            // The link is spent: opening it again says so — on the page every dead link gets,
            // with nothing on it to accept.
            $browser->visit('/invitations/'.$token)
                ->assertPresent('@invitation-invalid')
                ->assertSeeIn('@invitation-invalid', 'This invitation is no longer valid')
                ->assertMissing('@invitation-accept');
        });
    }

    /**
     * Owner, 2026-10-07: the invitation shows on the dashboard too, for the account it was sent to, and is accepted
     * there — the Smart Stop case, a person who already works in an organization of their own and never pressed the
     * email's button.
     */
    public function test_an_invitation_is_accepted_on_the_dashboard_without_the_email(): void
    {
        $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $beta = Organization::factory()->create(['name' => 'Beta Deli']);
        $owner = $this->organizationMember($alpha, Role::OWNER);
        $person = $this->organizationMember($beta, Role::OWNER, 'sana@example.com');

        [$invitation] = Invitation::open($alpha, 'sana@example.com', Role::starter(Role::ADMIN), $owner);

        $this->browse(function (Browser $browser) use ($person, $alpha, $invitation) {
            $this->freshSession($browser);
            $browser->loginAs($person)->visit('/dashboard')
                ->waitFor('@dashboard-invitations')
                ->assertSeeIn('@dashboard-invitation-'.$invitation->id, 'Alpha Mart invited you as Admin')
                ->assertSeeIn('@dashboard-invitations-count', '1');

            $browser->waitForReload(fn (Browser $b) => $this->jsClick($b, '@accept-invitation-'.$invitation->id));
            $browser->waitForText('Welcome to Alpha Mart!')
                ->assertSeeIn('@dashboard-organization-name', 'Alpha Mart')
                ->assertMissing('@dashboard-invitations');

            $this->assertSame(
                Role::starter(Role::ADMIN)->id,
                DB::table('organization_user')->where('user_id', $person->id)->where('organization_id', $alpha->id)->value('role_id'),
                'accepting on the dashboard gives the role the invitation named'
            );
            $this->assertNull(Invitation::find($invitation->id));
        });
    }

    public function test_an_invitation_is_declined_on_the_dashboard_after_a_question(): void
    {
        $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->organizationMember($alpha, Role::OWNER);
        $newcomer = User::factory()->create(['email' => 'newcomer@example.com']);

        [$invitation] = Invitation::open($alpha, 'newcomer@example.com', Role::starter(Role::STAFF), $owner);

        $this->browse(function (Browser $browser) use ($newcomer, $alpha, $invitation) {
            $this->freshSession($browser);
            $browser->loginAs($newcomer)->visit('/dashboard')
                ->waitFor('@dashboard-invitation-'.$invitation->id)
                ->assertPresent('@dashboard-empty');

            // It asks first: Cancel leaves it waiting, and the second question declines.
            $this->jsClick($browser, '@decline-invitation-'.$invitation->id);
            $browser->waitFor('@decline-invitation-'.$invitation->id.'-confirm')
                ->assertSee('Decline the invitation to Alpha Mart?')
                ->click('@decline-invitation-'.$invitation->id.'-cancel')
                ->waitUntilMissing('@decline-invitation-'.$invitation->id.'-confirm');
            $this->assertNotNull(Invitation::find($invitation->id), 'Cancel declines nothing');

            $this->jsClick($browser, '@decline-invitation-'.$invitation->id);
            $browser->waitFor('@decline-invitation-'.$invitation->id.'-confirm');
            $browser->waitForReload(fn (Browser $b) => $this->jsClick($b, '@decline-invitation-'.$invitation->id.'-confirm'));
            $browser->waitForText('Invitation declined.')->assertMissing('@dashboard-invitations');

            $this->assertNull(Invitation::find($invitation->id), 'a declined invitation is gone');
            $this->assertFalse(DB::table('organization_user')->where('user_id', $newcomer->id)->exists(), 'declining joins nobody');
            $this->assertDatabaseHas('activity_logs', ['action' => 'invitation.declined', 'organization_id' => $alpha->id, 'actor_id' => $newcomer->id]);
        });
    }
}
