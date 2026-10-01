<?php

use App\Models\BuilderAd;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Screen;
use App\Services\MediaStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\VideoFiles;

/*
|--------------------------------------------------------------------------
| A picture is on screen for six seconds at least
|--------------------------------------------------------------------------
|
| Owner's rule, 2026-09-28: "6 seconds minimum … us se kam nahi kar paye", and six by default — on a playlist, in
| a channel, in the ads network, and for an Ad Builder ad (AdLengthTest). Six seconds is the industry's shortest
| spot and the least a roadside screen must hold one in much of the United States; anything shorter is a flicker
| nobody can read. A video is not held to it: it runs to its own end, as it always has. What was saved before the
| rule keeps its place and simply plays for six (PlaylistItem::secondsForAPicture) — nothing is rewritten.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->keeper = createOrganizationUser($this->organization, [
        'screen-view', 'screen-playlist', 'media-view', 'channel-view', 'channel-update',
    ], 'Keeper');
    $this->actingAs($this->keeper)->withSession(['current_organization_id' => $this->organization->id]);
    $this->screen = Screen::factory()->withToken('six-token')->create(['organization_id' => $this->organization->id, 'name' => 'Counter TV']);
    $this->picture = Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Menu']);
});

/** Save the screen's whole playlist, as the page does. */
function putLines(array $lines): TestResponse
{
    $screen = test()->screen;
    $version = test()->getJson("/screens/{$screen->id}/playlist")->json('version');

    return test()->putJson("/screens/{$screen->id}/playlist", ['version' => $version, 'items' => $lines]);
}

/** What the television is told each item lasts, in order. */
function secondsSent(string $token = 'six-token'): array
{
    return collect(test()->getJson('/device/playlist', ['Authorization' => "Bearer {$token}"])->assertOk()->json('items'))
        ->pluck('duration')->all();
}

test('six seconds, both the least and the default', function () {
    expect(PlaylistItem::MIN_IMAGE_SECONDS)->toBe(6)
        ->and(PlaylistItem::DEFAULT_IMAGE_SECONDS)->toBe(6)
        ->and(PlaylistItem::secondsForAPicture(null))->toBe(6)
        ->and(PlaylistItem::secondsForAPicture(0))->toBe(6)
        ->and(PlaylistItem::secondsForAPicture(3))->toBe(6)
        ->and(PlaylistItem::secondsForAPicture(15))->toBe(15);
});

