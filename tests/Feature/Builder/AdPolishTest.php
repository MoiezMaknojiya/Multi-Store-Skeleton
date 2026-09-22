<?php

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Media;
use App\Models\Store;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Stage 5 on the server — posters, the draft preview, the platform's filter
|--------------------------------------------------------------------------
|
| docs/AD-BUILDER-SPEC.md §10a. The poster is a picture the BROWSER drew, so the server keeps only what GD
| can read as one, and writes its own re-encoding. The preview is the page a screen would get, served in a
| sandbox. The filter only ever narrows what a person may already see.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->store = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->other = Store::factory()->create(['name' => 'Beta Deli']);
    $this->designer = createStoreUser($this->store, ['ad-view', 'ad-store', 'ad-update', 'ad-destroy'], 'Designer');
    $this->actingAs($this->designer)->withSession(['current_store_id' => $this->store->id]);
});

/** A real picture as a data URI, the way a canvas hands one over. */
function pictureDataUri(string $format = 'jpeg', int $width = 640, int $height = 360): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 30, 120, 220));

    ob_start();
    $format === 'png' ? imagepng($image) : imagejpeg($image, null, 90);

    return "data:image/{$format};base64,".base64_encode((string) ob_get_clean());
}

/** A new ad saved from the editor with the poster it drew. */
function saveWithPoster(object $test, string $thumbnail): BuilderAd
{
    $id = $test->postJson('/builder', ['name' => 'With a poster', 'document' => BuilderAd::blankDocument(), 'thumbnail' => $thumbnail])
        ->assertOk()->json('ad.id');

    return BuilderAd::findOrFail($id);
}

/* ── Posters ─────────────────────────────────────────────────────────── */

test('a poster is kept in the ad’s own folder as a JPEG the server drew itself', function () {
    $sent = pictureDataUri('png');
    $ad = saveWithPoster($this, $sent);

    expect($ad->thumbnail_path)->toBe("builder/{$this->store->id}/ads/{$ad->id}/poster.jpg");

    $stored = Storage::disk('public')->get($ad->thumbnail_path);
    $size = getimagesizefromstring($stored);

    // A PNG was sent; what is on disk is GD's JPEG, not those bytes.
    expect($size[2])->toBe(IMAGETYPE_JPEG)
        ->and([$size[0], $size[1]])->toBe([640, 360])
        ->and($stored)->not->toBe(base64_decode(substr($sent, strlen('data:image/png;base64,'))));

    // The address carries the version, so a cache never shows an old poster.
    expect($ad->thumbnail_url)->toEndWith('/poster.jpg?v='.$ad->updated_at->getTimestamp());
});

test('a poster that is not a picture never reaches the disk', function (string $thumbnail) {
    $ad = saveWithPoster($this, $thumbnail);

    expect($ad->thumbnail_path)->toBeNull()
        ->and(Storage::disk('public')->allFiles("builder/{$this->store->id}"))->toBe([]);
})->with([
    'PHP wearing a JPEG label' => ['data:image/jpeg;base64,'.base64_encode('<?php system($_GET["c"]); ?>')],
    'a page wearing a PNG label' => ['data:image/png;base64,'.base64_encode('<html><script>alert(1)</script></html>')],
    'an SVG, which can carry script' => ['data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>')],
    'a GIF, which is not what a poster is' => ['data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'],
    'base64 that is not base64' => ['data:image/jpeg;base64,%%%not-base64%%%'],
    'nothing after the label' => ['data:image/png;base64,'],
]);

test('a picture that claims to be larger than a poster may be is refused before it is decoded', function () {
    // A real PNG 5000 pixels wide — past the 4096 a poster may measure either way. Only 50 tall, so the test
    // stays cheap to draw; the width alone is refused, read off the header before GD would allocate for it.
    $ad = saveWithPoster($this, pictureDataUri('png', 5000, 50));

    expect($ad->thumbnail_path)->toBeNull();
});

