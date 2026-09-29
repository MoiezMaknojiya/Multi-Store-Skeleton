<?php

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Store;
use App\Services\StoreStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\VideoFiles;

/*
|--------------------------------------------------------------------------
| Every shop has 512 MB, and none goes past it
|--------------------------------------------------------------------------
|
| Owner's rule, 2026-09-28: "Har store ko 512 MB ki storage milegi, koi bhi store 512 MB se upar na ja sake".
| A shop's storage is whatever its rows name on disk: its library (the Media page, a channel's Upload, the Ad
| Builder's published pages), the Ad Builder's shelf, and every preview those rows name. Each door that adds
| to it asks StoreStorage first; the platform's own library has no wall.
|
*/

const MB = 1024;   // kilobytes, as UploadedFile::fake() counts them

beforeEach(function () {
    Storage::fake('public');

    $this->store = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->manager = createStoreUser($this->store, [
        'media-view', 'media-store', 'media-destroy', 'channel-view', 'channel-update', 'ad-view', 'ad-store', 'ad-update',
    ], 'Manager');
    $this->actingAs($this->manager)->withSession(['current_store_id' => $this->store->id]);
});

/** A file that reports $kilobytes and has no preview, so the arithmetic is exact. */
function sized(int $kilobytes, string $name = 'big.jpg'): UploadedFile
{
    return UploadedFile::fake()->create($name, $kilobytes);
}

function usedBy(Store $store): int
{
    return app(StoreStorage::class)->used($store->id);
}

/** A poster as the editor hands one over. */
function designPoster(): string
{
    $image = imagecreatetruecolor(320, 180);
    imagefilledrectangle($image, 0, 0, 320, 180, imagecolorallocate($image, 200, 40, 40));

    ob_start();
    imagejpeg($image, null, 85);

    return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
}

test('a shop fills up to exactly 512 MB, and not one kilobyte more', function () {
    $this->postJson('/media', ['file' => sized(250 * MB, 'one.jpg')])->assertOk();
    $this->postJson('/media', ['file' => sized(250 * MB, 'two.jpg')])->assertOk();
    $this->postJson('/media', ['file' => sized(12 * MB, 'three.jpg')])->assertOk();

    expect(usedBy($this->store))->toBe(StoreStorage::LIMIT_BYTES);

    $this->postJson('/media', ['file' => sized(1, 'four.jpg')])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'Not enough storage: this needs 1 KB, and Alpha Mart has no space left of its 512 MB. Delete files you no longer use to make room.']);

    expect(Media::count())->toBe(3);
});

test('the refusal says what the file needs and what is left', function () {
    $this->postJson('/media', ['file' => sized(250 * MB, 'one.jpg')])->assertOk();
    $this->postJson('/media', ['file' => sized(200 * MB, 'two.jpg')])->assertOk();

    $this->postJson('/media', ['file' => sized(100 * MB, 'three.jpg')])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'Not enough storage: this needs 100 MB, and Alpha Mart has 62 MB left of its 512 MB. Delete files you no longer use to make room.']);

    // …and nothing of it stayed on disk.
    expect(Storage::disk('public')->allFiles("media/{$this->store->id}"))->toHaveCount(2);
});

test('deleting a file makes room again', function () {
    $this->postJson('/media', ['file' => sized(250 * MB, 'one.jpg')])->assertOk();
    $this->postJson('/media', ['file' => sized(250 * MB, 'two.jpg')])->assertOk();
    $this->postJson('/media', ['file' => sized(100 * MB, 'three.jpg')])->assertStatus(422);

    $response = $this->deleteJson('/media/'.Media::firstWhere('title', 'one')->id)->assertOk();
    expect($response->json('storage'))->toBe(['used' => 250 * MB * 1024, 'limit' => StoreStorage::LIMIT_BYTES]);

    $this->postJson('/media', ['file' => sized(100 * MB, 'three.jpg')])->assertOk();
});

test("one shop's files are no other shop's: each has its own 512 MB", function () {
    $this->postJson('/media', ['file' => sized(250 * MB, 'one.jpg')])->assertOk();
    $this->postJson('/media', ['file' => sized(250 * MB, 'two.jpg')])->assertOk();

    $other = Store::factory()->create(['name' => 'Beta Mart']);
    $this->actingAs(createStoreUser($other, ['media-store'], 'Other'))->withSession(['current_store_id' => $other->id]);

    $this->postJson('/media', ['file' => sized(250 * MB, 'theirs.jpg')])->assertOk();
    expect(usedBy($other))->toBe(250 * MB * 1024)->and(usedBy($this->store))->toBe(500 * MB * 1024);
});

