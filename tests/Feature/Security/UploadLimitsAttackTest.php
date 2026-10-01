<?php

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Screen;
use App\Services\OrganizationStorage;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\VideoFiles;

/*
|--------------------------------------------------------------------------
| The two upload limits, attacked on purpose
|--------------------------------------------------------------------------
|
| Owner, 2026-09-28: 5 minutes a video in a library or a channel, 60 seconds an advert, 512 MB an organization — "brute
| force laga kar bhi test karna". Everything a client controls is a lie here: the length it reports, the organization
| it names, how many requests it sends and when, and what the file's own boxes claim. What decides is what the
| server reads from the bytes and counts under the organization's lock.
|
*/

const KB_PER_MB = 1024;

beforeEach(function () {
    Storage::fake('public');

    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->member = createOrganizationUser($this->organization, ['media-view', 'media-store', 'channel-view', 'channel-update', 'ad-view', 'ad-store', 'ad-update'], 'Member');
    $this->actingAs($this->member)->withSession(['current_organization_id' => $this->organization->id]);
});

function filling(int $megabytes, string $name): UploadedFile
{
    return UploadedFile::fake()->create($name, $megabytes * KB_PER_MB);
}

/** The organization filled to within $leftKilobytes of its wall, by rows alone. */
function fillTo(Organization $organization, int $leftKilobytes): void
{
    Media::factory()->create(['organization_id' => $organization->id, 'title' => 'Everything else', 'thumbnail_path' => null, 'size' => OrganizationStorage::LIMIT_BYTES - $leftKilobytes * 1024]);
}

/**
 * The moment the organization's row is locked — the upload's second, decisive look — another upload lands first:
 * one PHP process cannot race itself, so the competitor is written from inside the query listener.
 */
function anotherUploadLandsAtTheLock(Organization $organization, int $kilobytes): object
{
    $landed = (object) ['done' => false];

    DB::listen(function (QueryExecuted $query) use ($landed, $organization, $kilobytes) {
        if (! $landed->done && str_starts_with($query->sql, 'select "id" from "organizations" where "organizations"."id" =')) {
            $landed->done = true;
            Media::factory()->create(['organization_id' => $organization->id, 'title' => 'The other upload', 'thumbnail_path' => null, 'size' => $kilobytes * 1024]);
        }
    });

    return $landed;
}

/*
|--------------------------------------------------------------------------
| 512 MB
|--------------------------------------------------------------------------
*/

test('two uploads at the same moment into the last megabytes: the second is seen under the lock, and refused', function (string $door) {
    fillTo($this->organization, 30 * KB_PER_MB);
    $landed = anotherUploadLandsAtTheLock($this->organization, 20 * KB_PER_MB);

    $response = match ($door) {
        'library' => $this->postJson('/media', ['file' => filling(20, 'mine.jpg')]),
        'channel' => $this->postJson('/channels/'.Channel::factory()->create(['organization_id' => $this->organization->id])->id.'/ads', ['file' => filling(20, 'mine.jpg'), 'seconds' => 10]),
        'shelf' => $this->postJson('/builder/assets', ['file' => filling(20, 'mine.jpg')]),
    };

    $response->assertStatus(422)->assertJsonValidationErrors('file');

    expect($landed->done)->toBeTrue()
        ->and(app(OrganizationStorage::class)->used($this->organization->id))->toBeLessThanOrEqual(OrganizationStorage::LIMIT_BYTES)
        ->and(Media::where('title', 'mine')->exists() || BuilderAsset::where('title', 'mine')->exists())->toBeFalse()
        // …and its file did not stay behind on disk with no row to name it.
        ->and(collect(Storage::disk('public')->allFiles())->count())->toBe(0);
})->with(['library', 'channel', 'shelf']);

test('a publish at the same moment as an upload is decided under the same lock', function () {
    $ad = BuilderAd::factory()->withText()->create(['organization_id' => $this->organization->id]);
    fillTo($this->organization, 40);
    $landed = anotherUploadLandsAtTheLock($this->organization, 39);

    $this->postJson("/builder/{$ad->id}/publish")->assertStatus(422)->assertJsonValidationErrors('publish');

    expect($landed->done)->toBeTrue()->and($ad->fresh()->media_id)->toBeNull();
});

test('two hundred uploads in a row never take an organization past 512 MB', function () {
    $accepted = 0;

    foreach (range(1, 200) as $i) {
        $status = $this->postJson('/media', ['file' => filling(3, "flood-{$i}.jpg")])->status();
        expect($status)->toBeIn([200, 422]);
        $accepted += $status === 200 ? 1 : 0;
    }

    // 170 × 3 MB = 510 MB; the 171st would be 513.
    expect($accepted)->toBe(170)
        ->and(app(OrganizationStorage::class)->used($this->organization->id))->toBe(510 * KB_PER_MB * 1024);
});

test('naming another organization, or no organization, does not move an upload out of its own 512 MB', function () {
    fillTo($this->organization, 10 * KB_PER_MB);
    $roomy = Organization::factory()->create(['name' => 'Roomy Mart']);

    foreach ([['organization_id' => $roomy->id], ['organization_id' => ''], ['organization_id' => 0]] as $claim) {
        $this->postJson('/media', ['file' => filling(20, 'mine.jpg'), ...$claim])->assertStatus(422);
    }

    expect(Media::where('organization_id', $roomy->id)->count())->toBe(0)
        ->and(Media::whereNull('organization_id')->count())->toBe(0);
});

