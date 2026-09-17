<?php

use App\Models\Campaign;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Seconds belong to an IMAGE advert
|--------------------------------------------------------------------------
|
| A video runs to its own length, so its form has no seconds field at all (owner's
| rule) — and the server must not demand one it will never be sent.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->admin = createSuperAdmin();
});

/** The fields a campaign form posts, as multipart. */
function advertFields(array $overrides = []): array
{
    return array_replace(['name' => 'Coca-Cola', 'is_active' => '1', 'screen_ids' => []], $overrides);
}

test('a video advert needs no seconds — the length the browser measured stands in', function () {
    $this->actingAs($this->admin)->post('/campaigns', advertFields([
        'file' => UploadedFile::fake()->create('coke.mp4', 800, 'video/mp4'),
        'duration_seconds' => 30,
    ]), ['Accept' => 'application/json'])->assertOk();

    $campaign = Campaign::firstWhere('name', 'Coca-Cola');
    expect($campaign->type)->toBe('video');
    expect($campaign->media_duration_seconds)->toBe(30);
    expect($campaign->play_seconds)->toBe(30);
});

test('even when the browser could not measure it', function () {
    $this->actingAs($this->admin)->post('/campaigns', advertFields([
        'file' => UploadedFile::fake()->create('coke.mp4', 800, 'video/mp4'),
    ]), ['Accept' => 'application/json'])->assertOk();

    expect(Campaign::firstWhere('name', 'Coca-Cola')->type)->toBe('video');
});

test('an image advert still needs its seconds', function () {
    $this->actingAs($this->admin)->post('/campaigns', advertFields([
        'file' => UploadedFile::fake()->image('coke.jpg'),
    ]), ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('duration_seconds');
});

test('retiming an image advert without uploading it again is saved', function () {
    // It used to be dropped without a word: a new number only reached the database when
    // a new file came with it.
    $campaign = Campaign::factory()->lasting(15)->create(['name' => 'Coca-Cola']);

    $this->actingAs($this->admin)
        ->post("/campaigns/{$campaign->id}", advertFields(['duration_seconds' => 25]), ['Accept' => 'application/json'])
        ->assertOk();

    expect($campaign->fresh()->duration_seconds)->toBe(25);
});

test('editing a video advert without a new file asks for no seconds and keeps its length', function () {
    $campaign = Campaign::factory()->video(40)->create(['name' => 'Coca-Cola']);

    $this->actingAs($this->admin)
        ->post("/campaigns/{$campaign->id}", advertFields(['name' => 'Coke']), ['Accept' => 'application/json'])
        ->assertOk();

    expect($campaign->fresh())
        ->name->toBe('Coke')
        ->play_seconds->toBe(40);
});
