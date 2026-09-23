<?php

use App\Models\BuilderAd;
use App\Models\Store;
use App\Services\AdCompiler;

/*
|--------------------------------------------------------------------------
| The design document's rules (BuilderAdRequest)
|--------------------------------------------------------------------------
|
| What is saved is `validated()`, and Laravel leaves out of it any key inside a checked array that has no
| rule of its own — so a key the editor writes but the rules forget is not refused, it silently vanishes
| on save. The first test is the guard against that: a document holding EVERY key the editor writes
| (stages 1a–5, the guides of stage 5 included) must come back exactly as it was sent.
|
*/

beforeEach(function () {
    $this->store = Store::factory()->create();
    $this->designer = createStoreUser($this->store, ['ad-view', 'ad-store', 'ad-update'], 'Designer');
    $this->actingAs($this->designer)->withSession(['current_store_id' => $this->store->id]);
});

/** A document with every key the editor can write, each set to something other than its default. */
function everyKeyDocument(): array
{
    $frame = ['width' => 6, 'style' => 'dashed', 'color' => '#ffffff'];
    $shadow = ['x' => 4, 'y' => 16, 'blur' => 40, 'spread' => 2, 'color' => 'rgba(0,0,0,0.4)'];
    $gradient = ['kind' => 'radial', 'angle' => 45, 'stops' => [
        ['color' => '#ff0000', 'at' => 0], ['color' => '#00ff00', 'at' => 40], ['color' => '#0000ff', 'at' => 100],
    ]];

    return [
        'version' => 1,
        'guides' => ['x' => [160, 960.5, -40], 'y' => [540]],
        'stage' => [
            'width' => 1920,
            'height' => 1080,
            'background' => [
                'color' => '#101828',
                'layers' => [
                    ['id' => 'bg_1', 'type' => 'color', 'visible' => true, 'opacity' => 0.5, 'blend' => 'multiply', 'color' => '#1e3a8a'],
                    ['id' => 'bg_2', 'type' => 'gradient', 'visible' => false, 'opacity' => 1, 'blend' => 'screen', 'gradient' => $gradient],
                    [
                        'id' => 'bg_3', 'type' => 'image', 'visible' => true, 'opacity' => 0.3, 'blend' => 'overlay', 'assetId' => 7,
                        'size' => 'custom', 'scale' => 12, 'position' => 'left top', 'repeat' => 'repeat',
                    ],
                    ['id' => 'bg_4', 'type' => 'video', 'visible' => true, 'opacity' => 1, 'blend' => 'normal', 'assetId' => 8, 'fit' => 'contain'],
                ],
            ],
        ],
        'elements' => [
            [
                'id' => 'el_text', 'type' => 'text', 'name' => 'Headline', 'x' => -40, 'y' => 60, 'w' => 900, 'h' => 300,
                'rotation' => -12, 'opacity' => 0.9, 'z' => 0, 'locked' => true, 'visible' => true, 'text' => "Two\nlines",
                'style' => [
                    'fontFamily' => 'Anton', 'fontSize' => 140, 'fontWeight' => 700, 'fontStyle' => 'italic', 'color' => '#ffcc00',
                    'align' => 'center', 'verticalAlign' => 'center', 'lineHeight' => 0.95, 'letterSpacing' => -2, 'wordSpacing' => 6,
                    'textTransform' => 'uppercase', 'textDecoration' => 'underline', 'padding' => 24, 'background' => '#101828',
                    'radius' => 18, 'textShadow' => ['x' => 0, 'y' => 6, 'blur' => 18, 'color' => '#000000'],
                    'textStroke' => ['width' => 3, 'color' => '#000000'], 'border' => $frame, 'blend' => 'overlay',
                ],
                'animations' => [
                    'in' => [
                        'effect' => 'slide', 'direction' => 'left', 'distance' => 120, 'scale' => 0.5, 'degrees' => -45, 'blur' => 12,
                        'duration' => 1.2, 'delay' => 0.3, 'ease' => 'cubic(0.2,1.4,0.6,1)',
                    ],
                    'loop' => [
                        'effect' => 'float', 'axis' => 'x', 'amount' => 14, 'amountX' => 5, 'amountY' => -5, 'duration' => 2.4,
                        'delay' => 1, 'yoyo' => false, 'ease' => 'sine.inOut',
                    ],
                    'out' => [
                        'effect' => 'blur', 'direction' => 'down', 'distance' => 60, 'scale' => 1.4, 'degrees' => 90, 'blur' => 30,
                        'at' => 12.5, 'duration' => 0.7, 'ease' => 'power4.in',
                    ],
                ],
            ],
            [
                'id' => 'el_image', 'type' => 'image', 'name' => 'Photo', 'x' => 100, 'y' => 100, 'w' => 640, 'h' => 400,
                'rotation' => 4, 'opacity' => 1, 'z' => 1, 'locked' => false, 'visible' => false, 'assetId' => 7,
                'style' => [
                    'fit' => 'contain', 'position' => 'right bottom', 'radius' => 28, 'flipX' => true, 'flipY' => false,
                    'border' => $frame, 'shadow' => $shadow, 'blend' => 'multiply',
                    'filters' => [
                        'blur' => 2, 'brightness' => 110, 'contrast' => 90, 'saturate' => 150, 'grayscale' => 10,
                        'sepia' => 20, 'hueRotate' => -30, 'invert' => 5,
                    ],
                ],
                'animations' => ['loop' => [
                    'effect' => 'kenburns', 'axis' => 'y', 'amount' => 12, 'amountX' => 20, 'amountY' => -10, 'duration' => 14,
                    'delay' => 0, 'yoyo' => true, 'ease' => 'none',
                ]],
            ],
            [
                'id' => 'el_video', 'type' => 'video', 'name' => 'Clip', 'x' => 0, 'y' => 0, 'w' => 320, 'h' => 180,
                'rotation' => 0, 'opacity' => 1, 'z' => 2, 'locked' => false, 'visible' => true, 'assetId' => 8,
                'style' => ['fit' => 'scale-down', 'position' => 'center top', 'radius' => 0],
                'animations' => ['out' => [
                    'effect' => 'wipe', 'direction' => 'right', 'distance' => 80, 'scale' => 0.6, 'degrees' => -90, 'blur' => 20,
                    'at' => 5, 'duration' => 0.6, 'ease' => 'expo.in',
                ]],
            ],
            [
                'id' => 'el_shape', 'type' => 'shape', 'name' => 'Badge', 'x' => 1500, 'y' => 600, 'w' => 300, 'h' => 300,
                'rotation' => 0, 'opacity' => 1, 'z' => 3, 'locked' => false, 'visible' => true,
                'style' => [
                    'shape' => 'ellipse', 'fill' => '#2563eb', 'gradient' => $gradient, 'radius' => 16,
                    'border' => $frame, 'shadow' => $shadow, 'blend' => 'normal',
                ],
                'animations' => [
                    'in' => [
                        'effect' => 'rotate', 'direction' => 'up', 'distance' => 80, 'scale' => 0.6, 'degrees' => -180, 'blur' => 20,
                        'duration' => 0.9, 'delay' => 1.3, 'ease' => 'back.out',
                    ],
                    'loop' => [
                        'effect' => 'spin', 'axis' => 'y', 'amount' => -1, 'amountX' => 0, 'amountY' => 0, 'duration' => 40,
                        'delay' => 0, 'yoyo' => false, 'ease' => 'none',
                    ],
                ],
            ],
            // A group and what is inside it (§13) — last, so the indexes the tests above point at stay.
            [
                'id' => 'grp_row', 'type' => 'group', 'name' => 'Menu row', 'x' => 100, 'y' => 800, 'w' => 900, 'h' => 100,
                'rotation' => 0, 'opacity' => 0.8, 'z' => 4, 'locked' => false, 'visible' => true,
                'style' => ['blend' => 'screen'],
                'animations' => ['in' => ['effect' => 'fade', 'direction' => 'up', 'distance' => 40, 'duration' => 0.6, 'delay' => 0.1, 'ease' => 'power1.out']],
            ],
            [
                'id' => 'el_dish', 'type' => 'text', 'name' => 'Dish', 'parentId' => 'grp_row', 'x' => 100, 'y' => 800, 'w' => 700, 'h' => 100,
                'rotation' => 0, 'opacity' => 1, 'z' => 5, 'locked' => false, 'visible' => true, 'text' => 'Chicken karahi',
                'style' => ['fontSize' => 64, 'color' => '#ffffff'],
                'animations' => ['out' => ['effect' => 'fade', 'direction' => 'down', 'distance' => 30, 'at' => 9, 'duration' => 0.5, 'ease' => 'power2.in']],
            ],
            // A line (§14): a shape with a thickness and a dash pattern of its own.
            [
                'id' => 'el_rule', 'type' => 'shape', 'name' => 'Rule', 'x' => 100, 'y' => 200, 'w' => 900, 'h' => 40,
                'rotation' => -3, 'opacity' => 1, 'z' => 6, 'locked' => false, 'visible' => true,
                'style' => ['shape' => 'line', 'fill' => '#ffd166', 'lineWidth' => 8, 'lineStyle' => 'dashed'],
                'animations' => ['in' => ['effect' => 'wipe', 'direction' => 'right', 'distance' => 80, 'duration' => 0.7, 'delay' => 0.4, 'ease' => 'power2.out']],
            ],
        ],
    ];
}

