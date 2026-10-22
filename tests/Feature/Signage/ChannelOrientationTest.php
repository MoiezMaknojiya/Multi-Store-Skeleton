<?php

use App\Models\ActivityLog;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Screen;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Which way a channel's screens are (owner, 2026-10-22)
|--------------------------------------------------------------------------
|
| "Mujhe channels ke liye bhi yahi orientation wala feature implement karna hai ... har vendor ya wholesaler ke liye do channels
| banaunga: ek landscape orientation ke liye aur ek portrait orientation ke liye", and the screen made right by itself when it is
| mounted flipped. A channel is landscape or portrait, chosen once and fixed (as an Ad Builder ad is); it takes files of its own
| shape alone; and a screen's Channels tab offers its own shape alone — Landscape (flipped 180°) the landscape ones, both Portrait
| rotations the portrait ones — since the player turns the picture. A line already on a screen keeps its place and plays on.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->person = createOrganizationUser($this->alpha, ['channel-view', 'channel-store', 'channel-update', 'screen-view', 'screen-playlist'], 'Manager');
    $this->actingAs($this->person)->withSession(['current_organization_id' => $this->alpha->id]);

    $this->wide = Channel::factory()->create(['organization_id' => $this->alpha->id, 'name' => 'GAMA']);
    $this->tall = Channel::factory()->portrait()->create(['organization_id' => $this->alpha->id, 'name' => 'GAMA']);
    foreach ([$this->wide, $this->tall] as $channel) {
        ChannelAd::factory()->lasting(10)->create(['channel_id' => $channel->id, 'media_id' => Media::factory()->create([
            'organization_id' => $this->alpha->id, 'orientation' => $channel->orientation,
            'width' => $channel->orientation === 'portrait' ? 1080 : 1920, 'height' => $channel->orientation === 'portrait' ? 1920 : 1080,
        ])->id]);
    }
});

function channelsOffered(TestCase $test, Screen $screen): array
{
    return collect($test->getJson("/screens/{$screen->id}/available-channels")->assertOk()->json('channels'))->pluck('id')->all();
}

function savePlaylistWith(TestCase $test, Screen $screen, array $items): TestResponse
{
    $version = $test->getJson("/screens/{$screen->id}/playlist")->assertOk()->json('version');

    return $test->putJson("/screens/{$screen->id}/playlist", ['version' => $version, 'items' => $items]);
}

/** A file of this many pixels, as a library would hold one. */
function fileShaped(Organization $organization, int $width, int $height, array $attributes = []): Media
{
    return Media::factory()->create([...$attributes, 'organization_id' => $organization->id, 'width' => $width, 'height' => $height,
        'orientation' => $height > $width ? 'portrait' : 'landscape']);
}

/* ── Making a channel ────────────────────────────────────────────────────── */

test('a channel is made landscape or portrait, said in the log, and its shape never changes after', function () {
    $id = $this->postJson('/channels', ['name' => 'Coast to Coast', 'orientation' => 'portrait'])->assertOk()->json('channel.id');
    $channel = Channel::findOrFail($id);

    expect($channel->orientation)->toBe('portrait')
        ->and(ActivityLog::where('action', 'channel.created')->latest('id')->value('description'))->toBe('Created portrait channel Coast to Coast');

    // An edit that posts another shape changes the name and nothing of the shape.
    $this->putJson("/channels/{$id}", ['name' => 'Coast to Coast (GAMA)', 'orientation' => 'landscape', 'is_active' => true])->assertOk();
    expect($channel->fresh()->orientation)->toBe('portrait')->and($channel->fresh()->name)->toBe('Coast to Coast (GAMA)');

    // The listing says which each is.
    $listed = collect($this->getJson('/channels/data')->assertOk()->json('channels'))->pluck('orientation', 'id');
    expect($listed[$id])->toBe('portrait')->and($listed[$this->wide->id])->toBe('landscape');
});

test('a new channel must say which way its screens are, in the form\'s words', function () {
    foreach ([null, '', 'sideways', 'landscape_flipped', ['landscape'], 1] as $orientation) {
        $this->postJson('/channels', ['name' => 'Try', 'orientation' => $orientation])->assertStatus(422)
            ->assertJsonValidationErrors(['orientation' => 'Choose Landscape or Portrait.']);
    }

    $this->postJson('/channels', ['name' => 'Try'])->assertStatus(422)->assertJsonValidationErrors(['orientation' => 'Choose Landscape or Portrait.']);
    expect(Channel::where('name', 'Try')->exists())->toBeFalse();
});

