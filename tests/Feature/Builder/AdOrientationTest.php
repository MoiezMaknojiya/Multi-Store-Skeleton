<?php

use App\Models\BuilderAd;
use App\Models\Channel;
use App\Models\Media;
use App\Models\Screen;
use App\Models\Store;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Portrait ads — which way the screen is mounted
|--------------------------------------------------------------------------
|
| docs/AD-BUILDER-SPEC.md §12 (owner, 2026-09-23: "Portrait Boards … lazmi"). An ad is landscape
| (1920 × 1080) or portrait (1080 × 1920), chosen when it is made and never changed after: the column is
| the truth, every save is measured against it, and the page, the media row and the pickers all say the
| same thing.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->store = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->designer = createStoreUser(
        $this->store,
        ['ad-view', 'ad-store', 'ad-update', 'ad-destroy', 'screen-view', 'screen-update', 'screen-playlist', 'channel-view', 'channel-update'],
        'Designer',
    );
    $this->actingAs($this->designer)->withSession(['current_store_id' => $this->store->id]);
});

/** A minimal, valid design of the given shape: one line of text on the stage. */
function orientedDocument(string $orientation): array
{
    return [
        ...BuilderAd::blankDocument($orientation),
        'elements' => [[
            'id' => 'el_1', 'type' => 'text', 'name' => 'Headline',
            'x' => 80, 'y' => 240, 'w' => 900, 'h' => 200,
            'rotation' => 0, 'opacity' => 1, 'z' => 0, 'locked' => false, 'visible' => true,
            'text' => 'Menu of the day',
            'style' => ['fontSize' => 96, 'color' => '#ffffff'],
            'animations' => [],
        ]],
    ];
}

test('Create asks which way the screen is mounted before the editor opens — then opens a stage of that shape', function () {
    // The Create tab: the choice, with both ways to go.
    $this->get('/builder/create')->assertOk()
        ->assertViewIs('builder.choose')
        ->assertSee(route('builder.create', ['orientation' => 'landscape']), false)
        ->assertSee(route('builder.create', ['orientation' => 'portrait']), false);

    $this->get('/builder/create?orientation=landscape')->assertOk()
        ->assertViewIs('builder.editor')
        ->assertViewHas('orientation', 'landscape')
        ->assertViewHas('document', fn (array $document) => $document['stage']['width'] === 1920 && $document['stage']['height'] === 1080);

    $this->get('/builder/create?orientation=portrait')->assertOk()
        ->assertViewIs('builder.editor')
        ->assertViewHas('orientation', 'portrait')
        ->assertViewHas('document', fn (array $document) => $document['stage']['width'] === 1080 && $document['stage']['height'] === 1920);

    // A page is forgiving: a word nobody offers, or a shape that is not a word, is no answer — the choice again.
    $this->get('/builder/create?orientation=sideways')->assertOk()->assertViewIs('builder.choose');
    $this->get('/builder/create?orientation[]=portrait')->assertOk()->assertViewIs('builder.choose');
});

test('a portrait ad is saved as one, with a 1080 × 1920 stage, and says so ever after', function () {
    $response = $this->postJson('/builder', [
        'name' => 'Menu board',
        'orientation' => 'portrait',
        'document' => orientedDocument('portrait'),
    ])->assertOk();

    $ad = BuilderAd::sole();

    expect($ad->orientation)->toBe('portrait')
        ->and($ad->isPortrait())->toBeTrue()
        ->and($ad->stageWidth())->toBe(1080)
        ->and($ad->stageHeight())->toBe(1920)
        ->and($ad->document['stage'])->toMatchArray(['width' => 1080, 'height' => 1920])
        ->and($response->json('ad.orientation'))->toBe('portrait');

    // The listing and the editor say which way it is.
    $row = collect($this->getJson('/builder/data')->assertOk()->json('ads'))->firstWhere('id', $ad->id);
    expect($row['orientation'])->toBe('portrait');

    $this->get("/builder/{$ad->id}")->assertOk()->assertViewHas('orientation', 'portrait');
});

