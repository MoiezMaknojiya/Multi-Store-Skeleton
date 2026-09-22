<?php

use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\ScheduleRule;
use App\Models\Screen;
use App\Models\Store;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| A shop putting a channel on one of its screens
|--------------------------------------------------------------------------
|
| One line, which plays whatever the channel is running that day, exactly where it
| stands. The platform's channels are offered to every shop, and adding one is the
| shop's own choice (owner's decision). A store's own channels are offered to that
| store alone — see StoreChannelsTest.
|
*/

beforeEach(function () {
    // Noon on the screens' default clock (Chicago).
    $this->travelTo('2026-10-10 17:00:00');

    $this->store = Store::factory()->create();
    $this->owner = createStoreUser($this->store, ['screen-view', 'screen-playlist']);
    $this->screen = Screen::factory()->create(['store_id' => $this->store->id]);
    $this->poster = Media::factory()->create(['store_id' => $this->store->id, 'title' => 'Burger deal']);

    $this->gama = Channel::factory()->create(['name' => 'GAMA']);
    ChannelAd::factory()->create(['channel_id' => $this->gama->id, 'title' => 'Monster', 'duration_seconds' => 10, 'position' => 0]);
    ChannelAd::factory()->create(['channel_id' => $this->gama->id, 'title' => 'Coke', 'duration_seconds' => 15, 'position' => 1]);
});

/** Save a playlist as the shop owner, from the version the screen holds right now. */
function saveOwnersPlaylist($test, array $items)
{
    return $test->actingAs($test->owner)->withSession(['current_store_id' => $test->store->id])
        ->putJson("/screens/{$test->screen->id}/playlist", [
            'items' => $items,
            'version' => $test->screen->fresh()->playlistFingerprint(),
        ]);
}

/*
|--------------------------------------------------------------------------
| What is offered
|--------------------------------------------------------------------------
*/

test("every one of the platform's channels is offered to every shop, described by what runs on the screen today", function () {
    ChannelAd::factory()->running(null, '2026-10-09')->create(['channel_id' => $this->gama->id, 'title' => 'Over']);
    $lottery = Channel::factory()->paused()->create(['name' => 'Lottery']);
    ChannelAd::factory()->create(['channel_id' => $lottery->id]);
    Channel::factory()->create(['name' => 'Thanksgiving']);   // no ads yet

    $channels = collect($this->actingAs($this->owner)->withSession(['current_store_id' => $this->store->id])
        ->getJson("/screens/{$this->screen->id}/available-channels")->assertOk()->json('channels'));

    expect($channels->pluck('title')->all())->toBe(['GAMA', 'Lottery', 'Thanksgiving']);

    $gama = $channels->firstWhere('title', 'GAMA');
    // The ended ad is neither counted nor shown.
    expect($gama['ads_count'])->toBe(2);
    expect(collect($gama['ads'])->pluck('title')->all())->toBe(['Monster', 'Coke']);
    expect($gama['pass_seconds'])->toBe(25);
    expect($gama['channel_active'])->toBeTrue();

    expect($channels->firstWhere('title', 'Lottery')['channel_active'])->toBeFalse();
    expect($channels->firstWhere('title', 'Thanksgiving')['ads_count'])->toBe(0);
});

test('a shop with no channels at all is simply offered an empty list', function () {
    // The state every existing shop is in the day this ships: the box hides itself
    // (x-show on channels.length), and nothing on the page changes for them.
    Channel::query()->delete();

    $channels = $this->actingAs($this->owner)->withSession(['current_store_id' => $this->store->id])
        ->getJson("/screens/{$this->screen->id}/available-channels")->assertOk()->json('channels');

    expect($channels)->toBe([]);
});

test('the channel list answers to the playlist permission alone', function () {
    $viewer = createStoreUser($this->store, ['screen-view'], 'Viewer');

    $this->actingAs($viewer)->withSession(['current_store_id' => $this->store->id])
        ->getJson("/screens/{$this->screen->id}/available-channels")->assertForbidden();
});

test('a screen in another shop is still out of reach', function () {
    $theirs = Screen::factory()->create(['store_id' => Store::factory()->create()->id]);

    $this->actingAs($this->owner)->withSession(['current_store_id' => $this->store->id])
        ->getJson("/screens/{$theirs->id}/available-channels")->assertNotFound();
});

