<?php

use App\Models\Organization;
use App\Models\Screen;

/*
|--------------------------------------------------------------------------
| The Screens page's Online and Offline filter
|--------------------------------------------------------------------------
|
| Owner, 2026-10-07: "screen per online aur offline filter lagao do". Online is exactly what each row's badge calls
| online (Screen::is_online, heard from within OFFLINE_AFTER_MINUTES); Offline is every other screen, a screen never
| heard from included. Anything else asked for is every screen.
|
*/

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->actingAs(createOrganizationUser($this->organization, ['screen-view']))
        ->withSession(['current_organization_id' => $this->organization->id]);

    $screen = fn (string $name, $lastSeen) => Screen::factory()->create([
        'organization_id' => $this->organization->id, 'name' => $name, 'last_seen_at' => $lastSeen,
    ]);
    $screen('Counter TV', now()->subMinute());
    $screen('Deli TV', now()->subMinutes(Screen::OFFLINE_AFTER_MINUTES)->addSeconds(20));
    $screen('Aisle Board', now()->subMinutes(Screen::OFFLINE_AFTER_MINUTES + 1));
    $screen('Back Office', null);

    // Another organization's screens never join either list.
    Screen::factory()->create(['organization_id' => Organization::factory()->create()->id, 'name' => 'Elsewhere TV', 'last_seen_at' => now()]);
});

/** @return list<string> the names the listing returned, A to Z */
function screensFiltered(string $query): array
{
    return collect(test()->getJson('/screens/data?sort=name&direction=asc&'.$query)->assertOk()->json('screens'))->pluck('name')->all();
}

test('online is what the badge calls online, offline everything else, never heard from included', function () {
    expect(screensFiltered('status=online'))->toBe(['Counter TV', 'Deli TV'])
        ->and(screensFiltered('status=offline'))->toBe(['Aisle Board', 'Back Office']);

    $listed = collect(test()->getJson('/screens/data?status=online')->json('screens'));
    expect($listed->every(fn (array $screen) => $screen['is_online'] === true))->toBeTrue();
});

test('anything else asked for is every screen', function (string $query) {
    expect(screensFiltered($query))->toBe(['Aisle Board', 'Back Office', 'Counter TV', 'Deli TV']);
})->with([
    'nothing' => [''],
    'empty' => ['status='],
    'a word that is not one of the two' => ['status=paused'],
    'SQL' => ['status='.urlencode("online' or 1=1 --")],
    'an array' => ['status[]=online'],
]);

test('the filter goes with a search, and the count and pages follow it', function () {
    $answer = test()->getJson('/screens/data?status=offline&search=Board')->assertOk();

    expect(collect($answer->json('screens'))->pluck('name')->all())->toBe(['Aisle Board'])
        ->and($answer->json('total'))->toBe(1);
});

test('the dashboard counts online screens by the same rule', function () {
    $this->actingAs(createSuperAdmin())->get('/dashboard')->assertOk()->assertSee('3 online now');
});
