<?php

use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\ScheduleRule;
use App\Models\Screen;
use App\Services\ScheduleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The day a repeat counts from
|--------------------------------------------------------------------------
|
| "Every 2 weeks", every N days or months and every year have to count from a day. With no Starting date they
| counted from the rule row's own creation — and a playlist's save writes every rule afresh, so whenever
| anybody saved the playlist the on weeks flipped and the yearly day moved; the creation was read as a UTC
| date, and the preview counted from the screen's own today (the review of 2026-10-15). Now a save gives such
| a repeat its day, keeps it on every later save and copy, and the preview counts from the very same day.
|
*/

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->actor = createOrganizationUser($this->organization, ['screen-view', 'screen-playlist']);
    $this->screen = Screen::factory()->create(['organization_id' => $this->organization->id, 'timezone' => 'America/Chicago']);
    $this->poster = Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Poster', 'type' => 'image']);
    $this->other = Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Other', 'type' => 'image']);

    $this->actingAs($this->actor)->withSession(['current_organization_id' => $this->organization->id]);

    // "Every 2 weeks on Monday", no Starting date — as the schedule window sends it.
    $this->fortnightly = [
        'start_time' => null, 'end_time' => null, 'starts_on' => null, 'ends_on' => null,
        'recurrence_type' => ScheduleRule::WEEKLY, 'recurrence_interval' => 2, 'recurrence_weekdays' => [1],
        'recurrence_monthday' => null, 'recurrence_ordinal' => null, 'recurrence_weekday' => null, 'recurrence_until' => null,
    ];

    // Save the lines; the answer is the page's next state, as the page keeps it.
    $this->save = function (array $items): array {
        return $this->putJson("/screens/{$this->screen->id}/playlist", [
            'items' => $items,
            'version' => $this->screen->fresh()->playlistFingerprint(),
        ])->assertOk()->json();
    };

    // Whether the poster is on screen at this moment (UTC).
    $this->plays = fn (string $utc, ?Screen $screen = null) => app(ScheduleResolver::class)
        ->resolve(($screen ?? $this->screen)->fresh(), CarbonImmutable::parse($utc, 'UTC'))['items']
        ->contains(fn (PlaylistItem $item) => $item->media_id === $this->poster->id);
});

test('a fortnightly repeat saved with no start is given a day of the screen’s own, and keeps it through every later save', function () {
    // Monday 12 October, 10:00 in Chicago.
    $this->travelTo(CarbonImmutable::parse('2026-10-12 15:00:00', 'UTC'));
    $saved = ($this->save)([
        ['media_id' => $this->poster->id, 'duration_seconds' => 10, 'rules' => [$this->fortnightly]],
        ['media_id' => $this->other->id, 'duration_seconds' => 10, 'rules' => []],
    ]);

    expect($saved['items'][0]['rules'][0]['starts_on'])->toBe('2026-10-12');

    // A week on — the off week — a colleague retimes the other line. Once with the page's own answer sent back, once
    // with the rule said afresh by hand, with no Starting date at all: the poster keeps its weeks either way.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 15:00:00', 'UTC'));
    $again = ($this->save)([
        ['media_id' => $this->poster->id, 'duration_seconds' => 10, 'rules' => $saved['items'][0]['rules']],
        ['media_id' => $this->other->id, 'duration_seconds' => 20, 'rules' => []],
    ]);
    ($this->save)([
        ['media_id' => $this->poster->id, 'duration_seconds' => 10, 'rules' => [$this->fortnightly]],
        ['media_id' => $this->other->id, 'duration_seconds' => 30, 'rules' => []],
    ]);

    expect($again['items'][0]['rules'][0]['starts_on'])->toBe('2026-10-12')
        ->and(ScheduleRule::sole()->starts_on->toDateString())->toBe('2026-10-12')
        ->and(($this->plays)('2026-10-12 15:00:00'))->toBeTrue()
        ->and(($this->plays)('2026-10-19 15:00:00'))->toBeFalse()
        ->and(($this->plays)('2026-10-26 15:00:00'))->toBeTrue()
        ->and(($this->plays)('2026-11-02 15:00:00'))->toBeFalse();
});

