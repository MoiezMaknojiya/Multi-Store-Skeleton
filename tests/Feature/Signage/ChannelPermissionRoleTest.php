<?php

use App\Models\Channel;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;

/*
|--------------------------------------------------------------------------
| The channel permissions: every channel on a platform role, the organization's own on an organization's
|--------------------------------------------------------------------------
|
| The owner's rules (2026-09-16). On a platform role the channel permissions reach every
| channel, and a channel made there is offered to every organization. On an organization's role they reach
| that organization's own channels alone (see OrganizationChannelsTest), so one organization can never publish onto
| another organization's screens. Who puts them on a role: the super admin anywhere; a member inside an
| organization only when they hold them there themselves.
|
*/

beforeEach(function () {
    $this->admin = createSuperAdmin(['role-view', 'role-store', 'role-update', 'channel-view', 'channel-update']);
    $this->channelView = Permission::firstWhere('name', 'channel-view');
});

test('a super admin gives the channel permissions to a platform role', function () {
    $this->actingAs($this->admin)->postJson('/roles', [
        'name' => 'Content Manager',
        'type' => 'platform',
        'permissions' => [$this->channelView->id],
    ])->assertCreated();

    $role = Role::firstWhere('name', 'Content Manager');
    expect($role->is_global)->toBeTrue()
        ->and($role->permissions->pluck('name')->all())->toBe(['channel-view']);
});

test('on an organization\'s role they come only from somebody who holds them — the super admin, or a member holding them there', function () {
    $organization = Organization::factory()->create();
    $owner = createOrganizationMember($organization, Role::OWNER);
    $screenView = Permission::firstWhere('name', 'screen-view');

    // The Owner does not hold channel-view out of the box, so cannot hand it on.
    $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])->postJson('/roles', [
        'name' => 'Publisher',
        'permissions' => [$screenView->id, $this->channelView->id],
    ])->assertStatus(422)->assertJsonValidationErrors(['permissions' => 'You can only give a role permissions you hold yourself.']);

    // The super admin gives it to every Owner; now an Owner may pass it on inside their organization.
    $ownerRole = Role::owner();
    $this->actingAs($this->admin)->putJson("/roles/{$ownerRole->id}", [
        'name' => $ownerRole->name,
        'permissions' => [...$ownerRole->permissions()->pluck('permissions.id')->all(), $this->channelView->id],
    ])->assertOk();

    $this->actingAs($owner->fresh())->withSession(['current_organization_id' => $organization->id])->postJson('/roles', [
        'name' => 'Publisher',
        'permissions' => [$screenView->id, $this->channelView->id],
    ])->assertCreated();

    expect(Role::firstWhere('name', 'Publisher'))->organization_id->toBe($organization->id)->is_global->toBeFalse();
});

test('a platform user who is not a super admin cannot make roles at all', function () {
    $staff = createPlatformUser(['role-store', 'channel-view'], 'Content Manager');

    $this->actingAs($staff)->postJson('/roles', ['name' => 'Helper', 'permissions' => [$this->channelView->id]])
        ->assertForbidden();
});

test('the super admin needs no channel rows on the Super-Admin role to make, change and delete a channel', function () {
    // A super admin holds every permission whatever the role's rows say. The rows the helper wrote onto
    // the role are taken off first — otherwise this would only prove that the helper wrote them.
    $superAdminRole = Role::find(Role::superAdminId());
    $superAdminRole->permissions()->detach(Permission::where('name', 'like', 'channel-%')->pluck('id'));
    expect($superAdminRole->permissions()->where('name', 'like', 'channel-%')->exists())->toBeFalse();

    $this->actingAs($this->admin)->postJson('/channels', ['name' => 'GAMA'])->assertOk();
    $channel = Channel::firstWhere('name', 'GAMA');

    $this->putJson("/channels/{$channel->id}", ['name' => 'GAMA Wholesale'])->assertOk();
    expect($channel->fresh()->name)->toBe('GAMA Wholesale');

    $this->deleteJson("/channels/{$channel->id}", ['password' => 'password'])->assertOk();
    expect(Channel::find($channel->id))->toBeNull();
});