test('every key the editor writes comes back exactly as it was saved', function () {
    $document = everyKeyDocument();

    $id = $this->postJson('/builder', ['name' => 'Everything', 'document' => $document])->assertOk()->json('ad.id');

    expect(BuilderAd::find($id)->document)->toEqual($document);

    // And again through an update, which replaces the whole document.
    $document['elements'][0]['text'] = 'Changed';
    $this->putJson("/builder/{$id}", ['name' => 'Everything', 'document' => $document])->assertOk();

    expect(BuilderAd::find($id)->document)->toEqual($document);
});

test('a key nobody wrote a rule for never reaches the database', function () {
    $document = everyKeyDocument();
    $document['stage']['background']['layers'][0]['onclick'] = 'alert(1)';
    $document['elements'][0]['style']['behavior'] = 'url(x.htc)';
    $document['elements'][0]['animations']['in']['onStart'] = 'alert(1)';
    $document['elements'][0]['script'] = '<script>alert(1)</script>';

    $id = $this->postJson('/builder', ['name' => 'Extra keys', 'document' => $document])->assertOk()->json('ad.id');
    $stored = BuilderAd::find($id)->document;

    expect($stored)->toEqual(everyKeyDocument())
        ->and(json_encode($stored))->not->toContain('alert(1)')->not->toContain('x.htc');
});

