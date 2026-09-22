<?php

use App\Models\Daypart;
use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\ScheduleRule;
use App\Models\Screen;
use App\Models\Store;
use App\Services\ScheduleResolver;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| The three layers, put together
|--------------------------------------------------------------------------
|
| The resolver is the one place the schedule is answered, and what every television
| is handed. WHEN something plays is said once, on the file, inside the screen —
| the screen itself keeps no hours. Two questions, and either can say no:
|
|   1. is the file itself live?                    (media start / expiry)
|   2. does any of the item's rules say yes?       (no rules = whenever)
|
| Then three different NOTHINGS, because a television showing the wrong one looks
| broken: an empty playlist says "No content"; nothing due with a default set shows
| the holding picture; nothing due with no default goes black and says nothing.
|
*/

/** Put one file at the head of a screen's playlist, optionally with a schedule rule. */
function place(Screen $screen, Media $media, ?array $rule = null): PlaylistItem
{
    $item = PlaylistItem::create([
        'screen_id' => $screen->id,
        'media_id' => $media->id,
        'position' => 0,
        'duration_seconds' => 10,
    ]);

    if ($rule !== null) {
        $item->scheduleRules()->create($rule);
    }

    return $item;
}

/** What the screen would be showing at that moment, in its own timezone. */
function resolveAt(Screen $screen, string $localMoment): array
{
    $at = CarbonImmutable::parse($localMoment, $screen->timezone);

    return app(ScheduleResolver::class)->resolve($screen->fresh(), $at);
}

beforeEach(function () {
    $this->store = Store::factory()->create();
    $this->screen = Screen::factory()->create([
        'store_id' => $this->store->id,
        'timezone' => 'America/Chicago',
    ]);
    $this->poster = Media::factory()->create(['store_id' => $this->store->id, 'title' => 'Poster']);
});

test('an unscheduled item plays around the clock', function () {
    place($this->screen, $this->poster);

    foreach (['03:00', '12:00', '23:30'] as $time) {
        $resolved = resolveAt($this->screen, "2026-03-20 {$time}");

        expect($resolved['blank'])->toBeFalse();
        expect($resolved['items'])->toHaveCount(1);
    }
});

test('an hour nothing is scheduled for goes black, not to "No content"', function () {
    // The screen has a playlist; it is simply not this item's turn. "No content"
    // across a shop's television at three in the morning reads as a fault.
    place($this->screen, $this->poster, [
        'daypart_id' => Daypart::factory()->between('11:00', '15:00')->create(['store_id' => $this->store->id])->id,
    ]);

    $quiet = resolveAt($this->screen, '2026-03-20 03:00');

    expect($quiet['blank'])->toBeTrue();
    expect($quiet['items'])->toBeEmpty();

    $busy = resolveAt($this->screen, '2026-03-20 12:00');

    expect($busy['blank'])->toBeFalse();
    expect($busy['items'])->toHaveCount(1);
});

test('an empty playlist is NOT blank — that screen is waiting to be given something', function () {
    // A different nothing, with a different answer: "No content" is true and useful
    // on a screen that has just been paired.
    $resolved = resolveAt($this->screen, '2026-03-20 12:00');

    expect($resolved['items'])->toBeEmpty();
    expect($resolved['fallback'])->toBeNull();
    expect($resolved['blank'])->toBeFalse();
});

test('an item plays only on the days and at the times its rule allows', function () {
    $lunch = Daypart::factory()->between('11:00', '15:00')->create(['store_id' => $this->store->id]);

    place($this->screen, $this->poster, [
        'recurrence_type' => ScheduleRule::WEEKLY,
        'recurrence_weekdays' => [5],           // Friday
        'daypart_id' => $lunch->id,
    ]);

    // 2026-03-20 is a Friday, 2026-03-21 a Saturday.
    expect(resolveAt($this->screen, '2026-03-20 12:00')['items'])->toHaveCount(1);
    expect(resolveAt($this->screen, '2026-03-20 16:00')['items'])->toBeEmpty();   // right day, wrong hour
    expect(resolveAt($this->screen, '2026-03-21 12:00')['items'])->toBeEmpty();   // right hour, wrong day
});