test('a picture on a playlist stays up six seconds at least, and the panel says which one is too short', function () {
    putLines([['media_id' => $this->picture->id, 'duration_seconds' => 5]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items.0.duration_seconds' => 'Menu: a picture stays on screen for at least 6 seconds.']);

    expect(PlaylistItem::count())->toBe(0);

    putLines([['media_id' => $this->picture->id, 'duration_seconds' => 6]])->assertOk();

    expect(PlaylistItem::sole()->duration_seconds)->toBe(6)
        ->and(secondsSent())->toBe([6]);
});

test('a video is not held to it: a three-second clip plays its three seconds', function () {
    $clip = Media::create([
        ...app(MediaStorage::class)->store(VideoFiles::upload(VideoFiles::mp4(3), 'clip.mp4'), $this->organization->id, []),
        'title' => 'Clip',
    ]);

    // Whatever the page sends for it, its line keeps the file's own length.
    putLines([['media_id' => $clip->id, 'duration_seconds' => 3]])->assertOk();

    expect($clip->fresh()->duration_seconds)->toBe(3)
        ->and(PlaylistItem::sole()->duration_seconds)->toBe(3)
        ->and(secondsSent())->toBe([3]);
});

test('an ad page with a length of its own is not asked; one published before lengths is timed like a picture', function () {
    $ad = BuilderAd::factory()->withText()->published()->create(['organization_id' => $this->organization->id, 'name' => 'Sale']);
    $earlier = BuilderAd::factory()->withText()->published()->create(['organization_id' => $this->organization->id, 'name' => 'Old sale']);
    $earlier->media->update(['duration_seconds' => null]);

    putLines([['media_id' => $ad->media_id, 'duration_seconds' => 3]])->assertOk();

    putLines([['media_id' => $earlier->media_id, 'duration_seconds' => 5]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items.0.duration_seconds' => 'Old sale: a picture stays on screen for at least 6 seconds.']);
});

test('a line saved before the rule keeps its place and plays for six: on the television, in the panel and when copied', function () {
    // As a playlist saved before 2026-09-28 holds it.
    PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $this->picture->id, 'position' => 0, 'duration_seconds' => 3]);
    $other = Screen::factory()->withToken('other-token')->create(['organization_id' => $this->organization->id, 'name' => 'Window TV']);

    expect(secondsSent())->toBe([6])
        ->and($this->getJson("/screens/{$this->screen->id}/playlist")->json('items.0.duration_seconds'))->toBe(6);

    $this->postJson("/screens/{$this->screen->id}/playlist/copy", ['target_screen_ids' => [$other->id]])->assertOk();

    // The copy is written at six; the original row is left as it was until its own screen is saved.
    expect(PlaylistItem::where('screen_id', $other->id)->sole()->duration_seconds)->toBe(6)
        ->and(PlaylistItem::where('screen_id', $this->screen->id)->sole()->duration_seconds)->toBe(3)
        ->and(secondsSent('other-token'))->toBe([6]);
});

test('a picture in a channel stays up six seconds at least; one saved before the rule plays for six', function () {
    $channel = Channel::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Our Deals']);

    $this->postJson("/channels/{$channel->id}/ads", ['media_id' => $this->picture->id, 'seconds' => 5])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['seconds' => 'A picture stays on screen for at least 6 seconds.']);

    $this->postJson("/channels/{$channel->id}/ads", ['media_id' => $this->picture->id, 'seconds' => 6])->assertOk();
    expect(ChannelAd::sole()->play_seconds)->toBe(6);

    // As an ad saved before 2026-09-28 holds it: it plays, and its form opens, at six.
    ChannelAd::sole()->update(['duration_seconds' => 2]);

    expect(ChannelAd::sole()->play_seconds)->toBe(6);
    $this->getJson("/channels/{$channel->id}/ads")->assertOk()
        ->assertJsonPath('ads.0.duration_seconds', 6)
        ->assertJsonPath('ads.0.play_seconds', 6);
});

test('a picture advert stays up six seconds at least; a short video advert runs its own length', function () {
    $this->actingAs(createSuperAdmin());
    $fields = ['name' => 'Coca-Cola', 'is_active' => '1', 'screen_ids' => [$this->screen->id]];

    $this->post('/campaigns', [...$fields, 'file' => UploadedFile::fake()->image('coke.jpg'), 'duration_seconds' => 5], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['duration_seconds' => 'An advert stays on screen for at least 6 seconds.']);

    $this->post('/campaigns', [...$fields, 'file' => UploadedFile::fake()->image('coke.jpg'), 'duration_seconds' => 6], ['Accept' => 'application/json'])
        ->assertOk();

    $this->post('/campaigns', [...$fields, 'name' => 'Sprite', 'file' => VideoFiles::upload(VideoFiles::mp4(3), 'sprite.mp4')], ['Accept' => 'application/json'])
        ->assertOk();

    expect(Campaign::firstWhere('name', 'Coca-Cola')->play_seconds)->toBe(6)
        ->and(Campaign::firstWhere('name', 'Sprite')->play_seconds)->toBe(3);
});

test('an advert saved before the rule plays for six, and a break counts it so', function () {
    $this->actingAs(createSuperAdmin());
    $advert = Campaign::factory()->lasting(4)->create(['name' => 'Old advert']);
    $advert->screens()->attach($this->screen);

    expect($advert->fresh()->play_seconds)->toBe(6);

    $booked = collect($this->getJson('/campaigns/screens')->assertOk()->json('screens'))->firstWhere('id', $this->screen->id);
    expect($booked['booked_seconds'])->toBe(6);

    // And one saved before the one-break ceiling at 90 s plays for sixty — never past a break — and is counted so.
    $advert->update(['duration_seconds' => 90]);

    expect($advert->fresh()->play_seconds)->toBe(60);
    $booked = collect($this->getJson('/campaigns/screens')->assertOk()->json('screens'))->firstWhere('id', $this->screen->id);
    expect($booked['booked_seconds'])->toBe(60);
});
