<?php

use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Two invitations accepted at the same moment, in two tabs
|--------------------------------------------------------------------------
|
| A platform account is never an organization member and an organization member never holds a platform role (rule 3).
| Read outside any lock, two invitations of the two tiers accepted at the same instant both passed that rule and gave one
| account both tiers; two to the same organization met the memberships' unique index, a 500. Joining is decided under
| the lock of the person's own account row and then the invitation's (InvitationResponseController::join), so the second
| accept waits for the first and sees what it added. SQLite takes no row locks, so the other tab's commit is played at
| the moment the invitation's row is taken: on MySQL the second accept is held at the person's row until then.
|
*/

/** What another tab commits while this accept is between its first look and its write. */
function whenTheInvitationIsTaken(callable $otherTab): void
{
    $done = false;

    DB::listen(function (QueryExecuted $query) use (&$done, $otherTab) {
        // The invitation's row taken under its lock (InvitationResponseController::lockOpen).
        if (! $done && str_starts_with($query->sql, 'select "id" from "invitations" where')) {
            $done = true;
            $otherTab();
        }
    });
}

/** What an accept in another tab commits while the platform's Users → Organizations waits at the person's account row. */
function whenThePersonsRowIsTaken(callable $otherTab): void
{
    $done = false;

    DB::listen(function (QueryExecuted $query) use (&$done, $otherTab) {
        // The person's row taken under its lock inside OrganizationTeam::changeTeam (Platform\UserController::assignToOrganization).
        if (! $done && str_starts_with($query->sql, 'select "id" from "users" where "users"."id" = ?')) {
            $done = true;
            $otherTab();
        }
    });
}

/** A membership written by the other tab, straight into the table. */
function joinedInTheOtherTab(User $user, int $organizationId, Role $role): void
{
    DB::table('organization_user')->insert([
        'user_id' => $user->id, 'organization_id' => $organizationId, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

beforeEach(function () {
    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->platformRole = Role::create(['name' => 'Support', 'is_global' => true]);
    $this->person = User::factory()->create(['email' => 'sana@example.com']);
});

test('an organization invitation accepted while a platform one is accepted in another tab gives one tier, not both', function () {
    Invitation::factory()->withToken($token = str_repeat('a', 64))->create(['organization_id' => $this->organization->id, 'email' => 'sana@example.com']);
    whenTheInvitationIsTaken(fn () => joinedInTheOtherTab($this->person, 0, $this->platformRole));

    $this->actingAs($this->person)->post("/invitations/{$token}/accept")
        ->assertRedirect(route('invitations.show', $token))
        ->assertSessionHas('error', 'Platform accounts cannot join an organization.');

    expect(DB::table('organization_user')->where('user_id', $this->person->id)->pluck('organization_id')->all())->toBe([0])
        ->and(Invitation::count())->toBe(1);
});

test('a platform invitation accepted while an organization one is accepted in another tab gives one tier, not both', function () {
    Invitation::factory()->forPlatform()->withToken($token = str_repeat('b', 64))->create(['email' => 'sana@example.com', 'role_id' => $this->platformRole->id]);
    whenTheInvitationIsTaken(fn () => joinedInTheOtherTab($this->person, $this->organization->id, Role::starter(Role::STAFF)));

    $this->actingAs($this->person)->post("/invitations/{$token}/accept")
        ->assertRedirect(route('invitations.show', $token))
        ->assertSessionHas('error', 'Your account is a member of one or more organizations. An organization account cannot join the platform team.');

    expect(DB::table('organization_user')->where('user_id', $this->person->id)->pluck('organization_id')->all())->toBe([$this->organization->id]);
});

test('on the dashboard too, the second tier is refused once the first is in', function () {
    $invitation = Invitation::factory()->create(['organization_id' => $this->organization->id, 'email' => 'sana@example.com']);
    whenTheInvitationIsTaken(fn () => joinedInTheOtherTab($this->person, 0, $this->platformRole));

    $this->actingAs($this->person)->post("/dashboard/invitations/{$invitation->id}/accept")
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('invitation-problem', 'Platform accounts cannot join an organization.');

    expect(DB::table('organization_user')->where('user_id', $this->person->id)->pluck('organization_id')->all())->toBe([0]);
});

test('two invitations to the same organization accepted at once make one membership, never a server error', function () {
    Invitation::factory()->withToken($token = str_repeat('c', 64))->create([
        'organization_id' => $this->organization->id, 'email' => 'sana@example.com', 'role_id' => Role::starter(Role::ADMIN)->id,
    ]);
    whenTheInvitationIsTaken(fn () => joinedInTheOtherTab($this->person, $this->organization->id, Role::starter(Role::STAFF)));

    $this->actingAs($this->person)->post("/invitations/{$token}/accept")
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('status', 'You are already a member of Alpha Mart.');

    expect(roleKeyIn($this->person, $this->organization))->toBe(Role::STAFF)
        ->and(DB::table('organization_user')->where('user_id', $this->person->id)->count())->toBe(1)
        ->and(Invitation::count())->toBe(0);
});

test('the platform putting a person in an organization while they accept a platform invitation gives one tier, not both', function () {
    // Users → Organizations asks the tier rule before the lock; join() holds the person's row while it joins the platform
    // team, so the platform's write waits there — and must read the tier again once it has the row.
    whenThePersonsRowIsTaken(fn () => joinedInTheOtherTab($this->person, 0, $this->platformRole));

    $this->actingAs(createSuperAdmin())->postJson("/users/{$this->person->id}/organizations", [
        'organization_id' => $this->organization->id, 'role_id' => Role::starter(Role::STAFF)->id,
    ])->assertStatus(422)->assertJsonValidationErrors([
        'organization_id' => "{$this->person->name} is on the platform team, which works above the organizations. Remove their platform role first.",
    ]);

    // The other tab's membership was played inside this request's transaction, which the refusal rolled back with it; on
    // MySQL it is committed apart. What counts: no organization membership was written beside it.
    expect(DB::table('organization_user')->where('user_id', $this->person->id)->where('organization_id', '>', 0)->exists())->toBeFalse();
});

test('with no accept on its way, the platform still puts the person in the organization', function () {
    $this->actingAs(createSuperAdmin())->postJson("/users/{$this->person->id}/organizations", [
        'organization_id' => $this->organization->id, 'role_id' => Role::starter(Role::STAFF)->id,
    ])->assertCreated();

    expect(roleKeyIn($this->person, $this->organization))->toBe(Role::STAFF);
});
