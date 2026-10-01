<?php

use App\Models\Invitation;
use App\Models\Role;
use App\Models\Organization;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Who sees whom inside an organization (docs/ORGANIZATION-SPEC.md rules 12–14)
|--------------------------------------------------------------------------
|
| Membership is the only source of power, so the list is the organization's list: every member,
| whoever brought them in — never anybody outside the organization, never the platform team.
|
*/

beforeEach(function () {
    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->owner = createOrganizationMember($this->organization, Role::OWNER, ['first_name' => 'Olive']);
    $this->admin = createOrganizationMember($this->organization, Role::ADMIN, ['first_name' => 'Adam']);
    $this->staff = createOrganizationMember($this->organization, Role::STAFF, ['first_name' => 'Sam']);

    $this->elsewhere = createOrganizationMember(Organization::factory()->create(), Role::OWNER);
    $this->platform = createPlatformUser(['user-view']);
});

function membersAs(User $user, Organization $organization)
{
    return test()->actingAs($user)->withSession(['current_organization_id' => $organization->id])->getJson('/members/data');
}

test('every member of the organization is listed, whoever brought them in — and nobody else', function () {
    $ids = collect(membersAs($this->admin, $this->organization)->assertOk()->json('members'))->pluck('id');

    expect($ids->sort()->values()->all())->toBe(collect([$this->owner->id, $this->admin->id, $this->staff->id])->sort()->values()->all());
    expect($ids)->not->toContain($this->elsewhere->id)->not->toContain($this->platform->id);
});

test('each row says what the viewer may do to it', function () {
    $rows = collect(membersAs($this->admin, $this->organization)->json('members'))->keyBy('id');

    expect($rows[$this->admin->id]['is_you'])->toBeTrue()
        ->and($rows[$this->admin->id]['can_manage'])->toBeFalse()   // never yourself
        ->and($rows[$this->owner->id]['can_manage'])->toBeFalse()   // the Admin holds less than the Owner role allows
        ->and($rows[$this->staff->id]['can_manage'])->toBeTrue()
        ->and($rows[$this->owner->id]['role']['key'])->toBe(Role::OWNER);

    $asOwner = collect(membersAs($this->owner, $this->organization)->json('members'))->keyBy('id');
    expect($asOwner[$this->admin->id]['can_manage'])->toBeTrue();
});

test('the role pickers offer only what the viewer may give', function () {
    expect(collect(membersAs($this->admin, $this->organization)->json('assignable_roles'))->pluck('key')->all())
        ->toBe([Role::ADMIN, Role::STAFF, Role::VIEWER]);

    expect(collect(membersAs($this->owner, $this->organization)->json('assignable_roles'))->pluck('key')->all())
        ->toBe([Role::OWNER, Role::ADMIN, Role::STAFF, Role::VIEWER]);

    // A custom role of THIS organization is offered; another organization's never is.
    Role::create(['name' => 'Cashier', 'organization_id' => $this->organization->id])->permissions()->sync(grantPermissions(['screen-view'])->pluck('id'));
    Role::create(['name' => 'Foreign', 'organization_id' => Organization::factory()->create()->id]);

    $names = collect(membersAs($this->owner, $this->organization)->json('assignable_roles'))->pluck('name');
    expect($names)->toContain('Cashier')->not->toContain('Foreign');
});

test('open invitations are listed with their state and what the viewer may do to them', function () {
    Invitation::factory()->create(['organization_id' => $this->organization->id, 'email' => 'fresh@example.com', 'role_id' => Role::starter(Role::STAFF)->id]);
    Invitation::factory()->expired()->create(['organization_id' => $this->organization->id, 'email' => 'stale@example.com']);
    Invitation::factory()->create(['organization_id' => $this->organization->id, 'email' => 'boss@example.com', 'role_id' => Role::starter(Role::OWNER)->id]);
    Invitation::factory()->create(['email' => 'other-organization@example.com']);

    $rows = collect(membersAs($this->admin, $this->organization)->json('invitations'))->keyBy('email');

    expect($rows->keys()->sort()->values()->all())->toBe(['boss@example.com', 'fresh@example.com', 'stale@example.com'])
        ->and($rows['stale@example.com']['is_expired'])->toBeTrue()
        ->and($rows['fresh@example.com']['is_expired'])->toBeFalse()
        ->and($rows['fresh@example.com']['can_manage'])->toBeTrue()
        ->and($rows['boss@example.com']['can_manage'])->toBeFalse();
});

test('without member-view the list stays shut', function () {
    membersAs($this->staff, $this->organization)->assertForbidden();
});

test('outside an organization there is no list, whatever the permission', function () {
    $support = createPlatformUser(['member-view'], 'Support');

    $this->actingAs($support)->getJson('/members/data')->assertNotFound();
});

test('someone removed from the organization loses the list on their very next request', function () {
    $this->actingAs($this->owner)->withSession(['current_organization_id' => $this->organization->id])
        ->deleteJson("/members/{$this->admin->id}", ['password' => 'password'])->assertOk();

    membersAs($this->admin, $this->organization)->assertForbidden();
});

test('a member of another organization cannot be reached by guessing their id', function () {
    $this->actingAs($this->owner)->withSession(['current_organization_id' => $this->organization->id])
        ->putJson("/members/{$this->elsewhere->id}", ['role_id' => Role::starter(Role::STAFF)->id])
        ->assertNotFound();

    $this->actingAs($this->owner)->withSession(['current_organization_id' => $this->organization->id])
        ->deleteJson("/members/{$this->elsewhere->id}")
        ->assertNotFound();
});

test('one person in two organizations holds a different role — and different powers — in each', function () {
    $beta = Organization::factory()->create(['name' => 'Beta Deli']);
    $this->staff->organizations()->attach($beta->id, ['role_id' => Role::starter(Role::ADMIN)->id]);

    // Staff in Alpha: the team page is shut…
    membersAs($this->staff, $this->organization)->assertForbidden();

    // …Admin in Beta: it opens, and shows Beta's people only.
    $ids = collect(membersAs($this->staff, $beta)->assertOk()->json('members'))->pluck('id');
    expect($ids->all())->toBe([$this->staff->id]);
});
