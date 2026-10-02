<?php

use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Role;
use App\Models\ScheduleRule;
use App\Models\Screen;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| The time moves onto the schedule rule, and the dayparts go (owner, 2026-10-01)
|--------------------------------------------------------------------------
|
| `2026_10_02_100000_put_the_time_on_the_schedule_rule` rewrites every rule that named a daypart so that it says the
| same thing by itself. "The same thing" is checked the hard way: what the OLD tables said for every half hour of two
| months — worked out here, from the old rows, by the rules the old models followed — against what the line says
| after the migration. A daypart with other hours on some weekdays becomes a rule for each set of hours; the three
| repeats that cannot also say "only on these weekdays" stop the migration before anything is changed.
|
*/

/** The migration under test. */
function timesMigration(): object
{
    return require database_path('migrations/2026_10_02_100000_put_the_time_on_the_schedule_rule.php');
}

/** When every rule in these tests was made: a repeat with no start date counts from here. */
const RULES_WRITTEN = '2026-03-02 09:00:00';

/** The dayparts the scenarios use: their hours, and the other hours of some weekdays (null: closed). */
const OLD_DAYPARTS = [
    'plain' => ['11:00', '15:00', []],
    'deli' => ['07:00', '20:00', [7 => ['09:00', '16:00'], 1 => null]],
    'late' => ['22:00', '02:00', [5 => null, 6 => ['20:00', '03:00']]],
    'shut' => ['09:00', '17:00', [1 => null, 2 => null, 3 => null, 4 => null, 5 => null, 6 => null, 7 => null]],
    'same' => ['09:00', '17:00', [7 => ['09:00', '17:00']]],
];

/** The days a rule may name that can be carried over whatever its daypart keeps. */
const CARRIED_DAYS = [
    'always' => [],
    'between dates' => ['starts_on' => '2026-03-10', 'ends_on' => '2026-03-25'],
    'every day until' => ['recurrence_type' => 'daily', 'starts_on' => '2026-03-05', 'recurrence_until' => '2026-03-28'],
    'some weekdays' => ['recurrence_type' => 'weekly', 'recurrence_weekdays' => [1, 5, 7]],
    'every other week' => ['recurrence_type' => 'weekly', 'recurrence_weekdays' => [1, 2, 3, 4, 5, 6, 7], 'recurrence_interval' => 2, 'starts_on' => '2026-03-04'],
    'every other week, no start' => ['recurrence_type' => 'weekly', 'recurrence_weekdays' => [5, 6], 'recurrence_interval' => 2],
    'the third Thursday' => ['recurrence_type' => 'monthly_weekday', 'recurrence_ordinal' => 3, 'recurrence_weekday' => 4],
    'the last Monday' => ['recurrence_type' => 'monthly_weekday', 'recurrence_ordinal' => -1, 'recurrence_weekday' => 1],
    'Mondays' => ['recurrence_type' => 'weekly', 'recurrence_weekdays' => [1]],
];

/** The days that cannot also say "only on these weekdays". */
const WEEKDAY_BLIND_DAYS = [
    'every third day' => ['recurrence_type' => 'daily', 'recurrence_interval' => 3, 'starts_on' => '2026-03-03'],
    'the 21st of the month' => ['recurrence_type' => 'monthly_day', 'recurrence_monthday' => 21],
    'once a year' => ['recurrence_type' => 'yearly', 'starts_on' => '2026-03-20'],
];

/** A daypart as the old tables kept it. */
function oldDaypart(Organization $organization, string $name): int
{
    [$start, $end, $exceptions] = OLD_DAYPARTS[$name];

    $id = DB::table('dayparts')->insertGetId([
        'organization_id' => $organization->id, 'name' => ucfirst($name).' hours', 'start_time' => $start.':00', 'end_time' => $end.':00',
        'is_retired' => false, 'created_at' => RULES_WRITTEN, 'updated_at' => RULES_WRITTEN,
    ]);

    foreach ($exceptions as $weekday => $hours) {
        DB::table('daypart_exceptions')->insert([
            'daypart_id' => $id, 'weekday' => $weekday,
            'start_time' => $hours === null ? null : $hours[0].':00', 'end_time' => $hours === null ? null : $hours[1].':00',
        ]);
    }

    return $id;
}

