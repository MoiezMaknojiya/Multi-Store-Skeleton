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
| player makes the next item ready behind the one on screen first; Fade is then a half-second dissolve over it, None a
| clean cut. Fade is every screen's own (2026-11-03 — "isay default mein hi laga do"); None is chosen on the screen's Edit
| dialog. Sent on every playlist answer.
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

it('starts every screen with Fade', function () {
    expect($this->screen->fresh()->transition)->toBe('fade')
        ->and(transitionOnTheSet())->toBe('fade');
});

it('gives Fade to every screen there was, and keeps a screen chosen Fade by hand when it is taken back', function () {
    $migration = require database_path('migrations/2026_11_03_200000_make_fade_every_screens_transition.php');
    $migration->down();
    $before = Screen::factory()->create(['organization_id' => $this->organization->id]);
    expect($before->fresh()->transition)->toBe('none');
    $this->screen->update(['transition' => 'fade']);

    $migration->up();

    expect($before->fresh()->transition)->toBe('fade')
        ->and(Screen::factory()->create(['organization_id' => $this->organization->id])->fresh()->transition)->toBe('fade');

    $migration->down();

    // A new screen is None again; the ones there are keep what they have.
    expect(Screen::factory()->create(['organization_id' => $this->organization->id])->fresh()->transition)->toBe('none')
        ->and($this->screen->fresh()->transition)->toBe('fade');

    $migration->up();
});

it('is changed on the screen and reaches the television on its next answer, and logged', function () {
    $this->putJson("/screens/{$this->screen->id}", [
        'name' => 'Counter TV', 'orientation' => 'landscape', 'transition' => 'none',
    ])->assertOk()->assertJsonPath('screen.transition', 'none');

    expect($this->screen->fresh()->transition)->toBe('none')
        ->and(transitionOnTheSet())->toBe('none')
        ->and(ActivityLog::where('action', 'screen.updated')->where('subject_id', $this->screen->id)->exists())->toBeTrue();

    $this->putJson("/screens/{$this->screen->id}", [
        'name' => 'Counter TV', 'orientation' => 'landscape', 'transition' => 'fade',
    ])->assertOk();

    expect(transitionOnTheSet())->toBe('fade');
});

it('is left as it was by a save that does not send it', function () {
    $this->screen->update(['transition' => 'none']);

    $this->putJson("/screens/{$this->screen->id}", ['name' => 'Front TV', 'orientation' => 'landscape'])->assertOk();

    expect($this->screen->fresh())->name->toBe('Front TV')->transition->toBe('none');
});

it('refuses anything but None or Fade, in the form\'s words', function (mixed $sent) {
    $this->putJson("/screens/{$this->screen->id}", [
        'name' => 'Counter TV', 'orientation' => 'landscape', 'transition' => $sent,
    ])->assertStatus(422)->assertJsonPath('errors.transition.0', 'Choose None or Fade.');

    expect($this->screen->fresh()->transition)->toBe('fade');
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