test('a vendor\'s landscape and portrait channels may share a name, two of one shape may not', function () {
    // GAMA is here in both shapes already (beforeEach).
    $this->postJson('/channels', ['name' => 'GAMA', 'orientation' => 'landscape'])->assertStatus(422)
        ->assertJsonValidationErrors(['name' => "There is already a landscape channel with this name in this organization's list. Choose a different name."]);
    $this->postJson('/channels', ['name' => 'GAMA', 'orientation' => 'portrait'])->assertStatus(422)
        ->assertJsonValidationErrors(['name' => "There is already a portrait channel with this name in this organization's list. Choose a different name."]);

    $this->postJson('/channels', ['name' => 'Coke', 'orientation' => 'landscape'])->assertOk();
    $this->postJson('/channels', ['name' => 'Coke', 'orientation' => 'portrait'])->assertOk();
    expect(Channel::where('name', 'Coke')->orderBy('orientation')->pluck('orientation')->all())->toBe(['landscape', 'portrait']);

    // A rename into the other shape's name is fine; into its own shape's twin is not.
    $this->putJson("/channels/{$this->wide->id}", ['name' => 'Coke'])->assertStatus(422)->assertJsonValidationErrors('name');
});

/* ── What a channel takes ────────────────────────────────────────────────── */

test('the Add Ad pickers offer files of the channel\'s own shape alone', function () {
    $menu = fileShaped($this->alpha, 1080, 1920, ['title' => 'Upright menu']);
    $banner = fileShaped($this->alpha, 1920, 1080, ['title' => 'Wide banner']);
    $square = fileShaped($this->alpha, 800, 800, ['title' => 'Square logo']);
    $unknown = Media::factory()->video()->create(['organization_id' => $this->alpha->id, 'title' => 'Unmeasured clip', 'width' => null, 'height' => null, 'orientation' => null]);

    $titles = fn (Channel $channel) => collect($this->getJson("/channels/{$channel->id}/library?type=files&per_page=100")->assertOk()->json('media'))->pluck('title')->all();

    expect($titles($this->tall))->toContain('Upright menu')->not->toContain('Wide banner')->not->toContain('Square logo')->not->toContain('Unmeasured clip')
        ->and($titles($this->wide))->toContain('Wide banner')->toContain('Square logo')->toContain('Unmeasured clip')->not->toContain('Upright menu');

    $this->get("/channels/{$this->tall->id}")->assertOk()->assertSee('Portrait channel: portrait files alone')
        ->assertSee('A portrait picture or video.');
});

test('a library file of the other shape is refused, and an ad keeps the file it shows', function () {
    $menu = fileShaped($this->alpha, 1080, 1920, ['title' => 'Upright menu']);

    $this->postJson("/channels/{$this->wide->id}/ads", ['media_id' => $menu->id, 'seconds' => 12])->assertStatus(422)
        ->assertJsonValidationErrors(['media_id' => 'Upright menu is portrait, and this is a landscape channel. Choose a landscape file, or add it to a portrait channel.']);

    $this->postJson("/channels/{$this->tall->id}/ads", ['media_id' => $menu->id, 'seconds' => 12])->assertOk();

    // An ad from before channels had a shape keeps its file when it is saved again.
    $old = ChannelAd::factory()->lasting(10)->create(['channel_id' => $this->wide->id, 'media_id' => fileShaped($this->alpha, 1080, 1920, ['title' => 'Old upright'])->id]);
    $this->postJson("/channels/{$this->wide->id}/ads/{$old->id}", ['title' => 'Old upright', 'seconds' => 15])->assertOk();
    expect($old->fresh()->duration_seconds)->toBe(15);
});

test('an upload of the other shape is refused, and nothing of it is kept', function () {
    $before = Media::count();

    $this->post("/channels/{$this->wide->id}/ads", ['file' => UploadedFile::fake()->image('tall-poster.jpg', 360, 640), 'seconds' => 12], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'tall-poster is portrait, and this is a landscape channel. Choose a landscape file, or add it to a portrait channel.']);

    expect(Media::count())->toBe($before)
        ->and(Storage::disk('public')->allFiles("media/{$this->alpha->id}"))->toBe([])
        ->and(ActivityLog::where('action', 'media.uploaded')->exists())->toBeFalse();

    // Its own shape goes in.
    $this->post("/channels/{$this->tall->id}/ads", ['file' => UploadedFile::fake()->image('tall-poster.jpg', 360, 640), 'seconds' => 12], ['Accept' => 'application/json'])
        ->assertOk();
    expect(Media::where('title', 'tall-poster')->sole()->orientation)->toBe('portrait');
});

/* ── Which screens a channel is offered to ───────────────────────────────── */

test('a screen is offered the channels of its own shape, a flipped one too, and says so', function () {
    $notes = [];

    foreach (['landscape' => $this->wide, 'landscape_flipped' => $this->wide, 'portrait' => $this->tall, 'portrait_flipped' => $this->tall] as $orientation => $channel) {
        $screen = Screen::factory()->create(['organization_id' => $this->alpha->id, 'orientation' => $orientation]);

        expect(channelsOffered($this, $screen))->toBe([$channel->id], $orientation);
        $notes[$orientation] = $this->getJson("/screens/{$screen->id}/available-channels")->json('shape_note');
    }

    expect($notes)->toBe([
        'landscape' => 'Landscape channels, for this landscape screen.',
        'landscape_flipped' => 'Landscape channels: this screen is mounted upside down, and the player turns them the right way up.',
        'portrait' => 'Portrait channels, for this portrait screen.',
        'portrait_flipped' => 'Portrait channels: this screen is turned the other way, and the player turns them the right way up.',
    ]);
});

