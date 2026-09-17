<?php

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\Store;

/*
|--------------------------------------------------------------------------
| Leaving a store from the profile (docs/STORE-ORGANIZATION-SPEC.md rule 10)
|--------------------------------------------------------------------------
|
| Any member may leave — Staff and Viewers too, who have no Members page to find the button on. "Your stores"
| sits on Settings → Stores for whoever has that tab (View Stores where they work), and on the profile for
| everybody else (owner's rules, 2026-09-17).
|
*/

beforeEach(function () {
    $this->alpha = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->beta = Store::factory()->create(['name' => "Joe's Diner"]);
});

test('the profile lists every store the person belongs to, with their role in each', function () {
    $member = createStoreMember($this->alpha, Role::VIEWER);
    $member->stores()->attach($this->beta->id, ['role_id' => Role::starter(Role::STAFF)->id]);

    $this->actingAs($member)->get('/profile')->assertOk()
        ->assertSee('Your stores')
        ->assertSee('Alpha Mart')
        ->assertSee("Joe's Diner")
        ->assertSee('Viewer')
        ->assertSee('Staff');
});

test('a Viewer leaves a store from the profile, and it drops out of their session', function () {
    $viewer = createStoreMember($this->alpha, Role::VIEWER);
    $viewer->stores()->attach($this->beta->id, ['role_id' => Role::starter(Role::STAFF)->id]);

    $this->actingAs($viewer)->withSession(['current_store_id' => $this->beta->id])
        ->delete("/profile/stores/{$this->beta->id}")
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('status', "You left Joe's Diner.");

    expect(roleKeyIn($viewer, $this->beta))->toBeNull()
        ->and(roleKeyIn($viewer, $this->alpha))->toBe(Role::VIEWER)
        ->and(session('current_store_id'))->toBeNull()
        ->and(ActivityLog::where('action', 'member.left')->where('subject_id', $this->beta->id)->exists())->toBeTrue();

    // A name with a quote in it must not break the page the flash comes back to.
    $this->get('/profile')->assertOk()->assertSee("passwordForm('You left Joe\u0027s Diner.')", false);
});

test('the last Owner stays; an Owner with a co-owner may go', function () {
    $owner = createStoreMember($this->alpha, Role::OWNER);

    $this->actingAs($owner)->from('/profile')->delete("/profile/stores/{$this->alpha->id}")
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasErrorsIn('storeMembership', ['store' => 'You are the only Owner of Alpha Mart. Make someone else an Owner before you leave.']);
    expect(roleKeyIn($owner, $this->alpha))->toBe(Role::OWNER);

    $this->get('/profile')->assertSee('You are its only Owner — make someone else an Owner before you leave.');

    $coOwner = createStoreMember($this->alpha, Role::OWNER);
    $this->actingAs($owner)->delete("/profile/stores/{$this->alpha->id}")->assertSessionHasNoErrors();
    expect(roleKeyIn($owner, $this->alpha))->toBeNull()
        ->and(roleKeyIn($coOwner, $this->alpha))->toBe(Role::OWNER);
});

test('a store you are not in answers 404, and guests are sent to sign in', function () {
    $member = createStoreMember($this->alpha, Role::STAFF);

    $this->actingAs($member)->delete("/profile/stores/{$this->beta->id}")->assertNotFound();

    auth()->logout();
    $this->delete("/profile/stores/{$this->alpha->id}")->assertRedirect(route('login'));
});

test('the platform team has no stores section', function () {
    $this->actingAs(createSuperAdmin())->get('/profile')->assertOk()->assertDontSee('Your stores');
});
