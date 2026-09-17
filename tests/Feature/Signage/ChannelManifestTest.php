<?php

use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Daypart;
use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\Screen;
use App\Models\Store;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| What a television is sent for a channel line
|--------------------------------------------------------------------------
|
| A channel line goes out where it stands, as ONE entry holding the ads it plays that
| day. Only the player knows when a pass has ended, so the whole set goes and the player
| rotates through `per_pass`. WHEN the line plays is decided here, like everything else:
|
|   · a channel line with a schedule of its own follows it
|   · one without rides along with the shop's own files, so it never lights up a screen
|     the shop left dark — unless no file on the playlist is live at all
|
*/

/** Put a file or a channel on the screen at this position, optionally with one rule. */
function lineOnScreen(Screen $screen, Media|Channel $what, int $position, ?array $rule = null): PlaylistItem
{
    $line = PlaylistItem::create([
        'screen_id' => $screen->id,
        'media_id' => $what instanceof Media ? $what->id : null,
        'channel_id' => $what instanceof Channel ? $what->id : null,
        'position' => $position,
        'duration_seconds' => $what instanceof Media ? 10 : null,
    ]);

    if ($rule !== null) {
        $line->scheduleRules()->create($rule);
    }

    return $line;
}

/** What the television is handed right now. */
function manifestNow($test): array
{
    return $test->withHeader('Authorization', 'Bearer tok')->getJson('/device/playlist')->assertOk()->json();
}

beforeEach(function () {
    // Noon in Chicago (October there is UTC-5).
    $this->travelTo(CarbonImmutable::parse('2026-10-10 17:00:00', 'UTC'));

    $this->store = Store::factory()->create();
    $this->screen = Screen::factory()->withToken('tok')->create([
        'store_id' => $this->store->id, 'timezone' => 'America/Chicago',
    ]);
    $this->poster = Media::factory()->create(['store_id' => $this->store->id, 'title' => 'Burger deal']);

    $this->gama = Channel::factory()->create(['name' => 'GAMA']);
    $this->monster = ChannelAd::factory()->lasting(10)->create(['channel_id' => $this->gama->id, 'title' => 'Monster', 'position' => 0]);
    $this->coke = ChannelAd::factory()->lasting(15)->create(['channel_id' => $this->gama->id, 'title' => 'Coke', 'position' => 1]);
});

/*
|--------------------------------------------------------------------------
| The shape of the entry
|--------------------------------------------------------------------------
*/

test('a channel line goes out where it stands, carrying its ads in their order', function () {
    lineOnScreen($this->screen, $this->poster, 0);
    $line = lineOnScreen($this->screen, $this->gama, 1);
    lineOnScreen($this->screen, $this->poster, 2);

    $items = manifestNow($this)['items'];

    expect($items)->toHaveCount(3);
    expect($items[0]['type'])->toBe('image');
    expect($items[2]['type'])->toBe('image');

    expect($items[1]['type'])->toBe('channel');
    expect($items[1]['id'])->toBe('p'.$line->id);
    expect($items[1]['per_pass'])->toBeNull();
    expect(collect($items[1]['ads'])->pluck('id')->all())
        ->toBe(['p'.$line->id.'-a'.$this->monster->id, 'p'.$line->id.'-a'.$this->coke->id]);
});

test('each ad carries what any file carries: url, checksum, length and type', function () {
    ChannelAd::factory()->video(40)->create(['channel_id' => $this->gama->id, 'position' => 2]);
    lineOnScreen($this->screen, $this->gama, 0);

    $ads = manifestNow($this)['items'][0]['ads'];

    expect($ads[0])->toMatchArray([
        'type' => 'image',
        'url' => $this->monster->url,
        'checksum' => $this->monster->cacheKey(),
        'duration' => 10,
        'mime' => 'image/jpeg',
    ]);
    // A video goes out with its own measured length.
    expect($ads[2])->toMatchArray(['type' => 'video', 'duration' => 40, 'mime' => 'video/mp4']);
});

test('the player is told how many of the ads to play each time round', function () {
    $this->gama->update(['ads_per_pass' => 1]);
    lineOnScreen($this->screen, $this->gama, 0);

    $entry = manifestNow($this)['items'][0];

    // All of them still go: only the player knows when a pass has ended, so only the
    // player can move on to the next ad.
    expect($entry['per_pass'])->toBe(1);
    expect($entry['ads'])->toHaveCount(2);
});

test('the same channel twice on a playlist is two entries, never one', function () {
    $first = lineOnScreen($this->screen, $this->gama, 0);
    lineOnScreen($this->screen, $this->poster, 1);
    $second = lineOnScreen($this->screen, $this->gama, 2);

    $items = manifestNow($this)['items'];

    expect($items[0]['id'])->toBe('p'.$first->id);
    expect($items[2]['id'])->toBe('p'.$second->id);
    expect($items[0]['ads'][0]['id'])->not->toBe($items[2]['ads'][0]['id']);
});

test('changing an ad changes the version, so every screen carrying the channel notices', function () {
    lineOnScreen($this->screen, $this->poster, 0);
    lineOnScreen($this->screen, $this->gama, 1);

    $before = manifestNow($this)['version'];

    $this->coke->update(['duration_seconds' => 20]);
    $afterRetime = manifestNow($this)['version'];

    ChannelAd::factory()->create(['channel_id' => $this->gama->id, 'position' => 9]);
    $afterNewAd = manifestNow($this)['version'];

    expect($afterRetime)->not->toBe($before);
    expect($afterNewAd)->not->toBe($afterRetime);
});

/*
|--------------------------------------------------------------------------
| What is sent at all
|--------------------------------------------------------------------------
*/

