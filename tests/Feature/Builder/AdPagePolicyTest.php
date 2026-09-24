<?php

use App\Models\BuilderAd;
use App\Models\Store;
use App\Services\AdCompiler;

/*
|--------------------------------------------------------------------------
| The published page's own policy — what may run in it, and what it may reach
|--------------------------------------------------------------------------
|
| docs/AD-BUILDER-SPEC.md §15. A television whose worker keeps it plays an ad page in a frame that is
| same-origin with the player, so the page carries its own Content-Security-Policy, first thing in its
| head: its inline scripts by their digests, the runtime from its own folder when the page moves, the
| app's pictures, videos and fonts — and no request, form, frame, plugin or base of its own.
|
*/

beforeEach(function () {
    $this->store = Store::factory()->create();
});

/** A design compiled for this store: a picture-less text, with the animations given. */
function policyPage(Store $store, array $animations = []): string
{
    $document = BuilderAd::blankDocument();
    $document['elements'] = [
        ['id' => 'a', 'type' => 'text', 'text' => 'Sale', 'x' => 0, 'y' => 0, 'w' => 400, 'h' => 100, 'z' => 0, 'style' => [], 'animations' => $animations],
    ];

    return app(AdCompiler::class)->compile(BuilderAd::factory()->create(['store_id' => $store->id, 'document' => $document]));
}

/** The page's policy, directive by directive: ['script-src' => ['a', 'b'], …]. */
function policyOf(string $html): array
{
    expect(preg_match('/<meta http-equiv="Content-Security-Policy" content="([^"]+)">/', $html, $match))->toBe(1);

    return collect(explode(';', html_entity_decode($match[1], ENT_QUOTES)))
        ->map(fn (string $directive) => preg_split('/\s+/', trim($directive)))
        ->filter(fn (array $parts) => $parts[0] !== '')
        ->mapWithKeys(fn (array $parts) => [$parts[0] => array_slice($parts, 1)])
        ->all();
}

/** The text of every inline script the browser would run (not the JSON block, not a script by address). */
function inlineScriptsOf(string $html): array
{
    preg_match_all('/<script>(.*?)<\/script>/s', $html, $matches);

    return $matches[1];
}

test('the policy is the first thing in the page’s head, right after its character set', function () {
    foreach ([policyPage($this->store), policyPage($this->store, ['in' => ['effect' => 'fade']])] as $html) {
        expect($html)->toMatch('/<head>\s*<meta charset="utf-8">\s*<meta http-equiv="Content-Security-Policy" content="/');
    }
});

test('every inline script is allowed by its digest — and nothing inline is allowed wholesale', function () {
    foreach ([policyPage($this->store), policyPage($this->store, ['in' => ['effect' => 'fade'], 'loop' => ['effect' => 'float']])] as $html) {
        $scripts = inlineScriptsOf($html);
        $allowed = policyOf($html)['script-src'];

        expect($scripts)->not->toBeEmpty();

        foreach ($scripts as $script) {
            expect($allowed)->toContain("'sha256-".base64_encode(hash('sha256', $script, true))."'");
        }

        expect($allowed)->not->toContain("'unsafe-inline'")
            ->not->toContain("'unsafe-eval'")
            ->not->toContain("'self'")
            ->not->toContain('*');
    }
});

test('a page that moves may load scripts from the runtime’s own folder alone; a still page from nowhere', function () {
    $moving = policyPage($this->store, ['in' => ['effect' => 'fade']]);
    $folders = collect(policyOf($moving)['script-src'])->reject(fn (string $source) => str_starts_with($source, "'sha256-"));

    // One source by address, and it is the runtime's folder — not the origin the panel lives on.
    expect($folders->values()->all())->toBe([asset('ad-runtime').'/']);

    // Every script the page loads by address is inside it.
    preg_match_all('/<script src="([^"]+)"><\/script>/', $moving, $sources);

    expect($sources[1])->toHaveCount(count(AdCompiler::MOTION_SCRIPTS));

    foreach ($sources[1] as $source) {
        expect(html_entity_decode($source))->toStartWith(asset('ad-runtime').'/');
    }

    // A still page loads no script by address, and its policy names no place to load one from.
    $still = policyPage($this->store);

    expect(collect(policyOf($still)['script-src'])->every(fn (string $source) => str_starts_with($source, "'sha256-")))->toBeTrue()
        ->and($still)->not->toContain('<script src=');
});

test('the page can reach nothing: no request of its own, no form, no frame, no plugin, no base', function () {
    $policy = policyOf(policyPage($this->store, ['in' => ['effect' => 'fade']]));

    expect($policy['default-src'])->toBe(["'none'"])
        ->and($policy['connect-src'])->toBe(["'none'"])
        ->and($policy['form-action'])->toBe(["'none'"])
        ->and($policy['frame-src'])->toBe(["'none'"])
        ->and($policy['object-src'])->toBe(["'none'"])
        ->and($policy['base-uri'])->toBe(["'none'"])
        // Pictures, videos and fonts from the app's own origins — and the fonts the page embeds.
        ->and($policy['img-src'])->toContain("'self'")
        ->and($policy['font-src'])->toContain('data:')
        ->and($policy['style-src'])->toBe(["'unsafe-inline'"]);
});

test('the words a person types reach the page as words: the policy is never theirs to change', function () {
    $document = BuilderAd::blankDocument();
    $document['elements'] = [
        ['id' => 'a', 'type' => 'text', 'text' => '"><meta http-equiv="Content-Security-Policy" content="script-src *"><script>alert(1)</script>', 'x' => 0, 'y' => 0, 'w' => 400, 'h' => 100, 'z' => 0, 'style' => [], 'animations' => []],
    ];

    $html = app(AdCompiler::class)->compile(BuilderAd::factory()->create([
        'store_id' => $this->store->id,
        'name' => '</title><script>alert(2)</script>',
        'document' => $document,
    ]));

    expect(substr_count($html, 'http-equiv="Content-Security-Policy"'))->toBe(1)
        ->and($html)->not->toContain('<script>alert(')
        ->and(collect(inlineScriptsOf($html))->filter(fn (string $script) => str_contains($script, 'alert(')))->toBeEmpty();
});
