<?php

use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\Screen;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Every screen fades, and nobody chooses it
|--------------------------------------------------------------------------
|
| How a television changes from one item to the next (owner, 2026-11-04 — "by default sub per fade hi chiya tha aur woo
| field hata deni thi"): the player makes the next item ready behind the one on screen and fades it in over it, on every
| screen. There is no Transition field in the Edit Screen dialog and no column for one. The playlist answer still says
| `fade`, for a player loaded before, which fades only when told.
|
*/

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->screen = Screen::factory()->withToken('fade-tok')->create([
        'organization_id' => $this->organization->id, 'name' => 'Counter TV', 'orientation' => 'landscape',
    ]);
    $this->owner = createOrganizationMember($this->organization);
    $this->actingAs($this->owner)->withSession(['current_organization_id' => $this->organization->id]);
});

function transitionOnTheSet(string $token = 'fade-tok'): mixed
{
    return test()->withHeader('Authorization', "Bearer {$token}")->getJson('/device/playlist')
        ->assertOk()->json('screen.transition');
}

it('tells every television to fade, a screen paired now or before', function () {
    Screen::factory()->withToken('second-tok')->create(['organization_id' => $this->organization->id]);

    expect(transitionOnTheSet())->toBe('fade')
        ->and(transitionOnTheSet('second-tok'))->toBe('fade');
});

it('keeps no Transition for a screen', function () {
    expect(Schema::hasColumn('screens', 'transition'))->toBeFalse()
        ->and($this->screen->fresh()->toArray())->not->toHaveKey('transition');
});

it('offers no Transition in the Edit Screen dialog', function () {
    $this->get('/screens')->assertOk()
        ->assertSee('dusk="screen-edit-timezone"', false)
        ->assertDontSee('screen-edit-transition', false)
        ->assertDontSee('How the screen changes from one item to the next.')
        ->assertDontSee('<option value="none">None</option>', false);
});

it('saves a screen as before, and lets a transition posted by hand change nothing', function (mixed $sent) {
    $this->putJson("/screens/{$this->screen->id}", [
        'name' => 'Front TV', 'orientation' => 'landscape', 'transition' => $sent,
    ])->assertOk()->assertJsonPath('screen.name', 'Front TV')->assertJsonMissingPath('screen.transition');

    expect($this->screen->fresh()->name)->toBe('Front TV')
        ->and(transitionOnTheSet())->toBe('fade')
        ->and(ActivityLog::where('action', 'screen.updated')->where('subject_id', $this->screen->id)->exists())->toBeTrue();
})->with([
    'None' => 'none',
    'another effect' => 'slide',
    'a list' => [['none']],
]);

it('takes the column away, and taken back gives it again with Fade for every screen', function () {
    $migration = require database_path('migrations/2026_11_04_100000_drop_transition_from_screens_table.php');

    $migration->down();

    expect(Schema::hasColumn('screens', 'transition'))->toBeTrue()
        ->and(DB::table('screens')->where('id', $this->screen->id)->value('transition'))->toBe('fade');
    $migration->down();

    $migration->up();

    expect(Schema::hasColumn('screens', 'transition'))->toBeFalse()
        ->and($this->screen->fresh()->name)->toBe('Counter TV');
    $migration->up();

    expect(Schema::hasColumn('screens', 'transition'))->toBeFalse();
});