/** A rule as the old table kept it, on a line of its own unless one is given. Returns the line's id. */
function oldRule(Screen $screen, ?int $daypartId, array $days = [], ?int $lineId = null, int $position = 0): int
{
    $lineId ??= PlaylistItem::create([
        'screen_id' => $screen->id, 'position' => PlaylistItem::where('screen_id', $screen->id)->count(), 'duration_seconds' => 10,
        'media_id' => Media::factory()->create(['organization_id' => $screen->organization_id])->id,
    ])->id;

    DB::table('schedule_rules')->insert([
        'playlist_item_id' => $lineId, 'daypart_id' => $daypartId, 'recurrence_interval' => 1, 'position' => $position,
        'created_at' => RULES_WRITTEN, 'updated_at' => RULES_WRITTEN,
        ...$days,
        ...(isset($days['recurrence_weekdays']) ? ['recurrence_weekdays' => json_encode($days['recurrence_weekdays'])] : []),
    ]);

    return $lineId;
}

/**
 * What the OLD tables said: was a line with this rule due at that moment? The old models' own rules, written out —
 * the daypart's hours for the weekday its window opened on (or yesterday's, still open past midnight), then the
 * rule's days asked about THAT day.
 */
function wasDue(ScheduleRule $days, ?string $daypart, CarbonImmutable $at): bool
{
    if ($daypart === null) {
        return $days->coversDay($at->startOfDay());
    }

    [$start, $end, $exceptions] = OLD_DAYPARTS[$daypart];
    $hoursOn = fn (int $weekday) => array_key_exists($weekday, $exceptions) ? $exceptions[$weekday] : [$start, $end];
    $time = $at->format('H:i');
    $today = $hoursOn($at->dayOfWeekIso);

    if ($today !== null && ($today[1] > $today[0] ? ($time >= $today[0] && $time < $today[1]) : $time >= $today[0])) {
        return $days->coversDay($at->startOfDay());
    }

    $yesterday = $hoursOn($at->subDay()->dayOfWeekIso);

    if ($yesterday !== null && $yesterday[1] <= $yesterday[0] && $time < $yesterday[1]) {
        return $days->coversDay($at->subDay()->startOfDay());
    }

    return false;
}

beforeEach(function () {
    // The database as the earlier files left it: the two daypart tables, the rules' daypart_id, the four permissions.
    timesMigration()->down();

    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->screen = Screen::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Deli TV']);
});

test('every rule says after the migration exactly what it said before, for every half hour of two months', function () {
    $dayparts = collect(array_keys(OLD_DAYPARTS))->mapWithKeys(fn (string $name) => [$name => oldDaypart($this->organization, $name)]);
    $scenarios = [];

    foreach (CARRIED_DAYS as $days => $attributes) {
        foreach ([null, ...array_keys(OLD_DAYPARTS)] as $daypart) {
            $scenarios[] = [$days, $daypart, $attributes, oldRule($this->screen, $daypart === null ? null : $dayparts[$daypart], $attributes)];
        }
    }

    // The weekday-blind repeats carry over too wherever the daypart keeps one set of hours, or none at all.
    foreach (WEEKDAY_BLIND_DAYS as $days => $attributes) {
        foreach (['plain', 'same', 'shut'] as $daypart) {
            $scenarios[] = [$days, $daypart, $attributes, oldRule($this->screen, $dayparts[$daypart], $attributes)];
        }
    }

    timesMigration()->up();

    $lines = PlaylistItem::with('scheduleRules')->get()->keyBy('id');
    $moments = collect(range(0, 61 * 48 - 1))->map(fn (int $step) => CarbonImmutable::parse('2026-03-01 00:00')->addMinutes($step * 30));
    $due = 0;

    foreach ($scenarios as [$days, $daypart, $attributes, $lineId]) {
        $before = new ScheduleRule($attributes);
        $before->created_at = RULES_WRITTEN;

        foreach ($moments as $at) {
            $expected = wasDue($before, $daypart, $at);
            $due += (int) $expected;

            if ($lines[$lineId]->isDueAt($at) !== $expected) {
                throw new RuntimeException("[{$days}] with [".($daypart ?? 'no daypart')."] differs at {$at->format('D Y-m-d H:i')}: it was ".($expected ? 'due' : 'not due').' before.');
            }
        }
    }

    // Not vacuous: thousands of those moments really were due, and far more really were not.
    expect(count($scenarios))->toBe(63)
        ->and($due)->toBeGreaterThan(10000)->toBeLessThan(count($scenarios) * $moments->count() - 10000);
});