test('an item plays if ANY of its rules says yes', function () {
    $lunch = Daypart::factory()->between('11:00', '15:00')->create(['store_id' => $this->store->id]);
    $evening = Daypart::factory()->between('16:00', '20:00')->create(['store_id' => $this->store->id]);

    $item = place($this->screen, $this->poster, [
        'starts_on' => '2026-03-20', 'ends_on' => '2026-03-22', 'daypart_id' => $evening->id,
    ]);
    $item->scheduleRules()->create([
        'recurrence_type' => ScheduleRule::WEEKLY,
        'recurrence_weekdays' => [5],
        'daypart_id' => $lunch->id,
        'position' => 1,
    ]);

    // The Eid rule, in the evening.
    expect(resolveAt($this->screen, '2026-03-21 17:00')['items'])->toHaveCount(1);
    // The Friday rule, at lunchtime.
    expect(resolveAt($this->screen, '2026-03-27 12:00')['items'])->toHaveCount(1);
    // Neither: a Saturday lunchtime outside the Eid dates.
    expect(resolveAt($this->screen, '2026-04-04 12:00')['items'])->toBeEmpty();
});

test('an expired file never plays, however welcoming its rule is', function () {
    $expired = Media::factory()->create([
        'store_id' => $this->store->id,
        'title' => 'Eid offer',
        'expires_at' => '2026-03-25 00:00:00',
    ]);

    place($this->screen, $expired, [
        'recurrence_type' => ScheduleRule::WEEKLY,
        'recurrence_weekdays' => [5],
    ]);

    // A Friday before the expiry, and a Friday after it. The rule says yes to both.
    expect(resolveAt($this->screen, '2026-03-20 12:00')['items'])->toHaveCount(1);
    expect(resolveAt($this->screen, '2026-03-27 12:00')['items'])->toBeEmpty();
});

test('with nothing eligible the screen shows its default media instead of black', function () {
    $holding = Media::factory()->create(['store_id' => $this->store->id, 'title' => 'Welcome']);
    $this->screen->update(['default_media_id' => $holding->id]);

    place($this->screen, $this->poster, [
        'recurrence_type' => ScheduleRule::WEEKLY,
        'recurrence_weekdays' => [5],
    ]);

    $gap = resolveAt($this->screen, '2026-03-21 12:00');   // a Saturday

    expect($gap['items'])->toBeEmpty();
    expect($gap['fallback']?->id)->toBe($holding->id);
    expect($gap['blank'])->toBeFalse();
});

test('an empty playlist falls back the same way a schedule gap does', function () {
    $holding = Media::factory()->create(['store_id' => $this->store->id, 'title' => 'Welcome']);
    $this->screen->update(['default_media_id' => $holding->id]);

    expect(resolveAt($this->screen, '2026-03-20 12:00')['fallback']?->id)->toBe($holding->id);
});

test('an expired default media is not a default', function () {
    // Showing it anyway would break the very promise the expiry date makes.
    $stale = Media::factory()->create([
        'store_id' => $this->store->id,
        'title' => 'Old welcome',
        'expires_at' => '2026-03-01 00:00:00',
    ]);
    $this->screen->update(['default_media_id' => $stale->id]);

    expect(resolveAt($this->screen, '2026-03-20 12:00')['fallback'])->toBeNull();
});

test('with no default set a gap is simply black', function () {
    place($this->screen, $this->poster, [
        'recurrence_type' => ScheduleRule::WEEKLY,
        'recurrence_weekdays' => [5],
    ]);

    $gap = resolveAt($this->screen, '2026-03-21 12:00');

    expect($gap['items'])->toBeEmpty();
    expect($gap['fallback'])->toBeNull();
});

test('two screens in different timezones answer differently at the same instant', function () {
    $lunch = Daypart::factory()->between('11:00', '15:00')->create(['store_id' => $this->store->id]);

    $chicago = Screen::factory()->create(['store_id' => $this->store->id, 'timezone' => 'America/Chicago']);
    $london = Screen::factory()->create(['store_id' => $this->store->id, 'timezone' => 'Europe/London']);

    place($chicago, $this->poster, ['daypart_id' => $lunch->id]);
    place($london, $this->poster, ['daypart_id' => $lunch->id]);

    // One instant: noon in Chicago, five in the evening in London. The rule is wall
    // clock, so the same "11:00 to 15:00" means different moments in the two shops.
    $instant = CarbonImmutable::parse('2026-03-20 12:00', 'America/Chicago');
    $resolver = app(ScheduleResolver::class);

    expect($resolver->resolve($chicago->fresh(), $instant)['items'])->toHaveCount(1);
    expect($resolver->resolve($london->fresh(), $instant)['items'])->toBeEmpty();
});

test('a retired daypart keeps working where it is already in use', function () {
    // Retiring takes a window out of the pickers; it must not stop the rules that
    // already point at it.
    $lunch = Daypart::factory()->between('11:00', '15:00')->retired()->create(['store_id' => $this->store->id]);
    place($this->screen, $this->poster, ['daypart_id' => $lunch->id]);

    expect(resolveAt($this->screen, '2026-03-20 12:00')['items'])->toHaveCount(1);
    expect(resolveAt($this->screen, '2026-03-20 20:00')['blank'])->toBeTrue();
});