test("the platform's own library has no wall, and a shop's library filled from the platform counts to the shop", function () {
    $this->actingAs(createSuperAdmin())->withSession([]);

    foreach (['a', 'b', 'c'] as $name) {
        $this->postJson('/media', ['file' => sized(250 * MB, "{$name}.jpg")])->assertOk();
    }

    $this->postJson('/media', ['file' => sized(250 * MB, 'one.jpg'), 'store_id' => $this->store->id])->assertOk();
    $this->postJson('/media', ['file' => sized(250 * MB, 'two.jpg'), 'store_id' => $this->store->id])->assertOk();
    $this->postJson('/media', ['file' => sized(20 * MB, 'three.jpg'), 'store_id' => $this->store->id])
        ->assertStatus(422)->assertJsonValidationErrors('file');

    expect(Media::whereNull('store_id')->count())->toBe(3)
        ->and(Media::where('store_id', $this->store->id)->count())->toBe(2);
});

test("a channel's Upload fills its shop's library, and the platform's channel fills none", function () {
    $own = Channel::factory()->create(['store_id' => $this->store->id]);
    $this->postJson('/media', ['file' => sized(250 * MB, 'one.jpg')])->assertOk();
    $this->postJson('/media', ['file' => sized(250 * MB, 'two.jpg')])->assertOk();

    $this->postJson("/channels/{$own->id}/ads", ['file' => sized(20 * MB, 'deal.jpg'), 'seconds' => 10])
        ->assertStatus(422)->assertJsonValidationErrors('file');
    expect(ChannelAd::count())->toBe(0);

    $this->actingAs(createSuperAdmin(['channel-view', 'channel-update']))->withSession([]);
    $gama = Channel::factory()->create(['store_id' => null, 'name' => 'GAMA']);
    $this->postJson("/channels/{$gama->id}/ads", ['file' => sized(250 * MB, 'gama.jpg'), 'seconds' => 10])->assertOk();
    expect(Media::whereNull('store_id')->count())->toBe(1);
});

test("the Ad Builder's shelf counts, and is refused once the shop is full", function () {
    $this->postJson('/builder/assets', ['file' => sized(250 * MB, 'texture.jpg')])->assertOk();
    $this->postJson('/media', ['file' => sized(250 * MB, 'one.jpg')])->assertOk();

    $this->postJson('/builder/assets', ['file' => sized(20 * MB, 'logo.jpg')])
        ->assertStatus(422)->assertJsonValidationErrors('file');

    expect(BuilderAsset::count())->toBe(1)->and(usedBy($this->store))->toBe(500 * MB * 1024);
});

test('previews count: a thumbnail and a poster take room like the files they show', function () {
    $this->postJson('/media', ['file' => UploadedFile::fake()->image('menu.jpg', 1600, 900)])->assertOk();
    $picture = Media::sole();

    expect($picture->thumbnail_path)->not->toBeNull()
        ->and(usedBy($this->store))->toBe($picture->size + Storage::disk('public')->size($picture->thumbnail_path));

    $this->postJson('/media', ['file' => VideoFiles::upload(VideoFiles::mp4(10), 'clip.mp4'), 'poster' => designPoster()])->assertOk();
    $video = Media::latest('id')->first();

    expect($video->thumbnail_path)->not->toBeNull()
        ->and(usedBy($this->store))->toBe($picture->size + $video->size
            + Storage::disk('public')->size($picture->thumbnail_path) + Storage::disk('public')->size($video->thumbnail_path));
});

test('a published page takes room; publishing into a full shop is refused, and the screens keep what they had', function () {
    $ad = BuilderAd::factory()->withText()->create(['store_id' => $this->store->id, 'name' => 'Winter sale']);
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();

    $page = $ad->fresh()->media;
    expect(usedBy($this->store))->toBe($page->size);

    // Full: less than a page's worth left (a file is 250 MB at most, so three of them).
    $this->postJson('/media', ['file' => sized(250 * MB, 'one.jpg')])->assertOk();
    $this->postJson('/media', ['file' => sized(250 * MB, 'two.jpg')])->assertOk();
    $this->postJson('/media', ['file' => sized(12 * MB - (int) ceil($page->size / 1024), 'three.jpg')])->assertOk();

    $second = BuilderAd::factory()->withText()->create(['store_id' => $this->store->id, 'name' => 'Spring sale']);
    $this->postJson("/builder/{$second->id}/publish")
        ->assertStatus(422)
        ->assertJsonValidationErrors('publish');

    expect($second->fresh()->media_id)->toBeNull()
        ->and(Storage::disk('public')->exists($second->storageDirectory().'/index.html'))->toBeFalse();

    // Publishing the first again changes nothing in size, so it is never refused.
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();
});

