<?php

use App\Models\Organization;
use App\Models\Screen;

/*
|--------------------------------------------------------------------------
| The switch for a turned Fire TV's own video decoder
|--------------------------------------------------------------------------
|
| A Fire TV's WebView draws a <video> on a hardware plane that no CSS turn reaches, so on a screen mounted another
| way the player plays its videos through its own decoder instead (docs/TV-ORIENTATION-SPEC.md §11, §12). Whether it
| may is the server's to say, on every playlist answer: turned off, every set is back to <video> on its next poll,
| with no deploy and no reload.
|
*/

beforeEach(function () {
    $this->screen = Screen::factory()->withToken('switch-tok')->create([
        'organization_id' => Organization::factory()->create()->id,
        'orientation' => 'portrait',
    ]);
});

it('tells every set whether a turned Fire TV decodes its videos itself, on unless it is turned off', function () {
    // On by default since 2026-10-28, on the owner's word; SIGNAGE_DECODE_TURNED_VIDEO=false turns it off.
    expect(config('signage.decode_turned_video'))->toBeTrue();

    $this->withHeader('Authorization', 'Bearer switch-tok')->getJson('/device/playlist')
        ->assertOk()
        ->assertJsonPath('screen.decode_turned_video', true)
        ->assertJsonPath('screen.orientation', 'portrait');

    config(['signage.decode_turned_video' => false]);

    $this->withHeader('Authorization', 'Bearer switch-tok')->getJson('/device/playlist')
        ->assertOk()
        ->assertJsonPath('screen.decode_turned_video', false);
});