test('an ad made without saying is landscape, the way every ad was before', function () {
    $this->postJson('/builder', ['name' => 'Poster', 'document' => orientedDocument('landscape')])->assertOk();

    expect(BuilderAd::sole()->orientation)->toBe('landscape');
});

test('a stage that is not its orientation’s size is refused — when the ad is made, and on every save after', function () {
    // Said portrait, drawn landscape: the two do not agree, and the message says which size this ad is.
    $errors = $this->postJson('/builder', ['name' => 'Menu board', 'orientation' => 'portrait', 'document' => orientedDocument('landscape')])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document.stage.width', 'document.stage.height'])
        ->json('errors');

    expect($errors['document.stage.width'][0])
        ->toBe('A portrait ad is 1080 × 1920 — the size a television mounted upright is. An ad\'s orientation is chosen when it is made.');

    // Said nothing (landscape), drawn portrait.
    $errors = $this->postJson('/builder', ['name' => 'Menu board', 'document' => orientedDocument('portrait')])
        ->assertStatus(422)
        ->json('errors');

    expect($errors['document.stage.width'][0])
        ->toBe('A landscape ad is 1920 × 1080 — the size a television is. An ad\'s orientation is chosen when it is made.');

    expect(BuilderAd::count())->toBe(0);

    // A saved portrait ad cannot be turned into a landscape one by saving a landscape design…
    $ad = BuilderAd::factory()->portrait()->withText()->create(['store_id' => $this->store->id]);

    $this->putJson("/builder/{$ad->id}", ['name' => $ad->name, 'document' => orientedDocument('landscape')])
        ->assertStatus(422)->assertJsonValidationErrors(['document.stage.width', 'document.stage.height']);

    // …nor by asking: on a saved ad the key is not read, and the design must still be the ad's size.
    $this->putJson("/builder/{$ad->id}", ['name' => $ad->name, 'orientation' => 'landscape', 'document' => orientedDocument('portrait')])
        ->assertOk();

    expect($ad->fresh()->orientation)->toBe('portrait');

    $this->putJson("/builder/{$ad->id}", ['name' => $ad->name, 'orientation' => 'landscape', 'document' => orientedDocument('landscape')])
        ->assertStatus(422);

    expect($ad->fresh()->orientation)->toBe('portrait')
        ->and($ad->fresh()->document['stage']['width'])->toBe(1080);
});

test('an orientation nobody offers is refused', function () {
    $this->postJson('/builder', ['name' => 'Square', 'orientation' => 'square', 'document' => orientedDocument('landscape')])
        ->assertStatus(422)->assertJsonValidationErrors('orientation');

    $this->postJson('/builder', ['name' => 'Square', 'orientation' => ['portrait'], 'document' => orientedDocument('landscape')])
        ->assertStatus(422)->assertJsonValidationErrors('orientation');

    expect(BuilderAd::count())->toBe(0);
});

test('publishing a portrait ad writes a page of its own size, on a portrait media row, with the stage colour around it', function () {
    $ad = BuilderAd::factory()->portrait()->withText('Menu of the day')->create(['store_id' => $this->store->id, 'name' => 'Menu board']);

    $this->postJson("/builder/{$ad->id}/publish")->assertOk();

    $media = Media::sole();

    expect($media->orientation)->toBe('portrait')
        ->and($media->width)->toBe(1080)
        ->and($media->height)->toBe(1920);

    $html = Storage::disk('public')->get($media->path);

    expect($html)->toContain('width: 1080px')
        ->toContain('height: 1920px')
        ->toContain('window.innerWidth / 1080')
        ->toContain('window.innerHeight / 1920')
        // The page's ground is the stage's own colour, so the bars a landscape screen shows are the design's.
        ->toContain('html, body { margin: 0; padding: 0; height: 100%; background: #0f172a;')
        ->not->toContain('width: 1920px');
});

