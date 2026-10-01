<?php

use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Screen;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| An organization's role may carry the platform permissions — for its own organization alone
|--------------------------------------------------------------------------
|
| The owner's rules (2026-09-16): the super admin sees every permission and decides who holds
| what, and an organization's people work within their organization ("store k hisab se"). On an organization's role,
| Channels and the Activity log reach that organization and nothing past it; the organization permissions work in
| Settings → Organizations, on the organization the person works in (2026-09-17: the Organizations page is the platform's,
| and a role does not matter — its permissions do). What cannot work inside one organization — the accounts
| (an organization's people are its Members page), the permission catalogue, yearly log maintenance — is
| never offered, and refused.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->superAdmin = createSuperAdmin();
    $this->alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->beta = Organization::factory()->create(['name' => 'Beta Deli']);
});

/** Give a starter organization role these permissions on top of what it holds (and register their gates). */
function grantToOrganizationRole(string $key, array $names): Role
{
    $role = Role::starter($key);
    $role->permissions()->syncWithoutDetaching(grantPermissions($names)->pluck('id'));

    return $role;
}

/** The assignable checklist as the given person sees it, keyed by permission name. */
function checklistFor(User $viewer, array $query = [], ?Organization $organization = null): Collection
{
    $request = test()->actingAs($viewer);
    if ($organization !== null) {
        $request = $request->withSession(['current_organization_id' => $organization->id]);
    }

    return collect($request->getJson('/roles/assignable?'.http_build_query($query))->assertOk()->json())->keyBy('name');
}

/*
|--------------------------------------------------------------------------
| The checklist, and what a role may carry
|--------------------------------------------------------------------------
*/

test('an organization role is offered what works inside an organization — and nothing else is even listed', function () {
    $checklist = checklistFor($this->superAdmin, ['role' => Role::owner()->id]);

    expect($checklist->keys()->sort()->values()->all())
        ->toBe(Permission::pluck('name')->filter(fn (string $name) => Permission::belongsToOrganizations($name))->sort()->values()->all())
        ->and($checklist->keys())->toContain('organization-destroy', 'channel-store', 'activity-view', 'media-view')
        ->not->toContain('user-view')
        ->not->toContain('user-destroy')
        ->not->toContain('activity-destroy')
        ->not->toContain('permission-view')
        ->and($checklist->first())->not->toHaveKey('unavailable');
});

test('a platform role is offered everything but the catalogue', function () {
    $checklist = checklistFor($this->superAdmin, ['type' => 'platform']);

    expect($checklist)->toHaveCount(Permission::count() - count(Permission::SUPER_ADMIN_ONLY))
        ->and($checklist->keys())->toContain('activity-destroy')->not->toContain('permission-update');
});