test("a design's poster is never a refusal: a full shop saves the design and keeps the poster it had", function () {
    $ad = BuilderAd::factory()->withText()->create(['store_id' => $this->store->id, 'name' => 'Winter sale']);
    $this->postJson('/media', ['file' => sized(250 * MB, 'one.jpg')])->assertOk();
    $this->postJson('/media', ['file' => sized(250 * MB, 'two.jpg')])->assertOk();
    $this->postJson('/media', ['file' => sized(12 * MB, 'three.jpg')])->assertOk();

    $this->putJson("/builder/{$ad->id}", ['name' => 'Winter sale', 'document' => $ad->document, 'thumbnail' => designPoster()])->assertOk();
    expect($ad->fresh()->thumbnail_path)->toBeNull();

    // With room it is kept…
    $this->deleteJson('/media/'.Media::firstWhere('title', 'three')->id)->assertOk();
    $this->putJson("/builder/{$ad->id}", ['name' => 'Winter sale', 'document' => $ad->document, 'thumbnail' => designPoster()])->assertOk();
    $poster = $ad->fresh()->thumbnail_path;
    expect($poster)->not->toBeNull();

    // …and a duplicate of it, in a shop full again, is a copy without one.
    $this->postJson('/media', ['file' => sized(12 * MB - (int) ceil(Storage::disk('public')->size($poster) / 1024), 'three.jpg')])->assertOk();
    $copy = BuilderAd::find($this->postJson("/builder/{$ad->id}/duplicate")->assertOk()->json('ad.id'));

    expect($copy->thumbnail_path)->toBeNull()->and(usedBy($this->store))->toBeLessThanOrEqual(StoreStorage::LIMIT_BYTES);
});

test('a shop already over 512 MB from before the rule takes nothing more, and may still delete', function () {
    // Rows written before the wall existed: 600 MB.
    Media::factory()->create(['store_id' => $this->store->id, 'title' => 'Old', 'size' => 600 * MB * 1024, 'thumbnail_path' => null]);

    $this->postJson('/media', ['file' => sized(1, 'tiny.jpg')])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'Not enough storage: this needs 1 KB, and Alpha Mart has no space left of its 512 MB. Delete files you no longer use to make room.']);

    $this->deleteJson('/media/'.Media::sole()->id)->assertOk();
    $this->postJson('/media', ['file' => sized(1, 'tiny.jpg')])->assertOk();
});

test('the pages are told how full the library they show is', function () {
    $this->postJson('/media', ['file' => sized(100 * MB, 'one.jpg')])->assertOk()
        ->assertJsonPath('storage', ['used' => 100 * MB * 1024, 'limit' => StoreStorage::LIMIT_BYTES]);

    $this->getJson('/media/data')->assertOk()->assertJsonPath('storage.used', 100 * MB * 1024);
    $this->getJson('/builder/assets/data')->assertOk()->assertJsonPath('storage.limit', StoreStorage::LIMIT_BYTES);

    // Above the stores: the shop chosen, or nothing for the platform's own library.
    $this->actingAs(createSuperAdmin())->withSession([]);
    $this->getJson("/media/data?library={$this->store->id}")->assertOk()->assertJsonPath('storage.used', 100 * MB * 1024);
    $this->getJson('/media/data?library=platform')->assertOk()->assertJsonPath('storage', null);
    $this->getJson('/builder/assets/data')->assertOk()->assertJsonPath('storage', null);
    $this->getJson("/builder/assets/data?store_id={$this->store->id}")->assertOk()->assertJsonPath('storage.used', 100 * MB * 1024);
});

test('sizes are said the way the panel says them', function () {
    expect(StoreStorage::inWords(1))->toBe('1 KB')
        ->and(StoreStorage::inWords(1536))->toBe('2 KB')
        ->and(StoreStorage::inWords(StoreStorage::LIMIT_BYTES))->toBe('512 MB')
        ->and(StoreStorage::inWords((int) (120.46 * 1024 * 1024)))->toBe('120.5 MB');
});
