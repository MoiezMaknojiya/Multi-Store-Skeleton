<?php

use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Screen;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Changing, removing and leaving (docs/ORGANIZATION-SPEC.md rules 7–10, 20)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->owner = createOrganizationMember($this->organization, Role::OWNER);
    $this->admin = createOrganizationMember($this->organization, Role::ADMIN);
    $this->staff = createOrganizationMember($this->organization, Role::STAFF);
});

function inOrganization(User $user, Organization $organization)
{
    return test()->actingAs($user)->withSession(['current_organization_id' => $organization->id]);
}

test('an Owner changes a member’s role, and it is logged', function () {
    inOrganization($this->owner, $this->organization)
        ->putJson("/members/{$this->staff->id}", ['role_id' => Role::starter(Role::ADMIN)->id])
        ->assertOk();

    expect(roleKeyIn($this->staff, $this->organization))->toBe(Role::ADMIN);
    expect(ActivityLog::where('action', 'member.role_changed')->latest('id')->value('description'))->toContain('from Staff to Admin');
});

test('the Owner role is given and managed only by whoever holds everything it allows — not an Admin', function () {
    inOrganization($this->admin, $this->organization)
        ->putJson("/members/{$this->staff->id}", ['role_id' => Role::starter(Role::OWNER)->id])
        ->assertStatus(422)->assertJsonValidationErrors('role_id');

    inOrganization($this->admin, $this->organization)
        ->putJson("/members/{$this->owner->id}", ['role_id' => Role::starter(Role::STAFF)->id])
        ->assertForbidden();

    inOrganization($this->admin, $this->organization)->deleteJson("/members/{$this->owner->id}")->assertForbidden();

    expect(roleKeyIn($this->owner, $this->organization))->toBe(Role::OWNER);
    expect(roleKeyIn($this->staff, $this->organization))->toBe(Role::STAFF);
});

test('a role from another organization cannot be given here', function () {
    $foreign = Role::create(['name' => 'Foreign', 'organization_id' => Organization::factory()->create()->id]);

    inOrganization($this->owner, $this->organization)
        ->putJson("/members/{$this->staff->id}", ['role_id' => $foreign->id])
        ->assertStatus(422)->assertJsonValidationErrors('role_id');
});

test('a custom role gives and manages only what it holds itself', function () {
    // A supervisor who may change roles, and can see — but not upload — content.
    $supervisor = createOrganizationUser($this->organization, ['member-update', 'screen-view', 'media-view'], 'Supervisor');
    $viewer = createOrganizationMember($this->organization, Role::VIEWER);
    $helper = Role::create(['name' => 'Helper', 'organization_id' => $this->organization->id]);
    $helper->permissions()->sync(grantPermissions(['screen-view'])->pluck('id'));

    // Staff can upload — more than the supervisor holds — so it cannot be handed out…
    inOrganization($supervisor, $this->organization)
        ->putJson("/members/{$viewer->id}", ['role_id' => Role::starter(Role::STAFF)->id])
        ->assertStatus(422);

    // …and a Staff member, holding more than the supervisor, cannot be changed at all.
    inOrganization($supervisor, $this->organization)
        ->putJson("/members/{$this->staff->id}", ['role_id' => $helper->id])
        ->assertForbidden();

    // Within its reach, it works.
    inOrganization($supervisor, $this->organization)
        ->putJson("/members/{$viewer->id}", ['role_id' => $helper->id])
        ->assertOk();
});

test('an Owner may demote another Owner, and then — the last one left — cannot leave', function () {
    $partner = createOrganizationMember($this->organization, Role::OWNER);

    inOrganization($this->owner, $this->organization)
        ->putJson("/members/{$partner->id}", ['role_id' => Role::starter(Role::ADMIN)->id])
        ->assertOk();

    // Now the only Owner, they cannot leave the organization without one — and the partner, an Admin
    // now, holds less than the Owner role allows, so cannot remove them either.
    inOrganization($this->owner, $this->organization)->postJson('/members/leave')->assertStatus(422)
        ->assertJsonFragment(['message' => 'You are the only Owner of Alpha Mart. Make someone else an Owner before you leave.']);
    inOrganization($partner, $this->organization)->deleteJson("/members/{$this->owner->id}")->assertForbidden();

    expect(roleKeyIn($this->owner, $this->organization))->toBe(Role::OWNER);
});

