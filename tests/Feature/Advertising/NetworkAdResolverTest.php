<?php

use App\Models\Campaign;
use App\Models\Screen;
use App\Models\Store;
use App\Services\NetworkAdResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/*
|--------------------------------------------------------------------------
| Which adverts reach one television
|--------------------------------------------------------------------------
|
| Four separate things must all be true, and each one is somebody's decision:
|
|   1. the SHOP agreed to carry advertising          stores.accepts_network_ads
|   2. and THIS television does                      screens.accepts_network_ads
|   3. and this campaign chose this screen           campaign_screen
|   4. and the campaign is live at this moment       dates, and its own window
|
| Consent is a standing fact about the shop; targeting is a decision about one
| campaign. Both start off, so a shop that was never asked never carries an advert.
|
| Calendar facts these tests lean on: 2026-03-20 is a Friday.
|
*/

/** A screen that has been cleared for advertising, in a shop that agreed. */
function consentingScreen(Store $store, array $overrides = []): Screen
{
    return Screen::factory()->create([
        'store_id' => $store->id,
        'timezone' => 'America/Chicago',
        'accepts_network_ads' => true,
        ...$overrides,
    ]);
}

/** @return Collection<int, Campaign> */
function adsAt(Screen $screen, string $localMoment)
{
    return app(NetworkAdResolver::class)->breakFor(
        $screen->fresh(),
        CarbonImmutable::parse($localMoment, $screen->timezone)
    );
}

beforeEach(function () {
    $this->store = Store::factory()->create(['accepts_network_ads' => true]);
    $this->screen = consentingScreen($this->store);
});

/*
|--------------------------------------------------------------------------
| Consent — both halves, and both start off
|--------------------------------------------------------------------------
*/

test('an advert reaches a screen when the shop agreed, the screen agreed, and it was targeted', function () {
    $campaign = Campaign::factory()->create(['name' => 'Coca-Cola Ramadan']);
    $campaign->screens()->attach($this->screen);

    expect(adsAt($this->screen, '2026-03-20 12:00')->pluck('name')->all())->toBe(['Coca-Cola Ramadan']);
});

test('a shop that never agreed carries nothing, however it was targeted', function () {
    $this->store->update(['accepts_network_ads' => false]);
    Campaign::factory()->create()->screens()->attach($this->screen);

    expect(adsAt($this->screen, '2026-03-20 12:00'))->toBeEmpty();
});

test('one television can be kept clean in a shop that otherwise carries adverts', function () {
    // The set over the children's tables, in a shop that agreed in principle.
    $quiet = consentingScreen($this->store, ['accepts_network_ads' => false, 'name' => 'Kids corner']);
    $campaign = Campaign::factory()->create();
    $campaign->screens()->attach([$this->screen->id, $quiet->id]);

    expect(adsAt($this->screen, '2026-03-20 12:00'))->toHaveCount(1);
    expect(adsAt($quiet, '2026-03-20 12:00'))->toBeEmpty();
});

test('both flags start off, so a shop nobody asked carries nothing', function () {
    $store = Store::factory()->create();
    $screen = Screen::factory()->create(['store_id' => $store->id]);

    // Read back from the database, because it is the COLUMN default being tested —
    // a model straight from a factory has never been told what the column decided.
    expect($store->fresh()->accepts_network_ads)->toBeFalse();
    expect($screen->fresh()->accepts_network_ads)->toBeFalse();

    Campaign::factory()->create()->screens()->attach($screen);

    expect(adsAt($screen, '2026-03-20 12:00'))->toBeEmpty();
});