test('the tables, the column and the four permissions are gone, and the rules keep their hours themselves', function () {
    $lineId = oldRule($this->screen, oldDaypart($this->organization, 'plain'), CARRIED_DAYS['some weekdays']);
    $grants = DB::table('role_has_permissions')->count();
    $daypartGrants = DB::table('role_has_permissions')->whereIn('permission_id', DB::table('permissions')->where('name', 'like', 'daypart-%')->pluck('id'))->count();

    expect($daypartGrants)->toBeGreaterThan(0);

    timesMigration()->up();

    $rule = ScheduleRule::where('playlist_item_id', $lineId)->sole();

    expect(Schema::hasTable('dayparts'))->toBeFalse()
        ->and(Schema::hasTable('daypart_exceptions'))->toBeFalse()
        ->and(Schema::hasColumn('schedule_rules', 'daypart_id'))->toBeFalse()
        ->and(collect(Schema::getForeignKeys('schedule_rules'))->pluck('foreign_table')->all())->toBe(['playlist_items'])
        ->and([$rule->start_time, $rule->end_time])->toBe(['11:00', '15:00'])
        ->and($rule->recurrence_weekdays)->toBe([1, 5, 7])
        ->and(DB::table('permissions')->where('name', 'like', 'daypart-%')->count())->toBe(0)
        // Their grants went with them, and no other grant was touched.
        ->and(DB::table('role_has_permissions')->count())->toBe($grants - $daypartGrants);
});

test('a daypart with other hours on some weekdays becomes a rule for each set of hours, in the rule\'s place on its line', function () {
    $deli = oldDaypart($this->organization, 'deli');
    $lineId = oldRule($this->screen, null, ['starts_on' => '2026-12-24', 'ends_on' => '2026-12-26']);
    oldRule($this->screen, $deli, ['starts_on' => '2026-03-10', 'ends_on' => '2026-12-31'], $lineId, 1);
    oldRule($this->screen, oldDaypart($this->organization, 'plain'), [], $lineId, 2);

    timesMigration()->up();

    $rules = ScheduleRule::where('playlist_item_id', $lineId)->orderBy('position')->get();

    expect($rules->map(fn (ScheduleRule $rule) => [
        $rule->position, $rule->start_time, $rule->end_time, $rule->recurrence_type, $rule->recurrence_weekdays,
        $rule->starts_on?->toDateString(), $rule->ends_on?->toDateString(), $rule->recurrence_until?->toDateString(),
    ])->all())->toBe([
        [0, null, null, null, null, '2026-12-24', '2026-12-26', null],
        // Tuesday to Saturday at the deli's own hours; Sunday at its Sunday hours; Monday, closed, in neither. A
        // repeat ends with its "until", so the last day moved there — where the schedule window shows it.
        [1, '07:00', '20:00', 'weekly', [2, 3, 4, 5, 6], '2026-03-10', null, '2026-12-31'],
        [2, '09:00', '16:00', 'weekly', [7], '2026-03-10', null, '2026-12-31'],
        [3, '11:00', '15:00', null, null, null, null, null],
    ]);
});

