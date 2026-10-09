<?php

use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\Organization;

/*
|--------------------------------------------------------------------------
| A tab left open while the session changed in another (QA round, 2026-10-09)
|--------------------------------------------------------------------------
|
| Every signed-in page says whose it is (`<meta name="session-context">`, "{user}:{organization}"), and sends it back with each
| request: axios and the uploader as `X-Session-Context`, a plain form as `_context`. A request from a page of another person or
| another organization is refused before it does anything (RefuseAStaleTab). Before this, a rename in a tab still showing Smart Stop
| ran in Alpha Mart (an empty 404), and the super admin's own profile form, sent after Log In As in another tab, was taken as the
| person viewed.
|
*/

beforeEach(function () {
    $this->smart = Organization::factory()->create(['name' => 'Smart Stop']);
    $this->alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->person = createOrganizationUser($this->smart, ['media-view', 'media-update'], 'Designer');
    $this->person->organizations()->attach($this->alpha->id, ['role_id' => $this->person->organizations()->first()->pivot->role_id]);
    $this->file = Media::factory()->create(['organization_id' => $this->smart->id, 'title' => 'Texas Toast']);
});

test('every signed-in page says whose it is, and a guest page says nothing', function () {
    $this->get('/login')->assertDontSee('name="session-context"', false);

    $this->actingAs($this->person)->withSession(['current_organization_id' => $this->smart->id])->get('/media')
        ->assertOk()->assertSee('<meta name="session-context" content="'.$this->person->id.':'.$this->smart->id.'">', false);
});

test('a request from a page of another organization is refused in words, and nothing is changed', function () {
    // The page was drawn in Smart Stop; another tab has since switched the session to Alpha Mart.
    $this->actingAs($this->person)->withSession(['current_organization_id' => $this->alpha->id])
        ->putJson("/media/{$this->file->id}", ['title' => 'Renamed'], ['X-Session-Context' => "{$this->person->id}:{$this->smart->id}"])
        ->assertStatus(409)
        ->assertJson(['stale_tab' => true, 'message' => 'This page is out of date: you switched to Alpha Mart in another tab. Nothing was changed. The page is reloaded.']);

    expect($this->file->fresh()->title)->toBe('Texas Toast')
        ->and(ActivityLog::where('action', 'media.renamed')->exists())->toBeFalse();
});

test('a request from a page of another person is refused, whoever the session now is', function () {
    $admin = createSuperAdmin();

    $this->actingAs($this->person)->withSession(['current_organization_id' => $this->smart->id])
        ->putJson("/media/{$this->file->id}", ['title' => 'Renamed'], ['X-Session-Context' => "{$admin->id}:0"])
        ->assertStatus(409)
        ->assertJsonPath('message', "This page is out of date: this browser is now signed in as {$this->person->name}. Nothing was changed. The page is reloaded.");

    expect($this->file->fresh()->title)->toBe('Texas Toast');
});

test('a plain form from a stale page is sent back to its page with the words, and nothing is saved', function () {
    $admin = createSuperAdmin();
    $before = $this->person->only(['first_name', 'last_name', 'email']);

    // The super admin's profile form, sent after Log In As in another tab made the session this person.
    $this->actingAs($this->person)->withSession(['current_organization_id' => $this->smart->id])
        ->from('/profile')
        ->patch('/profile', ['_context' => "{$admin->id}:0", 'first_name' => 'Hacked', 'last_name' => 'Admin', 'phone' => '0000000000', 'email' => $admin->email])
        ->assertRedirect('/profile')
        ->assertSessionHas('stale_tab', "This page is out of date: this browser is now signed in as {$this->person->name}. Nothing was changed. The page is reloaded.");

    expect($this->person->fresh()->only(['first_name', 'last_name', 'email']))->toBe($before);
});

test('the page as it is goes through, and so does a request that says nothing', function () {
    $session = ['current_organization_id' => $this->smart->id];

    $this->actingAs($this->person)->withSession($session)
        ->putJson("/media/{$this->file->id}", ['title' => 'Renamed'], ['X-Session-Context' => "{$this->person->id}:{$this->smart->id}"])
        ->assertOk();
    $this->actingAs($this->person)->withSession($session)->putJson("/media/{$this->file->id}", ['title' => 'Again'])->assertOk();

    expect($this->file->fresh()->title)->toBe('Again');
});

test('what changes the session on purpose is sent from the page it leaves, and is not refused', function () {
    // Switching organization from a page drawn in Smart Stop.
    $this->actingAs($this->person)->withSession(['current_organization_id' => $this->smart->id])
        ->post('/organizations/switch', ['organization_id' => $this->alpha->id, '_context' => "{$this->person->id}:{$this->smart->id}"])
        ->assertRedirect()->assertSessionHas('current_organization_id', $this->alpha->id);

    // And signing out from a page left over from before.
    $this->actingAs($this->person)->withSession(['current_organization_id' => $this->alpha->id])
        ->post('/logout', ['_context' => "{$this->person->id}:{$this->smart->id}"])->assertRedirect();
    $this->assertGuest();
});
