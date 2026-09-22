<?php

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Store;
use App\Services\AdCompiler;

/*
|--------------------------------------------------------------------------
| Stage 3 — backgrounds, pictures and shapes on the published page
|--------------------------------------------------------------------------
|
| docs/AD-BUILDER-SPEC.md §6/§9. The background is the stage's own colour with layers stacked on it,
| first one furthest back, each with its own opacity and blend mode; pictures and shapes carry frames,
| shadows, filters and mirrors. Everything is REBUILT by the compiler from values it checks — never
| copied — so these tests read the CSS a television will get.
|
*/

beforeEach(function () {
    $this->store = Store::factory()->create();
    $this->picture = BuilderAsset::factory()->create(['store_id' => $this->store->id, 'path' => 'builder/1/assets/snow.png']);
    $this->clip = BuilderAsset::factory()->video()->create(['store_id' => $this->store->id, 'path' => 'builder/1/assets/loop.mp4']);
});

/** Compile a document for this store. */
function compileFor(Store $store, array $layers = [], array $elements = [], string $colour = '#101828'): string
{
    $document = BuilderAd::blankDocument();
    $document['stage']['background'] = ['color' => $colour, 'layers' => $layers];
    $document['elements'] = $elements;

    return app(AdCompiler::class)->compile(BuilderAd::factory()->create(['store_id' => $store->id, 'document' => $document]));
}

test('the layers are stacked in order on the stage colour, each with its own opacity and blend', function () {
    $html = compileFor($this->store, [
        ['id' => 'a', 'type' => 'color', 'color' => '#1e3a8a', 'opacity' => 0.6, 'blend' => 'multiply'],
        ['id' => 'b', 'type' => 'gradient', 'opacity' => 1, 'blend' => 'screen', 'gradient' => [
            'kind' => 'linear', 'angle' => 135, 'stops' => [['color' => '#000000', 'at' => 0], ['color' => '#ffffff', 'at' => 100]],
        ]],
        ['id' => 'c', 'type' => 'image', 'assetId' => $this->picture->id, 'opacity' => 0.25, 'blend' => 'overlay',
            'size' => 'custom', 'scale' => 12, 'position' => 'left top', 'repeat' => 'repeat'],
        ['id' => 'd', 'type' => 'video', 'assetId' => $this->clip->id, 'opacity' => 1, 'blend' => 'normal', 'fit' => 'contain'],
    ], colour: '#0b1d3a');

    expect($html)->toContain('<div class="ad-bg" style="background-color:#0b1d3a;">')
        ->toContain('opacity:0.6;mix-blend-mode:multiply;background-color:#1e3a8a;')
        ->toContain('opacity:1;mix-blend-mode:screen;background-image:linear-gradient(135deg, #000000 0%, #ffffff 100%);')
        ->toContain("background-image:url('")
        ->toContain('snow.png')
        ->toContain('background-size:12%;background-position:left top;background-repeat:repeat;')
        ->toContain('<video class="ad-layer" src="')
        ->toContain('loop.mp4')
        ->toContain('autoplay muted loop playsinline style="opacity:1;mix-blend-mode:normal;object-fit:contain;"');

    // First layer furthest back: it comes first in the page.
    expect(strpos($html, '#1e3a8a'))->toBeLessThan(strpos($html, 'linear-gradient(135deg'))
        ->and(strpos($html, 'linear-gradient(135deg'))->toBeLessThan(strpos($html, 'snow.png'))
        ->and(strpos($html, 'snow.png'))->toBeLessThan(strpos($html, 'loop.mp4'));
});

test('a radial gradient, and one with more than two stops', function () {
    $html = compileFor($this->store, [['id' => 'a', 'type' => 'gradient', 'gradient' => [
        'kind' => 'radial',
        'stops' => [['color' => 'rgba(255,255,255,0.3)', 'at' => 0], ['color' => '#3b2216', 'at' => 55], ['color' => '#120a06', 'at' => 100]],
    ]]]);

    expect($html)->toContain('background-image:radial-gradient(circle at center, rgba(255,255,255,0.3) 0%, #3b2216 55%, #120a06 100%);');
});

test('a layer the television could not draw is left out, not drawn wrong', function () {
    $other = Store::factory()->create();
    $theirs = BuilderAsset::factory()->create(['store_id' => $other->id, 'path' => 'builder/2/assets/theirs.png']);

    $html = compileFor($this->store, [
        ['id' => 'hidden', 'type' => 'color', 'color' => '#abcdef', 'visible' => false],
        ['id' => 'not-a-colour', 'type' => 'color', 'color' => 'red; background:url(x)'],
        ['id' => 'one-stop', 'type' => 'gradient', 'gradient' => ['stops' => [['color' => '#ff0000', 'at' => 0]]]],
        ['id' => 'no-picture', 'type' => 'image'],
        ['id' => 'a-video-as-picture', 'type' => 'image', 'assetId' => $this->clip->id],
        ['id' => 'a-picture-as-video', 'type' => 'video', 'assetId' => $this->picture->id],
        ['id' => 'another-shop', 'type' => 'image', 'assetId' => $theirs->id],
        ['id' => 'unknown', 'type' => 'iframe', 'src' => 'https://example.com'],
    ]);

    // An empty background: the stage's colour and not one layer on it.
    expect($html)->toContain('<div class="ad-bg" style="background-color:#101828;"></div>')
        ->not->toContain('class="ad-layer"')
        ->not->toContain('#abcdef')
        ->not->toContain('theirs.png')
        ->not->toContain('example.com')
        ->not->toContain('url(x)');
});