test('the playlist page carries the Channels box', function () {
    $this->actingAs($this->owner)->withSession(['current_store_id' => $this->store->id])
        ->get("/screens/{$this->screen->id}")->assertOk()->assertSee('channel-picker', false);
});

/*
|--------------------------------------------------------------------------
| Putting one on the playlist
|--------------------------------------------------------------------------
*/

test('a channel goes on a playlist as one line, with no length of its own', function () {
    saveOwnersPlaylist($this, [
        ['media_id' => $this->poster->id, 'duration_seconds' => 10],
        ['channel_id' => $this->gama->id],
    ])->assertOk();

    $lines = PlaylistItem::where('screen_id', $this->screen->id)->orderBy('position')->get();
    expect($lines)->toHaveCount(2);
    expect($lines[1]->channel_id)->toBe($this->gama->id);
    expect($lines[1]->media_id)->toBeNull();
    expect($lines[1]->duration_seconds)->toBeNull();

    $items = $this->getJson("/screens/{$this->screen->id}/playlist")->assertOk()->json('items');
    expect($items[1])->toMatchArray([
        'type' => 'channel', 'title' => 'GAMA', 'channel_id' => $this->gama->id,
        'ads_count' => 2, 'pass_ads' => 2, 'pass_seconds' => 25, 'channel_active' => true,
    ]);
});

test('the order the shop arranged is the order that is stored, whichever kind of line comes first', function () {
    // A channel line carries no media_id, and a validated array is rebuilt rule by rule
    // rather than row by row — so this exact arrangement once came back to the screen
    // the other way round. The saved response has to agree with the request, every time.
    saveOwnersPlaylist($this, [
        ['channel_id' => $this->gama->id],
        ['media_id' => $this->poster->id, 'duration_seconds' => 10],
        ['channel_id' => $this->gama->id],
    ])->assertOk()->assertJsonPath('items.0.type', 'channel')
        ->assertJsonPath('items.1.type', 'image')
        ->assertJsonPath('items.2.type', 'channel');

    $lines = PlaylistItem::where('screen_id', $this->screen->id)->orderBy('position')->get();

    expect($lines[0]->channel_id)->toBe($this->gama->id);
    expect($lines[1]->media_id)->toBe($this->poster->id);
    expect($lines[2]->channel_id)->toBe($this->gama->id);
});

test('a file line still needs its length', function () {
    saveOwnersPlaylist($this, [['media_id' => $this->poster->id]])
        ->assertStatus(422)->assertJsonValidationErrors('items.0.duration_seconds');
});

test('a line that is neither a file nor a channel is refused', function () {
    saveOwnersPlaylist($this, [['rules' => []]])
        ->assertStatus(422)->assertJsonValidationErrors('items.0.media_id');
});

test('a line that claims to be both is refused', function () {
    saveOwnersPlaylist($this, [[
        'media_id' => $this->poster->id, 'channel_id' => $this->gama->id, 'duration_seconds' => 10,
    ]])->assertStatus(422)->assertJsonValidationErrors('items');

    expect(PlaylistItem::count())->toBe(0);
});

test('an id of zero is refused like any other bad id, not left to the database', function () {
    // Zero is neither a file nor "no file": read as the second, it walked straight past
    // the store wall and died on a foreign key with an error nobody could read.
    saveOwnersPlaylist($this, [['media_id' => 0, 'duration_seconds' => 10]])
        ->assertStatus(422)->assertJsonValidationErrors('items.0.media_id');

    saveOwnersPlaylist($this, [['channel_id' => 0]])
        ->assertStatus(422)->assertJsonValidationErrors('items.0.channel_id');

    expect(PlaylistItem::count())->toBe(0);
});

test('a channel deleted while the page was open is refused in words', function () {
    saveOwnersPlaylist($this, [['channel_id' => 999999]])
        ->assertStatus(422)->assertJsonValidationErrors('items');
});

test('a channel line keeps a schedule of its own', function () {
    saveOwnersPlaylist($this, [[
        'channel_id' => $this->gama->id,
        'rules' => [['recurrence_type' => ScheduleRule::WEEKLY, 'recurrence_interval' => 1, 'recurrence_weekdays' => [1, 5]]],
    ]])->assertOk();

    $line = PlaylistItem::firstWhere('channel_id', $this->gama->id);
    expect($line->scheduleRules)->toHaveCount(1);
    expect($line->scheduleRules[0]->recurrence_weekdays)->toBe([1, 5]);
});

