<?php

use App\Http\Controllers\Device\DeviceController;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Daypart;
use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\Screen;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| What a television should show over the next few days, from memory
|--------------------------------------------------------------------------
|
| docs/AD-BUILDER-SPEC.md §15. A line that drops at night must not leave a menu board dark all the next
| day, nor showing breakfast at dinner: the manifest carries a timeline — the answer at now and at every
| moment it can change over the next TIMELINE_HOURS — built by the very resolver that answers "now", so
| the two can never disagree. Each entry names its lines by key into one map.
|
*/

beforeEach(function () {
    $this->store = Store::factory()->create();
    $this->screen = Screen::factory()->withToken('tok')->create(['store_id' => $this->store->id, 'timezone' => 'America/Chicago']);
    $this->breakfast = Daypart::factory()->between('06:00', '11:00')->create(['store_id' => $this->store->id, 'name' => 'Breakfast']);
    $this->lunch = Daypart::factory()->between('11:30', '15:00')->create(['store_id' => $this->store->id, 'name' => 'Lunch']);
});

afterEach(fn () => Carbon::setTestNow());

/** Freeze the clock at a moment on the screen's own wall clock. */
function localNow(string $moment): CarbonImmutable
{
    $at = CarbonImmutable::parse($moment, 'America/Chicago');
    Carbon::setTestNow($at);

    return $at;
}

function scheduledPicture(Store $store, Screen $screen, string $title, int $position, ?Daypart $daypart = null, array $media = []): Media
{
    $picture = Media::factory()->create(['store_id' => $store->id, 'title' => $title, 'type' => Media::TYPE_IMAGE, ...$media]);
    $line = PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $picture->id, 'position' => $position, 'duration_seconds' => 10]);

    if ($daypart) {
        $line->scheduleRules()->create(['daypart_id' => $daypart->id, 'position' => 0]);
    }

    return $picture;
}

/** The timeline a television is handed now: each entry as [local "Y-m-d H:i", blank, [titles…]]. */
function timelineOf($test, array $titles): array
{
    $timeline = $test->withHeader('Authorization', 'Bearer tok')->getJson('/device/playlist')->assertOk()->json('timeline');
    $byUrl = collect($titles)->mapWithKeys(fn (string $title, string $url) => [$url => $title]);

    return collect($timeline['entries'])->map(fn (array $entry) => [
        CarbonImmutable::parse($entry['at'])->setTimezone('America/Chicago')->format('Y-m-d H:i'),
        $entry['blank'],
        collect($entry['items'])->map(fn (string $key) => $byUrl[$timeline['lines'][$key]['url']] ?? $timeline['lines'][$key]['type'])->all(),
    ])->all();
}

test('a menu board follows its dayparts for three days, one entry at each change and none in between', function () {
    localNow('2026-09-24 09:15');   // a Thursday, during breakfast

    $eggs = scheduledPicture($this->store, $this->screen, 'Eggs', 0, $this->breakfast);
    $curry = scheduledPicture($this->store, $this->screen, 'Curry', 1, $this->lunch);

    $entries = timelineOf($this, [$eggs->url => 'Eggs', $curry->url => 'Curry']);

    expect(array_slice($entries, 0, 7))->toBe([
        ['2026-09-24 09:15', false, ['Eggs']],
        ['2026-09-24 11:00', true, []],        // breakfast is over and lunch has not begun: dark
        ['2026-09-24 11:30', false, ['Curry']],
        ['2026-09-24 15:00', true, []],
        ['2026-09-25 06:00', false, ['Eggs']],
        ['2026-09-25 11:00', true, []],
        ['2026-09-25 11:30', false, ['Curry']],
    ]);

    // Never two entries in a row that say the same thing, and never past the horizon.
    foreach (array_slice($entries, 1) as $index => $entry) {
        expect([$entry[1], $entry[2]])->not->toBe([$entries[$index][1], $entries[$index][2]]);
    }

    // 72 hours from Thursday 09:15 reach Sunday 09:15: Sunday's breakfast is the last change inside them.
    expect(end($entries))->toBe(['2026-09-27 06:00', false, ['Eggs']])
        ->and(count($entries))->toBe(13);
});