test('publishing shows the poster in the media library, at an address that changes with each publish', function () {
    $ad = saveWithPoster($this, pictureDataUri());

    $this->postJson("/builder/{$ad->id}/publish")->assertOk();

    $media = Media::sole();

    // The library row has a copy of its own — the same picture, a different file.
    expect($media->thumbnail_path)->toBe($ad->storageDirectory().'/published.jpg')
        ->and($media->thumbnail_path)->not->toBe($ad->thumbnail_path)
        ->and(Storage::disk('public')->get($media->thumbnail_path))->toBe(Storage::disk('public')->get($ad->thumbnail_path))
        ->and($media->thumbnail_url)->toContain('/published.jpg?v='.$media->updated_at->getTimestamp());

    // An ordinary file's thumbnail keeps its plain address.
    $picture = Media::factory()->create(['store_id' => $this->store->id, 'thumbnail_path' => 'media/1/thumbs/a.jpg']);

    expect($picture->thumbnail_url)->toEndWith('/media/1/thumbs/a.jpg');
});

test('deleting the published page from the library leaves the design its poster', function () {
    $ad = saveWithPoster($this, pictureDataUri());
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();
    $media = Media::sole();

    $librarian = createStoreUser($this->store, ['media-view', 'media-destroy'], 'Librarian');
    $this->actingAs($librarian)->withSession(['current_store_id' => $this->store->id])
        ->deleteJson("/media/{$media->id}")->assertOk();

    Storage::disk('public')->assertMissing($media->path);
    Storage::disk('public')->assertMissing($media->thumbnail_path);
    Storage::disk('public')->assertExists($ad->fresh()->thumbnail_path);
    expect($ad->fresh()->media_id)->toBeNull();
});

test('a row published before it had a poster of its own still leaves the design its poster', function () {
    // Published by an earlier version, which pointed the library row at the design's own file.
    $ad = saveWithPoster($this, pictureDataUri());
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();
    $media = Media::sole();
    $media->forceFill(['thumbnail_path' => $ad->thumbnail_path])->save();

    $librarian = createStoreUser($this->store, ['media-view', 'media-destroy'], 'Librarian');
    $this->actingAs($librarian)->withSession(['current_store_id' => $this->store->id])
        ->deleteJson("/media/{$media->id}")->assertOk();

    Storage::disk('public')->assertMissing($media->path);
    Storage::disk('public')->assertExists($ad->thumbnail_path);
});

test('republishing a design that changed nothing measurable still moves the row, so screens fetch it again', function () {
    // Same name, same page length: without a fresh timestamp no column would change and the checksum a
    // screen caches by would stay on the old version.
    $ad = saveWithPoster($this, pictureDataUri());
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();
    $first = Media::sole()->cacheKey();

    $this->travel(5)->seconds();
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();

    expect(Media::sole()->cacheKey())->not->toBe($first);
});

test('a copy gets a poster file of its own, so deleting the original leaves the copy’s picture', function () {
    $ad = saveWithPoster($this, pictureDataUri());

    $copyId = $this->postJson("/builder/{$ad->id}/duplicate")->assertOk()->json('ad.id');
    $copy = BuilderAd::find($copyId);

    expect($copy->thumbnail_path)->toBe("builder/{$this->store->id}/ads/{$copy->id}/poster.jpg")
        ->and($copy->thumbnail_path)->not->toBe($ad->thumbnail_path);

    Storage::disk('public')->assertExists($copy->thumbnail_path);

    $this->deleteJson("/builder/{$ad->id}", ['password' => 'password'])->assertOk();

    Storage::disk('public')->assertMissing($ad->thumbnail_path);
    Storage::disk('public')->assertExists($copy->thumbnail_path);
});

/* ── The draft preview ───────────────────────────────────────────────── */