test('a rule its daypart never opened for keeps never playing, instead of playing around the clock', function () {
    // With no rule at all a line plays always: the opposite of what these two did.
    $closedMondays = oldRule($this->screen, oldDaypart($this->organization, 'deli'), CARRIED_DAYS['Mondays']);
    $neverOpen = oldRule($this->screen, oldDaypart($this->organization, 'shut'), WEEKDAY_BLIND_DAYS['the 21st of the month']);

    timesMigration()->up();

    foreach ([$closedMondays, $neverOpen] as $lineId) {
        $line = PlaylistItem::with('scheduleRules')->findOrFail($lineId);

        expect($line->scheduleRules)->toHaveCount(1)
            ->and($line->scheduleRules[0]->recurrence_type)->toBe('weekly')
            ->and($line->scheduleRules[0]->recurrence_weekdays)->toBe([]);

        foreach (['2026-03-02 12:00', '2026-03-21 12:00', '2026-03-22 03:00'] as $moment) {
            expect($line->isDueAt(CarbonImmutable::parse($moment)))->toBeFalse();
        }
    }
});

test('a repeat that cannot say "only on these weekdays" stops the migration before anything is changed', function (string $days) {
    $deli = oldDaypart($this->organization, 'deli');
    $lineId = oldRule($this->screen, $deli, WEEKDAY_BLIND_DAYS[$days]);
    oldRule($this->screen, oldDaypart($this->organization, 'plain'), CARRIED_DAYS['always']);
    $before = DB::table('schedule_rules')->orderBy('id')->get()->toArray();

    expect(fn () => timesMigration()->up())->toThrow(RuntimeException::class, 'on screen "Deli TV", line 1');

    // Nothing was touched: not the tables, not the columns, not the rule that could have been carried over.
    expect(Schema::hasTable('dayparts'))->toBeTrue()
        ->and(Schema::hasColumn('schedule_rules', 'daypart_id'))->toBeTrue()
        ->and(Schema::hasColumn('schedule_rules', 'start_time'))->toBeFalse()
        ->and(DB::table('schedule_rules')->orderBy('id')->get()->toArray())->toEqual($before)
        ->and(DB::table('permissions')->where('name', 'like', 'daypart-%')->count())->toBe(4);

    // Changed by its organization — a daypart that keeps one set of hours — it carries over.
    DB::table('daypart_exceptions')->where('daypart_id', $deli)->delete();
    timesMigration()->up();

    $rule = ScheduleRule::where('playlist_item_id', $lineId)->sole();

    expect([$rule->start_time, $rule->end_time, $rule->recurrence_type])->toBe(['07:00', '20:00', WEEKDAY_BLIND_DAYS[$days]['recurrence_type']]);
})->with(array_keys(WEEKDAY_BLIND_DAYS));