test('the super admin gives an organization role what works inside an organization — and nothing that does not', function () {
    $owner = Role::owner();
    $keep = $owner->permissions()->pluck('permissions.id')->all();
    $scoped = grantPermissions(['organization-view', 'organization-store', 'channel-view', 'channel-store', 'activity-view'])->pluck('id')->all();

    $this->actingAs($this->superAdmin)->putJson("/roles/{$owner->id}", ['name' => 'Owner', 'permissions' => array_values(array_unique([...$keep, ...$scoped]))])->assertOk();
    expect($owner->fresh()->permissions->pluck('name'))->toContain('organization-view', 'channel-store', 'activity-view', 'organization-destroy');

    $this->actingAs($this->superAdmin)->putJson("/roles/{$owner->id}", ['name' => 'Owner', 'permissions' => [...$keep, ...grantPermissions(['activity-destroy'])->pluck('id')]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['permissions' => 'An organization role cannot hold Delete Old Activity Logs: it works above the organizations only.']);

    $this->actingAs($this->superAdmin)->putJson("/roles/{$owner->id}", ['name' => 'Owner', 'permissions' => [...$keep, ...grantPermissions(['user-destroy'])->pluck('id')]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['permissions' => 'An organization role cannot hold Delete Accounts: it works above the organizations only.']);

    $this->actingAs($this->superAdmin)->putJson("/roles/{$owner->id}", ['name' => 'Owner', 'permissions' => [...$keep, ...grantPermissions(['permission-view'])->pluck('id')]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['permissions' => 'Permission management belongs to the Super-Admin role alone.']);

    expect($owner->fresh()->permissions->pluck('name'))
        ->not->toContain('activity-destroy')
        ->not->toContain('user-destroy')
        ->not->toContain('permission-view');
});

test('a permission made on the Permissions page stays off organization roles', function () {
    $custom = Permission::create(['name' => 'report-export']);

    expect(checklistFor($this->superAdmin, ['role' => Role::starter(Role::STAFF)->id])->keys())->not->toContain('report-export')
        ->and(checklistFor($this->superAdmin, ['type' => 'platform'])->keys())->toContain('report-export');

    $this->actingAs($this->superAdmin)->putJson('/roles/'.Role::starter(Role::STAFF)->id, ['name' => 'Staff', 'permissions' => [$custom->id]])
        ->assertStatus(422)->assertJsonValidationErrors('permissions');
});

test('inside an organization a member gives a custom role only what they hold — its platform permissions included', function () {
    $owner = createOrganizationMember($this->alpha, Role::OWNER);

    // The Owner starts with organization-destroy, so it is theirs to hand on; channel-view is not, and the accounts never are.
    $offered = checklistFor($owner, organization: $this->alpha);
    expect($offered->keys())->toContain('organization-destroy')
        ->not->toContain('channel-view')
        ->not->toContain('user-view')
        ->not->toContain('activity-destroy');

    $this->actingAs($owner)->withSession(['current_organization_id' => $this->alpha->id])->postJson('/roles', [
        'name' => 'Closer',
        'permissions' => Permission::whereIn('name', ['organization-destroy', 'organization-update'])->pluck('id')->all(),
    ])->assertCreated();

    $this->actingAs($owner)->withSession(['current_organization_id' => $this->alpha->id])->postJson('/roles', [
        'name' => 'Nosy',
        'permissions' => grantPermissions(['channel-view'])->pluck('id')->all(),
    ])->assertStatus(422)->assertJsonValidationErrors(['permissions' => 'You can only give a role permissions you hold yourself.']);

    $this->actingAs($owner)->withSession(['current_organization_id' => $this->alpha->id])->postJson('/roles', [
        'name' => 'Nosy',
        'permissions' => grantPermissions(['user-view'])->pluck('id')->all(),
    ])->assertStatus(422)->assertJsonValidationErrors(['permissions' => 'An organization role cannot hold View Accounts: it works above the organizations only.']);

    expect(Role::firstWhere('name', 'Closer')->permissions->pluck('name')->sort()->values()->all())->toBe(['organization-destroy', 'organization-update']);
});

/*
|--------------------------------------------------------------------------
| Settings → Organizations: deleting the organization is a permission
|--------------------------------------------------------------------------
*/

test('the Owner starts with organization-destroy; without it even an Owner cannot delete the organization, and an Admin given it can', function () {
    $owner = createOrganizationMember($this->alpha, Role::OWNER);
    $admin = createOrganizationMember($this->alpha, Role::ADMIN);

    expect(Role::starter(Role::OWNER)->permissions->pluck('name'))->toContain('organization-destroy');

    // Taken away from Owners by the super admin.
    Role::starter(Role::OWNER)->permissions()->detach(Permission::firstWhere('name', 'organization-destroy')->id);
    $this->actingAs($owner)->withSession(['current_organization_id' => $this->alpha->id])
        ->get('/settings/organization')->assertOk()->assertDontSee('Delete Organization');
    $this->actingAs($owner)->withSession(['current_organization_id' => $this->alpha->id])
        ->delete('/settings/organization', ['confirm_name' => 'Alpha Mart', 'password' => 'password'])->assertForbidden();

    // Given to Admins instead.
    grantToOrganizationRole(Role::ADMIN, ['organization-destroy']);
    $this->actingAs($admin)->withSession(['current_organization_id' => $this->alpha->id])
        ->get('/settings/organization')->assertOk()->assertSee('Delete Organization');
    $this->actingAs($admin)->withSession(['current_organization_id' => $this->alpha->id])
        ->delete('/settings/organization', ['confirm_name' => 'Alpha Mart', 'password' => 'password'])->assertRedirect(route('dashboard'));

    $this->assertDatabaseMissing('organizations', ['id' => $this->alpha->id]);
    expect(ActivityLog::where('action', 'organization.deleted')->value('organization_id'))->toBe($this->alpha->id);
});

test('the Organizations tab opens with View Organizations, and every card on it is a permission of its own', function () {
    // No View Organizations: no tab, and "Your organizations" stays on the profile so they can still leave.
    $viewer = createOrganizationMember($this->alpha, Role::VIEWER);
    $this->actingAs($viewer)->withSession(['current_organization_id' => $this->alpha->id])->get('/settings/organization')->assertForbidden();
    $this->actingAs($viewer)->withSession(['current_organization_id' => $this->alpha->id])->get('/profile')->assertOk()
        ->assertDontSee('dusk="settings-tab-organization"', false)->assertSee('Your organizations');

    // View Organizations alone: the details to read and the organizations they belong to — nothing to press.
    $reader = createOrganizationUser($this->alpha, ['organization-view'], 'Reader');
    $this->actingAs($reader)->withSession(['current_organization_id' => $this->alpha->id])->get('/settings/organization')->assertOk()
        ->assertSee('Your role here does not let you change the organization\'s details.', false)
        ->assertDontSee('dusk="organization-details-form"', false)
        ->assertSee('dusk="your-organizations"', false)
        ->assertDontSee('dusk="delete-organization"', false)->assertDontSee('dusk="open-organization-button"', false);
    $this->actingAs($reader)->withSession(['current_organization_id' => $this->alpha->id])
        ->put('/settings/organization', ['name' => 'Renamed'])->assertForbidden();
    // With the tab, "Your organizations" moves off the profile.
    $this->actingAs($reader)->withSession(['current_organization_id' => $this->alpha->id])->get('/profile')->assertOk()
        ->assertSee('dusk="settings-tab-organization"', false)->assertDontSee('Your organizations');

    // Each permission adds its own card, whatever the role is called.
    $closer = createOrganizationUser($this->alpha, ['organization-view', 'organization-destroy', 'organization-store'], 'Closer');
    $this->actingAs($closer)->withSession(['current_organization_id' => $this->alpha->id])->get('/settings/organization')->assertOk()
        ->assertSee('dusk="delete-organization"', false)->assertSee('dusk="open-organization-button"', false);

    // Turning View Organizations off hides the tab — even for the Owner role.
    $owner = createOrganizationMember($this->alpha, Role::OWNER);
    Role::owner()->permissions()->detach(Permission::firstWhere('name', 'organization-view')->id);
    $this->actingAs($owner)->withSession(['current_organization_id' => $this->alpha->id])->get('/settings/organization')->assertForbidden();
    $this->actingAs($owner)->withSession(['current_organization_id' => $this->alpha->id])->get('/profile')->assertOk()
        ->assertDontSee('dusk="settings-tab-organization"', false);
});

/*
|--------------------------------------------------------------------------
| Organizations, inside an organization: Settings → Organizations
|--------------------------------------------------------------------------
*/

test('"Your organizations" lists every organization the person belongs to, with their role in each — whatever those roles allow', function () {
    $gamma = Organization::factory()->create(['name' => 'Gamma Grill']);
    Organization::factory()->create(['name' => 'Delta Diner']);

    $person = createOrganizationMember($this->alpha, Role::OWNER);
    $person->organizations()->attach($gamma->id, ['role_id' => Role::starter(Role::STAFF)->id]);

    $this->actingAs($person)->withSession(['current_organization_id' => $this->alpha->id])->get('/settings/organization')->assertOk()
        ->assertSee('dusk="organization-membership-'.$this->alpha->id.'"', false)
        ->assertSee('dusk="organization-membership-'.$gamma->id.'"', false)
        ->assertSee('Gamma Grill')->assertSee('Staff')
        ->assertDontSee('Delta Diner');
});

test('Create organization opens an organization the person owns at once — no invitation, and the active switch stays the platform\'s', function () {
    grantToOrganizationRole(Role::OWNER, ['organization-store']);
    $owner = createOrganizationMember($this->alpha, Role::OWNER);

    $this->actingAs($owner)->withSession(['current_organization_id' => $this->alpha->id])->post('/settings/organization/open', [
        'organization_name' => 'Alpha Mart East', 'organization_street' => '9 Side St', 'organization_city' => 'Austin', 'organization_state' => 'TX',
        'organization_zip_code' => '73301', 'organization_country' => 'USA', 'is_active' => false, 'owner_email' => 'someone@example.com',
    ])->assertRedirect(route('organization-settings.edit'))
        ->assertSessionHas('status', 'Alpha Mart East is open, and you are its Owner. Switch to it from the organization menu.');

    $branch = Organization::firstWhere('name', 'Alpha Mart East');
    expect($branch->is_active)->toBeTrue()
        ->and($branch->street)->toBe('9 Side St')
        ->and(roleKeyIn($owner, $branch))->toBe(Role::OWNER)
        ->and(session('current_organization_id'))->toBe($this->alpha->id)
        ->and(DB::table('invitations')->count())->toBe(0)
        ->and(ActivityLog::where('action', 'organization.created')->value('organization_id'))->toBe($branch->id);
});

test('Create organization asks for organization-store, and checks the details under their own names', function () {
    $owner = createOrganizationMember($this->alpha, Role::OWNER);

    $this->actingAs($owner)->withSession(['current_organization_id' => $this->alpha->id])
        ->post('/settings/organization/open', ['organization_name' => 'Nope'])->assertForbidden();

    grantToOrganizationRole(Role::OWNER, ['organization-store']);
    // A fresh instance: the one above remembers the permissions it read on the first request.
    $this->actingAs($owner->fresh())->withSession(['current_organization_id' => $this->alpha->id])->from('/settings/organization')
        ->post('/settings/organization/open', ['organization_name' => '', 'organization_zip_code' => '7A'])
        ->assertRedirect('/settings/organization')
        ->assertSessionHasErrorsIn('newOrganization', [
            'organization_name' => 'The organization name field is required.',
            'organization_zip_code' => 'Zip code can only contain numbers.',
        ])
        // The details form of the organization being worked in never reads them back as its own.
        ->assertSessionDoesntHaveErrors(['name', 'zip_code']);

    expect(Organization::count())->toBe(2);
});

test('Settings → Organizations changes the organization the person works in, each action by its own permission there', function () {
    $person = createOrganizationMember($this->alpha, Role::OWNER);
    $person->organizations()->attach($this->beta->id, ['role_id' => Role::starter(Role::STAFF)->id]);

    // Working in Beta, where they are Staff: nothing.
    $this->actingAs($person)->withSession(['current_organization_id' => $this->beta->id]);
    $this->put('/settings/organization', ['name' => 'Taken', 'street' => '1', 'city' => 'X', 'state' => 'TX', 'zip_code' => '1', 'country' => 'USA'])
        ->assertForbidden();
    $this->delete('/settings/organization', ['confirm_name' => 'Beta Deli', 'password' => 'password'])->assertForbidden();
    expect($this->beta->fresh()->name)->toBe('Beta Deli');

    // Working in Alpha, where the role allows it: the details change, but never the platform's active switch.
    $this->actingAs($person)->withSession(['current_organization_id' => $this->alpha->id])->put('/settings/organization', [
        'name' => 'Alpha Mart II', 'street' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'zip_code' => '73301', 'country' => 'USA', 'is_active' => false,
    ])->assertRedirect(route('organization-settings.edit'));
    expect($this->alpha->fresh())->name->toBe('Alpha Mart II')->is_active->toBeTrue();
});

test('deleting the organization the person is working in sends them to the dashboard', function () {
    $owner = createOrganizationMember($this->alpha, Role::OWNER);

    $this->actingAs($owner)->withSession(['current_organization_id' => $this->alpha->id])
        ->delete('/settings/organization', ['confirm_name' => 'Alpha Mart', 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    expect(session('current_organization_id'))->toBeNull();
    $this->assertDatabaseMissing('organizations', ['id' => $this->alpha->id]);
});

test('giving an organization an owner stays above the organizations', function () {
    grantToOrganizationRole(Role::OWNER, ['organization-view', 'organization-store']);
    $owner = createOrganizationMember($this->alpha, Role::OWNER);

    $this->actingAs($owner)->withSession(['current_organization_id' => $this->alpha->id])
        ->postJson("/organizations/{$this->alpha->id}/owner-invitation", ['email' => 'second@example.com'])->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Accounts are the platform's
|--------------------------------------------------------------------------
*/

test('an organization\'s role never opens the accounts pages — not even holding their permissions', function () {
    // Written straight into the database, past the checklist that no longer offers them (owner's rule, 2026-09-17:
    // an organization's people are its Members page).
    grantToOrganizationRole(Role::OWNER, ['user-view', 'user-destroy']);
    $owner = createOrganizationMember($this->alpha, Role::OWNER);
    $staff = createOrganizationMember($this->alpha, Role::STAFF);

    $this->actingAs($owner)->withSession(['current_organization_id' => $this->alpha->id]);

    $this->get('/users')->assertForbidden();
    $this->getJson('/users/data')->assertForbidden();
    $this->deleteJson("/users/{$staff->id}", ['password' => 'password'])->assertForbidden();

    expect(User::find($staff->id))->not->toBeNull()
        ->and(roleKeyIn($staff, $this->alpha))->toBe(Role::STAFF);
});

test('what works only above the organizations stays shut to an organization\'s role, whatever it carries', function () {
    grantToOrganizationRole(Role::OWNER, ['user-view', 'user-destroy', 'organization-view', 'activity-view']);
    $owner = createOrganizationMember($this->alpha, Role::OWNER);
    $staff = createOrganizationMember($this->alpha, Role::STAFF);

    $this->actingAs($owner)->withSession(['current_organization_id' => $this->alpha->id]);

    $this->postJson("/users/{$staff->id}/impersonate")->assertForbidden();
    $this->getJson("/users/{$staff->id}/organizations")->assertForbidden();
    $this->putJson("/users/{$staff->id}/organizations/{$this->alpha->id}/role", ['role_id' => Role::starter(Role::ADMIN)->id])->assertForbidden();
    $this->getJson('/users/invitations')->assertForbidden();
    $this->getJson('/activity/partitions')->assertForbidden();
    $this->postJson('/activity/partitions/maintain')->assertForbidden();

    expect(roleKeyIn($staff, $this->alpha))->toBe(Role::STAFF);
});

/*
|--------------------------------------------------------------------------
| The activity log, inside an organization
|--------------------------------------------------------------------------
*/

test('an organization role with activity-view reads its own organization\'s history and nothing else', function () {
    grantToOrganizationRole(Role::OWNER, ['activity-view']);
    $owner = createOrganizationMember($this->alpha, Role::OWNER);

    ActivityLog::record('screen.paired', Screen::factory()->create(['organization_id' => $this->alpha->id, 'name' => 'Alpha Window']), 'Paired screen Alpha Window', $owner);
    ActivityLog::record('screen.paired', Screen::factory()->create(['organization_id' => $this->beta->id, 'name' => 'Beta Window']), 'Paired screen Beta Window', $owner);
    ActivityLog::record('permission.created', null, 'Created permission report-export', $this->superAdmin);
    ActivityLog::record('profile.updated', $owner, 'Updated their own profile', $owner);

    $descriptions = collect($this->actingAs($owner)->withSession(['current_organization_id' => $this->alpha->id])
        ->getJson('/activity/data')->assertOk()->json('logs'))->pluck('description');

    expect($descriptions->all())->toBe(['Paired screen Alpha Window']);

    $this->actingAs($owner)->withSession(['current_organization_id' => $this->alpha->id])->get('/activity')
        ->assertOk()->assertSee('Activity in Alpha Mart')->assertDontSee('Run Yearly Maintenance');

    // The platform reads every organization's history, and its own — with Alpha no longer in the session.
    $this->flushSession();
    expect($this->actingAs($this->superAdmin)->getJson('/activity/data')->assertOk()->json('total'))->toBe(4);
});

test('an entry belongs to the organization its subject belongs to — or the organization named for a delete; personal and platform work to none', function () {
    $owner = createOrganizationMember($this->alpha, Role::OWNER);
    $media = Media::factory()->create(['organization_id' => $this->beta->id]);

    ActivityLog::record('media.updated', $media, 'Updated media', $owner);
    ActivityLog::record('organization.updated', $this->alpha, 'Updated organization', $owner);
    ActivityLog::record('member.removed', $owner, 'Removed somebody', $owner, organizationId: $this->alpha->id);
    ActivityLog::record('password.changed', $owner, 'Changed their own password', $owner);
    ActivityLog::record('campaign.created', null, 'Created campaign', $this->superAdmin);

    expect(ActivityLog::orderBy('id')->pluck('organization_id', 'action')->all())->toBe([
        'media.updated' => $this->beta->id,
        'organization.updated' => $this->alpha->id,
        'member.removed' => $this->alpha->id,
        'password.changed' => null,
        'campaign.created' => null,
    ]);
});

/*
|--------------------------------------------------------------------------
| The sidebar follows the role
|--------------------------------------------------------------------------
*/

test('an organization\'s sidebar offers Channels and the Activity Log only when the role carries them — never Accounts or the Organizations page', function () {
    $owner = createOrganizationMember($this->alpha, Role::OWNER);

    $this->actingAs($owner)->withSession(['current_organization_id' => $this->alpha->id])->get('/dashboard')->assertOk()
        ->assertDontSee('href="'.route('organizations.view').'"', false)->assertDontSee('href="'.route('users.view').'"', false)
        ->assertDontSee('href="'.route('channels.view').'"', false)->assertDontSee('href="'.route('activity.view').'"', false);

    grantToOrganizationRole(Role::OWNER, ['organization-view', 'user-view', 'channel-view', 'activity-view']);

    // View Organizations is the Organizations tab of Settings inside an organization, and the accounts are the platform's: neither is a
    // page of an organization's sidebar.
    $this->actingAs($owner->fresh())->withSession(['current_organization_id' => $this->alpha->id])->get('/dashboard')->assertOk()
        ->assertDontSee('href="'.route('organizations.view').'"', false)->assertDontSee('href="'.route('users.view').'"', false)
        ->assertSee('href="'.route('channels.view').'"', false)->assertSee('href="'.route('activity.view').'"', false);

    $this->actingAs($owner->fresh())->withSession(['current_organization_id' => $this->alpha->id])->get('/organizations')->assertForbidden();
});
