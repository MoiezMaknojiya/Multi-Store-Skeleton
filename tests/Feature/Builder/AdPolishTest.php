<?php

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Media;
use App\Models\Organization;
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

    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->other = Organization::factory()->create(['name' => 'Beta Deli']);
    $this->designer = createOrganizationUser($this->organization, ['ad-view', 'ad-store', 'ad-update', 'ad-destroy'], 'Designer');
    $this->actingAs($this->designer)->withSession(['current_organization_id' => $this->organization->id]);
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

    expect($ad->thumbnail_path)->toBe("builder/{$this->organization->id}/ads/{$ad->id}/poster.jpg");

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
        ->and(Storage::disk('public')->allFiles("builder/{$this->organization->id}"))->toBe([]);
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
    $picture = Media::factory()->create(['organization_id' => $this->organization->id, 'thumbnail_path' => 'media/1/thumbs/a.jpg']);

    expect($picture->thumbnail_url)->toEndWith('/media/1/thumbs/a.jpg');
});

test('deleting the published page from the library leaves the design its poster', function () {
    $ad = saveWithPoster($this, pictureDataUri());
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();
    $media = Media::sole();

    $librarian = createOrganizationUser($this->organization, ['media-view', 'media-destroy'], 'Librarian');
    $this->actingAs($librarian)->withSession(['current_organization_id' => $this->organization->id])
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

    $librarian = createOrganizationUser($this->organization, ['media-view', 'media-destroy'], 'Librarian');
    $this->actingAs($librarian)->withSession(['current_organization_id' => $this->organization->id])
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

    expect($copy->thumbnail_path)->toBe("builder/{$this->organization->id}/ads/{$copy->id}/poster.jpg")
        ->and($copy->thumbnail_path)->not->toBe($ad->thumbnail_path);

    Storage::disk('public')->assertExists($copy->thumbnail_path);

    $this->deleteJson("/builder/{$ad->id}", ['password' => 'password'])->assertOk();

    Storage::disk('public')->assertMissing($ad->thumbnail_path);
    Storage::disk('public')->assertExists($copy->thumbnail_path);
});

/* ── The draft preview ───────────────────────────────────────────────── */

test('the preview is the saved design as a screen would show it, sandboxed, and nothing is written', function () {
    $ad = BuilderAd::factory()->withText('Saved words')->create(['organization_id' => $this->organization->id]);

    $response = $this->get("/builder/{$ad->id}/preview")->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toBe('sandbox allow-scripts')
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache')->toContain('private')
        ->and($response->headers->get('Content-Type'))->toContain('text/html')
        ->and($response->getContent())->toContain('Saved words')->toContain('<!doctype html>')
        ->and(Media::count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and($ad->fresh()->published_at)->toBeNull();
});

test('a preview left open is compiled again only when the draft has changed', function () {
    // The brute-force round, 2026-09-29: the preview reloads itself at the ad's length, and each reload compiled the
    // whole ad again. Now the browser asks with the version it has, and an unchanged draft answers 304, empty.
    $ad = BuilderAd::factory()->withText('Saved words')->create(['organization_id' => $this->organization->id]);

    $first = $this->get("/builder/{$ad->id}/preview")->assertOk();
    $etag = $first->headers->get('ETag');

    expect($etag)->not->toBeEmpty();
    $this->get("/builder/{$ad->id}/preview", ['If-None-Match' => $etag])->assertStatus(304)->assertContent('');

    // Changed: the next reload gets the new design.
    $this->putJson("/builder/{$ad->id}", ['name' => $ad->name, 'document' => [...$ad->document, 'elements' => [
        [...$ad->document['elements'][0], 'text' => 'New words'],
    ]]])->assertOk();

    expect($this->get("/builder/{$ad->id}/preview", ['If-None-Match' => $etag])->assertOk()->getContent())->toContain('New words');
});

test('the preview keeps the organization wall and asks for ad-view or ad-update', function () {
    $theirs = BuilderAd::factory()->withText()->create(['organization_id' => $this->other->id]);

    $this->get("/builder/{$theirs->id}/preview")->assertNotFound();

    $mine = BuilderAd::factory()->withText()->create(['organization_id' => $this->organization->id]);
    $stranger = createOrganizationUser($this->organization, ['screen-view'], 'No ads');

    $this->actingAs($stranger)->withSession(['current_organization_id' => $this->organization->id])
        ->get("/builder/{$mine->id}/preview")->assertForbidden();

    // Previewing is part of designing: whoever may change the ad may preview it, View Ads or not.
    $editor = createOrganizationUser($this->organization, ['ad-update'], 'Changes ads only');

    $this->actingAs($editor)->withSession(['current_organization_id' => $this->organization->id])
        ->get("/builder/{$mine->id}/preview")->assertOk();
    $this->get("/builder/{$theirs->id}/preview")->assertNotFound();
});

/* ── The platform's filter ───────────────────────────────────────────── */

test('above the organizations, the Ads and Assets pages narrow to one organization', function () {
    $admin = createSuperAdmin();
    BuilderAd::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Alpha ad']);
    BuilderAd::factory()->create(['organization_id' => $this->other->id, 'name' => 'Beta ad']);
    BuilderAsset::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Alpha logo']);
    BuilderAsset::factory()->create(['organization_id' => $this->other->id, 'title' => 'Beta logo']);

    $this->actingAs($admin)->flushSession();

    $names = fn (string $url, string $key, string $field) => collect($this->getJson($url)->assertOk()->json($key))->pluck($field)->sort()->values()->all();

    expect($names('/builder/data', 'ads', 'name'))->toBe(['Alpha ad', 'Beta ad'])
        ->and($names("/builder/data?organization_id={$this->other->id}", 'ads', 'name'))->toBe(['Beta ad'])
        ->and($names('/builder/assets/data', 'assets', 'title'))->toBe(['Alpha logo', 'Beta logo'])
        ->and($names("/builder/assets/data?organization_id={$this->organization->id}", 'assets', 'title'))->toBe(['Alpha logo']);

    // The tabs offer the filter above the organizations…
    $this->get('/builder')->assertOk()->assertSee('dusk="ads-filter-organization"', false)->assertSee('Beta Deli');
    $this->get('/builder/assets')->assertOk()->assertSee('dusk="assets-filter-organization"', false);
});

test('inside an organization the filter is not offered, and naming another organization finds nothing', function () {
    BuilderAd::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Mine']);
    BuilderAd::factory()->create(['organization_id' => $this->other->id, 'name' => 'Theirs']);
    BuilderAsset::factory()->create(['organization_id' => $this->other->id, 'title' => 'Their logo']);

    $this->get('/builder')->assertOk()->assertDontSee('dusk="ads-filter-organization"', false);

    expect($this->getJson("/builder/data?organization_id={$this->other->id}")->assertOk()->json('ads'))->toBe([])
        ->and($this->getJson("/builder/assets/data?organization_id={$this->other->id}")->assertOk()->json('assets'))->toBe([]);

    // A filter of the wrong shape is refused, never a 500.
    $this->getJson('/builder/data?organization_id[]=1')->assertStatus(422);
    $this->getJson('/builder/assets/data?organization_id=abc')->assertStatus(422);
});
