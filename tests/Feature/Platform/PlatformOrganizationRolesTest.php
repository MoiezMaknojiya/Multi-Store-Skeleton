<?php

use App\Models\ActivityLog;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The super admin puts anybody in any organization, with a role — Users → Manage organizations
|--------------------------------------------------------------------------
|
| The owner's rule (2026-09-17): as before the rebuild, the super admin assigns a person to an organization
| with a role straight away — no invitation — changes that role, and takes it away. An organization role or
| that organization's own custom role; a platform account never joins an organization; an organization keeps its Owner.
|
*/

beforeEach(function () {
    $this->superAdmin = createSuperAdmin();
    $this->alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->beta = Organization::factory()->create(['name' => 'Beta Deli']);
});

test("the form lists a person's organizations, the organizations they could join, and the roles on offer", function () {
    $person = createOrganizationMember($this->alpha, Role::STAFF);
    Organization::factory()->create(['name' => 'Gamma Grill']);
    Role::create(['name' => 'Cashier', 'organization_id' => $this->alpha->id]);
    Role::create(['name' => 'Baker', 'organization_id' => $this->beta->id]);

    $access = $this->actingAs($this->superAdmin)->getJson("/users/{$person->id}/organizations")->assertOk()->json();

    expect($access['memberships'])->toBe([['organization_id' => $this->alpha->id, 'organization_name' => 'Alpha Mart', 'role_id' => Role::starter(Role::STAFF)->id]])
        ->and(collect($access['organizations'])->pluck('name')->all())->toBe(['Beta Deli', 'Gamma Grill'])
        ->and(collect($access['organization_roles'])->pluck('name')->all())->toBe(['Owner', 'Admin', 'Staff', 'Viewer'])
        ->and($access['organization_roles'][0]['id'])->toBe(Role::owner()->id)
        ->and(collect($access['custom_roles'][$this->alpha->id])->pluck('name')->all())->toBe(['Cashier'])
        ->and(collect($access['custom_roles'][$this->beta->id])->pluck('name')->all())->toBe(['Baker']);
});

test('the custom roles come keyed by organization — an object in JSON even when no organization has one', function () {
    $person = createOrganizationMember($this->alpha, Role::STAFF);

    expect($this->actingAs($this->superAdmin)->getJson("/users/{$person->id}/organizations")->assertOk()->getContent())
        ->toContain('"custom_roles":{}');
});

test('the super admin adds anybody to an organization with a role — at once, no invitation', function () {
    $newcomer = User::factory()->create(['first_name' => 'Nadia', 'last_name' => 'New']);
    $baker = Role::create(['name' => 'Baker', 'organization_id' => $this->beta->id]);

    $this->actingAs($this->superAdmin)->postJson("/users/{$newcomer->id}/organizations", ['organization_id' => $this->beta->id, 'role_id' => $baker->id])
        ->assertCreated()->assertJsonPath('message', 'Nadia New is now Baker in Beta Deli.');

    expect(DB::table('organization_user')->where('user_id', $newcomer->id)->where('organization_id', $this->beta->id)->value('role_id'))->toBe($baker->id)
        ->and(Invitation::count())->toBe(0);

    $log = ActivityLog::where('action', 'member.assigned')->sole();
    expect($log->organization_id)->toBe($this->beta->id)
        ->and($log->description)->toBe("Added Nadia New ({$newcomer->email}) to Beta Deli as Baker, from the platform");

    // Making someone an Owner is giving them the Owner role — beside any Owner the organization already has.
    $this->actingAs($this->superAdmin)->postJson("/users/{$newcomer->id}/organizations", ['organization_id' => $this->alpha->id, 'role_id' => Role::owner()->id])
        ->assertCreated();
    expect(roleKeyIn($newcomer, $this->alpha))->toBe(Role::OWNER);
});

test('only a role that organization has, never twice in one organization, never a platform account', function () {
    $person = createOrganizationMember($this->alpha, Role::STAFF);
    $foreignRole = Role::create(['name' => 'Baker', 'organization_id' => $this->beta->id]);
    $platformRole = Role::create(['name' => 'Support', 'is_global' => true]);
    $support = createPlatformUser(['user-view']);

    $this->actingAs($this->superAdmin)->postJson("/users/{$person->id}/organizations", ['organization_id' => $this->beta->id, 'role_id' => Role::create(['name' => 'Alpha Only', 'organization_id' => $this->alpha->id])->id])
        ->assertStatus(422)->assertJsonValidationErrors(['role_id' => 'Choose a role that exists in this organization.']);
    $this->actingAs($this->superAdmin)->postJson("/users/{$person->id}/organizations", ['organization_id' => $this->beta->id, 'role_id' => $platformRole->id])
        ->assertStatus(422)->assertJsonValidationErrors('role_id');
    $this->actingAs($this->superAdmin)->postJson("/users/{$person->id}/organizations", ['organization_id' => $this->alpha->id, 'role_id' => Role::starter(Role::VIEWER)->id])
        ->assertStatus(422)->assertJsonValidationErrors(['organization_id' => "{$person->name} is already in Alpha Mart. Change their role there instead."]);
    $this->actingAs($this->superAdmin)->postJson("/users/{$support->id}/organizations", ['organization_id' => $this->alpha->id, 'role_id' => Role::starter(Role::VIEWER)->id])
        ->assertStatus(422)->assertJsonValidationErrors('organization_id');

    expect(roleKeyIn($person, $this->alpha))->toBe(Role::STAFF)
        ->and(roleKeyIn($person, $this->beta))->toBeNull()
        ->and($foreignRole->users()->count())->toBe(0)
        ->and(DB::table('organization_user')->where('user_id', $support->id)->where('organization_id', '>', 0)->exists())->toBeFalse();
});