test('the day is the screen’s own, and the preview counts from the very day the save gives', function () {
    // Sunday 18 October, 20:00 in Chicago — already Monday the 19th in UTC.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 01:00:00', 'UTC'));

    $preview = $this->postJson("/screens/{$this->screen->id}/playlist/preview", ['rules' => [$this->fortnightly], 'days' => 14])
        ->assertOk()->json('occurrences');
    ($this->save)([['media_id' => $this->poster->id, 'duration_seconds' => 10, 'rules' => [$this->fortnightly]]]);

    // The Monday of the screen's own week (Sunday the 18th in Chicago), never of the server's (Monday the 19th in UTC).
    expect(ScheduleRule::sole()->starts_on->toDateString())->toBe('2026-10-12')
        ->and(collect($preview)->pluck('date')->all())->toBe(['2026-10-26'])
        ->and(($this->plays)('2026-10-19 15:00:00'))->toBeFalse()
        ->and(($this->plays)('2026-10-26 15:00:00'))->toBeTrue();
});

test('every repeat that counts from a day is given one, and nothing else is', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 15:00:00', 'UTC'));
    $rule = fn (array $overrides) => [...$this->fortnightly, 'recurrence_weekdays' => [], ...$overrides];

    ($this->save)([['media_id' => $this->poster->id, 'duration_seconds' => 10, 'rules' => [
        $rule(['recurrence_type' => ScheduleRule::DAILY, 'recurrence_interval' => 3]),
        $rule(['recurrence_type' => ScheduleRule::MONTHLY_DAY, 'recurrence_interval' => 2, 'recurrence_monthday' => 21]),
        $rule(['recurrence_type' => ScheduleRule::MONTHLY_WEEKDAY, 'recurrence_interval' => 2, 'recurrence_ordinal' => 1, 'recurrence_weekday' => 4]),
        $rule(['recurrence_type' => ScheduleRule::YEARLY, 'recurrence_interval' => 1]),
        // Counted from no day at all: every Friday, every day, a plain range, a start typed in.
        $rule(['recurrence_type' => ScheduleRule::WEEKLY, 'recurrence_interval' => 1, 'recurrence_weekdays' => [5]]),
        $rule(['recurrence_type' => ScheduleRule::DAILY, 'recurrence_interval' => 1]),
        $rule(['recurrence_type' => null, 'recurrence_interval' => 1, 'ends_on' => '2026-12-31']),
        $rule(['recurrence_interval' => 2, 'recurrence_weekdays' => [1], 'starts_on' => '2026-09-07']),
    ]]]);

    // Thursday the 15th: every 3 days and every year from that very day, every 2 months from the 1st of its month.
    expect(ScheduleRule::orderBy('position')->get()->map(fn (ScheduleRule $saved) => $saved->starts_on?->toDateString())->all())
        ->toBe(['2026-10-15', '2026-10-01', '2026-10-01', '2026-10-15', null, null, null, '2026-09-07']);
});

test('a yearly day stays the day it was saved on, whoever saves again and when', function () {
    $yearly = [...$this->fortnightly, 'recurrence_type' => ScheduleRule::YEARLY, 'recurrence_interval' => 1, 'recurrence_weekdays' => []];

    $this->travelTo(CarbonImmutable::parse('2026-10-15 15:00:00', 'UTC'));
    ($this->save)([['media_id' => $this->poster->id, 'duration_seconds' => 10, 'rules' => [$yearly]]]);

    $this->travelTo(CarbonImmutable::parse('2026-11-03 15:00:00', 'UTC'));
    ($this->save)([['media_id' => $this->poster->id, 'duration_seconds' => 20, 'rules' => [$yearly]]]);

    expect(($this->plays)('2027-10-15 15:00:00'))->toBeTrue()
        ->and(($this->plays)('2026-11-03 15:00:00'))->toBeFalse()
        ->and(($this->plays)('2027-11-03 15:00:00'))->toBeFalse();
});