test('a picture gets its fit, focus point, corners, frame, shadow, filters and mirror', function () {
    $html = compileFor($this->store, elements: [[
        'id' => 'photo', 'type' => 'image', 'x' => 10, 'y' => 20, 'w' => 640, 'h' => 400, 'rotation' => 4, 'z' => 0,
        'assetId' => $this->picture->id,
        'style' => [
            'fit' => 'contain', 'position' => 'right bottom', 'radius' => 28, 'flipX' => true,
            'border' => ['width' => 10, 'style' => 'dashed', 'color' => '#ffffff'],
            'shadow' => ['x' => 0, 'y' => 30, 'blur' => 60, 'spread' => 2, 'color' => 'rgba(0,0,0,0.45)'],
            'filters' => ['brightness' => 110, 'saturate' => 100, 'grayscale' => 0, 'hueRotate' => -30],
            'blend' => 'multiply',
        ],
    ]]);

    expect($html)->toContain('object-fit:contain;object-position:right bottom;border-radius:28px;')
        ->toContain('border:10px dashed #ffffff;')
        ->toContain('box-shadow:0px 30px 60px 2px rgba(0,0,0,0.45);')
        // Only the filters changed from neutral are written.
        ->toContain('filter:brightness(110%) hue-rotate(-30deg);')
        ->not->toContain('saturate(100%)')
        ->not->toContain('grayscale(0%)')
        ->toContain('transform:scale(-1, 1);')
        // The box: rotation and blend belong to it, above the background.
        ->toContain('left:10px;top:20px;width:640px;height:400px;opacity:1;z-index:1;transform:rotate(4deg);mix-blend-mode:multiply;');
});

test('a shape is a rectangle or an ellipse, filled with a colour or a gradient', function () {
    $html = compileFor($this->store, elements: [
        ['id' => 'rect', 'type' => 'shape', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'z' => 0,
            'style' => ['shape' => 'rect', 'fill' => '#2563eb', 'radius' => 16]],
        ['id' => 'ellipse', 'type' => 'shape', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'z' => 1,
            'style' => ['shape' => 'ellipse', 'fill' => '#2563eb', 'radius' => 16, 'gradient' => [
                'kind' => 'linear', 'angle' => 90, 'stops' => [['color' => '#ffd60a', 'at' => 0], ['color' => '#ffb703', 'at' => 100]],
            ], 'border' => ['width' => 8, 'color' => '#ffffff']]],
    ]);

    expect($html)->toContain('background-color:#2563eb;border-radius:16px;')
        // The gradient wins over the fill, and an ellipse ignores the corner radius.
        ->toContain('background-image:linear-gradient(90deg, #ffd60a 0%, #ffb703 100%);border-radius:50%;border:8px solid #ffffff;');
});

test('a Ken Burns loop zooms the picture inside its own frame', function () {
    $html = compileFor($this->store, elements: [[
        'id' => 'photo', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 800, 'h' => 450, 'z' => 0, 'assetId' => $this->picture->id,
        'style' => ['radius' => 24],
        'animations' => ['loop' => ['effect' => 'kenburns', 'amount' => 12]],
    ], [
        'id' => 'still', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 800, 'h' => 450, 'z' => 1, 'assetId' => $this->picture->id,
        'style' => ['radius' => 24],
    ]]);

    expect($html)->toContain('data-anim-id="photo" style="left:0px;top:0px;width:800px;height:450px;opacity:1;z-index:1;overflow:hidden;border-radius:24px;"')
        ->toContain('data-anim-id="still" style="left:0px;top:0px;width:800px;height:450px;opacity:1;z-index:2;"');
});

test('numbers beyond what the compiler writes are held at its limits', function () {
    $html = compileFor($this->store, elements: [[
        'id' => 'photo', 'type' => 'image', 'x' => 99999, 'y' => -99999, 'w' => 0, 'h' => 50000, 'rotation' => 999, 'opacity' => 7, 'z' => 0,
        'assetId' => $this->picture->id,
        'style' => ['radius' => 5000, 'filters' => ['blur' => 900, 'brightness' => -5], 'border' => ['width' => 999, 'color' => '#000000']],
    ]], layers: [['id' => 'a', 'type' => 'color', 'color' => '#000000', 'opacity' => 9]]);

    expect($html)->toContain('left:20000px;top:-20000px;width:1px;height:20000px;opacity:1;z-index:1;transform:rotate(360deg);')
        ->toContain('border-radius:999px;')
        ->toContain('filter:blur(100px) brightness(0%);')
        ->toContain('border:200px solid #000000;')
        ->toContain('opacity:1;mix-blend-mode:normal;background-color:#000000;');
});
