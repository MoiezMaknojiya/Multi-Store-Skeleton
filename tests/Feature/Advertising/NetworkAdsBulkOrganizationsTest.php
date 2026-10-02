<?php

use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Screen;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Signing whole organizations up to network advertising, from the organizations listing
|--------------------------------------------------------------------------
|
| Deals are struck a dozen organizations at a time, not one television at a time, so the
| broad switch lives on the platform owner's own page — the organizations listing — where
| every organization is already in front of them.
|
| The one rule worth stating out loud: it sets the organization AND every television inside
| it. The two flags are ANDed when a screen asks for its playlist, so an organization switched
| on alone would change nothing at all — every television starts off. A bulk switch
| that silently does nothing is worse than none, so this one reaches all the way
| down, and the panel says so before it is pressed.
|
*/

/** A global user who is emphatically NOT a super admin. */
function globalNonAdmin(): User
{
    $role = Role::create(['name' => 'Global Admin', 'is_global' => true]);
    $user = User::factory()->create();
    $user->organizations()->attach(0, ['role_id' => $role->id]);

    return $user;
}

beforeEach(function () {
    $this->admin = createSuperAdmin(['organization-view']);

    $this->organization = Organization::factory()->create();
    $this->counter = Screen::factory()->create(['organization_id' => $this->organization->id]);
    $this->window = Screen::factory()->create(['organization_id' => $this->organization->id]);

    $this->other = Organization::factory()->create();
    $this->otherScreen = Screen::factory()->create(['organization_id' => $this->other->id]);
});

/*
|--------------------------------------------------------------------------
| Who may press it
|--------------------------------------------------------------------------
*/

test('a super admin switches whole organizations on without impersonating anybody', function () {
    // The point of this endpoint: the organizations listing is reached directly, so demanding
    // an impersonated session here would shut the door on the one person it is for.
    $this->actingAs($this->admin)
        ->putJson('/network-ads/organizations', ['organization_ids' => [$this->organization->id], 'accepts' => true])
        ->assertOk();

    expect($this->organization->fresh()->accepts_network_ads)->toBeTrue();
});

test('a global user who is not a super admin cannot', function () {
    $this->actingAs(globalNonAdmin())
        ->putJson('/network-ads/organizations', ['organization_ids' => [$this->organization->id], 'accepts' => true])
        ->assertForbidden();

    expect($this->organization->fresh()->accepts_network_ads)->toBeFalse();
});