test('the preview is the saved design as a screen would show it, sandboxed, and nothing is written', function () {
    $ad = BuilderAd::factory()->withText('Saved words')->create(['store_id' => $this->store->id]);

    $response = $this->get("/builder/{$ad->id}/preview")->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toBe('sandbox allow-scripts')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Content-Type'))->toContain('text/html')
        ->and($response->getContent())->toContain('Saved words')->toContain('<!doctype html>')
        ->and(Media::count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and($ad->fresh()->published_at)->toBeNull();
});

test('the preview keeps the store wall and asks for ad-view or ad-update', function () {
    $theirs = BuilderAd::factory()->withText()->create(['store_id' => $this->other->id]);

    $this->get("/builder/{$theirs->id}/preview")->assertNotFound();

    $mine = BuilderAd::factory()->withText()->create(['store_id' => $this->store->id]);
    $stranger = createStoreUser($this->store, ['screen-view'], 'No ads');

    $this->actingAs($stranger)->withSession(['current_store_id' => $this->store->id])
        ->get("/builder/{$mine->id}/preview")->assertForbidden();

    // Previewing is part of designing: whoever may change the ad may preview it, View Ads or not.
    $editor = createStoreUser($this->store, ['ad-update'], 'Changes ads only');

    $this->actingAs($editor)->withSession(['current_store_id' => $this->store->id])
        ->get("/builder/{$mine->id}/preview")->assertOk();
    $this->get("/builder/{$theirs->id}/preview")->assertNotFound();
});

/* ── The platform's filter ───────────────────────────────────────────── */

test('above the stores, the Ads and Assets tabs narrow to one shop', function () {
    $admin = createSuperAdmin();
    BuilderAd::factory()->create(['store_id' => $this->store->id, 'name' => 'Alpha ad']);
    BuilderAd::factory()->create(['store_id' => $this->other->id, 'name' => 'Beta ad']);
    BuilderAsset::factory()->create(['store_id' => $this->store->id, 'title' => 'Alpha logo']);
    BuilderAsset::factory()->create(['store_id' => $this->other->id, 'title' => 'Beta logo']);

    $this->actingAs($admin)->flushSession();

    $names = fn (string $url, string $key, string $field) => collect($this->getJson($url)->assertOk()->json($key))->pluck($field)->sort()->values()->all();

    expect($names('/builder/data', 'ads', 'name'))->toBe(['Alpha ad', 'Beta ad'])
        ->and($names("/builder/data?store_id={$this->other->id}", 'ads', 'name'))->toBe(['Beta ad'])
        ->and($names('/builder/assets/data', 'assets', 'title'))->toBe(['Alpha logo', 'Beta logo'])
        ->and($names("/builder/assets/data?store_id={$this->store->id}", 'assets', 'title'))->toBe(['Alpha logo']);

    // The tabs offer the filter above the stores…
    $this->get('/builder')->assertOk()->assertSee('dusk="ads-filter-store"', false)->assertSee('Beta Deli');
    $this->get('/builder/assets')->assertOk()->assertSee('dusk="assets-filter-store"', false);
});

test('inside a store the filter is not offered, and naming another shop finds nothing', function () {
    BuilderAd::factory()->create(['store_id' => $this->store->id, 'name' => 'Mine']);
    BuilderAd::factory()->create(['store_id' => $this->other->id, 'name' => 'Theirs']);
    BuilderAsset::factory()->create(['store_id' => $this->other->id, 'title' => 'Their logo']);

    $this->get('/builder')->assertOk()->assertDontSee('dusk="ads-filter-store"', false);

    expect($this->getJson("/builder/data?store_id={$this->other->id}")->assertOk()->json('ads'))->toBe([])
        ->and($this->getJson("/builder/assets/data?store_id={$this->other->id}")->assertOk()->json('assets'))->toBe([]);

    // A filter of the wrong shape is refused, never a 500.
    $this->getJson('/builder/data?store_id[]=1')->assertStatus(422);
    $this->getJson('/builder/assets/data?store_id=abc')->assertStatus(422);
});
