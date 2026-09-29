<?php

use App\Models\BuilderAsset;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Screen;
use App\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\VideoFiles;

/*
|--------------------------------------------------------------------------
| No video over five minutes, whichever door it comes in by
|--------------------------------------------------------------------------
|
| Owner's rule, 2026-09-28: "Media Library aur Channel mein 5 min se zyada wali video upload na ho". A video
| reaches a shop's screens through its library, a channel's Upload, and the Ad Builder's shelf, and each door
| measures the FILE — the browser's number is only a field. Rounded as a phone shows it: 5:00 goes in, 5:01
| does not.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->store = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->manager = createStoreUser($this->store, ['media-view', 'media-store', 'channel-view', 'channel-update', 'ad-view', 'ad-store'], 'Manager');
    $this->actingAs($this->manager)->withSession(['current_store_id' => $this->store->id]);
    $this->channel = Channel::factory()->create(['store_id' => $this->store->id, 'name' => 'GHRA Ware House']);
});

/** Upload through one of the three doors, the way its page does. */
function uploadVideoThrough(string $door, UploadedFile $file, array $extra = [])
{
    $test = test();

    return match ($door) {
        'library' => $test->postJson('/media', ['file' => $file, ...$extra]),
        'channel' => $test->postJson("/channels/{$test->channel->id}/ads", ['file' => $file, ...$extra]),
        'shelf' => $test->postJson('/builder/assets', ['file' => $file, ...$extra]),
    };
}

/** What each door kept of an upload: its row's recorded length. */
function lengthKeptBy(string $door): ?int
{
    return match ($door) {
        'library', 'channel' => Media::sole()->duration_seconds,
        'shelf' => BuilderAsset::sole()->duration_seconds,
    };
}

dataset('doors', ['the Media page' => 'library', "a channel's Upload" => 'channel', "the Ad Builder's shelf" => 'shelf']);

test('a video of exactly five minutes goes in, and keeps its own length', function (string $door) {
    uploadVideoThrough($door, VideoFiles::upload(VideoFiles::mp4(300.4), 'menu.mp4'))->assertOk();

    expect(lengthKeptBy($door))->toBe(300);
})->with('doors');

test('a video one second over five minutes is refused, saying how long it is, and nothing is kept', function (string $door) {
    uploadVideoThrough($door, VideoFiles::upload(VideoFiles::mp4(301), 'menu.mp4'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'A video may be at most 5 minutes long. This one is 5:01.']);

    expect(Media::count() + BuilderAsset::count() + ChannelAd::count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
})->with('doors');

test('an hour-long video is refused at every door, in both formats and every layout', function (string $door, string $name, string $bytes) {
    uploadVideoThrough($door, VideoFiles::upload($bytes, $name))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'A video may be at most 5 minutes long. This one is 1:00:00.']);
})->with('doors')->with([
    'MP4, index first' => ['menu.mp4', VideoFiles::mp4(3600)],
    'MP4, index last' => ['menu.mp4', VideoFiles::mp4(3600, ['moov_at_end' => true])],
    'MP4, fragmented' => ['menu.mp4', VideoFiles::mp4(0, ['fragments' => [1200, 1200, 1200]])],
    'WebM' => ['menu.webm', VideoFiles::webm(3600)],
    'WebM with no Duration' => ['menu.webm', VideoFiles::webm(3600, ['with_duration' => false, 'unknown_sizes' => true])],
]);

test("the browser's word is not what is measured: a lie in either direction changes nothing", function (string $door) {
    // Claiming ten seconds for a ten-minute video does not get it in…
    uploadVideoThrough($door, VideoFiles::upload(VideoFiles::mp4(600), 'menu.mp4'), ['duration_seconds' => 10])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    // …and claiming an hour for a twenty-second one neither keeps it out nor is what is kept.
    uploadVideoThrough($door, VideoFiles::upload(VideoFiles::mp4(20), 'menu.mp4'), ['duration_seconds' => 3600])->assertOk();

    expect(lengthKeptBy($door))->toBe(20);
})->with('doors');

test('a video nobody can measure is refused at every door', function (string $door) {
    uploadVideoThrough($door, UploadedFile::fake()->create('menu.mp4', 500, 'video/mp4'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'We could not read how long this video is. Save it again as an MP4 and upload that file.']);
})->with('doors');

test('a picture has no length to measure and goes in as before', function (string $door) {
    uploadVideoThrough($door, UploadedFile::fake()->image('menu.jpg', 800, 600), ['seconds' => 10])->assertOk();
})->with('doors');

test("changing a channel ad's file to a video over five minutes is refused, and the ad keeps its file", function () {
    $this->postJson("/channels/{$this->channel->id}/ads", ['file' => VideoFiles::upload(VideoFiles::mp4(30), 'deal.mp4')])->assertOk();
    $ad = ChannelAd::sole();

    $this->postJson("/channels/{$this->channel->id}/ads/{$ad->id}", ['file' => VideoFiles::upload(VideoFiles::mp4(900), 'long.mp4')])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'A video may be at most 5 minutes long. This one is 15:00.']);

    expect($ad->fresh()->media->duration_seconds)->toBe(30)->and(Media::count())->toBe(1);
});

test('the platform team is held to the same five minutes, in the platform\'s library and in a shop\'s', function () {
    $this->actingAs(createSuperAdmin())->withSession([]);

    $this->postJson('/media', ['file' => VideoFiles::upload(VideoFiles::mp4(420), 'brand.mp4')])
        ->assertStatus(422)->assertJsonValidationErrors(['file' => 'A video may be at most 5 minutes long. This one is 7:00.']);

    $this->postJson('/media', ['file' => VideoFiles::upload(VideoFiles::mp4(420), 'brand.mp4'), 'store_id' => $this->store->id])
        ->assertStatus(422)->assertJsonValidationErrors('file');

    expect(Media::count())->toBe(0);
});

test('a video line on a playlist keeps its file\'s own length, whatever the save sends', function () {
    $screen = Screen::factory()->create(['store_id' => $this->store->id]);
    $video = Media::factory()->create(['store_id' => $this->store->id, 'type' => Media::TYPE_VIDEO, 'mime_type' => 'video/mp4', 'duration_seconds' => 40]);
    $image = Media::factory()->create(['store_id' => $this->store->id]);
    $manager = createStoreUser($this->store, ['screen-view', 'screen-playlist'], 'Screens');
    $this->actingAs($manager)->withSession(['current_store_id' => $this->store->id]);
    $version = $this->getJson("/screens/{$screen->id}/playlist")->json('version');

    // A day written by hand for the video: its line keeps the file's forty seconds (the player's backstop);
    // a picture keeps the seconds it was given.
    $this->putJson("/screens/{$screen->id}/playlist", ['version' => $version, 'items' => [
        ['media_id' => $video->id, 'duration_seconds' => 86400],
        ['media_id' => $image->id, 'duration_seconds' => 12],
    ]])->assertOk();

    expect($screen->playlistItems()->orderBy('position')->pluck('duration_seconds')->all())->toBe([40, 12]);
});
