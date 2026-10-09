<?php

use App\Models\Organization;
use App\Models\Role;
use App\Models\Screen;

/*
|--------------------------------------------------------------------------
| Working in an organization from the first request (QA round, 2026-10-09)
|--------------------------------------------------------------------------
|
| Only the dashboard used to choose a lone organization, so an Owner who signed in from a link and landed straight on a screen
| was told "Your role does not allow it". ChooseTheOnlyOrganization chooses it on every request, and forgets one the person is no
| longer in. Somebody with several is sent to the chooser instead of the refusal, and choosing returns to the page.
|
*/

beforeEach(function () {
    $this->alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->screen = Screen::factory()->create(['organization_id' => $this->alpha->id, 'name' => 'Front Counter TV']);
});

test('an Owner of one organization opens a screen from a link straight after signing in', function () {
    $owner = createOrganizationUser($this->alpha, ['screen-view'], 'Owner Like');

    $this->actingAs($owner)->get("/screens/{$this->screen->id}")
        ->assertOk()->assertSee('Front Counter TV')->assertSessionHas('current_organization_id', $this->alpha->id);
});

test('somebody with several organizations is sent to choose, and choosing returns to the page they asked for', function () {
    $smart = Organization::factory()->create(['name' => 'Smart Stop']);
    $person = createOrganizationUser($this->alpha, ['screen-view'], 'Looks At Screens');
    $person->organizations()->attach($smart->id, ['role_id' => Role::owner()->id]);

    $this->actingAs($person)->get("/screens/{$this->screen->id}")->assertRedirect('/select-organization');

    $this->actingAs($person)->post('/organizations/switch', ['organization_id' => $this->alpha->id])
        ->assertRedirect("/screens/{$this->screen->id}");
    $this->actingAs($person)->get("/screens/{$this->screen->id}")->assertOk();
});

test('an organization the person was taken out of is forgotten, and the one left is theirs', function () {
    $smart = Organization::factory()->create(['name' => 'Smart Stop']);
    $person = createOrganizationUser($this->alpha, ['screen-view'], 'Looks At Screens');

    // The session still names Smart Stop, which they are no longer in.
    $this->actingAs($person)->withSession(['current_organization_id' => $smart->id])->get("/screens/{$this->screen->id}")
        ->assertOk()->assertSessionHas('current_organization_id', $this->alpha->id);
});

test('the platform team is never put in an organization', function () {
    $admin = createSuperAdmin();

    $this->actingAs($admin)->get('/dashboard')->assertOk()->assertSessionMissing('current_organization_id');
});
