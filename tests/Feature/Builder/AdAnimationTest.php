<?php

use App\Models\BuilderAd;
use App\Models\Store;
use App\Services\AdAnimations;
use App\Services\AdCompiler;

/*
|--------------------------------------------------------------------------
| Stage 4 — animations on the published page
|--------------------------------------------------------------------------
|
| docs/AD-BUILDER-SPEC.md §8. An element's animations reach the page as JSON the runtime PARSES — never
| runs — after AdAnimations has rebuilt every value from its lists and limits. The same runtime file is
| what the editor's preview plays, so its lists must stay the server's.
|
*/

beforeEach(function () {
    $this->store = Store::factory()->create();
});

/** Compile elements for this store. */
function compileElements(Store $store, array $elements): string
{
    $document = BuilderAd::blankDocument();
    $document['elements'] = $elements;

    return app(AdCompiler::class)->compile(BuilderAd::factory()->create(['store_id' => $store->id, 'document' => $document]));
}

function textElement(string $id, mixed $animations = [], array $more = []): array
{
    return ['id' => $id, 'type' => 'text', 'text' => 'Sale', 'x' => 0, 'y' => 0, 'w' => 400, 'h' => 100, 'z' => 0, 'style' => [], 'animations' => $animations, ...$more];
}

/** The page's animations, parsed the way the boot script does. */
function animationsOf(string $html): ?array
{
    if (preg_match('/<script type="application\/json" id="ad-animations">(.*?)<\/script>/s', $html, $match) !== 1) {
        return null;
    }

    return json_decode($match[1], true);
}

test('a still advert loads nothing extra; one that moves loads Anime.js and the runtime', function () {
    $still = compileElements($this->store, [textElement('a')]);

    expect($still)->not->toContain('ad-runtime/')->not->toContain('ad-animations')->not->toContain('class="ad-el ad-pending"');

    $moving = compileElements($this->store, [textElement('a', ['in' => ['effect' => 'fade']])]);

    expect($moving)->toContain('<script type="application/json" id="ad-animations">')
        ->toMatch('#<script src="[^"]*/ad-runtime/anime\.min\.js\?v=[0-9a-f]{10}"></script>#')
        ->toMatch('#<script src="[^"]*/ad-runtime/runtime\.js\?v=[0-9a-f]{10}"></script>#')
        ->toContain('window.AdRuntime.run(stage, config);')
        ->not->toContain('gsap');

    // The clock starts when the stage is really on screen — a player loads the next item behind the
    // one showing — and, whatever happens, within a few seconds.
    expect($moving)->toContain('new IntersectionObserver(')
        ->toContain('observer.observe(stage);')
        ->toContain('setTimeout(function () { observer.disconnect(); start(); }, 4000);');

    // The files are really there, at the fixed addresses the page names.
    foreach (AdCompiler::MOTION_SCRIPTS as $script) {
        expect(public_path($script))->toBeFile();
    }
});

test('only an element with an entrance waits hidden for it — a loop alone starts in place', function () {
    $html = compileElements($this->store, [
        textElement('arrives', ['in' => ['effect' => 'slide']]),
        textElement('floats', ['loop' => ['effect' => 'float']]),
    ]);

    expect($html)->toContain('class="ad-el ad-pending" data-anim-id="arrives"')
        ->toContain('class="ad-el" data-anim-id="floats"');
});

test('every slot reaches the page whole: the numbers it holds, and the defaults for the ones it does not', function () {
    $html = compileElements($this->store, [textElement('cup', [
        'in' => ['effect' => 'fade', 'duration' => 1],
        'loop' => ['effect' => 'float', 'axis' => 'y', 'amount' => 10, 'duration' => 2.4],
        'out' => ['effect' => 'zoom', 'at' => 12],
    ])]);

    expect(animationsOf($html))->toEqual(['cup' => [
        'in' => [
            'effect' => 'fade', 'direction' => 'up', 'distance' => 80.0, 'scale' => 0.6, 'degrees' => -90.0, 'blur' => 20.0,
            'duration' => 1.0, 'delay' => 0.0, 'ease' => 'power2.out',
        ],
        'loop' => [
            'effect' => 'float', 'axis' => 'y', 'amount' => 10.0, 'amountX' => 0.0, 'amountY' => 0.0, 'duration' => 2.4,
            'delay' => 0.0, 'yoyo' => true, 'ease' => 'sine.inOut',
        ],
        'out' => [
            'effect' => 'zoom', 'direction' => 'up', 'distance' => 80.0, 'scale' => 0.6, 'degrees' => -90.0, 'blur' => 20.0,
            'at' => 12.0, 'duration' => 0.6, 'ease' => 'power2.in',
        ],
    ]]);
});

test('a bounce lands on a bounce ease unless the person chose another', function () {
    $html = compileElements($this->store, [
        textElement('a', ['in' => ['effect' => 'bounce']]),
        textElement('b', ['in' => ['effect' => 'bounce', 'ease' => 'elastic.out']]),
    ]);

    expect(animationsOf($html)['a']['in']['ease'])->toBe('bounce.out')
        ->and(animationsOf($html)['b']['in']['ease'])->toBe('elastic.out');
});