test('a Starting date still to come, taken away, starts the repeat this week; a changed repeat counts afresh', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 15:00:00', 'UTC'));
    ($this->save)([['media_id' => $this->poster->id, 'duration_seconds' => 10, 'rules' => [[...$this->fortnightly, 'starts_on' => '2026-11-02']]]]);

    // The person empties Starting: they meant "from now", not the start they took away — from this week, Monday the 12th.
    ($this->save)([['media_id' => $this->poster->id, 'duration_seconds' => 10, 'rules' => [$this->fortnightly]]]);
    expect(ScheduleRule::sole()->starts_on->toDateString())->toBe('2026-10-12');

    // Other weekdays sent with no Starting date: a new repeat, counted from this week — never another line's day.
    $this->travelTo(CarbonImmutable::parse('2026-10-22 15:00:00', 'UTC'));
    ($this->save)([['media_id' => $this->poster->id, 'duration_seconds' => 10, 'rules' => [[...$this->fortnightly, 'recurrence_weekdays' => [2]]]]]);
    expect(ScheduleRule::sole()->starts_on->toDateString())->toBe('2026-10-19');
});

test('a copy onto a screen on another clock carries the day the repeat counts from', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 15:00:00', 'UTC'));
    ($this->save)([['media_id' => $this->poster->id, 'duration_seconds' => 10, 'rules' => [$this->fortnightly]]]);

    // A week on, a television in Karachi is given the same playlist.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 15:00:00', 'UTC'));
    $karachi = Screen::factory()->create(['organization_id' => $this->organization->id, 'timezone' => 'Asia/Karachi']);
    $this->postJson("/screens/{$this->screen->id}/playlist/copy", ['target_screen_ids' => [$karachi->id]])->assertOk();

    $copied = $karachi->playlistItems()->firstOrFail()->scheduleRules()->sole();

    expect($copied->starts_on->toDateString())->toBe('2026-10-12')
        ->and(($this->plays)('2026-10-26 10:00:00', $karachi))->toBeTrue()
        ->and(($this->plays)('2026-10-19 10:00:00', $karachi))->toBeFalse();
});

test('the preview of a line counts from the day its save would keep', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 15:00:00', 'UTC'));
    ($this->save)([['media_id' => $this->poster->id, 'duration_seconds' => 10, 'rules' => [$this->fortnightly]]]);

    // A week on, the window of that line shows the rule with its Starting emptied: the save would keep the 12th.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 15:00:00', 'UTC'));
    $ofTheLine = $this->postJson("/screens/{$this->screen->id}/playlist/preview", [
        'rules' => [$this->fortnightly], 'days' => 14, 'media_id' => $this->poster->id,
    ])->assertOk()->json('occurrences');

    expect(collect($ofTheLine)->pluck('date')->all())->toBe(['2026-10-26']);

    // A request that names nothing, or names a line's file in a shape no id has, is said plainly.
    $this->postJson("/screens/{$this->screen->id}/playlist/preview", ['rules' => [$this->fortnightly], 'media_id' => ['x']])
        ->assertStatus(422)->assertJsonValidationErrors(['media_id' => 'A schedule could not be read. Set it again.']);
});