test("an ad's last day is judged on the screen's own calendar, not the server's", function () {
    $this->coke->update(['ends_on' => '2026-10-15']);
    lineOnScreen($this->screen, $this->gama, 0);

    // 03:00 UTC on the 16th is still 22:00 on the 15th in Chicago: the ad's last evening.
    $this->travelTo(CarbonImmutable::parse('2026-10-16 03:00:00', 'UTC'));
    expect(manifestNow($this)['items'][0]['ads'])->toHaveCount(2);

    // 06:00 UTC is one in the morning on the 16th there, and the ad has ended.
    $this->travelTo(CarbonImmutable::parse('2026-10-16 06:00:00', 'UTC'));
    expect(manifestNow($this)['items'][0]['ads'])->toHaveCount(1);
});

test('a paused channel is not sent at all, and its line is simply skipped', function () {
    $this->gama->update(['is_active' => false]);
    lineOnScreen($this->screen, $this->poster, 0);
    lineOnScreen($this->screen, $this->gama, 1);

    $manifest = manifestNow($this);

    expect($manifest['items'])->toHaveCount(1);
    expect($manifest['items'][0]['type'])->toBe('image');
    expect($manifest['blank'])->toBeFalse();
});

test('two channels on one screen keep their places, and a paused one is simply missing', function () {
    $lottery = Channel::factory()->create(['name' => 'Lottery']);
    ChannelAd::factory()->lasting(5)->create(['channel_id' => $lottery->id, 'title' => 'Jackpot']);

    $paused = Channel::factory()->paused()->create(['name' => 'Thanksgiving']);
    ChannelAd::factory()->create(['channel_id' => $paused->id]);

    lineOnScreen($this->screen, $this->gama, 0);
    lineOnScreen($this->screen, $paused, 1);
    lineOnScreen($this->screen, $lottery, 2);

    $items = manifestNow($this)['items'];

    // Two entries, in the order the shop arranged them — the paused one leaves no gap.
    expect(collect($items)->pluck('type')->all())->toBe(['channel', 'channel']);
    expect($items[0]['ads'])->toHaveCount(2);
    expect($items[1]['ads'][0]['duration'])->toBe(5);
});

test('a channel with no ads running today is skipped rather than sent empty', function () {
    ChannelAd::query()->update(['ends_on' => '2026-10-01']);
    lineOnScreen($this->screen, $this->poster, 0);
    lineOnScreen($this->screen, $this->gama, 1);

    expect(manifestNow($this)['items'])->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| When the line plays
|--------------------------------------------------------------------------
*/

test("an unscheduled channel rides along while the shop's own files are on", function () {
    $hours = Daypart::factory()->between('07:00', '20:00')->create(['store_id' => $this->store->id]);
    lineOnScreen($this->screen, $this->poster, 0, ['daypart_id' => $hours->id]);
    lineOnScreen($this->screen, $this->gama, 1);

    // Noon: the shop's poster is on, and the channel with it.
    expect(collect(manifestNow($this)['items'])->pluck('type')->all())->toBe(['image', 'channel']);
});

test('and goes dark with them, so it never lights up a screen the shop left dark', function () {
    $hours = Daypart::factory()->between('07:00', '20:00')->create(['store_id' => $this->store->id]);
    lineOnScreen($this->screen, $this->poster, 0, ['daypart_id' => $hours->id]);
    lineOnScreen($this->screen, $this->gama, 1);

    // 23:00 in Chicago.
    $this->travelTo(CarbonImmutable::parse('2026-10-11 04:00:00', 'UTC'));

    $manifest = manifestNow($this);
    expect($manifest['items'])->toBeEmpty();
    expect($manifest['blank'])->toBeTrue();
});

test('a channel line with a schedule of its own follows that instead', function () {
    $day = Daypart::factory()->between('07:00', '20:00')->create(['store_id' => $this->store->id]);
    $late = Daypart::factory()->between('21:00', '23:59')->create(['store_id' => $this->store->id]);
    lineOnScreen($this->screen, $this->poster, 0, ['daypart_id' => $day->id]);
    lineOnScreen($this->screen, $this->gama, 1, ['daypart_id' => $late->id]);

    // 23:00: the shop's poster is off, but the channel was scheduled for exactly now.
    $this->travelTo(CarbonImmutable::parse('2026-10-11 04:00:00', 'UTC'));
    expect(collect(manifestNow($this)['items'])->pluck('type')->all())->toBe(['channel']);

    // Noon: the poster is on, and the channel's own hours are not.
    $this->travelTo(CarbonImmutable::parse('2026-10-11 17:00:00', 'UTC'));
    expect(collect(manifestNow($this)['items'])->pluck('type')->all())->toBe(['image']);
});

test('with no live file on the playlist, an unscheduled channel plays on its own', function () {
    // A screen given only channels is not a screen asking to be dark...
    lineOnScreen($this->screen, $this->gama, 0);
    expect(collect(manifestNow($this)['items'])->pluck('type')->all())->toBe(['channel']);

    // ...and neither is one whose own files have all expired: an old promotion left on
    // the list is not a request for a black screen.
    $expired = Media::factory()->expired()->create(['store_id' => $this->store->id]);
    lineOnScreen($this->screen, $expired, 1);

    $manifest = manifestNow($this);
    expect(collect($manifest['items'])->pluck('type')->all())->toBe(['channel']);
    expect($manifest['blank'])->toBeFalse();
});

test('a screen whose only channel has nothing running is dark, like any other gap', function () {
    ChannelAd::query()->update(['ends_on' => '2026-10-01']);
    lineOnScreen($this->screen, $this->gama, 0);

    $manifest = manifestNow($this);
    expect($manifest['items'])->toBeEmpty();
    expect($manifest['blank'])->toBeTrue();
});
