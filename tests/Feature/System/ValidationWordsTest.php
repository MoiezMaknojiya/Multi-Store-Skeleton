<?php

use App\Models\BuilderAd;
use App\Models\Channel;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Screen;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\VideoFiles;

/*
|--------------------------------------------------------------------------
| The server says it in the form's own words
|--------------------------------------------------------------------------
|
| The owner's brute-force round, 2026-09-29: "ui consistent ha na errors validation". Every form checks its fields
| in the browser first (validate.js); a request that gets past that — made by hand, or a check the browser could
| not make — is refused by the server in the SAME sentence, never in Laravel's default wording with a raw path in it
| ("The items.0.duration_seconds field must be an integer.").
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->keeper = createOrganizationUser($this->organization, [
        'screen-view', 'screen-playlist', 'media-view', 'media-store', 'channel-view', 'channel-store', 'channel-update',
        'ad-view', 'ad-store', 'ad-update',
    ], 'Keeper');
    $this->actingAs($this->keeper)->withSession(['current_organization_id' => $this->organization->id]);
});

test('a line of the playlist is refused in the words its box shows, with its place', function (mixed $seconds, string $said) {
    $screen = Screen::factory()->create(['organization_id' => $this->organization->id]);
    $picture = Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Menu']);
    $version = $this->getJson("/screens/{$screen->id}/playlist")->json('version');

    $this->putJson("/screens/{$screen->id}/playlist", ['version' => $version, 'items' => [
        ['media_id' => $picture->id, 'duration_seconds' => $seconds],
    ]])->assertStatus(422)->assertJsonValidationErrors(['items.0.duration_seconds' => $said]);
})->with([
    'a fraction' => [6.5, 'Line 1: give the seconds as a whole number.'],
    'past a day' => [100000, 'Line 1: a picture stays on screen for at most 24 hours.'],
    'nothing at all' => [0, 'Line 1: a picture stays on screen for at least 6 seconds.'],
]);

test('a schedule is refused in the words its window shows — on the save and in the preview alike', function () {
    $screen = Screen::factory()->create(['organization_id' => $this->organization->id]);
    $picture = Media::factory()->create(['organization_id' => $this->organization->id]);
    $rule = ['recurrence_type' => 'weekly', 'recurrence_interval' => 60, 'recurrence_weekdays' => [1]];
    $version = $this->getJson("/screens/{$screen->id}/playlist")->json('version');

    $this->putJson("/screens/{$screen->id}/playlist", ['version' => $version, 'items' => [
        ['media_id' => $picture->id, 'duration_seconds' => 8, 'rules' => [$rule]],
    ]])->assertStatus(422)->assertJsonValidationErrors(['items.0.rules.0.recurrence_interval' => 'Repeat every: enter a whole number from 1 to 52.']);

    $this->postJson("/screens/{$screen->id}/playlist/preview", ['rules' => [$rule], 'days' => 7])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rules.0.recurrence_interval' => 'Repeat every: enter a whole number from 1 to 52.']);

    $this->postJson("/screens/{$screen->id}/playlist/preview", ['rules' => [['recurrence_type' => 'monthly_day', 'recurrence_monthday' => 40]], 'days' => 7])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rules.0.recurrence_monthday' => 'On day: enter a day of the month from 1 to 31.']);
});

test("a channel ad's seconds are refused in the form's words", function (mixed $seconds, string $said) {
    $channel = Channel::factory()->create(['organization_id' => $this->organization->id]);
    $picture = Media::factory()->create(['organization_id' => $this->organization->id]);

    $this->postJson("/channels/{$channel->id}/ads", ['media_id' => $picture->id, 'seconds' => $seconds])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['seconds' => $said]);
})->with([
    'a fraction' => [7.5, 'Give the seconds as a whole number.'],
    'past five minutes' => [301, 'A picture stays on screen for at most 5 minutes.'],
    'under six' => [5, 'A picture stays on screen for at least 6 seconds.'],
]);

test("a channel's own fields are refused in the form's words", function () {
    $this->postJson('/channels', ['name' => '', 'is_active' => true])
        ->assertStatus(422)->assertJsonValidationErrors(['name' => 'Channel name is required.']);

    foreach (['abc', 0, 99] as $perPass) {
        $this->postJson('/channels', ['name' => 'Deals', 'is_active' => true, 'ads_per_pass' => $perPass])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ads_per_pass' => 'Enter a number from 1 to '.Channel::MAX_ADS_PER_PASS.', or leave it blank to play every ad.']);
    }
});

test("an advert's fields are refused in the form's words, and a video's own measure refuses nothing", function () {
    $this->actingAs(createSuperAdmin());
    $fields = ['name' => 'Coca-Cola', 'is_active' => '1', 'screen_ids' => []];

    $this->post('/campaigns', [...$fields, 'file' => UploadedFile::fake()->image('coke.jpg'), 'duration_seconds' => 6.5], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors(['duration_seconds' => 'Give the seconds as a whole number.']);

    $this->post('/campaigns', [...$fields, 'file' => UploadedFile::fake()->image('coke.jpg')], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors(['duration_seconds' => 'Say how many seconds it stays on screen.']);

    $this->post('/campaigns', [...$fields, 'name' => '', 'file' => UploadedFile::fake()->image('coke.jpg'), 'duration_seconds' => 8], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors(['name' => 'Campaign name is required.']);

    // The number a browser measured for a video (hidden, never shown) is only a hint: the file is measured here.
    $this->post('/campaigns', [...$fields, 'file' => VideoFiles::upload(VideoFiles::mp4(3), 'sprite.mp4'), 'duration_seconds' => 0], ['Accept' => 'application/json'])
        ->assertOk();
});

test("an ad's length is refused in the editor's words", function (mixed $seconds, string $said) {
    $ad = BuilderAd::factory()->withText()->create(['organization_id' => $this->organization->id]);

    $this->putJson("/builder/{$ad->id}", ['name' => $ad->name, 'document' => [...$ad->document, 'duration' => $seconds]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document.duration' => $said]);
})->with([
    'past five minutes' => [301, 'An ad stays on screen for at most 5 minutes.'],
    'a fraction' => [8.5, "Give the ad's length as a whole number of seconds."],
    'under six' => [5, 'An ad stays on screen for at least 6 seconds.'],
]);

test('a schedule\'s hours are refused in the words its window says', function (array $times, string $field, string $words) {
    $screen = Screen::factory()->create(['organization_id' => $this->organization->id]);
    $file = Media::factory()->create(['organization_id' => $this->organization->id]);
    $version = $this->getJson("/screens/{$screen->id}/playlist")->json('version');

    $this->putJson("/screens/{$screen->id}/playlist", [
        'version' => $version,
        'items' => [['media_id' => $file->id, 'duration_seconds' => 10, 'rules' => [$times]]],
    ])->assertStatus(422)->assertJsonValidationErrors(["items.0.rules.0.{$field}" => $words]);
})->with([
    'a start with no end' => [['start_time' => '11:00'], 'end_time', 'Time: give both a start and an end, or choose All day.'],
    'an end with no start' => [['end_time' => '15:00'], 'start_time', 'Time: give both a start and an end, or choose All day.'],
    'the same time twice' => [['start_time' => '11:00', 'end_time' => '11:00'], 'end_time', 'Time: the start and the end cannot be the same. To run past midnight, set an end earlier than the start.'],
    'a time typed in part' => [['start_time' => '11', 'end_time' => '15:00'], 'start_time', 'Time: enter the whole time, or choose All day.'],
]);

test('an upload is refused in the words its form shows', function () {
    $this->postJson('/media', ['file' => UploadedFile::fake()->image('menu.jpg'), 'title' => str_repeat('a', 256)])
        ->assertStatus(422)->assertJsonValidationErrors(['title' => 'Title may not be longer than 255 characters.']);

    $this->postJson('/builder/assets', ['file' => UploadedFile::fake()->create('menu.pdf', 10, 'application/pdf')])
        ->assertStatus(422)->assertJsonValidationErrors(['file' => 'Only images (JPG, PNG, GIF, WEBP) and videos (MP4, WEBM) can be uploaded.']);
});