test('a channel of the other shape goes onto no screen that lacks it, and one already there plays on, marked', function () {
    $flipped = Screen::factory()->create(['organization_id' => $this->alpha->id, 'name' => 'Counter TV', 'orientation' => 'landscape_flipped']);

    savePlaylistWith($this, $flipped, [['channel_id' => $this->tall->id]])->assertStatus(422)
        ->assertJsonValidationErrors(['items' => 'GAMA is a portrait channel, and Counter TV is a landscape screen. Add its landscape channel instead.']);
    savePlaylistWith($this, $flipped, [['channel_id' => $this->wide->id]])->assertOk();

    // A screen turned upright after its landscape channel went on: the line stays through a save, and says it plays with bars.
    $flipped->update(['orientation' => 'portrait']);
    $picture = fileShaped($this->alpha, 1080, 1920);
    savePlaylistWith($this, $flipped, [['media_id' => $picture->id, 'duration_seconds' => 10], ['channel_id' => $this->wide->id]])->assertOk();

    $lines = collect($this->getJson("/screens/{$flipped->id}/playlist")->assertOk()->json('items'));
    expect($lines->pluck('channel_id')->all())->toBe([null, $this->wide->id])
        ->and($lines[1]['orientation'])->toBe('landscape');
});

test('Copy to Other Screens carries no channel to a screen of the other shape', function () {
    $wideScreen = Screen::factory()->create(['organization_id' => $this->alpha->id, 'name' => 'Tv1', 'orientation' => 'landscape']);
    $tallScreen = Screen::factory()->create(['organization_id' => $this->alpha->id, 'name' => 'Kitchen TV', 'orientation' => 'portrait_flipped']);
    $otherWide = Screen::factory()->create(['organization_id' => $this->alpha->id, 'name' => 'Tv2', 'orientation' => 'landscape_flipped']);
    PlaylistItem::create(['screen_id' => $wideScreen->id, 'channel_id' => $this->wide->id, 'position' => 0]);

    $this->postJson("/screens/{$wideScreen->id}/playlist/copy", ['target_screen_ids' => [$tallScreen->id]])->assertStatus(422)
        ->assertJsonValidationErrors(['items' => 'GAMA is a landscape channel, and Kitchen TV is a portrait screen. Add its portrait channel instead.']);
    expect(PlaylistItem::where('screen_id', $tallScreen->id)->exists())->toBeFalse();

    $this->postJson("/screens/{$wideScreen->id}/playlist/copy", ['target_screen_ids' => [$otherWide->id]])->assertOk();
    expect(PlaylistItem::where('screen_id', $otherWide->id)->pluck('channel_id')->all())->toBe([$this->wide->id]);
});

test('a screen mounted upside down plays its landscape channel, and the player is told to turn it', function () {
    $screen = Screen::factory()->withToken('flipped-tok')->create(['organization_id' => $this->alpha->id, 'orientation' => 'landscape_flipped']);
    PlaylistItem::create(['screen_id' => $screen->id, 'channel_id' => $this->wide->id, 'position' => 0]);

    $manifest = $this->withHeader('Authorization', 'Bearer flipped-tok')->getJson('/device/playlist')->assertOk()->json();

    expect($manifest['screen']['orientation'])->toBe('landscape_flipped')
        ->and(collect($manifest['items'])->pluck('type')->all())->toBe(['channel']);
});

/* ── Channels made before ────────────────────────────────────────────────── */

test('the migration makes a channel whose every ad is portrait a portrait channel, and every other one landscape', function () {
    $mixed = Channel::factory()->create(['name' => 'Mixed']);
    ChannelAd::factory()->create(['channel_id' => $mixed->id, 'media_id' => fileShaped($this->alpha, 1080, 1920)->id]);
    ChannelAd::factory()->create(['channel_id' => $mixed->id, 'media_id' => fileShaped($this->alpha, 1920, 1080)->id]);
    $empty = Channel::factory()->create(['name' => 'Empty']);

    $migration = require database_path('migrations/2026_10_22_200000_add_orientation_to_channels_table.php');
    $migration->down();
    $migration->up();
    $migration->up();   // harmless a second time

    expect(Channel::pluck('orientation', 'id')->all())->toBe([
        $this->wide->id => 'landscape', $this->tall->id => 'portrait', $mixed->id => 'landscape', $empty->id => 'landscape',
    ]);
});