test('the first entry is exactly the answer for now, and the horizon is the controller’s', function () {
    localNow('2026-09-24 12:00');
    $curry = scheduledPicture($this->store, $this->screen, 'Curry', 0, $this->lunch);

    $manifest = $this->withHeader('Authorization', 'Bearer tok')->getJson('/device/playlist')->assertOk()->json();
    $first = $manifest['timeline']['entries'][0];

    expect($first['blank'])->toBe($manifest['blank'])
        ->and(collect($first['items'])->map(fn (string $key) => $manifest['timeline']['lines'][$key])->all())->toBe($manifest['items'])
        ->and(CarbonImmutable::parse($manifest['server_time'])->diffInHours(CarbonImmutable::parse($manifest['timeline']['until'])))
        ->toEqual((float) DeviceController::TIMELINE_HOURS);
});

test('the holding picture is what a dark hour shows, in the timeline as on the glass', function () {
    localNow('2026-09-24 10:00');
    $holding = Media::factory()->create(['store_id' => $this->store->id, 'title' => 'Holding', 'type' => Media::TYPE_IMAGE]);
    $this->screen->update(['default_media_id' => $holding->id]);
    $eggs = scheduledPicture($this->store, $this->screen, 'Eggs', 0, $this->breakfast);

    $entries = timelineOf($this, [$eggs->url => 'Eggs', $holding->url => 'Holding']);

    expect(array_slice($entries, 0, 3))->toBe([
        ['2026-09-24 10:00', false, ['Eggs']],
        ['2026-09-24 11:00', false, ['Holding']],
        ['2026-09-25 06:00', false, ['Eggs']],
    ]);
});

test('a file that expires, or starts, inside the horizon changes the timeline at that very minute', function () {
    localNow('2026-09-24 09:00');
    // Stored as the app stores them — in UTC — whatever wall clock they were said on.
    $sale = scheduledPicture($this->store, $this->screen, 'Sale', 0, media: ['expires_at' => CarbonImmutable::parse('2026-09-24 13:37', 'America/Chicago')->utc()]);
    $launch = scheduledPicture($this->store, $this->screen, 'Launch', 1, media: ['starts_at' => CarbonImmutable::parse('2026-09-25 08:05', 'America/Chicago')->utc()]);

    $entries = timelineOf($this, [$sale->url => 'Sale', $launch->url => 'Launch']);

    expect($entries)->toBe([
        ['2026-09-24 09:00', false, ['Sale']],
        ['2026-09-24 13:37', true, []],
        ['2026-09-25 08:05', false, ['Launch']],
    ]);
});

test('a window that runs past midnight closes on the next day, and a closed weekday is simply dark', function () {
    localNow('2026-09-24 21:00');   // Thursday
    $late = Daypart::factory()->overnight()->create(['store_id' => $this->store->id, 'name' => 'Late']);   // 22:00 – 02:00
    $late->syncExceptions([['weekday' => 5, 'start_time' => null, 'end_time' => null]]);             // closed Fridays
    $night = scheduledPicture($this->store, $this->screen, 'Night', 0, $late);

    $entries = timelineOf($this, [$night->url => 'Night']);

    expect(array_slice($entries, 0, 5))->toBe([
        ['2026-09-24 21:00', true, []],
        ['2026-09-24 22:00', false, ['Night']],
        ['2026-09-25 02:00', true, []],             // Thursday's window closes on Friday
        ['2026-09-26 22:00', false, ['Night']],     // no Friday night at all
        ['2026-09-27 02:00', true, []],
    ]);
});

