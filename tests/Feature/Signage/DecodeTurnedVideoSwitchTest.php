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

it('names the decoder and what it imports on a Fire TV\'s page, so the worker keeps them for a start with no line', function () {
    // The player imports its decoder only when a video needs it, and the worker keeps only the built files the page
    // names: a Fire TV that started with no line had no decoder, and played its videos sideways (2026-10-28).
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $decoder = $manifest['resources/js/player/decoded-video.js'];
    $files = array_merge([$decoder['file']], array_map(fn (string $import): string => $manifest[$import]['file'], $decoder['imports'] ?? []));
    expect($files)->toHaveCount(count(array_unique($files)))->and(count($files))->toBeGreaterThan(0);

    $fireTv = $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 11; AFTKRT Build/RS8148) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.6834.163 Mobile Safari/537.36')
        ->get('/player')->assertOk();
    foreach ($files as $file) {
        $fireTv->assertSee('<link rel="modulepreload" href="'.asset('build/'.$file).'">', false);
    }

    // Any other set never needs it: it is not fetched there, nor kept.
    $other = $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 9; BRAVIA 4K VH2) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/80.0.3987.162 Mobile Safari/537.36')
        ->get('/player')->assertOk();
    $other->assertDontSee($decoder['file'], false);
});