test('a curve from the Ease Visualizer is rebuilt from its four numbers, held inside what a handle may reach', function () {
    $html = compileElements($this->store, [
        textElement('a', ['in' => ['effect' => 'fade', 'ease' => 'cubic(0.25,0.1,0.6,1.3)']]),
        textElement('b', ['in' => ['effect' => 'fade', 'ease' => 'cubic(9,-9,-9,9)']]),
        textElement('c', ['in' => ['effect' => 'fade', 'ease' => 'cubic(1,2)']]),
        textElement('d', ['in' => ['effect' => 'fade', 'ease' => 'cubic(0.1,x,0.3,1)']]),
    ]);
    $animations = animationsOf($html);

    expect($animations['a']['in']['ease'])->toBe('cubic(0.25,0.1,0.6,1.3)')
        ->and($animations['b']['in']['ease'])->toBe('cubic(1,-1,0,2)')
        ->and($animations['c']['in']['ease'])->toBe('power2.out')
        // A part that is no number takes that handle's own default — never a word on the page.
        ->and($animations['d']['in']['ease'])->toBe('cubic(0.1,0.1,0.3,1)');
});

test('an effect, a direction or an ease nobody wrote reaches nothing', function () {
    $html = compileElements($this->store, [
        textElement('a', [
            'in' => ['effect' => 'explode', 'duration' => 1],
            'loop' => ['effect' => 'fade'],
            'out' => ['effect' => 'slide', 'direction' => 'sideways', 'ease' => 'power9.out'],
        ]),
        textElement('b', ['in' => 'fade', 'loop' => 42]),
        textElement('c', 'not an array'),
    ]);

    expect(animationsOf($html))->toEqual(['a' => ['out' => [
        'effect' => 'slide', 'direction' => 'up', 'distance' => 80.0, 'scale' => 0.6, 'degrees' => -90.0, 'blur' => 20.0,
        'at' => 5.0, 'duration' => 0.6, 'ease' => 'power2.in',
    ]]]);
});

test('numbers are held inside AdAnimations::NUMBERS, whatever the document says', function () {
    $html = compileElements($this->store, [textElement('a', [
        'in' => ['effect' => 'zoom', 'duration' => 0, 'delay' => 99999, 'scale' => -3, 'distance' => '1e9'],
        'loop' => ['effect' => 'shake', 'amount' => -99999, 'duration' => 'fast', 'yoyo' => 'no'],
        'out' => ['effect' => 'fade', 'at' => -5, 'duration' => 999],
    ])]);
    $animations = animationsOf($html)['a'];

    expect($animations['in'])->toMatchArray(['duration' => 0.05, 'delay' => 600.0, 'scale' => 0.0, 'distance' => 2000.0])
        ->and($animations['loop'])->toMatchArray(['amount' => -2000.0, 'duration' => 2.0, 'yoyo' => true])
        ->and($animations['out'])->toMatchArray(['at' => 0.0, 'duration' => 60.0]);
});

test('a hidden element does not move, and its animations never reach the page', function () {
    $html = compileElements($this->store, [textElement('gone', ['in' => ['effect' => 'fade']], ['visible' => false])]);

    expect($html)->not->toContain('data-anim-id="gone"')->not->toContain('ad-animations');
});

test('the runtime is plain ES5, so an old television box can run it', function () {
    $source = file_get_contents(public_path('ad-runtime/runtime.js'));

    // Comments may say anything; the code may not use what an old Chromium cannot run.
    $code = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source);

    expect($code)->not->toMatch('/=>/')
        ->not->toMatch('/\b(let|const|class)\s/')
        ->not->toContain('`')
        ->not->toContain('...');
});

test('the runtime knows exactly the effects and eases the server lets through', function () {
    $source = file_get_contents(public_path('ad-runtime/runtime.js'));

    $list = function (string $name) use ($source): array {
        preg_match('/var '.$name.' = \[(.*?)\];/s', $source, $match);
        preg_match_all("/'([^']+)'/", $match[1] ?? '', $names);

        return $names[1];
    };

    expect($list('EASES'))->toBe(AdAnimations::EASES)
        ->and($list('ENTRANCES'))->toBe(AdAnimations::ENTRANCES)
        ->and($list('LOOPS'))->toBe(AdAnimations::LOOPS)
        ->and($list('DIRECTIONS'))->toBe(AdAnimations::DIRECTIONS);
});

test('every ease the server lets through is one the runtime can hand to Anime.js', function () {
    // The saved designs keep GSAP's spelling (`power2.out`); the runtime maps each family onto the name
    // Anime.js gives the same curve, and the type onto its prefix (`outCubic`).
    $source = file_get_contents(public_path('ad-runtime/runtime.js'));
    preg_match('/var FAMILIES = \{(.*?)\};/s', $source, $match);
    preg_match_all("/(\\w+): '(\\w+)'/", $match[1] ?? '', $pairs);
    $families = array_combine($pairs[1], $pairs[2]);

    foreach (array_diff(AdAnimations::EASES, ['none']) as $ease) {
        [$family, $type] = explode('.', $ease);

        expect($families)->toHaveKey($family)
            ->and(['Quad', 'Cubic', 'Quart', 'Quint', 'Sine', 'Expo', 'Circ', 'Back', 'Elastic', 'Bounce'])->toContain($families[$family])
            ->and(['in', 'out', 'inOut'])->toContain($type);
    }
});
