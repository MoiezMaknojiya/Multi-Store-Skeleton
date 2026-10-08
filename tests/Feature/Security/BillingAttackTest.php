<?php

use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;

/*
|--------------------------------------------------------------------------
| Billing, attacked (docs/BILLING-SPEC.md)
|--------------------------------------------------------------------------
|
| The two switches are what an organization will pay for, so every way of turning them from the wrong place is tried: an
| organization's own people however much their role holds, a role made to carry Change Billing, a platform role without it,
| another organization's id, ids and payloads nobody sends.
|
*/

beforeEach(function () {
    $this->alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->beta = Organization::factory()->create(['name' => 'Beta Foods']);
    $this->alpha->forceFill(['premium_templates_unlocked' => false, 'platform_channels_unlocked' => false])->save();
});

/** Neither switch moved, and nothing was logged of it. */
function switchesUntouched(Organization $organization): void
{
    expect($organization->fresh())
        ->premium_templates_unlocked->toBeFalse()
        ->platform_channels_unlocked->toBeFalse()
        ->and(ActivityLog::where('action', 'organization.billing_updated')->exists())->toBeFalse();
}

test('an organization’s own people never unlock their organization, whatever their role holds', function () {
    $everything = createOrganizationUser($this->alpha, [...Permission::ORGANIZATION, ...Permission::ORGANIZATION_SCOPED], 'Everything');
    $owner = createOrganizationMember($this->alpha, Role::OWNER);

    foreach ([$everything, $owner] as $person) {
        $this->actingAs($person)->withSession(['current_organization_id' => $this->alpha->id]);

        $this->putJson("/organizations/{$this->alpha->id}/billing", ['premium_templates_unlocked' => true, 'platform_channels_unlocked' => true])->assertForbidden();
        $this->getJson("/organizations/{$this->alpha->id}/billing")->assertForbidden();
        // The page they read has no switch, and takes no write.
        $this->get('/settings/billing')->assertOk()->assertDontSee('role="switch"', false);
        $this->put('/settings/billing', ['premium_templates_unlocked' => true])->assertStatus(405);
    }

    switchesUntouched($this->alpha);
});

test('Change Billing cannot be put on an organization’s role, by the platform or from inside', function () {
    // From inside: a custom role.
    $roleMaker = createOrganizationUser($this->alpha, ['role-view', 'role-store', 'billing-view'], 'Role maker');
    $this->actingAs($roleMaker)->withSession(['current_organization_id' => $this->alpha->id]);
    $this->postJson('/roles', ['name' => 'Unlocker', 'permissions' => ['billing-update']])->assertStatus(422);

    // From the platform: an organization role.
    $this->actingAs(createSuperAdmin())->flushSession();
    $this->postJson('/roles', ['name' => 'Organization unlocker', 'type' => 'organization', 'permissions' => ['billing-update']])->assertStatus(422);

    expect(Role::where('name', 'like', '%nlocker%')->exists())->toBeFalse();
});

test('a platform role without Change Billing, and one without even View Billing, get nothing', function () {
    $this->actingAs(createPlatformUser(['organization-view', 'organization-update'], 'Support'));

    $this->getJson("/organizations/{$this->alpha->id}/billing")->assertForbidden();
    $this->putJson("/organizations/{$this->alpha->id}/billing", ['premium_templates_unlocked' => true, 'platform_channels_unlocked' => true])->assertForbidden();
    // Edit cannot reach the switches either: they are not among the details.
    $this->putJson("/organizations/{$this->alpha->id}", [
        'name' => 'Alpha Mart', 'street' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'zip_code' => '73301', 'country' => 'USA',
        'premium_templates_unlocked' => true, 'platform_channels_unlocked' => true,
    ])->assertOk();

    switchesUntouched($this->alpha);
});

test('ids and payloads nobody sends are refused, never a 500, and touch no organization', function () {
    $this->actingAs(createSuperAdmin());

    foreach (['abc', '0', '-1', '999999'] as $id) {
        $this->getJson("/organizations/{$id}/billing")->assertNotFound();
        $this->putJson("/organizations/{$id}/billing", ['premium_templates_unlocked' => true, 'platform_channels_unlocked' => true])->assertNotFound();
    }

    foreach ([
        ['premium_templates_unlocked' => ['x'], 'platform_channels_unlocked' => true],
        ['premium_templates_unlocked' => 'yes', 'platform_channels_unlocked' => 'no'],
        ['premium_templates_unlocked' => 2, 'platform_channels_unlocked' => true],
        ['premium_templates_unlocked' => true],
        ['premium_templates_unlocked' => null, 'platform_channels_unlocked' => null],
    ] as $payload) {
        $this->putJson("/organizations/{$this->alpha->id}/billing", $payload)->assertStatus(422);
    }

    // Saying more than the switches changes nothing more.
    $this->putJson("/organizations/{$this->alpha->id}/billing", [
        'premium_templates_unlocked' => false, 'platform_channels_unlocked' => false, 'name' => 'Taken', 'is_active' => false, 'id' => $this->beta->id,
    ])->assertOk()->assertJsonPath('message', 'Nothing changed.');

    expect($this->alpha->fresh())->name->toBe('Alpha Mart')->is_active->toBeTrue()
        ->and($this->beta->fresh())->premium_templates_unlocked->toBeTrue();
    switchesUntouched($this->alpha);
});

test('a guest and an account not yet confirmed reach no billing', function () {
    $this->getJson("/organizations/{$this->alpha->id}/billing")->assertUnauthorized();
    $this->get('/settings/billing')->assertRedirect('/login');

    $unconfirmed = createOrganizationMember($this->alpha, Role::OWNER, ['email_verified_at' => null]);
    $this->actingAs($unconfirmed)->withSession(['current_organization_id' => $this->alpha->id]);
    $this->get('/settings/billing')->assertRedirect(route('verification.notice'));
});
