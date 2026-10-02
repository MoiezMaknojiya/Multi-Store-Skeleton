<?php

use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Role;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Creating and editing organizations from the platform (validation and reach)
|--------------------------------------------------------------------------
|
| An organization's own people edit it from Settings → Organizations (OrganizationSettingsTest); giving an organization an owner and
| deleting one are covered by PlatformOrganizationsTest.
|
*/

beforeEach(function () {
    Notification::fake();
});

function newOrganizationPayload(array $overrides = []): array
{
    return [
        'name' => 'New Organization', 'street' => '123 Main St', 'city' => 'Austin', 'state' => 'TX',
        'zip_code' => '78701', 'country' => 'USA', 'is_active' => true, 'owner_email' => 'owner@example.com',
        ...$overrides,
    ];
}

test('guests cannot reach any organization endpoint', function () {
    $organization = Organization::factory()->create();

    $this->getJson('/organizations/data')->assertUnauthorized();
    $this->postJson('/organizations', newOrganizationPayload())->assertUnauthorized();
    $this->putJson("/organizations/{$organization->id}", newOrganizationPayload())->assertUnauthorized();
    $this->deleteJson("/organizations/{$organization->id}")->assertUnauthorized();
    $this->postJson('/organizations/switch', ['organization_id' => $organization->id])->assertUnauthorized();

    // And every other route under /organizations, read from the route table — so one added later is asked too.
    $routes = routesUnder('organizations');

    expect($routes)->not->toBeEmpty();

    foreach ($routes as [$method, $uri]) {
        expect($this->json($method, $uri)->status())->toBe(401, "{$method} {$uri}");
    }
});

test('a super admin sees every organization', function () {
    Organization::factory()->count(2)->create();

    $this->actingAs(createSuperAdmin(['organization-view']))->getJson('/organizations/data')->assertOk()->assertJsonCount(2, 'organizations');
});

test('a platform role holding organization-view sees every organization too', function () {
    Organization::factory()->create(['name' => 'Z Grocery']);
    Organization::factory()->create(['name' => 'Other Organization']);

    $names = collect($this->actingAs(createPlatformUser(['organization-view']))->getJson('/organizations/data')->assertOk()->json('organizations'))->pluck('name');

    expect($names)->toContain('Z Grocery')->toContain('Other Organization');
});

test('an organization member holding organization-update changes the organization they work in from Settings → Organizations — never through the Organizations page', function () {
    $organization = Organization::factory()->create(['name' => 'Corner Organization']);
    $member = createOrganizationUser($organization, ['organization-view', 'organization-update']);

    $this->actingAs($member)->withSession(['current_organization_id' => $organization->id]);

    $this->getJson('/organizations/data')->assertForbidden();
    $this->putJson("/organizations/{$organization->id}", newOrganizationPayload(['name' => 'Taken']))->assertForbidden();

    $this->put('/settings/organization', newOrganizationPayload(['name' => 'Corner Organization Renamed', 'is_active' => false]))
        ->assertRedirect(route('organization-settings.edit'));

    expect($organization->fresh())->name->toBe('Corner Organization Renamed')->is_active->toBeTrue();
});

test('creating an organization gives it a slug and invites its owner', function () {
    $response = $this->actingAs(createSuperAdmin(['organization-store']))->postJson('/organizations', newOrganizationPayload())->assertCreated();

    expect($response->json('organization.slug'))->not->toBeNull();
    expect(Invitation::sole()->role_id)->toBe(Role::starter(Role::OWNER)->id);
});

test('creating an organization validates required fields, the owner email included', function () {
    $this->actingAs(createSuperAdmin(['organization-store']))->postJson('/organizations', ['name' => 'Incomplete Organization'])
        ->assertJsonValidationErrors(['street', 'city', 'state', 'zip_code', 'country', 'owner_email']);
});

test('the state must be one of the 50 US state codes', function () {
    $admin = createSuperAdmin(['organization-store']);

    $this->actingAs($admin)->postJson('/organizations', newOrganizationPayload(['state' => 'Texas']))->assertJsonValidationErrors('state');
    $this->actingAs($admin)->postJson('/organizations', newOrganizationPayload(['state' => 'DC']))->assertJsonValidationErrors('state');
    $this->actingAs($admin)->postJson('/organizations', newOrganizationPayload(['state' => 'TX']))->assertCreated();
});

test('the zip code must be numbers only', function () {
    $admin = createSuperAdmin(['organization-store']);

    $this->actingAs($admin)->postJson('/organizations', newOrganizationPayload(['zip_code' => '787A1']))->assertJsonValidationErrors('zip_code');
    $this->actingAs($admin)->postJson('/organizations', newOrganizationPayload(['zip_code' => '78701', 'owner_email' => 'second@example.com']))->assertCreated();
});

test('the platform edits an organization’s details', function () {
    $organization = Organization::factory()->create(['name' => 'Old Name']);

    $this->actingAs(createSuperAdmin(['organization-update']))
        ->putJson("/organizations/{$organization->id}", newOrganizationPayload(['name' => 'Renamed', 'is_active' => false]))
        ->assertOk();

    expect($organization->fresh()->name)->toBe('Renamed')->and($organization->fresh()->is_active)->toBeFalse();
});
