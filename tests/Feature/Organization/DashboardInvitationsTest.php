<?php

use App\Models\ActivityLog;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Invitations answered on the dashboard
|--------------------------------------------------------------------------
|
| Owner, 2026-10-07: "haan bana do, decline par sirf log, dashboard card kaafi ha". An organization's invitation sent
| to a person's own confirmed address shows above their dashboard with Accept and Decline — the emailed link works
| as before. Accept is the link's own join; Decline takes the invitation away and only the log says so.
|
*/

beforeEach(function () {
    $this->alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->beta = Organization::factory()->create(['name' => 'Beta Deli']);
    $this->inviter = createOrganizationMember($this->alpha, Role::OWNER, ['first_name' => 'Olive', 'last_name' => 'Owner']);
    $this->person = createOrganizationMember($this->beta, Role::OWNER, ['email' => 'sana@example.com']);
});

/** An open invitation to Alpha Mart as Staff, for the given address. */
function dashboardInvitation(string $email = 'sana@example.com', array $attributes = []): Invitation
{
    return Invitation::factory()->create([
        'organization_id' => test()->alpha->id,
        'email' => $email,
        'role_id' => Role::starter(Role::STAFF)->id,
        'invited_by' => test()->inviter->id,
        ...$attributes,
    ]);
}

function dashboardOf(User $user, ?Organization $organization = null)
{
    $session = $organization ? ['current_organization_id' => $organization->id] : [];

    return test()->actingAs($user)->withSession($session)->get('/dashboard')->assertOk();
}

test('the dashboard offers the invitations sent to this person, and nobody else\'s', function () {
    $open = dashboardInvitation();
    $expired = dashboardInvitation(attributes: ['expires_at' => now()->subMinute()]);
    $somebodyElses = dashboardInvitation('someone@example.com');
    $platform = Invitation::factory()->forPlatform()->create(['email' => 'sana@example.com']);

    dashboardOf($this->person, $this->beta)
        ->assertSee('Invitations for you')
        ->assertSee('Alpha Mart invited you as Staff')
        ->assertSee('Sent by Olive Owner')
        ->assertSee('dusk="accept-invitation-'.$open->id.'"', false)
        ->assertSee('dusk="decline-invitation-'.$open->id.'"', false)
        ->assertDontSee('dusk="dashboard-invitation-'.$expired->id.'"', false)
        ->assertDontSee('dusk="dashboard-invitation-'.$somebodyElses->id.'"', false)
        ->assertDontSee('dusk="dashboard-invitation-'.$platform->id.'"', false);
});

test('without an invitation there is no card', function () {
    dashboardOf($this->person, $this->beta)->assertDontSee('dusk="dashboard-invitations"', false);
});

test('somebody in no organization yet sees it above the empty dashboard', function () {
    $newcomer = User::factory()->create(['email' => 'newcomer@example.com']);
    $invitation = dashboardInvitation('newcomer@example.com');

    dashboardOf($newcomer)
        ->assertSee('dusk="dashboard-empty"', false)
        ->assertSee('dusk="dashboard-invitation-'.$invitation->id.'"', false)
        ->assertSee('The invitation shows up here and in that inbox.');
});

test('a platform account is never offered one', function () {
    $admin = createSuperAdmin();
    dashboardInvitation($admin->email);

    dashboardOf($admin)->assertDontSee('dusk="dashboard-invitations"', false);
});

test('accept joins the organization at once, opens it and logs it, as the emailed link would', function () {
    $invitation = dashboardInvitation();

    $this->actingAs($this->person)->withSession(['current_organization_id' => $this->beta->id])
        ->post(route('dashboard.invitations.accept', $invitation->id))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('status', 'Welcome to Alpha Mart!')
        ->assertSessionHas('current_organization_id', $this->alpha->id);

    expect(DB::table('organization_user')->where(['user_id' => $this->person->id, 'organization_id' => $this->alpha->id])->value('role_id'))
        ->toBe(Role::starter(Role::STAFF)->id)
        ->and(Invitation::find($invitation->id))->toBeNull()
        ->and(ActivityLog::where('action', 'invitation.accepted')->where('organization_id', $this->alpha->id)->value('description'))
        ->toContain('joined Alpha Mart as Staff');
});

