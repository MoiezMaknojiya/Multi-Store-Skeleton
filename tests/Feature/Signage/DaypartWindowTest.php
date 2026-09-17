<?php

use App\Models\Daypart;
use App\Models\Store;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Is the daypart open right now?
|--------------------------------------------------------------------------
|
| This is the arithmetic every screen and every playlist item will lean on, so
| it is tested on its own before anything is built on top of it. The moment is
| always given in the screen's own timezone: this class knows about clock faces,
| not about where in the world they hang.
|
| The case that breaks naive code is the window that crosses midnight. "22:00 to
| 02:00" is ONE window, and at half past midnight the window that is open began
| YESTERDAY — so checking only today's hours turns the screen off at midnight.
|
*/

/** A moment, written the way a person would say it. */
function at(string $when): CarbonImmutable
{
    return CarbonImmutable::parse($when);
}

beforeEach(function () {
    $this->store = Store::factory()->create();
});

test('an ordinary window is open between its ends and shut outside them', function () {
    $daypart = Daypart::factory()->between('09:00', '17:00')->create(['store_id' => $this->store->id]);

    expect($daypart->coversAt(at('2026-09-10 12:00')))->toBeTrue();
    expect($daypart->coversAt(at('2026-09-10 08:59')))->toBeFalse();
    expect($daypart->coversAt(at('2026-09-10 17:30')))->toBeFalse();
});

test('the window opens ON the start minute and shuts ON the end minute', function () {
    $daypart = Daypart::factory()->between('09:00', '17:00')->create(['store_id' => $this->store->id]);

    // Half-open, like every other range in the app: the start counts, the end does not.
    expect($daypart->coversAt(at('2026-09-10 09:00')))->toBeTrue();
    expect($daypart->coversAt(at('2026-09-10 16:59')))->toBeTrue();
    expect($daypart->coversAt(at('2026-09-10 17:00')))->toBeFalse();
});

test('a window that crosses midnight stays open through it', function () {
    $daypart = Daypart::factory()->overnight()->create(['store_id' => $this->store->id]);  // 22:00 – 02:00

    expect($daypart->coversAt(at('2026-09-10 22:00')))->toBeTrue();   // opens
    expect($daypart->coversAt(at('2026-09-10 23:59')))->toBeTrue();   // still the same evening
    expect($daypart->coversAt(at('2026-09-11 00:30')))->toBeTrue();   // the half hour that used to go dark
    expect($daypart->coversAt(at('2026-09-11 01:59')))->toBeTrue();
    expect($daypart->coversAt(at('2026-09-11 02:00')))->toBeFalse();  // shuts
    expect($daypart->coversAt(at('2026-09-11 12:00')))->toBeFalse();  // the middle of the day
    expect($daypart->coversAt(at('2026-09-10 21:59')))->toBeFalse();  // just before it opens
});

test('an exception replaces that weekday\'s hours and leaves every other day alone', function () {
    $daypart = Daypart::factory()->between('09:00', '17:00')->create(['store_id' => $this->store->id]);
    // 2026-09-13 is a Sunday.
    $daypart->syncExceptions([['weekday' => 7, 'start_time' => '11:00', 'end_time' => '16:00']]);

    expect($daypart->coversAt(at('2026-09-13 10:00')))->toBeFalse();  // Sunday, before 11:00
    expect($daypart->coversAt(at('2026-09-13 12:00')))->toBeTrue();   // Sunday, inside
    expect($daypart->coversAt(at('2026-09-13 16:30')))->toBeFalse();  // Sunday, after 16:00

    expect($daypart->coversAt(at('2026-09-14 10:00')))->toBeTrue();   // Monday keeps 09:00–17:00
});

test('an exception with no times closes that weekday completely', function () {
    $daypart = Daypart::factory()->between('09:00', '17:00')->create(['store_id' => $this->store->id]);
    $daypart->syncExceptions([['weekday' => 7, 'start_time' => null, 'end_time' => null]]);

    // Sunday: shut at every hour, not merely shifted.
    foreach (['00:30', '09:00', '12:00', '16:59', '23:30'] as $time) {
        expect($daypart->coversAt(at("2026-09-13 {$time}")))->toBeFalse();
    }

    expect($daypart->coversAt(at('2026-09-14 12:00')))->toBeTrue();   // Monday is untouched
});

test('a window that opens on Saturday night and closes Sunday morning ignores Sunday\'s own exception', function () {
    $daypart = Daypart::factory()->overnight()->create(['store_id' => $this->store->id]);  // 22:00 – 02:00
    // Sunday closed. 2026-09-12 is a Saturday, 2026-09-13 a Sunday.
    $daypart->syncExceptions([['weekday' => 7, 'start_time' => null, 'end_time' => null]]);

    // Saturday's window is still running at 00:30 on Sunday. What Sunday says about
    // its OWN opening does not close a window that was already open.
    expect($daypart->coversAt(at('2026-09-13 00:30')))->toBeTrue();

    // But Sunday never opens one of its own.
    expect($daypart->coversAt(at('2026-09-13 23:00')))->toBeFalse();
});

test('a closed weekday does not leak into the small hours of the next day', function () {
    $daypart = Daypart::factory()->overnight()->create(['store_id' => $this->store->id]);  // 22:00 – 02:00
    // Saturday closed, so nothing should be running in Sunday's small hours.
    $daypart->syncExceptions([['weekday' => 6, 'start_time' => null, 'end_time' => null]]);

    expect($daypart->coversAt(at('2026-09-12 23:00')))->toBeFalse();  // Saturday itself
    expect($daypart->coversAt(at('2026-09-13 00:30')))->toBeFalse();  // the spill-over that never started
    expect($daypart->coversAt(at('2026-09-13 23:00')))->toBeTrue();   // Sunday opens normally
});

test('the moment is read as given, so two screens in different timezones disagree', function () {
    $daypart = Daypart::factory()->between('09:00', '17:00')->create(['store_id' => $this->store->id]);

    // One instant in time, two clock faces. Noon in Chicago is 17:00 in London.
    $instant = CarbonImmutable::parse('2026-09-10 17:00', 'Europe/London');

    expect($daypart->coversAt($instant->setTimezone('America/Chicago')))->toBeTrue();   // 12:00 there
    expect($daypart->coversAt($instant->setTimezone('Europe/London')))->toBeFalse();    // 17:00 here
});

test('daylight saving needs no handling, because the times are wall clock', function () {
    $daypart = Daypart::factory()->between('09:00', '17:00')->create(['store_id' => $this->store->id]);

    // 2026-03-08 is the US spring-forward Sunday; 2026-11-01 the fall-back one.
    // "Ten in the morning" is inside the window on both, with nothing to configure.
    expect($daypart->coversAt(CarbonImmutable::parse('2026-03-08 10:00', 'America/Chicago')))->toBeTrue();
    expect($daypart->coversAt(CarbonImmutable::parse('2026-11-01 10:00', 'America/Chicago')))->toBeTrue();
});

test('the times read back as H:i whichever database wrote them', function () {
    $daypart = Daypart::factory()->between('07:00', '20:00')->create(['store_id' => $this->store->id]);
    $daypart->syncExceptions([['weekday' => 7, 'start_time' => '09:00', 'end_time' => '16:00']]);

    // MySQL returns "07:00:00" from a TIME column and SQLite returns what it was
    // given; without HasClockTimes the suite and production would disagree.
    expect($daypart->fresh()->start_time)->toBe('07:00');
    expect($daypart->fresh()->exceptions->first()->start_time)->toBe('09:00');
});
