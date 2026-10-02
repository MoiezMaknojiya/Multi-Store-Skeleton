<?php

use App\Models\BuilderAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\ScheduleRule;
use App\Models\Screen;
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
    $this->organization = Organization::factory()->create();
    $this->screen = Screen::factory()->create([
        'organization_id' => $this->organization->id,
        'timezone' => 'America/Chicago',
    ]);
    $this->poster = Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Poster']);
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
    // across an organization's television at three in the morning reads as a fault.
    place($this->screen, $this->poster, ['start_time' => '11:00', 'end_time' => '15:00']);

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
    place($this->screen, $this->poster, [
        'recurrence_type' => ScheduleRule::WEEKLY,
        'recurrence_weekdays' => [5],           // Friday
        'start_time' => '11:00',
        'end_time' => '15:00',
    ]);

    // 2026-03-20 is a Friday, 2026-03-21 a Saturday.
    expect(resolveAt($this->screen, '2026-03-20 12:00')['items'])->toHaveCount(1);
    expect(resolveAt($this->screen, '2026-03-20 16:00')['items'])->toBeEmpty();   // right day, wrong hour
    expect(resolveAt($this->screen, '2026-03-21 12:00')['items'])->toBeEmpty();   // right hour, wrong day
});

test('an item plays if ANY of its rules says yes', function () {
    $item = place($this->screen, $this->poster, [
        'starts_on' => '2026-03-20', 'ends_on' => '2026-03-22', 'start_time' => '16:00', 'end_time' => '20:00',
    ]);
    $item->scheduleRules()->create([
        'recurrence_type' => ScheduleRule::WEEKLY,
        'recurrence_weekdays' => [5],
        'start_time' => '11:00',
        'end_time' => '15:00',
        'position' => 1,
    ]);

    // The Eid rule, in the evening.
    expect(resolveAt($this->screen, '2026-03-21 17:00')['items'])->toHaveCount(1);
    // The Friday rule, at lunchtime.
    expect(resolveAt($this->screen, '2026-03-27 12:00')['items'])->toHaveCount(1);
    // Neither: a Saturday lunchtime outside the Eid dates.
    expect(resolveAt($this->screen, '2026-04-04 12:00')['items'])->toBeEmpty();
});

test('an Ad Builder page taken off the screens never plays, however welcoming its rule is', function () {
    $ad = BuilderAd::factory()->published()->create(['organization_id' => $this->organization->id, 'name' => 'Eid offer']);

    place($this->screen, $ad->media, [
        'recurrence_type' => ScheduleRule::WEEKLY,
        'recurrence_weekdays' => [5],
    ]);

    // A Friday: the rule says yes, and so does the page while it is published…
    expect(resolveAt($this->screen, '2026-03-20 12:00')['items'])->toHaveCount(1);

    // …and not once it is unpublished. A file keeps no dates of its own (owner, 2026-10-01): its line's rule is
    // the whole of when it plays, and a draft is the one thing a rule cannot bring back.
    $ad->update(['published_at' => null]);
    expect(resolveAt($this->screen, '2026-03-27 12:00')['items'])->toBeEmpty();
});

test('with nothing eligible the screen shows its default media instead of black', function () {
    $holding = Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Welcome']);
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
    $holding = Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Welcome']);
    $this->screen->update(['default_media_id' => $holding->id]);

    expect(resolveAt($this->screen, '2026-03-20 12:00')['fallback']?->id)->toBe($holding->id);
});

test('an Ad Builder page taken off the screens is not a default', function () {
    // Unpublishing takes an ad off every screen, the holding picture's place included.
    $ad = BuilderAd::factory()->published()->create(['organization_id' => $this->organization->id, 'name' => 'Old welcome']);
    $this->screen->update(['default_media_id' => $ad->media_id]);

    expect(resolveAt($this->screen, '2026-03-20 12:00')['fallback']?->id)->toBe($ad->media_id);

    $ad->update(['published_at' => null]);

    expect(resolveAt($this->screen->fresh(), '2026-03-20 12:00')['fallback'])->toBeNull();
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
    $chicago = Screen::factory()->create(['organization_id' => $this->organization->id, 'timezone' => 'America/Chicago']);
    $london = Screen::factory()->create(['organization_id' => $this->organization->id, 'timezone' => 'Europe/London']);

    place($chicago, $this->poster, ['start_time' => '11:00', 'end_time' => '15:00']);
    place($london, $this->poster, ['start_time' => '11:00', 'end_time' => '15:00']);

    // One instant: noon in Chicago, five in the evening in London. The rule is wall
    // clock, so the same "11:00 to 15:00" means different moments in the two organizations.
    $instant = CarbonImmutable::parse('2026-03-20 12:00', 'America/Chicago');
    $resolver = app(ScheduleResolver::class);

    expect($resolver->resolve($chicago->fresh(), $instant)['items'])->toHaveCount(1);
    expect($resolver->resolve($london->fresh(), $instant)['items'])->toBeEmpty();
});

test('a line open late keeps the screen lit past midnight, on the night it opened', function () {
    // Friday night, 22:00 to 02:00. 2026-03-20 is a Friday.
    place($this->screen, $this->poster, [
        'recurrence_type' => ScheduleRule::WEEKLY, 'recurrence_weekdays' => [5], 'start_time' => '22:00', 'end_time' => '02:00',
    ]);

    expect(resolveAt($this->screen, '2026-03-20 23:00')['items'])->toHaveCount(1);
    expect(resolveAt($this->screen, '2026-03-21 01:00')['items'])->toHaveCount(1);     // Saturday by the calendar
    expect(resolveAt($this->screen, '2026-03-21 02:00')['blank'])->toBeTrue();
    expect(resolveAt($this->screen, '2026-03-21 23:00')['blank'])->toBeTrue();         // Saturday night opens none
});

test('the moments a screen can change at are its rules\' own times, on every day ahead', function () {
    place($this->screen, $this->poster, ['start_time' => '11:00', 'end_time' => '15:00']);

    $resolver = app(ScheduleResolver::class);
    $from = CarbonImmutable::parse('2026-03-20 09:00', 'America/Chicago');
    $points = collect($resolver->changePoints($this->screen, $resolver->load($this->screen), $from, $from->addDay()))
        ->map(fn (CarbonImmutable $point) => $point->setTimezone('America/Chicago')->format('d H:i'))->all();

    // Today's opening and closing, midnight, and tomorrow's nothing past 09:00.
    expect($points)->toBe(['20 11:00', '20 15:00', '21 00:00']);
});