test('a repeat set after midnight plays the night that opened yesterday, as it did before it was given a day', function () {
    // Saturday 17 October, one in the morning in Chicago: a fortnightly Friday night, 22:00 to 02:00, is added. Friday's
    // night is still on, and this week is one of its weeks: it plays now, as a repeat counted from today played before.
    $this->travelTo(CarbonImmutable::parse('2026-10-17 06:00:00', 'UTC'));
    $fridayNights = [...$this->fortnightly, 'recurrence_weekdays' => [5], 'start_time' => '22:00', 'end_time' => '02:00'];
    ($this->save)([['media_id' => $this->poster->id, 'duration_seconds' => 10, 'rules' => [$fridayNights]]]);

    expect(($this->plays)('2026-10-17 06:00:00'))->toBeTrue()
        ->and(ScheduleRule::sole()->starts_on->toDateString())->toBe('2026-10-12')
        ->and(($this->plays)('2026-10-24 04:00:00'))->toBeFalse()
        ->and(($this->plays)('2026-10-31 04:00:00'))->toBeTrue();

    // Every 2 months on the 16th, set at one in the morning of the 17th: the night of the 16th plays on too.
    $monthly = [...$this->fortnightly, 'recurrence_type' => ScheduleRule::MONTHLY_DAY, 'recurrence_weekdays' => [], 'recurrence_monthday' => 16,
        'start_time' => '22:00', 'end_time' => '02:00'];
    ($this->save)([['media_id' => $this->poster->id, 'duration_seconds' => 10, 'rules' => [$monthly]]]);

    expect(($this->plays)('2026-10-17 06:00:00'))->toBeTrue()
        ->and(ScheduleRule::sole()->starts_on->toDateString())->toBe('2026-10-01')
        ->and(($this->plays)('2026-11-17 06:00:00'))->toBeFalse()
        ->and(($this->plays)('2026-12-17 06:00:00'))->toBeTrue();
});

test('a repeat already over keeps no Starting date after its end, and the page saves it back as it came', function () {
    // Saturday 10 October in Chicago. Every 2 weeks on Monday, over on the 1st; every year, over on the 5th; every 2 weeks
    // with an end date sent by hand, past too: each plays nothing any more.
    $this->travelTo(CarbonImmutable::parse('2026-10-10 15:00:00', 'UTC'));
    $saved = ($this->save)([['media_id' => $this->poster->id, 'duration_seconds' => 10, 'rules' => [
        [...$this->fortnightly, 'recurrence_until' => '2026-10-01'],
        [...$this->fortnightly, 'recurrence_type' => ScheduleRule::YEARLY, 'recurrence_interval' => 1, 'recurrence_weekdays' => [], 'recurrence_until' => '2026-10-05'],
        [...$this->fortnightly, 'ends_on' => '2026-10-02'],
        // Over on the Monday its week starts on: counted from that very day, never after it.
        [...$this->fortnightly, 'recurrence_until' => '2026-10-05'],
    ]]]);

    expect(collect($saved['items'][0]['rules'])->pluck('starts_on')->all())->toBe([null, null, null, '2026-10-05']);

    // The page's own answer sent back by whoever saves next: saved, never "The repeat cannot end before the schedule starts."
    $this->travelTo(CarbonImmutable::parse('2026-10-12 15:00:00', 'UTC'));
    $again = ($this->save)([['media_id' => $this->poster->id, 'duration_seconds' => 20, 'rules' => $saved['items'][0]['rules']]]);

    expect(collect($again['items'][0]['rules'])->pluck('starts_on')->all())->toBe([null, null, null, '2026-10-05'])
        ->and(ScheduleRule::orderBy('position')->get()->map(fn (ScheduleRule $rule) => $rule->starts_on?->toDateString())->all())
        ->toBe([null, null, null, '2026-10-05']);
});

