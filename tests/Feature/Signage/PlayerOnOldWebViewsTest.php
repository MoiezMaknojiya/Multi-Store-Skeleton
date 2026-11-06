<?php

use App\Services\AdCompiler;

/*
|--------------------------------------------------------------------------
| The player on an old WebView
|--------------------------------------------------------------------------
|
| An organization's television is whatever it put behind the screen: a Fire TV, a cheap Android box, the Android
| player app on either (com.thedisplaysolution.player; com.digitallifts.player before 2026-10-04) — and their WebViews can be as old as Chrome 79
| (owner, 2026-11-04: a commercial Android TV carried WebView 79). So the player is built for that floor: no
| stylesheet but its own (the panel's Tailwind v4 needs Chrome 111), no CSS the floor lacks (inset is Chrome 87),
| and no browser API newer than it in anything the television runs — the page's script, its worker and the Ad
| Builder's runtime. Newer syntax Vite writes down to chrome79 itself (vite.config.js); an API it cannot, nor a
| file it never builds: the worker, the runtime and Anime.js, whose one optional chaining (Chrome 80) is written
| out by hand. The Android app blames the WebView only below 80, and only when the player did not start.
|
| And a Samsung TV of 2021 (Tizen 6.0) runs Chromium 76, the Tizen app's floor (owner, 2026-11-05: "2021 minimum
| rakho"). Chrome 77 to 79 brought no new syntax, so the chrome79 build parses there; of what they brought, only CSS's
| clamp(), min() and max() (79) reached the player, and each has a plain value before it, which an engine without them
| keeps: the pairing code was body-text size in Chromium 76.0.3809.0 itself until it had one, and the whole mix played
| there, offline too.
|
*/

/** A script's code without its comments, which may name anything. */
function codeOf(string $script): string
{
    return (string) preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', $script);
}

/** The syntax Chrome 80 brought, which a WebView 79 stops at: `a?.b` and `a ?? b` (`x?.5:1` is a ternary). */
function syntaxNewerThan79(string $code): array
{
    $found = [];
    if (preg_match('~\?\.(?!\d)~', $code, $match, PREG_OFFSET_CAPTURE)) {
        $found[] = 'optional chaining (?.) at "'.substr($code, max(0, $match[0][1] - 30), 60).'"';
    }
    if (preg_match('~\?\?~', $code, $match, PREG_OFFSET_CAPTURE)) {
        $found[] = 'nullish coalescing (??) at "'.substr($code, max(0, $match[0][1] - 30), 60).'"';
    }

    return $found;
}

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

it('gives every clamp(), min() and max() a plain value before it, for a Samsung TV of 2021', function () {
    // Chromium 76 drops a declaration it cannot read and keeps the one before it, in a <style> as in a style="".
    $html = $this->get('/player')->assertOk()->getContent();
    preg_match_all('~<style>(.*?)</style>~s', $html, $styles);
    preg_match_all('~\sstyle="([^"]*)"~', $html, $inline);
    $checked = 0;

    // Each <style> and each style="" on its own, its comments taken out.
    foreach (array_merge($styles[1], $inline[1]) as $source) {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $source);
        preg_match_all('~([a-z-]+)\s*:\s*(?:clamp|min|max)\(~', $css, $uses, PREG_OFFSET_CAPTURE);

        foreach ($uses[0] as $index => [$use, $offset]) {
            $property = $uses[1][$index][0];
            // The declaration before this one in its rule: between the delimiter before it and its own semicolon.
            $declarations = preg_split('~[;{}]~', rtrim(substr($css, 0, $offset)));
            array_pop($declarations);
            $previous = trim((string) end($declarations));

            expect($previous)->toMatch(
                '~^'.preg_quote($property, '~').'\s*:\s*[^()]+$~',
                "{$use} has no plain {$property} before it (it reads \"{$previous}\"): a Samsung TV of 2021 shows its default"
            );
            $checked++;
        }
    }

    expect($checked)->toBeGreaterThan(0);
});

it('calls no browser API newer than Chrome 79 in anything the television runs', function (string $file) {
    $code = codeOf(file_get_contents(base_path($file)));

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
    'Anime.js' => 'public/ad-runtime/anime.min.js',
]);

it('writes no syntax newer than Chrome 79 in a script Vite never builds', function (string $file) {
    // A WebView 79 stops at the first ?. or ?? and runs nothing of the file: Anime.js 4.5 shipped one, and every
    // advert stood still there (the page's boot script shows it unmoved when the runtime is missing).
    expect(syntaxNewerThan79(codeOf(file_get_contents(base_path($file)))))->toBe([]);
})->with([
    'its worker' => 'public/player-sw.js',
    'the Ad Builder runtime' => 'public/ad-runtime/runtime.js',
    'Anime.js' => 'public/ad-runtime/anime.min.js',
]);

it('writes no syntax newer than Chrome 79 in the scripts its pages carry inline', function () {
    $html = $this->get('/player')->assertOk()->getContent();
    preg_match_all('~<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>~s', $html, $inline);

    expect($inline[1])->not->toBeEmpty();
    foreach ($inline[1] as $script) {
        expect(syntaxNewerThan79(codeOf($script)))->toBe([]);
    }

    // And what the Ad Builder writes into every published page.
    foreach ((new ReflectionClass(AdCompiler::class))->getConstants() as $name => $value) {
        if (is_string($value)) {
            expect(syntaxNewerThan79(codeOf($value)))->toBe([], "AdCompiler::{$name}");
        }
    }
});

it('has the built player written down to Chrome 79, every chunk it imports included', function () {
    // What the televisions are sent: the build of resources/js/player.js and every chunk it loads, now or later.
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $files = [];
    $walk = function (string $key) use (&$walk, &$files, $manifest): void {
        $file = $manifest[$key]['file'] ?? null;
        if ($file === null || in_array($file, $files, true)) {
            return;
        }

        $files[] = $file;
        foreach (array_merge($manifest[$key]['imports'] ?? [], $manifest[$key]['dynamicImports'] ?? []) as $import) {
            $walk((string) $import);
        }
    };
    $walk('resources/js/player.js');

    expect($files)->toContain($manifest['resources/js/player/decoded-video.js']['file']);
    foreach ($files as $file) {
        expect(syntaxNewerThan79(file_get_contents(public_path('build/'.$file))))->toBe([], $file);
    }
});

it('loads its own video decoder only behind a check that WebCodecs is there, in a chunk of its own', function () {
    // WebCodecs is Chrome 94: a turned Fire TV's decoder (docs/TV-ORIENTATION-SPEC.md §12) is the one place the
    // player reaches past its floor, and only in a chunk it imports after asking whether the API is there.
    $player = preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', file_get_contents(base_path('resources/js/player.js')));

    expect($player)
        ->toContain("typeof window.VideoDecoder !== 'function'")
        ->toContain("import('./player/decoded-video.js')")
        ->not->toContain('new VideoDecoder(')
        ->not->toContain('new EncodedVideoChunk(')
        ->not->toMatch('~^\s*import\s[^(]*decoded-video~m');

    expect(file_get_contents(base_path('resources/js/player/decoded-video.js')))->toContain('new VideoDecoder(');
});

it('has its script written down to Chrome 79', function () {
    expect(file_get_contents(base_path('vite.config.js')))->toContain("target: ['chrome79'");
});