test('numbers outside what the compiler writes are refused, with a name a person can read', function (string $path, mixed $value, string $message) {
    $document = everyKeyDocument();
    data_set($document, $path, $value);

    $this->postJson('/builder', ['name' => 'Too much', 'document' => $document])
        ->assertStatus(422)
        ->assertJsonFragment([$message]);
})->with([
    'letter spacing' => ['elements.0.style.letterSpacing', 500, 'The letter spacing field must be between -100 and 100.'],
    'a shadow’s blur' => ['elements.1.style.shadow.blur', 9000, 'The shadow blur field must be between 0 and 500.'],
    'a line’s thickness' => ['elements.6.style.lineWidth', 999, 'The line width field must be between 1 and 200.'],
    'a filter' => ['elements.1.style.filters.brightness', 999, 'The filters brightness field must be between 0 and 300.'],
    'a gradient stop' => ['elements.3.style.gradient.stops.1.at', 140, 'The gradient stops at field must be between 0 and 100.'],
    'a layer’s opacity' => ['stage.background.layers.0.opacity', 3, 'The background layer opacity field must be between 0 and 1.'],
    'an entrance’s duration' => ['elements.0.animations.in.duration', 0, 'The entrance duration field must be between 0.05 and 60.'],
    'a loop’s amount' => ['elements.0.animations.loop.amount', 99999, 'The loop amount field must be between -2000 and 2000.'],
    'an exit’s start' => ['elements.0.animations.out.at', -1, 'The exit at field must be between 0 and 3600.'],
    'a rotation' => ['elements.0.rotation', 400, 'The rotation field must be between -360 and 360.'],
]);