test("the super admin changes a person's role in one organization, and only that organization", function () {
    $person = createOrganizationMember($this->alpha, Role::STAFF);
    $person->organizations()->attach($this->beta->id, ['role_id' => Role::starter(Role::VIEWER)->id]);
    $manager = Role::create(['name' => 'Area Manager', 'organization_id' => $this->alpha->id]);

    $this->actingAs($this->superAdmin)->putJson("/users/{$person->id}/organizations/{$this->alpha->id}/role", ['role_id' => $manager->id])
        ->assertOk()->assertJsonPath('message', "{$person->name} is now Area Manager in Alpha Mart.");

    expect(DB::table('organization_user')->where('user_id', $person->id)->where('organization_id', $this->alpha->id)->value('role_id'))->toBe($manager->id)
        ->and(roleKeyIn($person, $this->beta))->toBe(Role::VIEWER);

    $log = ActivityLog::where('action', 'member.role_changed')->sole();
    expect($log->organization_id)->toBe($this->alpha->id)
        ->and($log->description)->toBe("Changed the role of {$person->name} in Alpha Mart from Staff to Area Manager, from the platform");
});

test('an organization keeps its Owner — neither demoted nor removed while it is the only one', function () {
    $owner = createOrganizationMember($this->alpha, Role::OWNER);

    $this->actingAs($this->superAdmin)->putJson("/users/{$owner->id}/organizations/{$this->alpha->id}/role", ['role_id' => Role::starter(Role::STAFF)->id])
        ->assertStatus(422)->assertJsonValidationErrors(['role_id' => "{$owner->name} is the only Owner of Alpha Mart. Make someone else an Owner first."]);
    $this->actingAs($this->superAdmin)->deleteJson("/users/{$owner->id}/organizations/{$this->alpha->id}", ['password' => 'password'])
        ->assertStatus(422)->assertJsonPath('message', "{$owner->name} is the only Owner of Alpha Mart. Make someone else an Owner first.");

    $second = createOrganizationMember($this->alpha, Role::OWNER);
    $this->actingAs($this->superAdmin)->putJson("/users/{$owner->id}/organizations/{$this->alpha->id}/role", ['role_id' => Role::starter(Role::ADMIN)->id])
        ->assertOk();

    expect(roleKeyIn($owner, $this->alpha))->toBe(Role::ADMIN)->and(roleKeyIn($second, $this->alpha))->toBe(Role::OWNER);
});

test('taking a person out of an organization asks for the password, and leaves their account and other organizations', function () {
    $person = createOrganizationMember($this->alpha, Role::STAFF);
    $person->organizations()->attach($this->beta->id, ['role_id' => Role::starter(Role::VIEWER)->id]);

    $this->actingAs($this->superAdmin)->deleteJson("/users/{$person->id}/organizations/{$this->alpha->id}")
        ->assertStatus(422)->assertJsonValidationErrors(['password' => 'Password is required.']);
    $this->actingAs($this->superAdmin)->deleteJson("/users/{$person->id}/organizations/{$this->alpha->id}", ['password' => 'password'])
        ->assertOk()->assertJsonPath('message', "{$person->name} was removed from Alpha Mart.");

    expect(roleKeyIn($person, $this->alpha))->toBeNull()
        ->and(roleKeyIn($person, $this->beta))->toBe(Role::VIEWER)
        ->and(User::find($person->id))->not->toBeNull()
        ->and(ActivityLog::where('action', 'member.removed')->value('organization_id'))->toBe($this->alpha->id);

    // Somebody who is not in the organization is not found.
    $this->actingAs($this->superAdmin)->deleteJson("/users/{$person->id}/organizations/{$this->alpha->id}", ['password' => 'password'])->assertNotFound();
});

test("managing a person's organizations from the platform is the super admin's alone", function () {
    $person = createOrganizationMember($this->alpha, Role::STAFF);
    $support = createPlatformUser(['user-view', 'member-update', 'member-remove']);
    $owner = createOrganizationMember($this->alpha, Role::OWNER);

    $requests = [
        fn () => $this->getJson("/users/{$person->id}/organizations"),
        fn () => $this->postJson("/users/{$person->id}/organizations", ['organization_id' => $this->beta->id, 'role_id' => Role::starter(Role::VIEWER)->id]),
        fn () => $this->putJson("/users/{$person->id}/organizations/{$this->alpha->id}/role", ['role_id' => Role::starter(Role::ADMIN)->id]),
        fn () => $this->deleteJson("/users/{$person->id}/organizations/{$this->alpha->id}", ['password' => 'password']),
    ];

    foreach ($requests as $request) {
        $this->actingAs($support);
        $request()->assertForbidden();

        $this->actingAs($owner)->withSession(['current_organization_id' => $this->alpha->id]);
        $request()->assertForbidden();
    }

    expect(roleKeyIn($person, $this->alpha))->toBe(Role::STAFF)->and(roleKeyIn($person, $this->beta))->toBeNull();
});

test('the Users page offers Organizations for organization accounts only', function () {
    $person = createOrganizationMember($this->alpha, Role::STAFF);
    $support = createPlatformUser(['user-view']);

    $rows = collect($this->actingAs($this->superAdmin)->getJson('/users/data')->assertOk()->json('users'))->keyBy('id');

    expect($rows[$person->id]['can']['manage_organizations'])->toBeTrue()
        ->and($rows[$support->id]['can']['manage_organizations'])->toBeFalse();
});
