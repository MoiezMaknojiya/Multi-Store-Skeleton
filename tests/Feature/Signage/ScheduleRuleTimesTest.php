<?php

use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\ScheduleRule;
use App\Models\Screen;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| The hours a schedule keeps (owner, 2026-10-01)
|--------------------------------------------------------------------------
|
| "Dayparts ko hata k … Playlist mein jab schedule set karte ha waha." A rule's time of day is typed on the rule
| itself — from one clock time to another — where it used to point at a named daypart. This is the arithmetic every
| screen leans on, tested on its own. The moment is always given in the screen's own timezone: a rule knows about
| clock faces, not about where in the world they hang.
|
| The case that breaks naive code is hours that run past midnight. "22:00 to 02:00" is ONE stretch, and at half
| past midnight the hours that are open began YESTERDAY — so checking only today's turns the screen off at midnight.
|
| 2026-09-10 is a Thursday, 2026-09-12 a Saturday, 2026-09-13 a Sunday.
|
*/

/** A moment, written the way a person would say it. */
function at(string $when): CarbonImmutable
{
    return CarbonImmutable::parse($when);
}

/** A rule with hours, built in memory — coversAt() needs no database at all. */
function between(string $start, string $end, array $days = []): ScheduleRule
{
    return new ScheduleRule(['start_time' => $start, 'end_time' => $end, ...$days]);
}

test('a rule with no hours covers the whole of every day it covers', function () {
    $always = new ScheduleRule;

    expect($always->hasTimes())->toBeFalse()
        ->and($always->clockTimes())->toBe([]);

    foreach (['00:00', '03:17', '12:00', '23:59'] as $time) {
        expect($always->coversAt(at("2026-09-10 {$time}")))->toBeTrue();
    }
});

test('ordinary hours are open between their ends and shut outside them', function () {
    $rule = between('09:00', '17:00');

    expect($rule->coversAt(at('2026-09-10 12:00')))->toBeTrue()
        ->and($rule->coversAt(at('2026-09-10 08:59')))->toBeFalse()
        ->and($rule->coversAt(at('2026-09-10 17:30')))->toBeFalse()
        ->and($rule->crossesMidnight())->toBeFalse()
        ->and($rule->clockTimes())->toBe(['09:00', '17:00']);
});

test('the hours open ON the start minute and shut ON the end minute', function () {
    $rule = between('09:00', '17:00');

    // Half-open, like every other range in the app: the start counts, the end does not.
    expect($rule->coversAt(at('2026-09-10 09:00')))->toBeTrue()
        ->and($rule->coversAt(at('2026-09-10 16:59')))->toBeTrue()
        ->and($rule->coversAt(at('2026-09-10 17:00')))->toBeFalse();
});

test('hours that run past midnight stay open through it', function () {
    $rule = between('22:00', '02:00');

    expect($rule->crossesMidnight())->toBeTrue()
        ->and($rule->coversAt(at('2026-09-10 22:00')))->toBeTrue()    // opens
        ->and($rule->coversAt(at('2026-09-10 23:59')))->toBeTrue()    // still the same evening
        ->and($rule->coversAt(at('2026-09-11 00:30')))->toBeTrue()    // the half hour that used to go dark
        ->and($rule->coversAt(at('2026-09-11 01:59')))->toBeTrue()
        ->and($rule->coversAt(at('2026-09-11 02:00')))->toBeFalse()   // shuts
        ->and($rule->coversAt(at('2026-09-11 12:00')))->toBeFalse()   // the middle of the day
        ->and($rule->coversAt(at('2026-09-10 21:59')))->toBeFalse();  // just before it opens
});

test('hours past midnight belong to the day they OPENED on, whatever the calendar says', function () {
    // Saturday nights only. At 00:30 on Sunday the hours that are open began on Saturday, so they play;
    // Sunday opens none of its own, and Friday's never started.
    $saturdayNights = between('22:00', '02:00', ['recurrence_type' => ScheduleRule::WEEKLY, 'recurrence_weekdays' => [6]]);

    expect($saturdayNights->coversAt(at('2026-09-12 23:00')))->toBeTrue()    // Saturday itself
        ->and($saturdayNights->coversAt(at('2026-09-13 00:30')))->toBeTrue()   // Sunday's small hours
        ->and($saturdayNights->coversAt(at('2026-09-13 23:00')))->toBeFalse()  // Sunday evening
        ->and($saturdayNights->coversAt(at('2026-09-12 00:30')))->toBeFalse()  // Saturday's small hours are Friday's
        ->and($saturdayNights->coversAt(at('2026-09-12 15:00')))->toBeFalse(); // outside the hours on a Saturday
});