test('a paused channel reads as paused on the playlist, not as empty', function () {
    $this->gama->update(['is_active' => false]);
    PlaylistItem::create(['screen_id' => $this->screen->id, 'channel_id' => $this->gama->id, 'position' => 0]);

    $line = $this->actingAs($this->owner)->withSession(['current_store_id' => $this->store->id])
        ->getJson("/screens/{$this->screen->id}/playlist")->assertOk()->json('items.0');

    expect($line['channel_active'])->toBeFalse();
    expect($line['ads_count'])->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Versions and copies
|--------------------------------------------------------------------------
*/

test('a channel line never fingerprints like the file that happens to share its id', function () {
    // Both are the first row of their own table here, so both ids are 1 — exactly the
    // case a bare id in the fingerprint would miss.
    expect($this->poster->id)->toBe($this->gama->id);

    PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $this->poster->id, 'position' => 0, 'duration_seconds' => 10]);
    $asFile = $this->screen->playlistFingerprint();

    PlaylistItem::query()->update(['media_id' => null, 'channel_id' => $this->gama->id]);
    $asChannel = $this->screen->playlistFingerprint();

    expect($asChannel)->not->toBe($asFile);
});

test('a colleague who added a channel meanwhile is a conflict, not a silent loss', function () {
    $stale = $this->screen->playlistFingerprint();

    // A colleague puts GAMA on the screen...
    saveOwnersPlaylist($this, [['channel_id' => $this->gama->id]])->assertOk();

    // ...and this page, still holding the old version, tries to save over it.
    $this->putJson("/screens/{$this->screen->id}/playlist", [
        'items' => [['media_id' => $this->poster->id, 'duration_seconds' => 10]],
        'version' => $stale,
    ])->assertStatus(409);

    expect(PlaylistItem::firstWhere('channel_id', $this->gama->id))->not->toBeNull();
});

test('deleting a file takes its own line and leaves the channel line standing', function () {
    // Deleting the file unlinks it from the disk, so the disk is a throwaway one.
    Storage::fake('public');

    // The two kinds of line live in one table now, so each cascade has to stay in its
    // own lane: a file leaving must not take a channel with it.
    saveOwnersPlaylist($this, [
        ['media_id' => $this->poster->id, 'duration_seconds' => 10],
        ['channel_id' => $this->gama->id],
    ])->assertOk();

    $keeper = createStoreUser($this->store, ['media-destroy'], 'Media Keeper');
    $this->actingAs($keeper)->withSession(['current_store_id' => $this->store->id])
        ->deleteJson("/media/{$this->poster->id}")->assertOk();

    $lines = PlaylistItem::where('screen_id', $this->screen->id)->get();
    expect($lines)->toHaveCount(1);
    expect($lines[0]->channel_id)->toBe($this->gama->id);
});

test('deleting the screen takes every line with it, channels included', function () {
    saveOwnersPlaylist($this, [['channel_id' => $this->gama->id]])->assertOk();

    $keeper = createStoreUser($this->store, ['screen-destroy'], 'Screen Keeper');
    $this->actingAs($keeper)->withSession(['current_store_id' => $this->store->id])
        ->deleteJson("/screens/{$this->screen->id}")->assertOk();

    expect(PlaylistItem::count())->toBe(0);
    // And the channel itself is untouched — it belongs to the platform, not the screen.
    expect(Channel::find($this->gama->id))->not->toBeNull();
});

test('copying a playlist carries its channel lines and their schedules', function () {
    $target = Screen::factory()->create(['store_id' => $this->store->id]);

    saveOwnersPlaylist($this, [[
        'channel_id' => $this->gama->id,
        'rules' => [['recurrence_type' => ScheduleRule::WEEKLY, 'recurrence_interval' => 1, 'recurrence_weekdays' => [3]]],
    ]])->assertOk();

    $this->postJson("/screens/{$this->screen->id}/playlist/copy", ['target_screen_ids' => [$target->id]])->assertOk();

    $copied = PlaylistItem::where('screen_id', $target->id)->with('scheduleRules')->get();
    expect($copied)->toHaveCount(1);
    expect($copied[0]->channel_id)->toBe($this->gama->id);
    expect($copied[0]->media_id)->toBeNull();
    expect($copied[0]->duration_seconds)->toBeNull();
    expect($copied[0]->scheduleRules[0]->recurrence_weekdays)->toBe([3]);
});
