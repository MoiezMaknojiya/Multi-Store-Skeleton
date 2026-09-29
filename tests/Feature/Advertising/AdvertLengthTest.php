<?php

use App\Models\Campaign;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\VideoFiles;

/*
|--------------------------------------------------------------------------
| An advert is one break long at most: 60 seconds
|--------------------------------------------------------------------------
|
| Owner's rule, 2026-09-28: "Ads Network mein 60 seconds". A picture's seconds and a video's own length,
| measured from the file — never the browser's number. A longer advert could only ever play alone and hold a
| break past its ceiling (NetworkAdResolver::trimToBreak lets the first one through whatever its length).
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->admin = createSuperAdmin();
    $this->actingAs($this->admin);
});

function advert(array $fields): array
{
    return array_replace(['name' => 'Coca-Cola', 'is_active' => '1', 'screen_ids' => []], $fields);
}

test('a video advert of 60 seconds goes in; one of 61 is refused, saying how long it is', function () {
    $this->postJson('/campaigns', advert(['file' => VideoFiles::upload(VideoFiles::mp4(60.4), 'coke.mp4')]))->assertOk();
    expect(Campaign::sole()->play_seconds)->toBe(60);

    $this->postJson('/campaigns', advert(['name' => 'Pepsi', 'file' => VideoFiles::upload(VideoFiles::mp4(61), 'pepsi.mp4')]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'An advert may be at most 60 seconds long. This one is 1:01.']);

    expect(Campaign::count())->toBe(1);
});

test('a picture advert is on screen for 60 seconds at most', function () {
    $this->postJson('/campaigns', advert(['file' => UploadedFile::fake()->image('coke.jpg'), 'duration_seconds' => 61]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['duration_seconds' => 'An advert may be on screen for at most 60 seconds: one break.']);

    $this->postJson('/campaigns', advert(['file' => UploadedFile::fake()->image('coke.jpg'), 'duration_seconds' => 60]))->assertOk();
    expect(Campaign::sole()->play_seconds)->toBe(60);
});

test("the browser's word is not what is measured: a five-minute video claiming 15 seconds is refused", function () {
    $this->postJson('/campaigns', advert(['file' => VideoFiles::upload(VideoFiles::mp4(300, ['moov_at_end' => true]), 'coke.mp4'), 'duration_seconds' => 15]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'An advert may be at most 60 seconds long. This one is 5:00.']);
});

test('a video advert nobody can measure is refused', function () {
    $this->postJson('/campaigns', advert(['file' => UploadedFile::fake()->create('coke.mp4', 500, 'video/mp4')]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    expect(Campaign::count())->toBe(0)->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('an advert changed to a longer file, or retimed past a break, is refused and keeps what it had', function () {
    $video = Campaign::factory()->video(30)->create(['name' => 'Coca-Cola']);
    $picture = Campaign::factory()->lasting(15)->create(['name' => 'Pepsi']);

    $this->postJson("/campaigns/{$video->id}", advert(['file' => VideoFiles::upload(VideoFiles::mp4(90), 'long.mp4')]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'An advert may be at most 60 seconds long. This one is 1:30.']);

    $this->postJson("/campaigns/{$picture->id}", advert(['name' => 'Pepsi', 'duration_seconds' => 90]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('duration_seconds');

    expect($video->fresh()->play_seconds)->toBe(30)->and($picture->fresh()->play_seconds)->toBe(15);
});

test('a new file for an advert keeps the length read from it, not the one it had', function () {
    $video = Campaign::factory()->video(30)->create(['name' => 'Coca-Cola']);

    $this->postJson("/campaigns/{$video->id}", advert(['file' => VideoFiles::upload(VideoFiles::webm(45), 'short.webm'), 'duration_seconds' => 5]))->assertOk();

    expect($video->fresh())
        ->media_duration_seconds->toBe(45)
        ->duration_seconds->toBe(45)
        ->play_seconds->toBe(45);
});