test('a channel carries each day’s own ads, and an unscheduled channel rides along with the shop’s files', function () {
    localNow('2026-09-24 09:00');
    $eggs = scheduledPicture($this->store, $this->screen, 'Eggs', 0, $this->breakfast);
    $channel = Channel::factory()->create(['store_id' => $this->store->id]);
    $today = ChannelAd::factory()->create(['channel_id' => $channel->id, 'ends_on' => '2026-09-24']);
    $tomorrow = ChannelAd::factory()->create(['channel_id' => $channel->id, 'starts_on' => '2026-09-25']);
    PlaylistItem::create(['screen_id' => $this->screen->id, 'channel_id' => $channel->id, 'position' => 1]);

    $timeline = $this->withHeader('Authorization', 'Bearer tok')->getJson('/device/playlist')->assertOk()->json('timeline');
    $channelAds = fn (array $entry) => collect($entry['items'])
        ->map(fn (string $key) => $timeline['lines'][$key])
        ->where('type', 'channel')
        ->flatMap(fn (array $line) => collect($line['ads'])->pluck('url'))
        ->all();

    $at = fn (string $local) => collect($timeline['entries'])
        ->last(fn (array $entry) => CarbonImmutable::parse($entry['at'])->lte(CarbonImmutable::parse($local, 'America/Chicago')));

    expect($channelAds($at('2026-09-24 09:30')))->toBe([$today->url])
        ->and($at('2026-09-24 12:00')['blank'])->toBeTrue()                 // the shop's own files are over: so is the channel
        ->and($channelAds($at('2026-09-25 07:00')))->toBe([$tomorrow->url]);
});

test('the network adverts follow their own window in the timeline', function () {
    localNow('2026-09-24 09:00');
    $this->store->update(['accepts_network_ads' => true]);
    $this->screen->update(['accepts_network_ads' => true]);
    scheduledPicture($this->store, $this->screen, 'Menu', 0);
    $campaign = Campaign::factory()->create(['name' => 'Cola', 'start_time' => '17:00', 'end_time' => '19:00']);
    $campaign->screens()->attach($this->screen);

    $timeline = $this->withHeader('Authorization', 'Bearer tok')->getJson('/device/playlist')->assertOk()->json('timeline');
    $ads = collect($timeline['entries'])->map(fn (array $entry) => [
        CarbonImmutable::parse($entry['at'])->setTimezone('America/Chicago')->format('Y-m-d H:i'),
        collect($entry['ads'])->map(fn (string $key) => $timeline['lines'][$key]['id'])->all(),
    ])->all();

    expect(array_slice($ads, 0, 3))->toBe([
        ['2026-09-24 09:00', []],
        ['2026-09-24 17:00', ['c'.$campaign->id]],
        ['2026-09-24 19:00', []],
    ]);
});

test('a busy menu board stays small: every line is sent once, however many entries carry it', function () {
    localNow('2026-09-24 09:00');
    $dinner = Daypart::factory()->between('17:00', '22:00')->create(['store_id' => $this->store->id, 'name' => 'Dinner']);

    foreach (range(0, 29) as $position) {
        scheduledPicture($this->store, $this->screen, "Dish {$position}", $position, [$this->breakfast, $this->lunch, $dinner][$position % 3]);
    }

    $timeline = $this->withHeader('Authorization', 'Bearer tok')->getJson('/device/playlist')->assertOk()->json('timeline');

    expect($timeline['lines'])->toHaveCount(30)
        ->and(strlen(json_encode($timeline)))->toBeLessThan(40000)
        ->and(count($timeline['entries']))->toBeLessThanOrEqual(19);
});

test('the timeline does not move the version: only what plays now does', function () {
    localNow('2026-09-24 09:00');
    scheduledPicture($this->store, $this->screen, 'Eggs', 0, $this->breakfast);

    $before = $this->withHeader('Authorization', 'Bearer tok')->getJson('/device/playlist')->json('version');

    // Tomorrow's lunch changes the timeline, not what is on the glass at nine.
    scheduledPicture($this->store, $this->screen, 'Curry', 1, $this->lunch);

    expect($this->withHeader('Authorization', 'Bearer tok')->getJson('/device/playlist')->json('version'))->toBe($before);
});