test('holding everything the Owner role allows reaches an Owner — yet the organization\'s only Owner always stays', function () {
    // No Owner-only rule (owner's rule, 2026-09-17): a role with every permission the Owner role holds reaches an Owner.
    $deputy = createOrganizationUser($this->organization, Role::owner()->permissions()->pluck('name')->all(), 'Deputy');
    $message = "{$this->owner->name} is the only Owner of Alpha Mart. Make someone else an Owner first.";

    $row = fn () => collect(inOrganization($deputy, $this->organization)->getJson('/members/data')->assertOk()->json('members'))->firstWhere('id', $this->owner->id);
    expect($row()['can_manage'])->toBeFalse();

    inOrganization($deputy, $this->organization)
        ->putJson("/members/{$this->owner->id}", ['role_id' => Role::starter(Role::ADMIN)->id])
        ->assertStatus(422)->assertJsonValidationErrors(['role_id' => $message]);
    inOrganization($deputy, $this->organization)
        ->deleteJson("/members/{$this->owner->id}", ['password' => 'password'])
        ->assertStatus(422)->assertJsonPath('message', $message);
    expect(roleKeyIn($this->owner, $this->organization))->toBe(Role::OWNER);

    // With a partner Owner beside them, the deputy may make the first one an Admin.
    $partner = createOrganizationMember($this->organization, Role::OWNER);
    expect($row()['can_manage'])->toBeTrue();

    inOrganization($deputy, $this->organization)
        ->putJson("/members/{$this->owner->id}", ['role_id' => Role::starter(Role::ADMIN)->id])
        ->assertOk();
    expect(roleKeyIn($this->owner, $this->organization))->toBe(Role::ADMIN)
        ->and(roleKeyIn($partner, $this->organization))->toBe(Role::OWNER);
});

test('removing a member takes only the membership — their account and their work stay', function () {
    $upload = Media::factory()->create(['organization_id' => $this->organization->id, 'created_by' => $this->staff->id]);
    $screen = Screen::factory()->create(['organization_id' => $this->organization->id, 'created_by' => $this->staff->id]);

    inOrganization($this->admin, $this->organization)->deleteJson("/members/{$this->staff->id}", ['password' => 'password'])->assertOk();

    expect(roleKeyIn($this->staff, $this->organization))->toBeNull();
    expect(User::find($this->staff->id))->not->toBeNull();
    expect(Media::find($upload->id)->created_by)->toBe($this->staff->id);
    expect(Screen::find($screen->id))->not->toBeNull();
    expect(ActivityLog::where('action', 'member.removed')->exists())->toBeTrue();
});

test('you do not remove yourself — you leave, and the organization drops out of your session', function () {
    inOrganization($this->admin, $this->organization)->deleteJson("/members/{$this->admin->id}")->assertStatus(422);

    inOrganization($this->admin, $this->organization)->postJson('/members/leave')
        ->assertOk()
        ->assertJsonFragment(['redirect' => route('dashboard')]);

    expect(roleKeyIn($this->admin, $this->organization))->toBeNull();
    expect(session('current_organization_id'))->toBeNull();
    expect(ActivityLog::where('action', 'member.left')->exists())->toBeTrue();
});

test('the gates hold for people without the permission', function () {
    inOrganization($this->staff, $this->organization)
        ->putJson("/members/{$this->admin->id}", ['role_id' => Role::starter(Role::VIEWER)->id])
        ->assertForbidden();

    inOrganization($this->staff, $this->organization)->deleteJson("/members/{$this->admin->id}")->assertForbidden();
});
