<?php

use App\Models\BuilderAd;
use App\Models\Organization;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| An ad stays on screen at least as long as its animations run
|--------------------------------------------------------------------------
|
| The owner, 2026-10-14: "agar ad builder mein animation 10 seconds se ziyada ho toh minimum second wahi dikhaye aur
| max toh 5 minutes ha" — and, asked what happens when an exit is set past the length: "Length khud badhe". The least
| an ad may last is the moment its last shown animation is over — an entrance landed, an exit gone, a loop round once
| — rounded up to a whole second, never under BuilderAd::MIN_SECONDS nor over BuilderAd::MAX_SECONDS. The editor
| raises the length by itself (AdLengthFollowsAnimationsFlowTest); this is the wall behind it.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->designer = createOrganizationUser($this->organization, ['ad-view', 'ad-store', 'ad-update'], 'Designer');
    $this->actingAs($this->designer)->withSession(['current_organization_id' => $this->organization->id]);
});

/** The factory's text element, as the rules take it, with these animations. */
function movingText(array $animations, string $id = 'el_text', bool $visible = true, ?string $parentId = null): array
{
    $element = BuilderAd::factory()->withText('Sale')->make()->document['elements'][0];

    return [
        ...$element,
        'id' => $id, 'visible' => $visible, 'animations' => $animations,
        ...($parentId !== null ? ['parentId' => $parentId] : []),
    ];
}

/** A design of these elements lasting $seconds. */
function designOf(array $elements, ?int $seconds): array
{
    $document = [...BuilderAd::blankDocument(), 'elements' => $elements];

    if ($seconds === null) {
        unset($document['duration']);
    } else {
        $document['duration'] = $seconds;
    }

    return $document;
}

test('the least length is when the last animation is over, rounded up, never under ten nor past five minutes', function (array $elements, int $least) {
    expect(BuilderAd::leastLengthFor(designOf($elements, 10)))->toBe($least);
})->with([
    'nothing moves' => [fn () => [movingText([])], 10],
    'an entrance that lands by 9.8 s' => [fn () => [movingText(['in' => ['effect' => 'fade', 'delay' => 9, 'duration' => 0.8]])], 10],
    'an entrance that lands at 12.6 s' => [fn () => [movingText(['in' => ['effect' => 'fade', 'delay' => 12, 'duration' => 0.6]])], 13],
    'an exit gone by 15.6 s' => [fn () => [movingText(['out' => ['effect' => 'fade', 'at' => 15, 'duration' => 0.6]])], 16],
    'a loop round once, after the entrance and its own wait' => [fn () => [movingText([
        'in' => ['effect' => 'fade', 'delay' => 8, 'duration' => 1],
        'loop' => ['effect' => 'pulse', 'delay' => 2, 'duration' => 3],
    ])], 14],
    'the latest of several elements' => [fn () => [
        movingText(['out' => ['effect' => 'fade', 'at' => 20, 'duration' => 0.6]], 'el_a'),
        movingText(['in' => ['effect' => 'fade', 'delay' => 25, 'duration' => 2]], 'el_b'),
    ], 27],
    'an element that is hidden is on no screen' => [fn () => [movingText(['out' => ['effect' => 'fade', 'at' => 40, 'duration' => 1]], 'el_a', false)], 10],
    'an element inside a hidden group is on no screen either' => [fn () => [
        ['id' => 'grp', 'type' => 'group', 'name' => 'Group', 'x' => 160, 'y' => 400, 'w' => 1600, 'h' => 240, 'rotation' => 0, 'opacity' => 1, 'z' => 0, 'locked' => false, 'visible' => false],
        movingText(['out' => ['effect' => 'fade', 'at' => 40, 'duration' => 1]], 'el_a', true, 'grp'),
    ], 10],
    'an effect nobody knows is no animation' => [fn () => [movingText(['out' => ['effect' => 'teleport', 'at' => 40, 'duration' => 1]])], 10],
    'an exit at the last moment an ad may have' => [fn () => [movingText(['out' => ['effect' => 'fade', 'at' => 300, 'duration' => 1]])], 300],
]);

test('a length shorter than the animations is refused in the editor’s words; one as long is kept', function () {
    $elements = [movingText(['out' => ['effect' => 'fade', 'at' => 15, 'duration' => 0.6]])];

    $this->postJson('/builder', ['name' => 'Exit late', 'document' => designOf($elements, 12)])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document.duration' => "This ad's animations run for 16 seconds, so it stays on screen for at least 16 seconds."]);

    $id = $this->postJson('/builder', ['name' => 'Exit late', 'document' => designOf($elements, 16)])->assertOk()->json('ad.id');
    expect(BuilderAd::find($id)->document['duration'])->toBe(16);

    // The same on a save of a design that is already there.
    $this->putJson("/builder/{$id}", ['name' => 'Exit late', 'document' => designOf($elements, 15)])
        ->assertStatus(422)->assertJsonValidationErrors('document.duration');
});

test('a design with no length of its own is not asked: each screen gives it its own seconds', function () {
    $elements = [movingText(['out' => ['effect' => 'fade', 'at' => 40, 'duration' => 1]])];

    $this->postJson('/builder', ['name' => 'Old sale', 'document' => designOf($elements, null)])->assertOk();
});

test('a document of any shape gives a least length, never an error', function (mixed $document) {
    // The rule runs on a save whatever else in the document is wrong: a parent posted as a list once made it a 500.
    expect(BuilderAd::leastLengthFor($document))->toBe(BuilderAd::MIN_SECONDS);
})->with([
    'nothing' => [null],
    'no elements' => [['version' => 1]],
    'elements that are a word' => [['elements' => 'x']],
    'an element that is a word' => [['elements' => ['x']]],
    'an id and a parent that are lists' => [['elements' => [['id' => ['x'], 'parentId' => ['y'], 'animations' => ['out' => ['effect' => 'fade', 'at' => 40]]]]]],
    'a parent that is a number' => [['elements' => [['id' => 'a', 'parentId' => 7, 'animations' => ['out' => ['effect' => 'fade', 'at' => 40]]]]]],
    'animations that are a word' => [['elements' => [['id' => 'a', 'animations' => 'fade']]]],
    'a slot that is a word' => [['elements' => [['id' => 'a', 'animations' => ['out' => 'fade']]]]],
    'a parent that is its own' => [['elements' => [['id' => 'a', 'parentId' => 'a', 'animations' => ['out' => ['effect' => 'fade', 'at' => 40]]]]]],
]);
