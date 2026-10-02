<?php

use App\Models\ScheduleRule;
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
    // would put the item on a day the organization never asked for.
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

test('hours that run past midnight are counted against the day they OPENED', function () {
    // The whole reason coversAt asks which day the hours open now began on.
    $fridayNights = new ScheduleRule([
        'recurrence_type' => ScheduleRule::WEEKLY,
        'recurrence_weekdays' => [5],           // Friday
        'start_time' => '22:00',
        'end_time' => '02:00',
    ]);

    // Friday night, inside the window: yes, obviously.
    expect($fridayNights->coversAt(on('2026-03-20 23:00')))->toBeTrue();

    // Half past midnight is a SATURDAY by the calendar — but the window that is open
    // began on Friday, and the organization said "Friday night". Checking the calendar date
    // instead would switch the item off at midnight, halfway through the evening.
    expect($fridayNights->coversAt(on('2026-03-21 00:30')))->toBeTrue();

    // Saturday evening opens a window of its own, and that one is not a Friday.
    expect($fridayNights->coversAt(on('2026-03-21 23:00')))->toBeFalse();

    // And outside the window on a Friday, nothing plays.
    expect($fridayNights->coversAt(on('2026-03-20 15:00')))->toBeFalse();
});

test('a rule with one of its two times missing keeps no hours at all', function () {
    // The playlist's rules refuse one time without the other, so this is a row written by hand. It reads as the
    // whole day — never as hours that open and do not close.
    $half = new ScheduleRule(['start_time' => '11:00']);

    expect($half->hasTimes())->toBeFalse()
        ->and($half->coversAt(on('2026-03-20 03:00')))->toBeTrue();
});

test('the next fourteen days are listed with the hours each day opens', function () {
    $rule = new ScheduleRule([
        'recurrence_type' => ScheduleRule::WEEKLY,
        'recurrence_weekdays' => [5],
        'start_time' => '11:00',
        'end_time' => '15:00',
    ]);

    $found = $rule->occurrences(on('2026-03-16'), 14);   // a Monday, two weeks out

    expect(collect($found)->pluck('date')->all())->toBe(['2026-03-20', '2026-03-27']);
    expect($found[0]['start'])->toBe('11:00');
    expect($found[0]['end'])->toBe('15:00');
    expect($found[0]['crosses_midnight'])->toBeFalse();
});

test('a weekday the rule does not name is left out of the preview entirely, and hours past midnight say so', function () {
    // Every day but Sunday, 22:00 to 02:00. 2026-09-13 is the Sunday in that week.
    $nights = new ScheduleRule([
        'recurrence_type' => ScheduleRule::WEEKLY,
        'recurrence_weekdays' => [1, 2, 3, 4, 5, 6],
        'start_time' => '22:00',
        'end_time' => '02:00',
    ]);

    $found = collect($nights->occurrences(on('2026-09-11'), 7));

    expect($found->pluck('date'))->not->toContain('2026-09-13')
        ->and($found->pluck('date'))->toContain('2026-09-12', '2026-09-14')
        ->and($found->pluck('crosses_midnight')->unique()->all())->toBe([true]);
});
