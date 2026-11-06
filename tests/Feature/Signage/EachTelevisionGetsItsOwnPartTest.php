<?php

/**
 * A change made for one kind of television never reaches another (owner, 2026-11-06: "Tizen ko fix karo toh Android
 * aur Firestick toot jayein ... aisa nahi hona chahiye", and no code twice). One player plays on every set; what only
 * one kind needs is in a chunk of its own that the player loads there alone, and the page draws its part there alone:
 * a Samsung TV's (player/samsung.js, by Tizen in its user agent) and a turned Fire TV's decoder (player/decoded-video.js,
 * by AFT). These hold that line where the server draws the page and in what the build sends.
 */
const SAMSUNG_TV = 'Mozilla/5.0 (SMART-TV; LINUX; Tizen 6.0) AppleWebKit/537.36 (KHTML, like Gecko) 76.0.3809.146/6.0 TV Safari/537.36';
const FIRE_TV = 'Mozilla/5.0 (Linux; Android 11; AFTKA Build/RS8150.2953N; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/130.0.6723.102 Safari/537.36';
const ANDROID_TV = 'Mozilla/5.0 (Linux; Android 9; 55BDL4511D Build/PPR1.180610.011; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/79.0.3945.136 Safari/537.36';
const DESK = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36';

/** @return array<string, array{file: string, isDynamicEntry?: bool, dynamicImports?: list<string>, imports?: list<string>}> */
function playerManifest(): array
{
    return json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
}

function playerPageFor(string $userAgent): string
{
    return test()->withHeader('User-Agent', $userAgent)->get('/player')->assertOk()->getContent();
}

it('draws the question, its look and the Samsung part on a Samsung TV page alone', function () {
    $samsung = playerManifest()['resources/js/player/samsung.js']['file'];

    expect(playerPageFor(SAMSUNG_TV))
        ->toContain('id="exit-question"')
        ->toContain('.exit-question-box')
        ->toContain('.media-layer iframe { pointer-events: none; }')
        ->toContain('<link rel="modulepreload" href="'.asset('build/'.$samsung).'">');

    foreach ([FIRE_TV, ANDROID_TV, DESK] as $userAgent) {
        expect(playerPageFor($userAgent))
            ->not->toContain('exit-question')
            ->not->toContain('pointer-events')
            ->not->toContain($samsung);
    }
});

it('preloads the Fire TV decoder on a Fire TV page alone, never on a Samsung TV', function () {
    $decoder = playerManifest()['resources/js/player/decoded-video.js']['file'];

    expect(playerPageFor(FIRE_TV))->toContain($decoder);
    foreach ([SAMSUNG_TV, ANDROID_TV, DESK] as $userAgent) {
        expect(playerPageFor($userAgent))->not->toContain($decoder);
    }
});

it('sends every television the same player, the Samsung part only as a chunk it loads on a Samsung TV', function () {
    $manifest = playerManifest();
    $player = $manifest['resources/js/player.js'];
    $samsung = $manifest['resources/js/player/samsung.js'];

    expect($player['dynamicImports'] ?? [])->toContain('resources/js/player/samsung.js')
        ->and($player['imports'] ?? [])->not->toContain('resources/js/player/samsung.js')
        ->and($samsung['isDynamicEntry'] ?? false)->toBeTrue();

    // What is the Samsung TV's own is in its chunk, not in what every set runs.
    $built = (string) file_get_contents(public_path('build/'.$player['file']));
    expect($built)->not->toContain('exit-question')->not->toContain('XF86Back')->not->toContain('location.replace(');
    expect((string) file_get_contents(public_path('build/'.$samsung['file'])))
        ->toContain('exit-question')->toContain('XF86Back')->toContain('location.replace(');

    // And the source asks the user agent before it imports it, never importing it outright.
    $source = (string) file_get_contents(base_path('resources/js/player.js'));
    expect($source)
        ->toContain('const ON_A_SAMSUNG_TV = /\bTizen\b/i.test(navigator.userAgent);')
        ->toContain("if (ON_A_SAMSUNG_TV) {\n    // Its RETURN asks before it closes the app, and it empties a page's frame its own way (player/samsung.js).\n    import('./player/samsung.js')")
        ->not->toMatch('~^\s*import\s[^(]*(samsung|exit-question)~m');
});
