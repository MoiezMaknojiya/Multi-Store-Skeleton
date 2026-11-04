<?php

use App\Models\ActivityLog;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Role;
use App\Notifications\InvitationNotification;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Copy Invitation Link, in the Invite Owner dialog (owner, 2026-11-04)
|--------------------------------------------------------------------------
|
| A customer driving for eight hours could not open the email: a super admin copies the invitation's link to send it
| another way. It is Invite Owner exactly, the email included, with the new link handed back, and super admins alone
| may have it: whoever holds the link sets up the Owner's account.
|
*/

beforeEach(function () {
    Notification::fake();

    $this->admin = createSuperAdmin(['organization-view', 'organization-store']);
    $this->organization = Organization::factory()->create(['name' => 'Road Mart']);
});

/** The token at the end of an invitation link. */
function tokenOf(string $link): string
{
    expect($link)->toMatch('#/invitations/[A-Za-z0-9]{64}\z#');

    return substr($link, -64);
}

/** The token the email of the last owner invitation carried. */
function emailedToken(): string
{
    $token = null;
    Notification::assertSentOnDemand(InvitationNotification::class, function (InvitationNotification $notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    return $token;
}

test('a super admin\'s Copy Invitation Link invites the owner, emails the very same link and hands it back', function () {
    $response = $this->actingAs($this->admin)
        ->postJson("/organizations/{$this->organization->id}/owner-invitation/link", ['email' => 'Driver@Example.com'])
        ->assertCreated()
        ->assertJson(['email_sent' => true, 'message' => 'The same link was emailed to driver@example.com.']);

    $token = tokenOf($response->json('link'));
    $invitation = Invitation::forOrganization($this->organization)->sole();

    expect($response->json('link'))->toBe(route('invitations.show', $token))
        ->and(emailedToken())->toBe($token)
        ->and($invitation->email)->toBe('driver@example.com')
        ->and($invitation->role_id)->toBe(Role::owner()->id)
        ->and($invitation->token_hash)->toBe(Invitation::hashToken($token))
        ->and(ActivityLog::where('action', 'organization.owner_link_copied')->value('description'))
        ->toBe('Invited driver@example.com to own Road Mart and copied the invitation link');

    // Opened by anybody with no account, it is the Owner's sign-up.
    auth()->logout();
    $this->get($response->json('link'))->assertOk()->assertViewHas('state', 'register')->assertSee('Road Mart');
});

test('pressed again it makes a new link, and the one before stops working, emailed or copied', function () {
    $first = $this->actingAs($this->admin)
        ->postJson("/organizations/{$this->organization->id}/owner-invitation/link", ['email' => 'driver@example.com'])->json('link');

    $second = $this->actingAs($this->admin)
        ->postJson("/organizations/{$this->organization->id}/owner-invitation/link", ['email' => 'driver@example.com'])
        ->assertOk()->json('link');

    expect($second)->not->toBe($first)
        ->and(Invitation::forOrganization($this->organization)->count())->toBe(1)
        ->and(ActivityLog::where('action', 'organization.owner_link_copied')->latest('id')->value('description'))
        ->toBe('Sent the invitation to own Road Mart to driver@example.com again and copied its new link');
    Notification::assertSentOnDemandTimes(InvitationNotification::class, 2);

    auth()->logout();
    $this->get($first)->assertOk()->assertViewIs('invitations.invalid');
    $this->get($second)->assertOk()->assertViewHas('state', 'register');
});

test('a link to a different address replaces the earlier owner invitation, as Send does', function () {
    $this->actingAs($this->admin)->postJson("/organizations/{$this->organization->id}/owner-invitation", ['email' => 'typo@exmaple.com'])->assertCreated();

    $this->actingAs($this->admin)
        ->postJson("/organizations/{$this->organization->id}/owner-invitation/link", ['email' => 'driver@example.com'])
        ->assertCreated()
        ->assertJsonPath('message', 'The same link was emailed to driver@example.com. The earlier invitation for typo@exmaple.com no longer works.');

    expect(Invitation::forOrganization($this->organization)->pluck('email')->all())->toBe(['driver@example.com']);
});

test('a member of the organization is made Owner at once, with no link to copy', function () {
    $staff = createOrganizationMember($this->organization, Role::STAFF);

    $this->actingAs($this->admin)
        ->postJson("/organizations/{$this->organization->id}/owner-invitation/link", ['email' => $staff->email])
        ->assertOk()
        ->assertJsonMissingPath('link')
        ->assertJsonPath('message', "{$staff->name} is now an Owner of Road Mart.");

    expect(roleKeyIn($staff, $this->organization))->toBe(Role::OWNER)
        ->and(Invitation::forOrganization($this->organization)->exists())->toBeFalse();
    Notification::assertNothingSent();
});

test('it is refused as Send is, under the field: an organization that has an Owner, an address that is no email', function () {
    $this->actingAs($this->admin)->postJson("/organizations/{$this->organization->id}/owner-invitation/link", ['email' => 'not an email'])
        ->assertStatus(422)->assertJsonValidationErrors('email');

    createOrganizationMember($this->organization, Role::OWNER);

    $this->actingAs($this->admin)->postJson("/organizations/{$this->organization->id}/owner-invitation/link", ['email' => 'driver@example.com'])
        ->assertStatus(422)->assertJsonValidationErrors(['email' => 'Road Mart already has an Owner.']);

    expect(Invitation::forOrganization($this->organization)->exists())->toBeFalse();
    Notification::assertNothingSent();
});

test('the dialog offers Copy Invitation Link to a super admin alone', function () {
    $this->actingAs($this->admin)->get('/organizations')->assertOk()
        ->assertSee('dusk="invite-owner-copy-link"', false)
        ->assertSee('dusk="invite-owner-link"', false)
        ->assertSee('Copy Invitation Link');

    $this->actingAs(createPlatformUser(['organization-view', 'organization-store']))->get('/organizations')->assertOk()
        ->assertSee('dusk="invite-owner-send"', false)
        ->assertDontSee('dusk="invite-owner-copy-link"', false)
        ->assertDontSee('dusk="invite-owner-link"', false);
});
