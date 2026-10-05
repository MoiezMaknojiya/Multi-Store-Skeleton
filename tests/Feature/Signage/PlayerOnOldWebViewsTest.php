<?php

/*
|--------------------------------------------------------------------------
| The player on an old WebView
|--------------------------------------------------------------------------
|
| An organization's television is whatever it put behind the screen: a Fire TV, a cheap Android box, the Android
| player app on either (com.thedisplaysolution.player; com.digitallifts.player before 2026-10-04) — and their WebViews can be as old as Chrome 80. So the
| player is built for that floor: no stylesheet but its own (the panel's Tailwind v4 needs Chrome 111), no
| CSS the floor lacks (inset is Chrome 87), and no browser API newer than it in anything the television
| runs — the page's script, its worker and the Ad Builder's runtime. Newer syntax Vite writes down to
| chrome80 itself (vite.config.js); an API it cannot. The Android app blames the WebView only below this
| same floor.
|
*/

it('loads no stylesheet but its own', function () {
    $this->get('/player')
        ->assertOk()
        ->assertDontSee('rel="stylesheet"', false)
        ->assertDontSee('/build/assets/app-', false)
        ->assertSee('/build/assets/player-', false);
});

it('hides a hidden view itself, now that no reset does it', function () {
    $this->get('/player')->assertOk()->assertSee('[hidden] { display: none !important; }', false);
});

it('uses no CSS an old WebView ignores', function () {
    $html = $this->get('/player')->assertOk()->getContent();
    preg_match_all('~<style>(.*?)</style>~s', $html, $styles);
    $css = implode("\n", $styles[1]);

    expect($css)->not->toBeEmpty();

    // inset is Chrome 87 and aspect-ratio 88; the rest is what Tailwind v4 is made of (Chrome 99 to 111).
    foreach (['inset:', 'aspect-ratio', '@layer', 'oklch(', 'color-mix(', '@property', ':is(', ':where(', 'dvh', 'svh', 'lvh'] as $feature) {
        expect(str_contains($css, $feature))->toBeFalse("The player's CSS uses {$feature}");
    }
});

it('calls no browser API newer than Chrome 80 in anything the television runs', function (string $file) {
    // Comments may name anything; only the code counts.
    $code = preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', file_get_contents(base_path($file)));

    $newer = [
        '.at(' => 'Array.prototype.at (Chrome 92)',
        'structuredClone(' => 'structuredClone (98)',
        'Object.hasOwn(' => 'Object.hasOwn (93)',
        'AbortSignal.timeout(' => 'AbortSignal.timeout (103)',
        'AbortSignal.any(' => 'AbortSignal.any (116)',
        '.findLast(' => 'findLast (97)',
        '.findLastIndex(' => 'findLastIndex (97)',
        '.toSorted(' => 'toSorted (110)',
        '.toReversed(' => 'toReversed (110)',
        '.toSpliced(' => 'toSpliced (110)',
        '.replaceAll(' => 'replaceAll (85)',
        'Promise.any(' => 'Promise.any (85)',
        'crypto.randomUUID(' => 'crypto.randomUUID (92)',
        'Object.groupBy(' => 'Object.groupBy (117)',
        'Promise.withResolvers(' => 'Promise.withResolvers (119)',
        'Array.fromAsync(' => 'Array.fromAsync (121)',
    ];

    foreach ($newer as $call => $what) {
        expect(str_contains($code, $call))->toBeFalse("{$file} calls {$what}: an old WebView stops there");
    }
})->with([
    'the player' => 'resources/js/player.js',
    'its worker' => 'public/player-sw.js',
    'the Ad Builder runtime' => 'public/ad-runtime/runtime.js',
]);

it('has its script written down to Chrome 80', function () {
    expect(file_get_contents(base_path('vite.config.js')))->toContain("target: ['chrome80'");
});
