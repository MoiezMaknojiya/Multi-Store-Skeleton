<?php

use App\Models\BuilderAd;
use App\Models\Store;
use App\Services\AdCompiler;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Groups — several elements made one
|--------------------------------------------------------------------------
|
| docs/AD-BUILDER-SPEC.md §13. A group is an element of type `group`; what is inside it says so with
| `parentId`. The rules check the elements as a tree, and the compiler writes the tree: a group is a
| wrapper its animations move, with its children placed against its own origin.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->store = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->designer = createStoreUser($this->store, ['ad-view', 'ad-store', 'ad-update', 'ad-destroy'], 'Designer');
    $this->actingAs($this->designer)->withSession(['current_store_id' => $this->store->id]);
});

/** A box with the keys every element carries. */
function box(string $id, string $type, int $x, int $y, int $w, int $h, int $z, array $more = []): array
{
    return [
        'id' => $id, 'type' => $type, 'name' => ucfirst($type), 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
        'rotation' => 0, 'opacity' => 1, 'z' => $z, 'locked' => false, 'visible' => true,
        'style' => [], 'animations' => [],
        ...$more,
    ];
}

/** A menu row: a group holding a name and a price, beside a headline outside it. */
function groupedDocument(): array
{
    return [
        ...BuilderAd::blankDocument(),
        'elements' => [
            box('headline', 'text', 100, 60, 800, 120, 0, ['text' => 'Menu of the day', 'style' => ['fontSize' => 96, 'color' => '#ffffff']]),
            box('row', 'group', 100, 300, 900, 100, 1, ['animations' => ['in' => ['effect' => 'fade', 'duration' => 1, 'delay' => 0.5, 'ease' => 'power2.out']]]),
            box('dish', 'text', 100, 300, 600, 100, 2, ['parentId' => 'row', 'text' => 'Chicken karahi', 'style' => ['fontSize' => 64, 'color' => '#ffffff']]),
            box('price', 'text', 800, 300, 200, 100, 3, ['parentId' => 'row', 'text' => '$12', 'style' => ['fontSize' => 64, 'color' => '#ffd166'], 'animations' => ['loop' => ['effect' => 'pulse', 'amount' => 6, 'duration' => 1.2]]]),
        ],
    ];
}

test('a group and what is inside it come back from a save exactly as sent', function () {
    $id = $this->postJson('/builder', ['name' => 'Menu', 'document' => groupedDocument()])->assertOk()->json('ad.id');

    $saved = BuilderAd::find($id)->document;
    $byId = collect($saved['elements'])->keyBy('id');

    expect($byId->get('row')['type'])->toBe('group')
        ->and($byId->get('dish')['parentId'])->toBe('row')
        ->and($byId->get('price')['parentId'])->toBe('row')
        ->and($byId->get('headline'))->not->toHaveKey('parentId');
});

test('the elements must make a tree: a real group as parent, no circles, three deep at most', function (array $elements, string $message) {
    $document = [...BuilderAd::blankDocument(), 'elements' => $elements];

    $errors = $this->postJson('/builder', ['name' => 'Broken tree', 'document' => $document])
        ->assertStatus(422)
        ->assertJsonValidationErrors('document.elements')
        ->json('errors');

    expect($errors['document.elements'][0])->toBe($message);
    expect(BuilderAd::count())->toBe(0);
})->with([
    'a parent that does not exist' => [
        [box('a', 'text', 0, 0, 10, 10, 0, ['text' => 'a', 'parentId' => 'nowhere'])],
        "An element's group must be a group in this design, and not the element itself.",
    ],
    'a parent that is a text, not a group' => [
        [box('a', 'text', 0, 0, 10, 10, 0, ['text' => 'a']), box('b', 'text', 0, 0, 10, 10, 1, ['text' => 'b', 'parentId' => 'a'])],
        "An element's group must be a group in this design, and not the element itself.",
    ],
    'an element inside itself' => [
        [box('g', 'group', 0, 0, 10, 10, 0, ['parentId' => 'g'])],
        "An element's group must be a group in this design, and not the element itself.",
    ],
    'two groups inside each other' => [
        [box('g1', 'group', 0, 0, 10, 10, 0, ['parentId' => 'g2']), box('g2', 'group', 0, 0, 10, 10, 1, ['parentId' => 'g1'])],
        'A group cannot be inside itself.',
    ],
    'four groups deep' => [
        [
            box('g1', 'group', 0, 0, 10, 10, 0),
            box('g2', 'group', 0, 0, 10, 10, 1, ['parentId' => 'g1']),
            box('g3', 'group', 0, 0, 10, 10, 2, ['parentId' => 'g2']),
            box('g4', 'group', 0, 0, 10, 10, 3, ['parentId' => 'g3']),
            box('leaf', 'text', 0, 0, 10, 10, 4, ['parentId' => 'g4', 'text' => 'deep']),
        ],
        'Groups can be 3 deep at most.',
    ],
]);

test('three groups deep is allowed', function () {
    $document = [...BuilderAd::blankDocument(), 'elements' => [
        box('g1', 'group', 0, 0, 10, 10, 0),
        box('g2', 'group', 0, 0, 10, 10, 1, ['parentId' => 'g1']),
        box('g3', 'group', 0, 0, 10, 10, 2, ['parentId' => 'g2']),
        box('leaf', 'text', 0, 0, 10, 10, 3, ['parentId' => 'g3', 'text' => 'deep enough']),
    ]];

    $this->postJson('/builder', ['name' => 'Nested', 'document' => $document])->assertOk();
});