test('the last night of a date range runs on into the next morning, and no further', function () {
    $nights = between('22:00', '02:00', ['starts_on' => '2026-09-10', 'ends_on' => '2026-09-12']);

    expect($nights->coversAt(at('2026-09-12 23:30')))->toBeTrue()     // the last evening
        ->and($nights->coversAt(at('2026-09-13 01:00')))->toBeTrue()  // …and its small hours
        ->and($nights->coversAt(at('2026-09-13 23:30')))->toBeFalse() // the evening after the range
        ->and($nights->coversAt(at('2026-09-10 01:00')))->toBeFalse(); // the small hours before the first evening
});

test('other hours on one weekday are a second rule, and a closed weekday is one no rule names', function () {
    // "Deli hours": 07:00 to 20:00 Tuesday to Saturday, 09:00 to 16:00 on Sunday, closed on Monday —
    // what a daypart with exceptions used to say, as the two rules of one line.
    $screen = Screen::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    $line = PlaylistItem::create([
        'screen_id' => $screen->id, 'position' => 0, 'duration_seconds' => 10,
        'media_id' => Media::factory()->create(['organization_id' => $screen->organization_id])->id,
    ]);
    $line->scheduleRules()->create(['start_time' => '07:00', 'end_time' => '20:00', 'recurrence_type' => ScheduleRule::WEEKLY, 'recurrence_weekdays' => [2, 3, 4, 5, 6], 'position' => 0]);
    $line->scheduleRules()->create(['start_time' => '09:00', 'end_time' => '16:00', 'recurrence_type' => ScheduleRule::WEEKLY, 'recurrence_weekdays' => [7], 'position' => 1]);
    $line->load('scheduleRules');

    expect($line->isDueAt(at('2026-09-10 07:30')))->toBeTrue()     // Thursday morning
        ->and($line->isDueAt(at('2026-09-13 07:30')))->toBeFalse() // Sunday opens at nine
        ->and($line->isDueAt(at('2026-09-13 12:00')))->toBeTrue()
        ->and($line->isDueAt(at('2026-09-13 18:00')))->toBeFalse() // …and shuts at four
        ->and($line->isDueAt(at('2026-09-14 12:00')))->toBeFalse(); // Monday: closed
});

test('the moment is read as given, so two screens in different timezones disagree', function () {
    $rule = between('09:00', '17:00');

    // One instant in time, two clock faces. Noon in Chicago is 17:00 in London.
    $instant = CarbonImmutable::parse('2026-09-10 17:00', 'Europe/London');

    expect($rule->coversAt($instant->setTimezone('America/Chicago')))->toBeTrue()    // 12:00 there
        ->and($rule->coversAt($instant->setTimezone('Europe/London')))->toBeFalse(); // 17:00 here
});

test('daylight saving needs no handling, because the times are wall clock', function () {
    $rule = between('09:00', '17:00');

    // 2026-03-08 is the US spring-forward Sunday; 2026-11-01 the fall-back one.
    // "Ten in the morning" is inside the hours on both, with nothing to configure.
    expect($rule->coversAt(CarbonImmutable::parse('2026-03-08 10:00', 'America/Chicago')))->toBeTrue()
        ->and($rule->coversAt(CarbonImmutable::parse('2026-11-01 10:00', 'America/Chicago')))->toBeTrue();
});

test('the times read back as H:i whichever database wrote them, and change the fingerprint', function () {
    $screen = Screen::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    $line = PlaylistItem::create([
        'screen_id' => $screen->id, 'position' => 0, 'duration_seconds' => 10,
        'media_id' => Media::factory()->create(['organization_id' => $screen->organization_id])->id,
    ]);
    $rule = $line->scheduleRules()->create(['start_time' => '07:00', 'end_time' => '20:00', 'position' => 0]);

    // MySQL returns "07:00:00" from a TIME column and SQLite returns what it was given; without
    // HasClockTimes the suite and production would disagree.
    expect($rule->fresh()->start_time)->toBe('07:00')
        ->and($rule->fresh()->end_time)->toBe('20:00');

    // Two people changing one line's hours must collide (Screen::playlistFingerprint reads this).
    $before = $screen->playlistFingerprint();
    $rule->update(['end_time' => '21:00']);

    expect($screen->playlistFingerprint())->not->toBe($before)
        ->and($rule->fresh()->fingerprint())->toStartWith('07:00,21:00,');
});