test('a campaign that did not choose this screen does not reach it', function () {
    $other = consentingScreen($this->store, ['name' => 'Window TV']);
    Campaign::factory()->create()->screens()->attach($other);

    expect(adsAt($this->screen, '2026-03-20 12:00'))->toBeEmpty();
    expect(adsAt($other, '2026-03-20 12:00'))->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| When a campaign is live
|--------------------------------------------------------------------------
*/

test('a campaign runs only between its dates', function () {
    $campaign = Campaign::factory()->running('2026-03-20', '2026-03-22')->create();
    $campaign->screens()->attach($this->screen);

    expect(adsAt($this->screen, '2026-03-19 12:00'))->toBeEmpty();
    expect(adsAt($this->screen, '2026-03-20 12:00'))->toHaveCount(1);   // the first day counts
    expect(adsAt($this->screen, '2026-03-22 12:00'))->toHaveCount(1);   // and the last
    expect(adsAt($this->screen, '2026-03-23 12:00'))->toBeEmpty();
});

test('a switched-off campaign runs nowhere, whatever its dates say', function () {
    $campaign = Campaign::factory()->paused()->create();
    $campaign->screens()->attach($this->screen);

    expect(adsAt($this->screen, '2026-03-20 12:00'))->toBeEmpty();
});

test('a campaign can be limited to a window of the day', function () {
    $campaign = Campaign::factory()->between('11:00', '15:00')->create();
    $campaign->screens()->attach($this->screen);

    expect(adsAt($this->screen, '2026-03-20 10:59'))->toBeEmpty();
    expect(adsAt($this->screen, '2026-03-20 11:00'))->toHaveCount(1);   // opens ON the minute
    expect(adsAt($this->screen, '2026-03-20 14:59'))->toHaveCount(1);
    expect(adsAt($this->screen, '2026-03-20 15:00'))->toBeEmpty();      // and shuts ON it
});

test('a window that runs past midnight stays open through it', function () {
    $campaign = Campaign::factory()->between('22:00', '02:00')->create();
    $campaign->screens()->attach($this->screen);

    expect(adsAt($this->screen, '2026-03-20 22:00'))->toHaveCount(1);
    expect(adsAt($this->screen, '2026-03-21 00:30'))->toHaveCount(1);   // the half hour that used to go dark
    expect(adsAt($this->screen, '2026-03-21 01:59'))->toHaveCount(1);
    expect(adsAt($this->screen, '2026-03-21 02:00'))->toBeEmpty();
    expect(adsAt($this->screen, '2026-03-20 12:00'))->toBeEmpty();
});

test('the window is read on the screen own clock, so two shops disagree', function () {
    $london = consentingScreen($this->store, ['timezone' => 'Europe/London']);
    $campaign = Campaign::factory()->between('11:00', '15:00')->create();
    $campaign->screens()->attach([$this->screen->id, $london->id]);

    // One instant: noon in Chicago, five in the evening in London.
    $instant = CarbonImmutable::parse('2026-03-20 12:00', 'America/Chicago');
    $resolver = app(NetworkAdResolver::class);

    expect($resolver->breakFor($this->screen->fresh(), $instant))->toHaveCount(1);
    expect($resolver->breakFor($london->fresh(), $instant))->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| One break, and how much fits in it
|--------------------------------------------------------------------------
*/

test('several brands share one break, in a stable order', function () {
    foreach (['Coca-Cola', 'Nestle', 'Lays'] as $brand) {
        Campaign::factory()->lasting(15)->create(['name' => $brand])->screens()->attach($this->screen);
    }

    $break = adsAt($this->screen, '2026-03-20 12:00');

    expect($break->pluck('name')->all())->toBe(['Coca-Cola', 'Nestle', 'Lays']);

    // Same order every hour, so a brand's slot does not wander about.
    expect(adsAt($this->screen, '2026-03-20 13:00')->pluck('name')->all())
        ->toBe($break->pluck('name')->all());
});

test('a break is cut at the ceiling rather than running for minutes', function () {
    // Four 20-second adverts is 80 seconds; the break holds 60.
    foreach (range(1, 4) as $n) {
        Campaign::factory()->lasting(20)->create(['name' => "Brand {$n}"])->screens()->attach($this->screen);
    }

    $break = adsAt($this->screen, '2026-03-20 12:00');

    expect($break->pluck('name')->all())->toBe(['Brand 1', 'Brand 2', 'Brand 3']);
    expect($break->sum('play_seconds'))->toBe(60);
    expect($break->sum('play_seconds'))->toBeLessThanOrEqual(Campaign::MAX_BREAK_SECONDS);
});

test('one over-long advert still plays rather than blocking the whole break', function () {
    // Otherwise a single advert longer than the ceiling would mean nothing ever ran,
    // and nobody would be able to see why.
    Campaign::factory()->lasting(90)->create(['name' => 'Long one'])->screens()->attach($this->screen);

    expect(adsAt($this->screen, '2026-03-20 12:00')->pluck('name')->all())->toBe(['Long one']);
});

test('a video occupies the break for its own length, not the typed one', function () {
    Campaign::factory()->video(25)->lasting(5)->create(['name' => 'Film'])->screens()->attach($this->screen);

    expect(adsAt($this->screen, '2026-03-20 12:00')->first()->play_seconds)->toBe(25);
});

test('a video whose length was never measured falls back to the typed seconds', function () {
    // A break's length has to be knowable in advance, even for a file the browser
    // could not measure at upload.
    $campaign = Campaign::factory()->video(0)->lasting(12)->create();
    $campaign->update(['media_duration_seconds' => null]);
    $campaign->screens()->attach($this->screen);

    expect(adsAt($this->screen, '2026-03-20 12:00')->first()->play_seconds)->toBe(12);
});

test('a screen with no campaigns has no break at all', function () {
    expect(adsAt($this->screen, '2026-03-20 12:00'))->toBeEmpty();
});
