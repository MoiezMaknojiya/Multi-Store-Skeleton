<?php

use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\Role;

/*
|--------------------------------------------------------------------------
| Leaving an organization from the profile (docs/ORGANIZATION-SPEC.md rule 10)
|--------------------------------------------------------------------------
|
| Any member may leave — Staff and Viewers too, who have no Members page to find the button on. "Your organizations"
| sits on Settings → Organizations for whoever has that tab (View Organizations where they work), and on the profile for
| everybody else (owner's rules, 2026-09-17).
|
*/

beforeEach(function () {
    $this->alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->beta = Organization::factory()->create(['name' => "Joe's Diner"]);
});

test('the profile lists every organization the person belongs to, with their role in each', function () {
    $member = createOrganizationMember($this->alpha, Role::VIEWER);
    $member->organizations()->attach($this->beta->id, ['role_id' => Role::starter(Role::STAFF)->id]);

    $this->actingAs($member)->get('/profile')->assertOk()
        ->assertSee('Your organizations')
        ->assertSee('Alpha Mart')
        ->assertSee("Joe's Diner")
        ->assertSee('Viewer')
        ->assertSee('Staff');
});

test('a Viewer leaves an organization from the profile, and it drops out of their session', function () {
    $viewer = createOrganizationMember($this->alpha, Role::VIEWER);
    $viewer->organizations()->attach($this->beta->id, ['role_id' => Role::starter(Role::STAFF)->id]);

    $this->actingAs($viewer)->withSession(['current_organization_id' => $this->beta->id])
        ->delete("/profile/organizations/{$this->beta->id}")
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('status', "You left Joe's Diner.");

    expect(roleKeyIn($viewer, $this->beta))->toBeNull()
        ->and(roleKeyIn($viewer, $this->alpha))->toBe(Role::VIEWER)
        ->and(session('current_organization_id'))->toBeNull()
        ->and(ActivityLog::where('action', 'member.left')->where('subject_id', $this->beta->id)->exists())->toBeTrue();

    // A name with a quote in it must not break the page the flash comes back to: the toast says it, escaped.
    $this->get('/profile')->assertOk()->assertSee("window.toast('You left Joe\u0027s Diner.', 'success')", false);
});

test('the last Owner stays; an Owner with a co-owner may go', function () {
    $owner = createOrganizationMember($this->alpha, Role::OWNER);

    $this->actingAs($owner)->from('/profile')->delete("/profile/organizations/{$this->alpha->id}")
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasErrorsIn('organizationMembership', ['organization' => 'You are the only Owner of Alpha Mart. Make someone else an Owner before you leave.']);
    expect(roleKeyIn($owner, $this->alpha))->toBe(Role::OWNER);

    $this->get('/profile')->assertSee('You are its only Owner — make someone else an Owner before you leave.');

    $coOwner = createOrganizationMember($this->alpha, Role::OWNER);
    $this->actingAs($owner)->delete("/profile/organizations/{$this->alpha->id}")->assertSessionHasNoErrors();
    expect(roleKeyIn($owner, $this->alpha))->toBeNull()
        ->and(roleKeyIn($coOwner, $this->alpha))->toBe(Role::OWNER);
});

test('an organization you are not in answers 404, and guests are sent to sign in', function () {
    $member = createOrganizationMember($this->alpha, Role::STAFF);

    $this->actingAs($member)->delete("/profile/organizations/{$this->beta->id}")->assertNotFound();

    auth()->logout();
    $this->delete("/profile/organizations/{$this->alpha->id}")->assertRedirect(route('login'));
});

test('the platform team has no organizations section', function () {
    $this->actingAs(createSuperAdmin())->get('/profile')->assertOk()->assertDontSee('Your organizations');
});