test('a repeat saved before and created after its own end is left as it was, and the page and a copy save it back', function () {
    $line = PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $this->poster->id, 'position' => 0, 'duration_seconds' => 10]);
    DB::table('schedule_rules')->insert([
        'playlist_item_id' => $line->id, 'recurrence_type' => ScheduleRule::WEEKLY, 'recurrence_interval' => 2,
        'recurrence_weekdays' => json_encode([1]), 'recurrence_until' => '2026-10-05', 'position' => 0,
        // The playlist was saved on the 14th: its rule rows are that save's, though the repeat ended on the 5th.
        'created_at' => '2026-10-14 10:00:00', 'updated_at' => '2026-10-14 10:00:00',
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-10-20 15:00:00', 'UTC'));
    expect($this->getJson("/screens/{$this->screen->id}/playlist")->assertOk()->json('items.0.rules.0.starts_on'))->toBeNull();

    (require database_path('migrations/2026_10_15_210000_give_every_repeat_the_day_it_counts_from.php'))->up();
    $page = $this->getJson("/screens/{$this->screen->id}/playlist")->assertOk()->json();

    expect(ScheduleRule::sole()->starts_on)->toBeNull()
        ->and($page['items'][0]['rules'][0]['starts_on'])->toBeNull();

    $karachi = Screen::factory()->create(['organization_id' => $this->organization->id, 'timezone' => 'Asia/Karachi']);
    $this->postJson("/screens/{$this->screen->id}/playlist/copy", ['target_screen_ids' => [$karachi->id]])->assertOk();
    ($this->save)([['media_id' => $this->poster->id, 'duration_seconds' => 20, 'rules' => $page['items'][0]['rules']]]);

    $copied = $this->getJson("/screens/{$karachi->id}/playlist")->assertOk()->json();
    $this->putJson("/screens/{$karachi->id}/playlist", ['items' => $copied['items'], 'version' => $karachi->fresh()->playlistFingerprint()])->assertOk();

    expect(ScheduleRule::pluck('starts_on')->all())->toBe([null, null]);
});

