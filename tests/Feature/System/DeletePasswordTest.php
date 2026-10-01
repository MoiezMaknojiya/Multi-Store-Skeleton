<?php

use App\Models\Campaign;
use App\Models\Channel;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Big deletes re-confirm the password (owner's rule, 2026-09-16)
|--------------------------------------------------------------------------
|
| An organization, an account, a platform role, a member, a role, a channel, a campaign, a permission: a
| session left open on a shared computer must not destroy any of them with two clicks. The password
| is asked after every other refusal, and wrong ones are limited per person.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->superAdmin = createSuperAdmin();
});

/** Refused without the password and with a wrong one — each time with the reason on the field. */
function refusesWithoutThePassword(string $url, array $payload = []): void
{
    test()->deleteJson($url, $payload)
        ->assertStatus(422)->assertJsonValidationErrors(['password' => 'Password is required.']);

    test()->deleteJson($url, [...$payload, 'password' => 'not-my-password'])
        ->assertStatus(422)->assertJsonValidationErrors(['password' => 'The password is incorrect.']);
}

test('the platform asks for it before deleting an organization, an account or a platform role', function () {
    $organization = Organization::factory()->create(['name' => 'Doomed Deli']);
    $customer = User::factory()->create();
    $support = createPlatformUser(['organization-view']);

    $this->actingAs($this->superAdmin);
    refusesWithoutThePassword("/organizations/{$organization->id}", ['confirm_name' => 'Doomed Deli']);
    refusesWithoutThePassword("/users/{$customer->id}");
    refusesWithoutThePassword("/users/{$support->id}/platform-role");

    expect(Organization::find($organization->id))->not->toBeNull()
        ->and(User::find($customer->id))->not->toBeNull()
        ->and($support->fresh()->globalRole())->not->toBeNull();

    $this->deleteJson("/organizations/{$organization->id}", ['confirm_name' => 'Doomed Deli', 'password' => 'password'])->assertOk();
    $this->assertDatabaseMissing('organizations', ['id' => $organization->id]);
});

test('channels, campaigns and permissions ask for it too', function () {
    $channel = Channel::factory()->create();
    $campaign = Campaign::factory()->create();
    $permission = Permission::create(['name' => 'report-export']);

    $this->actingAs($this->superAdmin);
    refusesWithoutThePassword("/channels/{$channel->id}");
    refusesWithoutThePassword("/campaigns/{$campaign->id}");
    refusesWithoutThePassword("/permissions/{$permission->id}");

    expect(Channel::find($channel->id))->not->toBeNull()
        ->and(Campaign::find($campaign->id))->not->toBeNull()
        ->and(Permission::find($permission->id))->not->toBeNull();
});

test('inside an organization, removing a member and deleting a role ask for it — after every other refusal', function () {
    $organization = Organization::factory()->create();
    $owner = createOrganizationMember($organization, Role::OWNER);
    $staff = createOrganizationMember($organization, Role::STAFF);
    $cashier = Role::create(['name' => 'Cashier', 'organization_id' => $organization->id]);
    $held = Role::create(['name' => 'Held', 'organization_id' => $organization->id]);
    User::factory()->create()->organizations()->attach($organization->id, ['role_id' => $held->id]);

    $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id]);
    refusesWithoutThePassword("/members/{$staff->id}");
    refusesWithoutThePassword("/roles/{$cashier->id}");

    // A role still held is refused for that reason, whatever the password.
    $this->deleteJson("/roles/{$held->id}")->assertStatus(422)->assertJsonPath('message', 'Please unassign Held from everyone first: 1 person still holds it.');

    expect(roleKeyIn($staff, $organization))->toBe(Role::STAFF)
        ->and(Role::find($cashier->id))->not->toBeNull();

    $this->deleteJson("/members/{$staff->id}", ['password' => 'password'])->assertOk();
    expect(roleKeyIn($staff, $organization))->toBeNull();
});

test('wrong passwords are limited: after five in a minute even the right one waits', function () {
    $organization = Organization::factory()->create(['name' => 'Guarded Grocer']);
    $this->actingAs($this->superAdmin);

    foreach (range(1, 5) as $attempt) {
        $this->deleteJson("/organizations/{$organization->id}", ['confirm_name' => 'Guarded Grocer', 'password' => "guess-{$attempt}"])->assertStatus(422);
    }

    $this->deleteJson("/organizations/{$organization->id}", ['confirm_name' => 'Guarded Grocer', 'password' => 'password'])
        ->assertStatus(429)
        ->assertJsonPath('errors.password.0', fn (string $message) => str_starts_with($message, 'Too many wrong passwords. Try again in'));
    expect(Organization::find($organization->id))->not->toBeNull();

    $this->travel(61)->seconds();

    $this->deleteJson("/organizations/{$organization->id}", ['confirm_name' => 'Guarded Grocer', 'password' => 'password'])->assertOk();
});

test('small everyday deletes stay one confirmation away', function () {
    $organization = Organization::factory()->create();
    $owner = createOrganizationMember($organization, Role::OWNER);
    $invitation = Invitation::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
        ->deleteJson("/members/invitations/{$invitation->id}")->assertOk();

    expect(DB::table('invitations')->count())->toBe(0);
});

test('the wrong-password limit is shared by every form that asks — plain forms, and changing your password, too', function () {
    $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $owner = createOrganizationMember($organization, Role::OWNER);
    $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id]);

    foreach (range(1, 5) as $attempt) {
        $this->from('/profile')->put('/password', [
            'current_password' => "guess-{$attempt}", 'password' => 'N3w-Password!', 'password_confirmation' => 'N3w-Password!',
        ])->assertSessionHasErrorsIn('updatePassword', ['current_password' => 'The password is incorrect.']);
    }

    // Now even the right password waits — on a different form.
    $this->from('/settings/organization')->delete('/settings/organization', ['confirm_name' => 'Alpha Mart', 'password' => 'password'])
        ->assertRedirect('/settings/organization')
        ->assertSessionHasErrorsIn('organizationDeletion', 'password');

    expect(session('errors')->getBag('organizationDeletion')->first('password'))->toStartWith('Too many wrong passwords. Try again in')
        ->and(Organization::find($organization->id))->not->toBeNull();
});