test('a huge poster cannot slip a small video past the wall: its preview is counted too', function () {
    fillTo($this->organization, 200);

    // A noisy 1000 × 1000 frame, inside every limit a poster has: GD's own JPEG of it is far more than the
    // video itself, and far more than the 200 KB the organization has left.
    $noise = imagecreatetruecolor(1000, 1000);
    for ($i = 0; $i < 250_000; $i++) {
        imagesetpixel($noise, random_int(0, 999), random_int(0, 999), random_int(0, 0xFFFFFF));
    }
    ob_start();
    imagejpeg($noise, null, 90);
    $jpeg = (string) ob_get_clean();
    $poster = 'data:image/jpeg;base64,'.base64_encode($jpeg);

    expect(strlen($jpeg))->toBeGreaterThan(300 * 1024)->toBeLessThan(2 * 1024 * 1024);

    $this->postJson('/media', ['file' => VideoFiles::upload(VideoFiles::mp4(10), 'tiny.mp4'), 'poster' => $poster])
        ->assertStatus(422)->assertJsonValidationErrors('file');

    expect(Media::where('title', 'tiny')->exists())->toBeFalse()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('a client-sent size means nothing: what is counted is what the file weighs', function () {
    fillTo($this->organization, 10 * KB_PER_MB);

    $this->postJson('/media', ['file' => filling(20, 'mine.jpg'), 'size' => 1, 'bytes' => 1])->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| 5 minutes, 60 seconds
|--------------------------------------------------------------------------
*/

test('a crafted file cannot claim a short length over a long one', function (string $name, string $bytes) {
    $this->postJson('/media', ['file' => VideoFiles::upload($bytes, $name), 'duration_seconds' => 30])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    expect(Media::count())->toBe(0);
})->with([
    'MP4: a one-minute header over an hour of track' => ['a.mp4', VideoFiles::mp4(3600, ['mvhd_seconds' => 60])],
    'MP4: fragments adding up to an hour, no header at all' => ['a.mp4', VideoFiles::mp4(0, ['fragments' => array_fill(0, 12, 300.0)])],
    'MP4: a short video track beside an hour of sound' => ['a.mp4', VideoFiles::mp4(30, ['audio_seconds' => 3600])],
    'WebM: a thirty-second Duration over an hour of clusters' => ['a.webm', VideoFiles::webm(3600, ['declared_seconds' => 30])],
    'a WebM wearing an .mp4 name' => ['a.mp4', VideoFiles::webm(3600)],
]);

test('broken and hostile videos are refused, never a 500 and never a file left behind', function (string $bytes) {
    $this->postJson('/media', ['file' => VideoFiles::upload($bytes, 'x.mp4')])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    expect(Storage::disk('public')->allFiles())->toBe([]);
})->with([
    'an MP4 header and then nothing' => [VideoFiles::box('ftyp', 'isom', pack('N', 0), 'isom').str_repeat("\0", 64)],
    'an MP4 whose moov is cut short' => [substr(VideoFiles::mp4(60), 0, -30)],
    'a million empty boxes' => [VideoFiles::box('ftyp', 'isom', pack('N', 0), 'isom').str_repeat(pack('N', 8).'free', 1_100_000)],
    'a WebM header and garbage' => [substr(VideoFiles::webm(10), 0, 40).random_bytes(2000)],
]);

test('an advert over one break is refused however it is dressed', function () {
    $this->actingAs(createSuperAdmin())->withSession([]);

    foreach ([
        VideoFiles::upload(VideoFiles::mp4(61, ['moov_at_end' => true]), 'a.mp4'),
        VideoFiles::upload(VideoFiles::mp4(0, ['fragments' => [30, 30, 30]]), 'b.mp4'),
        VideoFiles::upload(VideoFiles::webm(90, ['with_duration' => false, 'unknown_sizes' => true]), 'c.webm'),
    ] as $file) {
        $this->postJson('/campaigns', ['name' => 'Brand', 'is_active' => '1', 'screen_ids' => [], 'file' => $file, 'duration_seconds' => 15])
            ->assertStatus(422)->assertJsonValidationErrors('file');
    }

    expect(Campaign::count())->toBe(0);
});

test('a playlist written by hand cannot give a video more time than its file has', function () {
    $screen = Screen::factory()->create(['organization_id' => $this->organization->id]);
    $video = Media::factory()->create(['organization_id' => $this->organization->id, 'type' => Media::TYPE_VIDEO, 'mime_type' => 'video/mp4', 'duration_seconds' => 300]);
    $this->actingAs(createOrganizationUser($this->organization, ['screen-view', 'screen-playlist'], 'Screens'))->withSession(['current_organization_id' => $this->organization->id]);
    $version = $this->getJson("/screens/{$screen->id}/playlist")->json('version');

    $this->putJson("/screens/{$screen->id}/playlist", ['version' => $version, 'items' => [
        ['media_id' => $video->id, 'duration_seconds' => 86400],
    ]])->assertOk();

    // The line — and so the player's backstop on every television — is the file's five minutes.
    expect($screen->playlistItems()->sole()->duration_seconds)->toBe(300);
});