test('a landscape ad still publishes as it always did', function () {
    $ad = BuilderAd::factory()->withText()->create(['store_id' => $this->store->id]);

    $this->postJson("/builder/{$ad->id}/publish")->assertOk();

    $media = Media::sole();
    $html = Storage::disk('public')->get($media->path);

    expect($media->orientation)->toBe('landscape')
        ->and($media->width)->toBe(1920)
        ->and($media->height)->toBe(1080)
        ->and($html)->toContain('width: 1920px')->toContain('window.innerWidth / 1920');
});

test('a copy keeps the orientation', function () {
    $ad = BuilderAd::factory()->portrait()->withText()->create(['store_id' => $this->store->id, 'name' => 'Menu board']);

    $this->postJson("/builder/{$ad->id}/duplicate")->assertOk()->assertJsonPath('ad.orientation', 'portrait');

    $copy = BuilderAd::firstWhere('name', 'Menu board (copy)');

    expect($copy->orientation)->toBe('portrait')
        ->and($copy->document['stage']['width'])->toBe(1080);
});

test('every picker and playlist line says which way a portrait page is', function () {
    $ad = BuilderAd::factory()->portrait()->withText()->published()->create(['store_id' => $this->store->id, 'name' => 'Menu board']);
    // The factory's published row is a plain ad page; the publisher is what stamps the shape on it.
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();
    $media = $ad->fresh()->media;
    $screen = Screen::factory()->create(['store_id' => $this->store->id, 'orientation' => 'landscape']);

    expect($media->orientation)->toBe('portrait');

    // The playlist's picker…
    $offered = collect($this->getJson("/screens/{$screen->id}/available-media")->assertOk()->json('media'))->firstWhere('id', $media->id);
    expect($offered['orientation'])->toBe('portrait');

    // …the holding picture's list…
    $option = collect($this->getJson("/screens/{$screen->id}/media-options")->assertOk()->json('media'))->firstWhere('id', $media->id);
    expect($option['orientation'])->toBe('portrait');

    // …a saved line…
    $version = $this->getJson("/screens/{$screen->id}/playlist")->json('version');
    $this->putJson("/screens/{$screen->id}/playlist", [
        'version' => $version,
        'items' => [['media_id' => $media->id, 'duration_seconds' => 10, 'rules' => []]],
    ])->assertOk();

    $line = collect($this->getJson("/screens/{$screen->id}/playlist")->assertOk()->json('items'))->firstWhere('media_id', $media->id);
    expect($line['orientation'])->toBe('portrait');

    // …and a channel's library.
    $channel = Channel::factory()->create(['store_id' => $this->store->id]);
    $inLibrary = collect($this->getJson("/channels/{$channel->id}/library?type=html")->assertOk()->json('media'))->firstWhere('id', $media->id);
    expect($inLibrary['orientation'])->toBe('portrait');
});

test('the blank documents and the factory agree on the two sizes', function () {
    expect(BuilderAd::blankDocument()['stage'])->toMatchArray(['width' => 1920, 'height' => 1080])
        ->and(BuilderAd::blankDocument('portrait')['stage'])->toMatchArray(['width' => 1080, 'height' => 1920])
        ->and(BuilderAd::blankDocument('sideways')['stage'])->toMatchArray(['width' => 1920, 'height' => 1080])
        ->and(BuilderAd::stageSize(null))->toBe([1920, 1080]);

    $portrait = BuilderAd::factory()->withText()->portrait()->make();
    $alsoPortrait = BuilderAd::factory()->portrait()->withText()->make();

    expect($portrait->document['stage'])->toMatchArray(['width' => 1080, 'height' => 1920])
        ->and($portrait->document['elements'][0]['text'])->toBe('Winter sale')
        ->and($alsoPortrait->document['stage'])->toMatchArray(['width' => 1080, 'height' => 1920])
        ->and($alsoPortrait->document['elements'][0]['text'])->toBe('Winter sale');
});
