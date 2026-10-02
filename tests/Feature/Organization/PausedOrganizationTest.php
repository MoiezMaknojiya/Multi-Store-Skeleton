<?php

use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Role;
use App\Models\Screen;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| A paused organization (owner, 2026-09-30: the platform's Active switch, made to mean something)
|--------------------------------------------------------------------------
|
| Off, the organization is closed to its own people: the dashboard says why, every page of it leads there and every
| request a page makes is refused with the same words — while switching to another of their organizations, leaving
| it and their own profile stay open. The platform still looks after it and turns it back on. Its screens keep
| playing.
|
*/

beforeEach(function () {
    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart', 'is_active' => false]);
    $this->owner = createOrganizationMember($this->organization);
});

/** The person, working in the organization (a fresh instance, so no memo from an earlier request speaks for this one). */
function workingIn(User $user, Organization $organization)
{
    return test()->actingAs($user->fresh())->withSession(['current_organization_id' => $organization->id]);
}

test('a paused organization\'s people land on a page that says so, and the menu offers none of its pages', function () {
    $page = workingIn($this->owner, $this->organization)->get('/dashboard')->assertOk();

    $page->assertSee('dusk="dashboard-paused"', false)
        ->assertSee('Alpha Mart is paused')
        ->assertSee('its screens keep playing')
        ->assertDontSee('Choose Another Organization')
        ->assertDontSee('href="'.route('screens.view').'"', false)
        ->assertDontSee('href="'.route('media.view').'"', false)
        ->assertDontSee('href="'.route('members.view').'"', false);

    // The role they hold there grants nothing while it is paused.
    session(['current_organization_id' => $this->organization->id]);
    expect($this->owner->fresh()->contextPermissionNames())->toBe([])
        ->and($this->owner->fresh()->pausedOrganization()?->is($this->organization))->toBeTrue();
});

test('every page of a paused organization leads to the dashboard, and every request a page makes is refused with its words', function () {
    foreach (['/screens', '/media', '/members', '/roles', '/builder', '/builder/assets', '/channels', '/activity', '/settings/organization'] as $page) {
        workingIn($this->owner, $this->organization)->get($page)->assertRedirect(route('dashboard'));
    }

    $words = 'Alpha Mart is paused. Contact support to turn it back on.';

    workingIn($this->owner, $this->organization)->getJson('/media/data')->assertForbidden()->assertJsonPath('message', $words);
    workingIn($this->owner, $this->organization)->getJson('/screens/data')->assertForbidden()->assertJsonPath('message', $words);
    workingIn($this->owner, $this->organization)->postJson('/members/invitations', ['email' => 'new@example.com', 'role_id' => Role::owner()->id])
        ->assertForbidden()->assertJsonPath('message', $words);

    // A plain form is not saved either: the organization keeps its name.
    workingIn($this->owner, $this->organization)->put('/settings/organization', ['name' => 'Renamed'])->assertRedirect(route('dashboard'));
    expect($this->organization->fresh()->name)->toBe('Alpha Mart');

    // An upload is refused before a byte is sent.
    workingIn($this->owner, $this->organization)
        ->withHeaders(['Tus-Resumable' => '1.0.0', 'Upload-Length' => '10', 'Accept' => 'application/json'])
        ->post('/uploads')
        ->assertForbidden()->assertJsonPath('message', $words);
});

test('another organization of theirs, leaving the paused one and their profile stay open', function () {
    $beta = Organization::factory()->create(['name' => 'Beta Mart']);
    $staff = createOrganizationMember($this->organization, Role::STAFF);
    $staff->organizations()->attach($beta->id, ['role_id' => Role::owner()->id]);

    // Several organizations: the paused one's page points to the others.
    workingIn($staff, $this->organization)->get('/dashboard')->assertOk()->assertSee('Choose Another Organization');

    workingIn($staff, $this->organization)->post('/organizations/switch', ['organization_id' => $beta->id])->assertRedirect(route('dashboard'));
    workingIn($staff, $beta)->get('/screens')->assertOk();

    workingIn($staff, $this->organization)->get('/profile')->assertOk();
    workingIn($staff, $this->organization)->delete("/profile/organizations/{$this->organization->id}")->assertRedirect();

    expect($staff->fresh()->organizations()->pluck('organizations.id')->all())->toBe([$beta->id]);
});

test('a paused organization\'s screens keep playing', function () {
    $screen = Screen::factory()->withToken('tok')->create(['organization_id' => $this->organization->id]);
    $poster = Media::factory()->create(['organization_id' => $this->organization->id]);
    PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $poster->id, 'position' => 0, 'duration_seconds' => 10]);

    $manifest = $this->withHeader('Authorization', 'Bearer tok')->getJson('/device/playlist')->assertOk()->json();

    expect(collect($manifest['items'])->pluck('id'))->toContain($poster->id);
});

test('the platform still looks after a paused organization, and turning it back on opens it to its people again', function () {
    $admin = createSuperAdmin();

    $row = collect($this->actingAs($admin)->getJson('/organizations/data')->assertOk()->json('organizations'))->firstWhere('id', $this->organization->id);
    expect($row['is_active'])->toBeFalse();

    // Its library, from above the organizations.
    $this->actingAs($admin)->getJson("/media/data?library={$this->organization->id}")->assertOk();

    $this->actingAs($admin)->putJson("/organizations/{$this->organization->id}", [
        'name' => 'Alpha Mart', 'street' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'zip_code' => '78701',
        'country' => 'USA', 'is_active' => true,
    ])->assertOk()->assertJsonPath('message', 'Alpha Mart is active again.');

    workingIn($this->owner, $this->organization)->get('/screens')->assertOk();
    workingIn($this->owner, $this->organization)->get('/dashboard')->assertOk()->assertDontSee('dusk="dashboard-paused"', false);
});

test('pausing and turning back on are logged as what they are', function () {
    $this->organization->update(['is_active' => true]);
    $admin = createSuperAdmin();
    $details = ['name' => 'Alpha Mart', 'street' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'zip_code' => '78701', 'country' => 'USA'];

    $this->actingAs($admin)->putJson("/organizations/{$this->organization->id}", [...$details, 'is_active' => false])
        ->assertOk()->assertJsonPath('message', 'Alpha Mart is paused.');
    $this->actingAs($admin)->putJson("/organizations/{$this->organization->id}", [...$details, 'is_active' => true])
        ->assertOk()->assertJsonPath('message', 'Alpha Mart is active again.');
    $this->actingAs($admin)->putJson("/organizations/{$this->organization->id}", [...$details, 'name' => 'Alpha Market', 'is_active' => true])
        ->assertOk()->assertJsonPath('message', 'Organization Alpha Market updated.');

    expect(ActivityLog::where('organization_id', $this->organization->id)->orderBy('id')->pluck('action')->all())
        ->toBe(['organization.paused', 'organization.resumed', 'organization.updated']);
});

test('the Organizations page says Paused, and the switch says what it does', function () {
    $this->actingAs(createSuperAdmin())->get('/organizations')->assertOk()
        ->assertSee('<span x-show="!item.is_active" class="badge-neutral">Paused</span>', false)
        ->assertSee('Off pauses the organization: its people cannot open it, and its screens keep playing.');
});