test('decline takes the invitation away and only logs it: nobody is emailed', function () {
    Notification::fake();
    $invitation = dashboardInvitation();

    $this->actingAs($this->person)->withSession(['current_organization_id' => $this->beta->id])
        ->post(route('dashboard.invitations.decline', $invitation->id))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('status', 'Invitation declined.');

    $entry = ActivityLog::where('action', 'invitation.declined')->first();

    expect(Invitation::find($invitation->id))->toBeNull()
        ->and(DB::table('organization_user')->where(['user_id' => $this->person->id, 'organization_id' => $this->alpha->id])->exists())->toBeFalse()
        ->and($entry->organization_id)->toBe($this->alpha->id)
        ->and($entry->actor_id)->toBe($this->person->id)
        ->and($entry->description)->toBe('sana@example.com declined the invitation to Alpha Mart on their dashboard');

    Notification::assertNothingSent();
});

test('an invitation to an organization the person is in already is used up and opens it', function () {
    $this->person->organizations()->attach($this->alpha->id, ['role_id' => Role::starter(Role::VIEWER)->id]);
    $invitation = dashboardInvitation();

    $this->actingAs($this->person)->withSession(['current_organization_id' => $this->beta->id])
        ->post(route('dashboard.invitations.accept', $invitation->id))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('status', 'You are already a member of Alpha Mart.')
        ->assertSessionHas('current_organization_id', $this->alpha->id);

    expect(Invitation::find($invitation->id))->toBeNull()
        ->and(DB::table('organization_user')->where(['user_id' => $this->person->id, 'organization_id' => $this->alpha->id])->value('role_id'))
        ->toBe(Role::starter(Role::VIEWER)->id);
});

test('one that expired on a page left open is said on the dashboard, and nothing is joined', function () {
    $invitation = dashboardInvitation(attributes: ['expires_at' => now()->subMinute()]);

    $this->actingAs($this->person)->withSession(['current_organization_id' => $this->beta->id])
        ->followingRedirects()
        ->post(route('dashboard.invitations.accept', $invitation->id))
        ->assertOk()
        ->assertSee('dusk="dashboard-invitation-problem"', false)
        ->assertSee('The invitation to Alpha Mart has expired. Ask them to send it again.');

    expect(DB::table('organization_user')->where(['user_id' => $this->person->id, 'organization_id' => $this->alpha->id])->exists())->toBeFalse()
        ->and(Invitation::find($invitation->id))->not->toBeNull();
});

test('a paused organization\'s person may still answer an invitation to another', function () {
    $this->beta->update(['is_active' => false]);
    $accepted = dashboardInvitation();

    dashboardOf($this->person, $this->beta)
        ->assertSee('dusk="dashboard-paused"', false)
        ->assertSee('dusk="dashboard-invitation-'.$accepted->id.'"', false);

    $this->actingAs($this->person)->withSession(['current_organization_id' => $this->beta->id])
        ->post(route('dashboard.invitations.accept', $accepted->id))
        ->assertSessionHas('status', 'Welcome to Alpha Mart!');

    $declined = Invitation::factory()->create(['organization_id' => Organization::factory()->create()->id, 'email' => 'sana@example.com']);
    $this->beta->update(['is_active' => false]);

    $this->actingAs($this->person)->withSession(['current_organization_id' => $this->beta->id])
        ->post(route('dashboard.invitations.decline', $declined->id))
        ->assertSessionHas('status', 'Invitation declined.');

    expect(Invitation::find($declined->id))->toBeNull();
});
