<?php

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Services\OrganizationStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\VideoFiles;

/*
|--------------------------------------------------------------------------
| Every organization has 512 MB, and none goes past it
|--------------------------------------------------------------------------
|
| Owner's rule, 2026-09-28: "Har store ko 512 MB ki storage milegi, koi bhi store 512 MB se upar na ja sake".
| An organization's storage is whatever its rows name on disk: its library (the Media page, a channel's Upload, the Ad
| Builder's published pages), the Ad Builder's shelf, and every preview those rows name. Each door that adds
| to it asks OrganizationStorage first; the platform's own library has no wall.
|
*/

const MB = 1024;   // kilobytes, as UploadedFile::fake() counts them

beforeEach(function () {
    Storage::fake('public');

    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->manager = createOrganizationUser($this->organization, [
        'media-view', 'media-store', 'media-destroy', 'channel-view', 'channel-update', 'ad-view', 'ad-store', 'ad-update',
    ], 'Manager');
    $this->actingAs($this->manager)->withSession(['current_organization_id' => $this->organization->id]);
});

/** A file that reports $kilobytes and has no preview, so the arithmetic is exact. */
function sized(int $kilobytes, string $name = 'big.jpg'): UploadedFile
{
    return UploadedFile::fake()->create($name, $kilobytes);
}

function usedBy(Organization $organization): int
{
    return app(OrganizationStorage::class)->used($organization->id);
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

test('an organization fills up to exactly 512 MB, and not one kilobyte more', function () {
    $this->postJson('/media', ['file' => sized(250 * MB, 'one.jpg')])->assertOk();
    $this->postJson('/media', ['file' => sized(250 * MB, 'two.jpg')])->assertOk();
    $this->postJson('/media', ['file' => sized(12 * MB, 'three.jpg')])->assertOk();

    expect(usedBy($this->organization))->toBe(OrganizationStorage::LIMIT_BYTES);

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
    expect(Storage::disk('public')->allFiles("media/{$this->organization->id}"))->toHaveCount(2);
});

test('deleting a file makes room again', function () {
    $this->postJson('/media', ['file' => sized(250 * MB, 'one.jpg')])->assertOk();
    $this->postJson('/media', ['file' => sized(250 * MB, 'two.jpg')])->assertOk();
    $this->postJson('/media', ['file' => sized(100 * MB, 'three.jpg')])->assertStatus(422);

    $response = $this->deleteJson('/media/'.Media::firstWhere('title', 'one')->id)->assertOk();
    expect($response->json('storage'))->toBe(['used' => 250 * MB * 1024, 'limit' => OrganizationStorage::LIMIT_BYTES]);

    $this->postJson('/media', ['file' => sized(100 * MB, 'three.jpg')])->assertOk();
});

test("one organization's files are no other organization's: each has its own 512 MB", function () {
    $this->postJson('/media', ['file' => sized(250 * MB, 'one.jpg')])->assertOk();
    $this->postJson('/media', ['file' => sized(250 * MB, 'two.jpg')])->assertOk();

    $other = Organization::factory()->create(['name' => 'Beta Mart']);
    $this->actingAs(createOrganizationUser($other, ['media-store'], 'Other'))->withSession(['current_organization_id' => $other->id]);

    $this->postJson('/media', ['file' => sized(250 * MB, 'theirs.jpg')])->assertOk();
    expect(usedBy($other))->toBe(250 * MB * 1024)->and(usedBy($this->organization))->toBe(500 * MB * 1024);
});

test("the platform's own library has no wall, and an organization's library filled from the platform counts to the organization", function () {
    $this->actingAs(createSuperAdmin())->withSession([]);

    foreach (['a', 'b', 'c'] as $name) {
        $this->postJson('/media', ['file' => sized(250 * MB, "{$name}.jpg")])->assertOk();
    }

    $this->postJson('/media', ['file' => sized(250 * MB, 'one.jpg'), 'organization_id' => $this->organization->id])->assertOk();
    $this->postJson('/media', ['file' => sized(250 * MB, 'two.jpg'), 'organization_id' => $this->organization->id])->assertOk();
    $this->postJson('/media', ['file' => sized(20 * MB, 'three.jpg'), 'organization_id' => $this->organization->id])
        ->assertStatus(422)->assertJsonValidationErrors('file');

    expect(Media::whereNull('organization_id')->count())->toBe(3)
        ->and(Media::where('organization_id', $this->organization->id)->count())->toBe(2);
});

test("a channel's Upload fills its organization's library, and the platform's channel fills none", function () {
    $own = Channel::factory()->create(['organization_id' => $this->organization->id]);
    $this->postJson('/media', ['file' => sized(250 * MB, 'one.jpg')])->assertOk();
    $this->postJson('/media', ['file' => sized(250 * MB, 'two.jpg')])->assertOk();

    $this->postJson("/channels/{$own->id}/ads", ['file' => sized(20 * MB, 'deal.jpg'), 'seconds' => 10])
        ->assertStatus(422)->assertJsonValidationErrors('file');
    expect(ChannelAd::count())->toBe(0);

    $this->actingAs(createSuperAdmin(['channel-view', 'channel-update']))->withSession([]);
    $gama = Channel::factory()->create(['organization_id' => null, 'name' => 'GAMA']);
    $this->postJson("/channels/{$gama->id}/ads", ['file' => sized(250 * MB, 'gama.jpg'), 'seconds' => 10])->assertOk();
    expect(Media::whereNull('organization_id')->count())->toBe(1);
});

test("the Ad Builder's shelf counts, and is refused once the organization is full", function () {
    $this->postJson('/builder/assets', ['file' => sized(250 * MB, 'texture.jpg')])->assertOk();
    $this->postJson('/media', ['file' => sized(250 * MB, 'one.jpg')])->assertOk();

    $this->postJson('/builder/assets', ['file' => sized(20 * MB, 'logo.jpg')])
        ->assertStatus(422)->assertJsonValidationErrors('file');

    expect(BuilderAsset::count())->toBe(1)->and(usedBy($this->organization))->toBe(500 * MB * 1024);
});

test('previews count: a thumbnail and a poster take room like the files they show', function () {
    $this->postJson('/media', ['file' => UploadedFile::fake()->image('menu.jpg', 1600, 900)])->assertOk();
    $picture = Media::sole();

    expect($picture->thumbnail_path)->not->toBeNull()
        ->and(usedBy($this->organization))->toBe($picture->size + Storage::disk('public')->size($picture->thumbnail_path));

    $this->postJson('/media', ['file' => VideoFiles::upload(VideoFiles::mp4(10), 'clip.mp4'), 'poster' => designPoster()])->assertOk();
    $video = Media::latest('id')->first();

    expect($video->thumbnail_path)->not->toBeNull()
        ->and(usedBy($this->organization))->toBe($picture->size + $video->size
            + Storage::disk('public')->size($picture->thumbnail_path) + Storage::disk('public')->size($video->thumbnail_path));
});

test('a published page takes room; publishing into a full organization is refused, and the screens keep what they had', function () {
    $ad = BuilderAd::factory()->withText()->create(['organization_id' => $this->organization->id, 'name' => 'Winter sale']);
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();

    $page = $ad->fresh()->media;
    expect(usedBy($this->organization))->toBe($page->size);

    // Full: less than a page's worth left (a file is 250 MB at most, so three of them).
    $this->postJson('/media', ['file' => sized(250 * MB, 'one.jpg')])->assertOk();
    $this->postJson('/media', ['file' => sized(250 * MB, 'two.jpg')])->assertOk();
    $this->postJson('/media', ['file' => sized(12 * MB - (int) ceil($page->size / 1024), 'three.jpg')])->assertOk();

    $second = BuilderAd::factory()->withText()->create(['organization_id' => $this->organization->id, 'name' => 'Spring sale']);
    $this->postJson("/builder/{$second->id}/publish")
        ->assertStatus(422)
        ->assertJsonValidationErrors('publish');

    expect($second->fresh()->media_id)->toBeNull()
        ->and(Storage::disk('public')->exists($second->storageDirectory().'/index.html'))->toBeFalse();

    // Publishing the first again changes nothing in size, so it is never refused.
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();
});

test("a design's poster is never a refusal: a full organization saves the design and keeps the poster it had", function () {
    $ad = BuilderAd::factory()->withText()->create(['organization_id' => $this->organization->id, 'name' => 'Winter sale']);
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

    // …and a duplicate of it, in an organization full again, is a copy without one.
    $this->postJson('/media', ['file' => sized(12 * MB - (int) ceil(Storage::disk('public')->size($poster) / 1024), 'three.jpg')])->assertOk();
    $copy = BuilderAd::find($this->postJson("/builder/{$ad->id}/duplicate")->assertOk()->json('ad.id'));

    expect($copy->thumbnail_path)->toBeNull()->and(usedBy($this->organization))->toBeLessThanOrEqual(OrganizationStorage::LIMIT_BYTES);
});

test('an organization already over 512 MB from before the rule takes nothing more, and may still delete', function () {
    // Rows written before the wall existed: 600 MB.
    Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Old', 'size' => 600 * MB * 1024, 'thumbnail_path' => null]);

    $this->postJson('/media', ['file' => sized(1, 'tiny.jpg')])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'Not enough storage: this needs 1 KB, and Alpha Mart has no space left of its 512 MB. Delete files you no longer use to make room.']);

    $this->deleteJson('/media/'.Media::sole()->id)->assertOk();
    $this->postJson('/media', ['file' => sized(1, 'tiny.jpg')])->assertOk();
});

