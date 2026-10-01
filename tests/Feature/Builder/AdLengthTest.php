<?php

use App\Models\BuilderAd;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Screen;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| An ad is on screen for the length its design says
|--------------------------------------------------------------------------
|
| Owner, 2026-09-28: "agar mein koi ad banata hu ad builder se aur woo 8 seconds ki ho aur background 20 seconds
| toh hamari ads 8 seconds k bad change honi chahiye" — by the industry's standard. That is Xibo's layout
| duration and Canva's page duration: the DESIGN owns its length, every playlist and channel plays it that long,
| and a video inside it repeats when it is shorter and is cut when the ad ends. Published with the page, like
| the rest of the design (docs/AD-BUILDER-SPEC.md §9).
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->designer = createOrganizationUser($this->organization, [
        'ad-view', 'ad-store', 'ad-update', 'screen-view', 'screen-playlist', 'media-view', 'channel-view', 'channel-update',
    ], 'Designer');
    $this->actingAs($this->designer)->withSession(['current_organization_id' => $this->organization->id]);
    $this->screen = Screen::factory()->withToken('length-token')->create(['organization_id' => $this->organization->id]);
});

/** A design with text on it, lasting $seconds, published through the endpoint. */
function adLasting(int $seconds, string $name = 'Winter sale'): BuilderAd
{
    $ad = BuilderAd::factory()->withText($name)->create(['organization_id' => test()->organization->id, 'name' => $name]);
    $ad->update(['document' => [...$ad->document, 'duration' => $seconds]]);
    test()->postJson("/builder/{$ad->id}/publish")->assertOk();

    return $ad->fresh();
}

/** What the television is told for each item, as [type => seconds]. */
function secondsOnTheTelevision(): array
{
    $items = test()->getJson('/device/playlist', ['Authorization' => 'Bearer length-token'])->assertOk()->json('items');

    return collect($items)->mapWithKeys(fn (array $item) => [$item['type'] => $item['duration']])->all();
}

function savePlaylist(array $lines): void
{
    $screen = test()->screen;
    $version = test()->getJson("/screens/{$screen->id}/playlist")->json('version');

    test()->putJson("/screens/{$screen->id}/playlist", ['version' => $version, 'items' => $lines])->assertOk();
}

test('a new ad says how long it is on screen: six seconds, until the designer says otherwise', function () {
    $id = $this->postJson('/builder', ['name' => 'Fresh', 'document' => BuilderAd::blankDocument()])->assertOk()->json('ad.id');

    // Owner's rule, 2026-09-28: six by default, and six at least.
    expect(BuilderAd::find($id)->document['duration'])->toBe(6)
        ->and(BuilderAd::lengthOf(['version' => 1]))->toBe(6)
        ->and(BuilderAd::DEFAULT_SECONDS)->toBe(6)
        ->and(BuilderAd::MIN_SECONDS)->toBe(6);
});

test('the length is a whole number of seconds, from six seconds to five minutes', function (mixed $seconds, bool $accepted) {
    $response = $this->postJson('/builder', ['name' => 'Fresh', 'document' => [...BuilderAd::blankDocument(), 'duration' => $seconds]]);

    $accepted ? $response->assertOk() : $response->assertStatus(422)->assertJsonValidationErrors('document.duration');
})->with([
    'six seconds' => [6, true],
    'five minutes' => [300, true],
    'five seconds' => [5, false],
    'one second' => [1, false],
    'nothing' => [0, false],
    'a second past five minutes' => [301, false],
    'half a second' => [8.5, false],
    'a word' => ['eight', false],
    'a number written as text' => ['8', false],
    'a truth value' => [true, false],
    'a list' => [[8], false],
]);