test('an organization member cannot, not even for their own organization', function () {
    $keeper = createOrganizationUser($this->organization, ['organization-view', 'organization-update']);

    $this->actingAs($keeper)->withSession(['current_organization_id' => $this->organization->id])
        ->putJson('/network-ads/organizations', ['organization_ids' => [$this->organization->id], 'accepts' => true])
        ->assertForbidden();

    expect($this->organization->fresh()->accepts_network_ads)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| What it actually changes
|--------------------------------------------------------------------------
*/

test('the organization and every television inside it are switched together', function () {
    $this->actingAs($this->admin)
        ->putJson('/network-ads/organizations', ['organization_ids' => [$this->organization->id], 'accepts' => true])
        ->assertOk();

    expect($this->organization->fresh()->accepts_network_ads)->toBeTrue();
    expect($this->counter->fresh()->accepts_network_ads)->toBeTrue();
    expect($this->window->fresh()->accepts_network_ads)->toBeTrue();
});

test('an organization that was not ticked is left exactly as it was', function () {
    $this->actingAs($this->admin)
        ->putJson('/network-ads/organizations', ['organization_ids' => [$this->organization->id], 'accepts' => true])
        ->assertOk();

    expect($this->other->fresh()->accepts_network_ads)->toBeFalse();
    expect($this->otherScreen->fresh()->accepts_network_ads)->toBeFalse();
});

test('switching off puts the organization and every television back', function () {
    $this->organization->update(['accepts_network_ads' => true]);
    Screen::where('organization_id', $this->organization->id)->update(['accepts_network_ads' => true]);

    $this->actingAs($this->admin)
        ->putJson('/network-ads/organizations', ['organization_ids' => [$this->organization->id], 'accepts' => false])
        ->assertOk();

    expect($this->organization->fresh()->accepts_network_ads)->toBeFalse();
    expect($this->counter->fresh()->accepts_network_ads)->toBeFalse();
    expect($this->window->fresh()->accepts_network_ads)->toBeFalse();
});

test('a television set apart by hand is swept up too — deliberately, and the panel warns first', function () {
    // The owner's own instruction: the bulk does the broad strokes, and an exception
    // is set again from inside the organization. Recorded as a test so nobody "fixes" it
    // later into a merge that would quietly leave organizations half-switched.
    $this->organization->update(['accepts_network_ads' => true]);
    $this->counter->update(['accepts_network_ads' => true]);
    $this->window->update(['accepts_network_ads' => false]);   // the children's corner

    $this->actingAs($this->admin)
        ->putJson('/network-ads/organizations', ['organization_ids' => [$this->organization->id], 'accepts' => true])
        ->assertOk();

    expect($this->window->fresh()->accepts_network_ads)->toBeTrue();
});

test('several organizations in one press, and the message counts both organizations and screens', function () {
    $response = $this->actingAs($this->admin)->putJson('/network-ads/organizations', [
        'organization_ids' => [$this->organization->id, $this->other->id], 'accepts' => true,
    ])->assertOk();

    expect($response->json('message'))->toContain('2 organizations')->toContain('3 screens');
    expect(Organization::whereIn('id', [$this->organization->id, $this->other->id])
        ->where('accepts_network_ads', false)->count())->toBe(0);
    expect(Screen::where('accepts_network_ads', false)->count())->toBe(0);
});

test('an organization with no televisions yet is still switched, and says so', function () {
    $empty = Organization::factory()->create();

    $response = $this->actingAs($this->admin)
        ->putJson('/network-ads/organizations', ['organization_ids' => [$empty->id], 'accepts' => true])
        ->assertOk();

    expect($empty->fresh()->accepts_network_ads)->toBeTrue();
    expect($response->json('message'))->toContain('0 screens');
});

test('ids that match no organization are refused rather than silently doing nothing', function () {
    $this->actingAs($this->admin)
        ->putJson('/network-ads/organizations', ['organization_ids' => [999999], 'accepts' => true])
        ->assertStatus(422)->assertJsonValidationErrors('organization_ids');
});

test('an empty list is refused', function () {
    $this->actingAs($this->admin)
        ->putJson('/network-ads/organizations', ['organization_ids' => [], 'accepts' => true])
        ->assertStatus(422)->assertJsonValidationErrors('organization_ids');
});

test('the switch is written to the activity log', function () {
    $this->actingAs($this->admin)
        ->putJson('/network-ads/organizations', ['organization_ids' => [$this->organization->id], 'accepts' => true])
        ->assertOk();

    $entry = ActivityLog::where('action', 'organization.network_ads_updated')->latest('id')->first();

    expect($entry)->not->toBeNull();
    expect($entry->description)->toContain('Enabled')->toContain('1 organization')->toContain('2 screens');
});

/*
|--------------------------------------------------------------------------
| What the listing itself shows
|--------------------------------------------------------------------------
*/

test('the bulk switch and the adverts column are on the page for a super admin', function () {
    $this->actingAs($this->admin)->get('/organizations')->assertOk()
        ->assertSee('Network advertising')
        ->assertSee('organizations-ads-on', false)
        ->assertSee('select-all-organizations', false);
});

test('and are absent for everybody else', function () {
    // Platform support can read the organizations list; the advertising deal is still not theirs.
    $keeper = createPlatformUser(['organization-view']);

    $this->actingAs($keeper)
        ->get('/organizations')->assertOk()
        ->assertDontSee('Network advertising')
        ->assertDontSee('organizations-ads-on', false)
        ->assertDontSee('select-all-organizations', false);
});

test('the listing carries the screen counts a super admin needs, and nobody else pays for them', function () {
    $this->counter->update(['accepts_network_ads' => true]);

    $mine = $this->actingAs($this->admin)->getJson('/organizations/data')->assertOk()
        ->json('organizations');
    $row = collect($mine)->firstWhere('id', $this->organization->id);

    expect($row['screens_count'])->toBe(2);
    expect($row['ad_screens_count'])->toBe(1);

    $keeper = createPlatformUser(['organization-view']);
    $theirs = $this->actingAs($keeper)->getJson('/organizations/data')->assertOk()->json('organizations');

    expect($theirs[0])->not->toHaveKey('screens_count');
});
