<?php

use App\Models\Daypart;
use App\Models\ScheduleRule;
use App\Models\Store;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| When one item is allowed to play
|--------------------------------------------------------------------------
|
| A rule answers two questions kept deliberately apart: WHICH DAYS, and WHAT
| TIME on those days. This file tests the day half on its own, because it is
| where all the arithmetic is, and then the two together for the one case where
| they interact — a window that runs past midnight.
|
| Calendar facts these tests lean on, checked once so nothing here is guesswork:
|   2026-03-20 is a Friday        2026-09-13 is a Sunday
|   2026-03-01 is a Sunday        Thursdays in March 2026: 5, 12, 19, 26
|                                 Fridays  in March 2026: 6, 13, 20, 27
|
*/

/** A rule built in memory — coversDay() needs no database at all. */
function rule(array $attributes = []): ScheduleRule
{
    return new ScheduleRule($attributes);
}

function on(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date);
}

test('a rule with nothing set covers every day', function () {
    expect(rule()->coversDay(on('2026-03-20')))->toBeTrue();
    expect(rule()->coversDay(on('2030-12-31')))->toBeTrue();
});

test('a date range covers its ends and nothing outside them', function () {
    $eid = rule(['starts_on' => '2026-03-20', 'ends_on' => '2026-03-22']);

    expect($eid->coversDay(on('2026-03-19')))->toBeFalse();
    expect($eid->coversDay(on('2026-03-20')))->toBeTrue();   // the first day counts
    expect($eid->coversDay(on('2026-03-21')))->toBeTrue();
    expect($eid->coversDay(on('2026-03-22')))->toBeTrue();   // and so does the last
    expect($eid->coversDay(on('2026-03-23')))->toBeFalse();
});

test('an open-ended range runs from, or until, forever', function () {
    expect(rule(['starts_on' => '2026-03-20'])->coversDay(on('2099-01-01')))->toBeTrue();
    expect(rule(['starts_on' => '2026-03-20'])->coversDay(on('2026-03-19')))->toBeFalse();

    expect(rule(['ends_on' => '2026-03-20'])->coversDay(on('1999-01-01')))->toBeTrue();
    expect(rule(['ends_on' => '2026-03-20'])->coversDay(on('2026-03-21')))->toBeFalse();
});

test('every Friday means every Friday and no other day', function () {
    $friday = rule([
        'recurrence_type' => ScheduleRule::WEEKLY,
        'recurrence_weekdays' => [5],
        'starts_on' => '2026-03-20',
    ]);

    expect($friday->coversDay(on('2026-03-20')))->toBeTrue();    // Friday
    expect($friday->coversDay(on('2026-03-27')))->toBeTrue();    // the next Friday
    expect($friday->coversDay(on('2026-04-03')))->toBeTrue();
    expect($friday->coversDay(on('2026-03-21')))->toBeFalse();   // Saturday
    expect($friday->coversDay(on('2026-03-19')))->toBeFalse();   // the Thursday before it starts
});

test('several weekdays can be chosen at once', function () {
    $weekend = rule([
        'recurrence_type' => ScheduleRule::WEEKLY,
        'recurrence_weekdays' => [6, 7],
    ]);

    expect($weekend->coversDay(on('2026-03-21')))->toBeTrue();   // Saturday
    expect($weekend->coversDay(on('2026-03-22')))->toBeTrue();   // Sunday
    expect($weekend->coversDay(on('2026-03-20')))->toBeFalse();  // Friday
});

test('every second Friday skips the Friday in between', function () {
    $fortnightly = rule([
        'recurrence_type' => ScheduleRule::WEEKLY,
        'recurrence_weekdays' => [5],
        'recurrence_interval' => 2,
        'starts_on' => '2026-03-20',
    ]);

    expect($fortnightly->coversDay(on('2026-03-20')))->toBeTrue();
    expect($fortnightly->coversDay(on('2026-03-27')))->toBeFalse();  // the one in between
    expect($fortnightly->coversDay(on('2026-04-03')))->toBeTrue();
    expect($fortnightly->coversDay(on('2026-04-10')))->toBeFalse();
});

test('a monthly date falls only on that date, and skips months that have no such day', function () {
    $payday = rule([
        'recurrence_type' => ScheduleRule::MONTHLY_DAY,
        'recurrence_monthday' => 31,
        'starts_on' => '2026-01-31',
    ]);

    expect($payday->coversDay(on('2026-01-31')))->toBeTrue();
    expect($payday->coversDay(on('2026-03-31')))->toBeTrue();

    // February has no 31st, and neither has April. Clamping to the 28th or the 30th
    // would put the item on a day the shop never asked for.
    expect($payday->coversDay(on('2026-02-28')))->toBeFalse();
    expect($payday->coversDay(on('2026-04-30')))->toBeFalse();
});

test('the third Thursday of the month is found without counting by hand', function () {
    $thirdThursday = rule([
        'recurrence_type' => ScheduleRule::MONTHLY_WEEKDAY,
        'recurrence_ordinal' => 3,
        'recurrence_weekday' => 4,
    ]);

    expect($thirdThursday->coversDay(on('2026-03-19')))->toBeTrue();    // the third one
    expect($thirdThursday->coversDay(on('2026-03-12')))->toBeFalse();   // the second
    expect($thirdThursday->coversDay(on('2026-03-26')))->toBeFalse();   // the fourth
    expect($thirdThursday->coversDay(on('2026-03-18')))->toBeFalse();   // a Wednesday
});

