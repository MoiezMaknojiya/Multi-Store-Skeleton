<?php

use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\Screen;

/*
|--------------------------------------------------------------------------
| A screen's Transition: None or Fade
|--------------------------------------------------------------------------
|
| How a television changes from one item to the next (owner, 2026-11-02 — "fade setting mein rakho"). Either way the
| player makes the next item ready behind the one on screen first; None is then a clean cut, Fade a half-second dissolve
| over it. Set on the screen's Edit dialog, sent on every playlist answer.
|
*/

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->screen = Screen::factory()->withToken('transition-tok')->create([
        'organization_id' => $this->organization->id, 'name' => 'Counter TV', 'orientation' => 'landscape',
    ]);
    $this->owner = createOrganizationMember($this->organization);
    $this->actingAs($this->owner)->withSession(['current_organization_id' => $this->organization->id]);
});

function transitionOnTheSet(): string
{
    return test()->withHeader('Authorization', 'Bearer transition-tok')->getJson('/device/playlist')
        ->assertOk()->json('screen.transition');
}

it('starts every screen with None, a screen paired before included', function () {
    expect($this->screen->fresh()->transition)->toBe('none')
        ->and(transitionOnTheSet())->toBe('none');
});

it('is changed on the screen and reaches the television on its next answer, and logged', function () {
    $this->putJson("/screens/{$this->screen->id}", [
        'name' => 'Counter TV', 'orientation' => 'landscape', 'transition' => 'fade',
    ])->assertOk()->assertJsonPath('screen.transition', 'fade');

    expect($this->screen->fresh()->transition)->toBe('fade')
        ->and(transitionOnTheSet())->toBe('fade')
        ->and(ActivityLog::where('action', 'screen.updated')->where('subject_id', $this->screen->id)->exists())->toBeTrue();

    $this->putJson("/screens/{$this->screen->id}", [
        'name' => 'Counter TV', 'orientation' => 'landscape', 'transition' => 'none',
    ])->assertOk();

    expect(transitionOnTheSet())->toBe('none');
});

it('is left as it was by a save that does not send it', function () {
    $this->screen->update(['transition' => 'fade']);

    $this->putJson("/screens/{$this->screen->id}", ['name' => 'Front TV', 'orientation' => 'landscape'])->assertOk();

    expect($this->screen->fresh())->name->toBe('Front TV')->transition->toBe('fade');
});

it('refuses anything but None or Fade, in the form\'s words', function (mixed $sent) {
    $this->putJson("/screens/{$this->screen->id}", [
        'name' => 'Counter TV', 'orientation' => 'landscape', 'transition' => $sent,
    ])->assertStatus(422)->assertJsonPath('errors.transition.0', 'Choose None or Fade.');

    expect($this->screen->fresh()->transition)->toBe('none');
})->with([
    'another effect' => 'slide',
    'nothing' => '',
    'a list' => [['fade']],
    'a number' => 1,
    'its label' => 'Fade',
]);

it('offers both on the Screens page, in the Edit dialog', function () {
    $this->get('/screens')->assertOk()
        ->assertSee('dusk="screen-edit-transition"', false)
        ->assertSee('<option value="none">None</option>', false)
        ->assertSee('<option value="fade">Fade</option>', false);
});