test('a daypart whose weekday opens before the night before has closed is carried over only for a rule that names no days', function () {
    // Friday 22:00 to 04:00, and Saturday opening at 02:00. At three on Saturday morning the daypart said "Saturday's
    // hours"; a rule for each set of hours says "both" — the same thing only while every day is covered alike.
    $overlapping = DB::table('dayparts')->insertGetId([
        'organization_id' => $this->organization->id, 'name' => 'Night shift', 'start_time' => '22:00:00', 'end_time' => '04:00:00',
        'is_retired' => false, 'created_at' => RULES_WRITTEN, 'updated_at' => RULES_WRITTEN,
    ]);
    DB::table('daypart_exceptions')->insert(['daypart_id' => $overlapping, 'weekday' => 6, 'start_time' => '02:00:00', 'end_time' => '06:00:00']);

    $always = oldRule($this->screen, $overlapping);
    $ranged = oldRule($this->screen, $overlapping, ['ends_on' => '2026-03-20']);   // ends on a Friday

    expect(fn () => timesMigration()->up())->toThrow(RuntimeException::class, 'opens on one weekday before the hours of the day before have closed');
    expect(Schema::hasColumn('schedule_rules', 'start_time'))->toBeFalse();

    // Without the rule that tells its days apart, the other one goes: two rules, the same at every moment.
    DB::table('schedule_rules')->where('playlist_item_id', $ranged)->delete();
    timesMigration()->up();

    $line = PlaylistItem::with('scheduleRules')->findOrFail($always);

    expect($line->scheduleRules)->toHaveCount(2)
        ->and($line->isDueAt(CarbonImmutable::parse('2026-03-21 03:00')))->toBeTrue()    // Saturday 03:00
        ->and($line->isDueAt(CarbonImmutable::parse('2026-03-21 05:00')))->toBeTrue()    // Saturday's own hours
        ->and($line->isDueAt(CarbonImmutable::parse('2026-03-21 12:00')))->toBeFalse()
        ->and($line->isDueAt(CarbonImmutable::parse('2026-03-22 03:00')))->toBeFalse()   // Sunday 03:00: Saturday kept no night
        ->and($line->isDueAt(CarbonImmutable::parse('2026-03-23 03:00')))->toBeTrue();   // Monday 03:00: Sunday night's
});

test('rolled back, every set of hours is a daypart of its organization again, and the permissions are held as they began', function () {
    $beta = Organization::factory()->create(['name' => 'Beta Deli']);
    $betaScreen = Screen::factory()->create(['organization_id' => $beta->id]);
    $alphaLunch = oldDaypart($this->organization, 'plain');
    $lunch = oldRule($this->screen, $alphaLunch);
    $alsoLunch = oldRule($this->screen, $alphaLunch);                 // the same hours, the same organization
    $theirs = oldRule($betaScreen, oldDaypart($beta, 'plain'));      // the same hours, another organization
    $always = oldRule($this->screen, null);

    $migration = timesMigration();
    $migration->up();
    $migration->down();

    $daypartOf = fn (int $lineId) => DB::table('schedule_rules')->where('playlist_item_id', $lineId)->value('daypart_id');
    $named = DB::table('dayparts')->get()->keyBy('id');

    expect(Schema::hasColumn('schedule_rules', 'start_time'))->toBeFalse()
        ->and($named)->toHaveCount(2)
        ->and($named[$daypartOf($lunch)]->name)->toBe('11:00 – 15:00')
        ->and($named[$daypartOf($lunch)]->organization_id)->toBe($this->organization->id)
        ->and($daypartOf($alsoLunch))->toBe($daypartOf($lunch))
        ->and($named[$daypartOf($theirs)]->organization_id)->toBe($beta->id)
        ->and($daypartOf($always))->toBeNull();

    // The four permissions are back, held by the roles that began with them.
    $held = fn (string $key) => DB::table('role_has_permissions')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('role_id', Role::starter($key)->id)->where('permissions.name', 'like', 'daypart-%')->pluck('permissions.name')->sort()->values()->all();

    expect($held(Role::OWNER))->toBe(['daypart-destroy', 'daypart-store', 'daypart-update', 'daypart-view'])
        ->and($held(Role::ADMIN))->toBe(['daypart-destroy', 'daypart-store', 'daypart-update', 'daypart-view'])
        ->and($held(Role::STAFF))->toBe(['daypart-view'])
        ->and($held(Role::VIEWER))->toBe(['daypart-view']);

    // …and forward again, the hours are on the rules once more.
    $migration->up();

    expect(ScheduleRule::where('playlist_item_id', $lunch)->sole()->start_time)->toBe('11:00')
        ->and(ScheduleRule::where('playlist_item_id', $theirs)->sole()->end_time)->toBe('15:00')
        ->and(ScheduleRule::where('playlist_item_id', $always)->sole()->hasTimes())->toBeFalse();
});
