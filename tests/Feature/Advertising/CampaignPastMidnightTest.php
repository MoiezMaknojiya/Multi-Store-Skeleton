<?php

use App\Models\Campaign;
use App\Models\Organization;
use App\Models\Screen;
use App\Services\NetworkAdResolver;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| A campaign whose hours run past midnight
|--------------------------------------------------------------------------
|
| A bar sells the nights of the 10th to the 15th, 22:00 to 02:00. The contract's dates are read against the
| night the hours OPENED on, as a playlist line's schedule is (ScheduleRule::coversAt): the last night plays
| until two on the morning of the 16th, and one o'clock on the morning of the 10th is the night of the 9th,
| before the contract (the review of 2026-10-15: it was cut at midnight of its last night and spilled into
| the night before its first). The same holds for the live answer and for the offline timeline.
|
*/

beforeEach(function () {
    $organization = Organization::factory()->create(['accepts_network_ads' => true]);
    $this->screen = Screen::factory()->create([
        'organization_id' => $organization->id,
        'timezone' => 'America/Chicago',
        'accepts_network_ads' => true,
    ]);

    $this->nights = Campaign::factory()->running('2026-10-10', '2026-10-15')->between('22:00', '02:00')->create();
    $this->nights->screens()->attach($this->screen->id);

    // On the screen's own clock.
    $this->at = fn (string $local) => CarbonImmutable::parse($local, 'America/Chicago')->utc();
});

/** The moments that decide it, on the screen's clock, and whether the advert is due at each. */
dataset('the nights', [
    'the tail of the night of the 9th' => ['2026-10-10 01:00', false],
    'the evening of the 10th, before its hours' => ['2026-10-10 21:59', false],
    'the first night' => ['2026-10-10 22:00', true],
    'the first night after midnight' => ['2026-10-11 01:30', true],
    'a night in the middle, before midnight' => ['2026-10-12 23:59', true],
    'the last night' => ['2026-10-15 23:00', true],
    'the last night after midnight' => ['2026-10-16 01:00', true],
    'the last night at its end' => ['2026-10-16 02:00', false],
    'the night after the contract' => ['2026-10-16 22:30', false],
    'the morning after the night after' => ['2026-10-17 01:00', false],
]);

test('the live break follows the night the hours opened on', function (string $local, bool $due) {
    $break = app(NetworkAdResolver::class)->breakFor($this->screen->fresh(), ($this->at)($local));

    expect($break->pluck('id')->all())->toBe($due ? [$this->nights->id] : []);
})->with('the nights');

test('the offline timeline says the same at every one of those moments', function () {
    $moments = collect(['2026-10-10 01:00', '2026-10-10 22:00', '2026-10-11 01:30', '2026-10-15 23:00', '2026-10-16 01:00', '2026-10-16 02:00', '2026-10-17 01:00']);
    $instants = $moments->map(fn (string $local) => ($this->at)($local))->all();

    $breaks = app(NetworkAdResolver::class)->breaksAt($this->screen->fresh(), $instants);

    $said = $moments->mapWithKeys(fn (string $local) => [$local => $breaks[($this->at)($local)->getTimestamp()]->isNotEmpty()])->all();

    expect($said)->toBe([
        '2026-10-10 01:00' => false,
        '2026-10-10 22:00' => true,
        '2026-10-11 01:30' => true,
        '2026-10-15 23:00' => true,
        '2026-10-16 01:00' => true,
        '2026-10-16 02:00' => false,
        '2026-10-17 01:00' => false,
    ]);
});

test('hours inside one day and no hours at all keep to the calendar date', function () {
    $lunch = Campaign::factory()->running('2026-10-10', '2026-10-15')->between('11:00', '15:00')->create();
    $allDay = Campaign::factory()->running('2026-10-10', '2026-10-15')->create();
    $lunch->screens()->attach($this->screen->id);
    $allDay->screens()->attach($this->screen->id);

    $due = fn (string $local) => app(NetworkAdResolver::class)->breakFor($this->screen->fresh(), ($this->at)($local))->pluck('id')->all();

    expect($due('2026-10-10 00:05'))->toBe([$allDay->id])           // the first day from its first minute; the nights not yet
        ->and($due('2026-10-15 12:00'))->toContain($lunch->id, $allDay->id)
        ->and($due('2026-10-16 01:00'))->toBe([$this->nights->id])  // the day after: only the last night's tail
        ->and($due('2026-10-16 12:00'))->toBe([]);
});

test('a campaign switched off plays on no night', function () {
    $this->nights->update(['is_active' => false]);

    expect(app(NetworkAdResolver::class)->breakFor($this->screen->fresh(), ($this->at)('2026-10-16 01:00')))->toBeEmpty()
        ->and($this->nights->fresh()->isDueAt(CarbonImmutable::parse('2026-10-16 01:00', 'America/Chicago')))->toBeFalse();
});