test('the pages are told how full the library they show is', function () {
    $this->postJson('/media', ['file' => sized(100 * MB, 'one.jpg')])->assertOk()
        ->assertJsonPath('storage', ['used' => 100 * MB * 1024, 'limit' => OrganizationStorage::LIMIT_BYTES]);

    $this->getJson('/media/data')->assertOk()->assertJsonPath('storage.used', 100 * MB * 1024);
    $this->getJson('/builder/assets/data')->assertOk()->assertJsonPath('storage.limit', OrganizationStorage::LIMIT_BYTES);

    // Above the organizations: the organization chosen, or nothing for the platform's own library.
    $this->actingAs(createSuperAdmin())->withSession([]);
    $this->getJson("/media/data?library={$this->organization->id}")->assertOk()->assertJsonPath('storage.used', 100 * MB * 1024);
    $this->getJson('/media/data?library=platform')->assertOk()->assertJsonPath('storage', null);
    $this->getJson('/builder/assets/data')->assertOk()->assertJsonPath('storage', null);
    $this->getJson("/builder/assets/data?organization_id={$this->organization->id}")->assertOk()->assertJsonPath('storage.used', 100 * MB * 1024);
});

test('sizes are said the way the panel says them', function () {
    expect(OrganizationStorage::inWords(1))->toBe('1 KB')
        ->and(OrganizationStorage::inWords(1536))->toBe('2 KB')
        ->and(OrganizationStorage::inWords(OrganizationStorage::LIMIT_BYTES))->toBe('512 MB')
        ->and(OrganizationStorage::inWords((int) (120.46 * 1024 * 1024)))->toBe('120.5 MB');
});