test('the page writes a group as a wrapper its children sit inside, each against the group’s origin', function () {
    $ad = BuilderAd::factory()->create(['store_id' => $this->store->id, 'document' => groupedDocument()]);

    $html = app(AdCompiler::class)->compile($ad);

    // The wrapper: the group's own animation id, its box (the box around its children), and its entrance.
    expect($html)->toMatch('/<div class="ad-el ad-pending" data-anim-id="row" style="left:100px;top:300px;width:900px;height:100px;[^"]*"><div class="ad-anim">/');

    // Its children are INSIDE it, placed against its origin: the dish at 0,0 and the price at 700,0.
    $wrapper = substr($html, strpos($html, 'data-anim-id="row"'));
    $dish = strpos($wrapper, 'data-anim-id="dish"');
    $price = strpos($wrapper, 'data-anim-id="price"');
    $end = strpos($wrapper, '</div></div>', $price);

    expect($dish)->toBeGreaterThan(0)
        ->and($price)->toBeGreaterThan($dish)
        ->and($end)->toBeGreaterThan($price)
        ->and($wrapper)->toContain('data-anim-id="dish" style="left:0px;top:0px;width:600px;height:100px;')
        ->and($wrapper)->toContain('data-anim-id="price" style="left:700px;top:0px;width:200px;height:100px;');

    // The headline stays on the stage itself, at its own place.
    expect($html)->toContain('data-anim-id="headline" style="left:100px;top:60px;');

    // The animations block carries the group's entrance AND the price's loop: both run on the page.
    expect($html)->toContain('"row":{"in":')->toContain('"price":{"loop":');
});

test('a group’s box is the box around its children, rotated corners counted — never the numbers it carries', function () {
    $document = [...BuilderAd::blankDocument(), 'elements' => [
        // The group claims a box of its own; the page ignores it.
        box('g', 'group', 5, 5, 5, 5, 0),
        box('a', 'text', 200, 200, 100, 100, 1, ['parentId' => 'g', 'text' => 'a']),
        // A square turned 45° reaches further than its box does.
        box('b', 'text', 400, 200, 100, 100, 2, ['parentId' => 'g', 'text' => 'b', 'rotation' => 45]),
    ]];
    $ad = BuilderAd::factory()->create(['store_id' => $this->store->id, 'document' => $document]);

    $html = app(AdCompiler::class)->compile($ad);

    // b's diagonal is 141.42: turned about its centre (450, 250) its box runs from 379.29 to 520.71 across
    // and 179.29 to 320.71 down — so the group runs from a's left edge, 200, to 520.71.
    expect($html)->toMatch('/data-anim-id="g" style="left:200px;top:179\.289px;width:320\.711px;height:141\.421px;/')
        ->and($html)->toContain('data-anim-id="a" style="left:0px;top:20.711px;');
});

test('a hidden group takes everything inside it off the page, and an element nobody can reach is not written', function () {
    $document = groupedDocument();
    $document['elements'][1]['visible'] = false;
    // An orphan in a circle of its own: never reached from the top, so never written — and never a hang.
    $document['elements'][] = box('lost', 'text', 0, 0, 10, 10, 9, ['text' => 'lost', 'parentId' => 'nowhere']);

    $ad = BuilderAd::factory()->create(['store_id' => $this->store->id, 'document' => $document]);

    $html = app(AdCompiler::class)->compile($ad);

    expect($html)->toContain('data-anim-id="headline"')
        ->not->toContain('data-anim-id="row"')
        ->not->toContain('Chicken karahi')
        ->not->toContain('"price":{"loop"')
        // A parent that is not a group of this design reads as the top level: the element is still there.
        ->and($html)->toContain('data-anim-id="lost" style="left:0px;top:0px;');
});

test('a design already stored with a circle of groups still compiles — and writes neither of them', function () {
    $document = [...BuilderAd::blankDocument(), 'elements' => [
        box('headline', 'text', 100, 60, 800, 120, 0, ['text' => 'Still here']),
        box('g1', 'group', 0, 0, 10, 10, 1, ['parentId' => 'g2']),
        box('g2', 'group', 0, 0, 10, 10, 2, ['parentId' => 'g1']),
        box('inside', 'text', 0, 0, 10, 10, 3, ['parentId' => 'g1', 'text' => 'unreachable']),
    ]];
    $ad = BuilderAd::factory()->create(['store_id' => $this->store->id, 'document' => $document]);

    $html = app(AdCompiler::class)->compile($ad);

    expect($html)->toContain('Still here')
        ->not->toContain('data-anim-id="g1"')
        ->not->toContain('unreachable');
});

test('a group with nothing shown inside it writes nothing, not an empty wrapper', function () {
    $document = groupedDocument();
    $document['elements'][2]['visible'] = false;
    $document['elements'][3]['visible'] = false;

    $ad = BuilderAd::factory()->create(['store_id' => $this->store->id, 'document' => $document]);

    expect(app(AdCompiler::class)->compile($ad))->not->toContain('data-anim-id="row"');
});