test('a length is read as the editor reads it: a whole number held between six and five minutes, anything else the default', function (mixed $stored, int $seconds) {
    expect(BuilderAd::lengthOf(['version' => 1, 'duration' => $stored]))->toBe($seconds);
})->with([
    'eight' => [8, 8],
    'under six' => [3, BuilderAd::MIN_SECONDS],
    'past five minutes' => [900, BuilderAd::MAX_SECONDS],
    'text' => ['8', BuilderAd::DEFAULT_SECONDS],
    'a truth value' => [true, BuilderAd::DEFAULT_SECONDS],
    'a fraction' => [8.5, BuilderAd::DEFAULT_SECONDS],
    'nothing' => [0, BuilderAd::DEFAULT_SECONDS],
    'below nothing' => [-8, BuilderAd::DEFAULT_SECONDS],
    'null' => [null, BuilderAd::DEFAULT_SECONDS],
]);

test('publishing puts the length on the library row, and every screen plays the ad that long', function () {
    $ad = adLasting(8);
    $picture = Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Menu']);

    expect($ad->media->duration_seconds)->toBe(8)->and($ad->media->ownLength())->toBe(8);

    // Whatever the save sends for the ad, its line keeps the ad's eight; the picture keeps what it was given.
    savePlaylist([
        ['media_id' => $ad->media_id, 'duration_seconds' => 30],
        ['media_id' => $picture->id, 'duration_seconds' => 12],
    ]);

    expect($this->screen->playlistItems()->orderBy('position')->pluck('duration_seconds')->all())->toBe([8, 12])
        ->and(secondsOnTheTelevision())->toBe(['html' => 8, 'image' => 12]);

    // The panel is told so, and shows no seconds to set for the ad.
    $lines = $this->getJson("/screens/{$this->screen->id}/playlist")->json('items');
    expect([$lines[0]['duration_seconds'], $lines[0]['runs_own_length']])->toBe([8, true])
        ->and([$lines[1]['duration_seconds'], $lines[1]['runs_own_length']])->toBe([12, false]);
});

test('a changed length reaches the screens only when it is published, and Discard changes takes it back', function () {
    $ad = adLasting(8);
    savePlaylist([['media_id' => $ad->media_id, 'duration_seconds' => 8]]);

    $this->putJson("/builder/{$ad->id}", ['name' => $ad->name, 'document' => [...$ad->document, 'duration' => 20]])->assertOk();

    expect(secondsOnTheTelevision())->toBe(['html' => 8])
        ->and($ad->fresh()->hasUnpublishedChanges())->toBeTrue();

    $this->postJson("/builder/{$ad->id}/discard", ['password' => 'password'])->assertOk();
    expect($ad->fresh()->document['duration'])->toBe(8);

    $this->putJson("/builder/{$ad->id}", ['name' => $ad->name, 'document' => [...$ad->fresh()->document, 'duration' => 20]])->assertOk();
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();

    expect(secondsOnTheTelevision())->toBe(['html' => 20]);
});

test('a channel plays an ad page for its own length, and asks no seconds for it', function () {
    $ad = adLasting(8);
    $channel = Channel::factory()->create(['organization_id' => $this->organization->id, 'name' => 'GHRA Ware House']);

    // Seconds written by hand are thrown away, as a video's are.
    $this->postJson("/channels/{$channel->id}/ads", ['media_id' => $ad->media_id, 'seconds' => 99])->assertOk()
        ->assertJsonPath('ads.0.own_length', 8)
        ->assertJsonPath('ads.0.play_seconds', 8);

    expect(ChannelAd::sole())->duration_seconds->toBeNull()->play_seconds->toBe(8);
});

test('a page published before designs had a length keeps the seconds it is given, on a playlist and in a channel', function () {
    // As AdPublisher left a page before 2026-09-28: no length on its row.
    $ad = adLasting(8);
    $ad->media->update(['duration_seconds' => null]);

    savePlaylist([['media_id' => $ad->media_id, 'duration_seconds' => 15]]);
    expect(secondsOnTheTelevision())->toBe(['html' => 15]);

    $channel = Channel::factory()->create(['organization_id' => $this->organization->id]);
    $other = adLasting(8, 'Spring sale');
    $other->media->update(['duration_seconds' => null]);

    $this->postJson("/channels/{$channel->id}/ads", ['media_id' => $other->media_id])
        ->assertStatus(422)->assertJsonValidationErrors('seconds');
    $this->postJson("/channels/{$channel->id}/ads", ['media_id' => $other->media_id, 'seconds' => 12])->assertOk();

    expect(ChannelAd::sole()->play_seconds)->toBe(12);
});