test('names that are not on the lists are refused', function (string $path, mixed $value) {
    $document = everyKeyDocument();
    data_set($document, $path, $value);

    $this->postJson('/builder', ['name' => 'Wrong word', 'document' => $document])->assertStatus(422);
})->with([
    'a blend mode' => ['stage.background.layers.0.blend', 'plus-lighter'],
    'a layer kind' => ['stage.background.layers.0.type', 'iframe'],
    'a picture fit' => ['elements.1.style.fit', 'url(x)'],
    'a focus point' => ['elements.1.style.position', '10px 20px'],
    'a frame line' => ['elements.1.style.border.style', 'groove'],
    'a shape' => ['elements.3.style.shape', 'star'],
    'a gradient kind' => ['elements.3.style.gradient.kind', 'conic'],
    'an entrance' => ['elements.0.animations.in.effect', 'explode'],
    'a loop' => ['elements.0.animations.loop.effect', 'fade'],
    'a direction' => ['elements.0.animations.in.direction', 'sideways'],
    'an axis' => ['elements.0.animations.loop.axis', 'z'],
    'an ease' => ['elements.0.animations.in.ease', 'power9.out'],
    'an ease with code in it' => ['elements.0.animations.in.ease', 'cubic(alert(1),0,0,1)'],
    'a curve with three numbers' => ['elements.0.animations.in.ease', 'cubic(0.1,0.2,0.3)'],
]);

test('a list must be a list — an object keyed any old way comes back in no particular order', function (string $path) {
    $document = everyKeyDocument();
    $list = data_get($document, $path);
    data_set($document, $path, ['first' => $list[0], 'second' => $list[1]]);

    $this->postJson('/builder', ['name' => 'Keyed', 'document' => $document])->assertStatus(422);
})->with([
    'the elements' => ['elements'],
    'the background layers' => ['stage.background.layers'],
    'a gradient’s stops' => ['elements.3.style.gradient.stops'],
]);

test('a stop is both a colour and a place, and a gradient holds at most six', function () {
    $document = everyKeyDocument();
    unset($document['elements'][3]['style']['gradient']['stops'][1]['color']);

    $this->postJson('/builder', ['name' => 'Half a stop', 'document' => $document])->assertStatus(422);

    $document = everyKeyDocument();
    $document['elements'][3]['style']['gradient']['stops'] = array_fill(0, 7, ['color' => '#ffffff', 'at' => 50]);

    $this->postJson('/builder', ['name' => 'Seven stops', 'document' => $document])->assertStatus(422);
});

test('a background stacks at most twelve layers', function () {
    $document = everyKeyDocument();
    $document['stage']['background']['layers'] = array_map(
        fn (int $i) => ['id' => "bg_{$i}", 'type' => 'color', 'color' => '#000000'],
        range(1, 13),
    );

    $this->postJson('/builder', ['name' => 'Thirteen', 'document' => $document])
        ->assertStatus(422)
        ->assertJsonFragment(['A background may stack at most 12 layers.']);
});

test('guides travel with the design, at most fifty each way, and the television never sees them', function () {
    $document = everyKeyDocument();
    $document['guides']['y'] = range(10, 510, 10);           // 51

    $this->postJson('/builder', ['name' => 'Too many guides', 'document' => $document])
        ->assertStatus(422)
        ->assertJsonFragment(['A stage may keep at most 50 guides each way.']);

    $document = everyKeyDocument();
    $document['guides']['x'] = ['left'];

    $this->postJson('/builder', ['name' => 'A word', 'document' => $document])
        ->assertStatus(422)
        ->assertJsonFragment(['The guide across field must be a number.']);

    $document = everyKeyDocument();
    $document['guides']['x'] = ['a' => 100];

    $this->postJson('/builder', ['name' => 'Keyed', 'document' => $document])->assertStatus(422);

    // The design keeps them; the page a screen gets does not know they exist.
    $id = $this->postJson('/builder', ['name' => 'Guided', 'document' => everyKeyDocument()])->assertOk()->json('ad.id');
    $html = app(AdCompiler::class)->compile(BuilderAd::find($id));

    expect(BuilderAd::find($id)->document['guides'])->toEqual(['x' => [160, 960.5, -40], 'y' => [540]])
        ->and($html)->not->toContain('guide')->not->toContain('960.5');
});

test('a slot with no effect is kept as the editor sent it, and simply never plays', function () {
    $document = everyKeyDocument();
    $document['elements'][0]['animations'] = ['in' => null, 'loop' => null];

    $id = $this->postJson('/builder', ['name' => 'Still', 'document' => $document])->assertOk()->json('ad.id');

    expect(BuilderAd::find($id)->document['elements'][0]['animations'])->toBe(['in' => null, 'loop' => null]);
});