test('the migration gives every repeat saved before the day it plays by, cuts no night still to play, and its down() takes back those rows alone', function () {
    $line = PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $this->poster->id, 'position' => 0, 'duration_seconds' => 10]);
    $insert = fn (array $row) => DB::table('schedule_rules')->insertGetId([
        'playlist_item_id' => $line->id, 'starts_on' => null, 'ends_on' => null, 'recurrence_type' => ScheduleRule::WEEKLY,
        'recurrence_interval' => 2, 'recurrence_weekdays' => json_encode([1]), 'position' => 0,
        // Late on a Sunday in Chicago, already Monday in UTC: the day the resolver has read it by.
        'created_at' => '2026-10-05 03:30:00', 'updated_at' => '2026-10-05 03:30:00', ...$row,
    ]);
    // Saved on a Thursday evening in Chicago — already Friday the 16th in UTC — with hours that evening.
    $thursdayEvening = ['created_at' => '2026-10-16 02:00:00', 'updated_at' => '2026-10-16 02:00:00', 'start_time' => '21:30', 'end_time' => '23:30'];

    $rows = [
        'fortnight' => $insert([]),
        'yearly' => $insert(['recurrence_type' => ScheduleRule::YEARLY, 'recurrence_interval' => 1, 'recurrence_weekdays' => null]),
        'weekly' => $insert(['recurrence_interval' => 1]),
        'typed' => $insert(['starts_on' => '2026-09-14']),
        'thursdays' => $insert([...$thursdayEvening, 'recurrence_weekdays' => json_encode([4])]),
        'the 15th' => $insert([...$thursdayEvening, 'recurrence_type' => ScheduleRule::MONTHLY_DAY, 'recurrence_weekdays' => null, 'recurrence_monthday' => 15]),
        // Every other day, 22:00 to 21:30 the next evening: Wednesday's night still plays on Thursday at nine.
        'every other day' => $insert([...$thursdayEvening, 'recurrence_type' => ScheduleRule::DAILY, 'recurrence_weekdays' => null,
            'start_time' => '22:00', 'end_time' => '21:30']),
        'first Thursday' => $insert(['recurrence_type' => ScheduleRule::MONTHLY_WEEKDAY, 'recurrence_interval' => 3, 'recurrence_weekdays' => null,
            'recurrence_ordinal' => 1, 'recurrence_weekday' => 4, 'created_at' => '2026-10-31 23:00:00', 'updated_at' => '2026-10-31 23:00:00']),
        // Created on the 14th, over on the 5th: it plays nothing any more, and is left as it was.
        'over' => $insert(['recurrence_until' => '2026-10-05', 'created_at' => '2026-10-14 10:00:00', 'updated_at' => '2026-10-14 10:00:00']),
    ];

    // Every day from two days before each rule was made, and every half hour of the screen's own clock from the day before.
    $answers = function (): array {
        $answers = [];
        foreach (ScheduleRule::orderBy('id')->get() as $rule) {
            $made = CarbonImmutable::parse($rule->created_at->toDateString());
            foreach (range(-2, 400) as $offset) {
                $answers[$rule->id.'@'.$made->addDays($offset)->toDateString()] = $rule->coversDay($made->addDays($offset));
            }
            $clock = CarbonImmutable::parse($made->subDay()->toDateString(), 'America/Chicago');
            foreach (range(0, 48 * 22) as $halfHour) {
                $at = $clock->addMinutes(30 * $halfHour);
                $answers[$rule->id.'@'.$at->toIso8601String()] = $rule->coversAt($at);
            }
        }

        return $answers;
    };

    $before = $answers();
    $migration = require database_path('migrations/2026_10_15_210000_give_every_repeat_the_day_it_counts_from.php');
    $migration->up();
    $starts = fn (): array => DB::table('schedule_rules')->orderBy('id')->pluck('starts_on')
        ->map(fn (?string $day) => $day === null ? null : substr($day, 0, 10))->all();
    $given = ['2026-10-05', '2026-10-05', null, '2026-09-14', '2026-10-12', '2026-10-01', '2026-10-14', '2026-10-01', null];

    // The Monday of its week, its date for every year, the 1st of its month, one turn earlier for every other day.
    expect($answers())->toBe($before)
        ->and($starts())->toBe($given)
        // Thursday the 15th in Chicago: the evening each was saved on still plays.
        ->and(ScheduleRule::find($rows['thursdays'])->coversAt(CarbonImmutable::parse('2026-10-15 22:00:00', 'America/Chicago')))->toBeTrue()
        ->and(ScheduleRule::find($rows['the 15th'])->coversAt(CarbonImmutable::parse('2026-10-15 22:00:00', 'America/Chicago')))->toBeTrue()
        ->and(ScheduleRule::find($rows['every other day'])->coversAt(CarbonImmutable::parse('2026-10-15 21:15:00', 'America/Chicago')))->toBeTrue();

    // Run again, it changes nothing.
    $migration->up();
    expect($starts())->toBe($given)
        ->and(DB::table('schedule_rules')->where('updated_at', '2026-10-15 21:00:00')->orderBy('id')->pluck('id')->all())
        ->toBe([$rows['fortnight'], $rows['yearly'], $rows['thursdays'], $rows['the 15th'], $rows['every other day'], $rows['first Thursday']]);

    // A rule saved since the migration is a row of its own — on another screen here, so this one's rows stay — which
    // down() never touches, though its day is the Monday of its own week too.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 15:00:00', 'UTC'));
    $window = Screen::factory()->create(['organization_id' => $this->organization->id, 'timezone' => 'America/Chicago']);
    $this->putJson("/screens/{$window->id}/playlist", [
        'items' => [['media_id' => $this->other->id, 'duration_seconds' => 10, 'rules' => [$this->fortnightly]]],
        'version' => $window->playlistFingerprint(),
    ])->assertOk();
    $since = ScheduleRule::where('playlist_item_id', $window->playlistItems()->value('id'))->sole();

    $migration->down();

    $mine = DB::table('schedule_rules')->whereIn('id', $rows)->orderBy('id')->get();
    expect($mine->pluck('starts_on')->map(fn (?string $day) => $day === null ? null : substr($day, 0, 10))->all())
        ->toBe([null, null, null, '2026-09-14', null, null, null, null, null])
        ->and($mine->map(fn (object $rule) => $rule->updated_at === $rule->created_at)->unique()->all())->toBe([true])
        ->and($since->fresh()->starts_on->toDateString())->toBe('2026-10-19')
        ->and(collect($answers())->reject(fn (bool $covers, string $key) => str_starts_with($key, $since->id.'@'))->all())->toBe($before);
});