test('the preview shows the draft as a screen does: for its length, then from the start again; a published page never', function () {
    $ad = adLasting(8);
    $this->putJson("/builder/{$ad->id}", ['name' => $ad->name, 'document' => [...$ad->document, 'duration' => 7]])->assertOk();

    expect($this->get("/builder/{$ad->id}/preview")->assertOk()->getContent())
        ->toContain('<meta charset="utf-8">'."\n".'<meta http-equiv="refresh" content="7">');

    // The page the screens play has none: the player times it, and moves on to the next item.
    expect(Storage::disk('public')->get($ad->fresh()->media->path))->not->toContain('http-equiv="refresh"');

    // A design with no length of its own has none to go by: each screen gives it its own seconds.
    $this->putJson("/builder/{$ad->id}", ['name' => $ad->name, 'document' => collect($ad->document)->except('duration')->all()])->assertOk();
    expect($this->get("/builder/{$ad->id}/preview")->assertOk()->getContent())->not->toContain('http-equiv="refresh"');
});

test("builder:recompile writes the published version's length, never the draft's", function () {
    $ad = adLasting(8);
    $this->putJson("/builder/{$ad->id}", ['name' => $ad->name, 'document' => [...$ad->document, 'duration' => 25]])->assertOk();

    Artisan::call('builder:recompile');

    expect($ad->fresh()->media->duration_seconds)->toBe(8);
});

test("a design made before designs had a length keeps each line's seconds, published again or not, until it is given one", function () {
    // The brute-force round, 2026-09-29: a typo fixed and published re-timed a 15 s ad to 6 on every screen.
    $ad = BuilderAd::factory()->withText('Old sale')->create(['organization_id' => $this->organization->id, 'name' => 'Old sale']);
    $earlier = collect($ad->document)->except('duration')->all();
    BuilderAd::withoutTimestamps(fn () => $ad->forceFill(['document' => $earlier])->save());

    expect(BuilderAd::hasOwnLength($earlier))->toBeFalse();
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();
    expect($ad->fresh()->media->duration_seconds)->toBeNull();

    savePlaylist([['media_id' => $ad->fresh()->media_id, 'duration_seconds' => 15]]);
    expect(secondsOnTheTelevision())->toBe(['html' => 15]);

    // A typo fixed, published again: still each line's own.
    $this->putJson("/builder/{$ad->id}", ['name' => 'Old sale!', 'document' => $earlier])->assertOk();
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();
    expect(secondsOnTheTelevision())->toBe(['html' => 15]);

    // Given a length of its own, and published: now every screen plays that.
    $this->putJson("/builder/{$ad->id}", ['name' => 'Old sale!', 'document' => [...$earlier, 'duration' => 9]])->assertOk();
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();
    expect(secondsOnTheTelevision())->toBe(['html' => 9]);
});

test('builder:recompile never gives a page published before designs had a length one: its lines keep their seconds', function () {
    $ad = adLasting(8);
    $earlier = collect($ad->published_document)->except('duration')->all();
    BuilderAd::withoutTimestamps(fn () => $ad->forceFill(['document' => $earlier, 'published_document' => $earlier])->save());
    $ad->media->update(['duration_seconds' => null]);
    savePlaylist([['media_id' => $ad->media_id, 'duration_seconds' => 15]]);

    Artisan::call('builder:recompile');

    expect($ad->fresh()->media->duration_seconds)->toBeNull()
        ->and(secondsOnTheTelevision())->toBe(['html' => 15]);
});

test('a playlist line for an ad cannot be stretched past its length by hand either', function () {
    $ad = adLasting(8);

    savePlaylist([['media_id' => $ad->media_id, 'duration_seconds' => 86400]]);

    expect(PlaylistItem::sole()->duration_seconds)->toBe(8)
        ->and(secondsOnTheTelevision())->toBe(['html' => 8]);
});