test('the LAST Friday is whichever one has no successor that month', function () {
    $lastFriday = rule([
        'recurrence_type' => ScheduleRule::MONTHLY_WEEKDAY,
        'recurrence_ordinal' => -1,
        'recurrence_weekday' => 5,
    ]);

    // March 2026 has Fridays on the 6th, 13th, 20th and 27th.
    expect($lastFriday->coversDay(on('2026-03-27')))->toBeTrue();
    expect($lastFriday->coversDay(on('2026-03-20')))->toBeFalse();
});

test('a yearly rule repeats the day and month it started on', function () {
    $independence = rule([
        'recurrence_type' => ScheduleRule::YEARLY,
        'starts_on' => '2026-08-14',
    ]);

    expect($independence->coversDay(on('2026-08-14')))->toBeTrue();
    expect($independence->coversDay(on('2027-08-14')))->toBeTrue();
    expect($independence->coversDay(on('2030-08-14')))->toBeTrue();
    expect($independence->coversDay(on('2027-08-15')))->toBeFalse();
});

test('a repeat stops at its until date', function () {
    $friday = rule([
        'recurrence_type' => ScheduleRule::WEEKLY,
        'recurrence_weekdays' => [5],
        'starts_on' => '2026-03-20',
        'recurrence_until' => '2026-03-27',
    ]);

    expect($friday->coversDay(on('2026-03-20')))->toBeTrue();
    expect($friday->coversDay(on('2026-03-27')))->toBeTrue();    // the last one counts
    expect($friday->coversDay(on('2026-04-03')))->toBeFalse();
});

test('every second day counts from the start, not from the calendar', function () {
    $alternate = rule([
        'recurrence_type' => ScheduleRule::DAILY,
        'recurrence_interval' => 2,
        'starts_on' => '2026-03-20',
    ]);

    expect($alternate->coversDay(on('2026-03-20')))->toBeTrue();
    expect($alternate->coversDay(on('2026-03-21')))->toBeFalse();
    expect($alternate->coversDay(on('2026-03-22')))->toBeTrue();
});

test('a daypart that runs past midnight is counted against the day it OPENED', function () {
    // The whole reason coversAt asks the daypart which day its window began on.
    $store = Store::factory()->create();
    $night = Daypart::factory()->overnight()->create(['store_id' => $store->id]);  // 22:00 – 02:00

    $fridayNights = new ScheduleRule([
        'recurrence_type' => ScheduleRule::WEEKLY,
        'recurrence_weekdays' => [5],           // Friday
        'daypart_id' => $night->id,
    ]);

    // Friday night, inside the window: yes, obviously.
    expect($fridayNights->coversAt(on('2026-03-20 23:00')))->toBeTrue();

    // Half past midnight is a SATURDAY by the calendar — but the window that is open
    // began on Friday, and the shop said "Friday night". Checking the calendar date
    // instead would switch the item off at midnight, halfway through the evening.
    expect($fridayNights->coversAt(on('2026-03-21 00:30')))->toBeTrue();

    // Saturday evening opens a window of its own, and that one is not a Friday.
    expect($fridayNights->coversAt(on('2026-03-21 23:00')))->toBeFalse();

    // And outside the window on a Friday, nothing plays.
    expect($fridayNights->coversAt(on('2026-03-20 15:00')))->toBeFalse();
});

test('a rule whose daypart was deleted plays never, not always', function () {
    // The foreign key is nullOnDelete, so the id survives as null. Treating "no
    // daypart" as "all day" here would turn a lunchtime advert into a permanent one.
    $orphan = new ScheduleRule(['daypart_id' => 999999]);

    expect($orphan->coversAt(on('2026-03-20 12:00')))->toBeFalse();
});

test('the next seven days are listed with the window each day opens', function () {
    $store = Store::factory()->create();
    $lunch = Daypart::factory()->between('11:00', '15:00')->create(['store_id' => $store->id]);

    $rule = new ScheduleRule([
        'recurrence_type' => ScheduleRule::WEEKLY,
        'recurrence_weekdays' => [5],
        'daypart_id' => $lunch->id,
    ]);

    $found = $rule->occurrences(on('2026-03-16'), 14);   // a Monday, two weeks out

    expect(collect($found)->pluck('date')->all())->toBe(['2026-03-20', '2026-03-27']);
    expect($found[0]['start'])->toBe('11:00');
    expect($found[0]['end'])->toBe('15:00');
    expect($found[0]['crosses_midnight'])->toBeFalse();
});

test('a day the daypart is closed is left out of the preview entirely', function () {
    $store = Store::factory()->create();
    $hours = Daypart::factory()->between('09:00', '17:00')->create(['store_id' => $store->id]);
    $hours->syncExceptions([['weekday' => 7, 'start_time' => null, 'end_time' => null]]);  // Sunday shut

    $everyDay = new ScheduleRule(['daypart_id' => $hours->id]);

    $dates = collect($everyDay->occurrences(on('2026-09-11'), 7))->pluck('date');

    // 2026-09-13 is the Sunday in that week. The rule covers the day; the window
    // does not exist, so there is nothing to show.
    expect($dates)->not->toContain('2026-09-13');
    expect($dates)->toContain('2026-09-12', '2026-09-14');
});
