<?php

use App\Models\Screen;
use App\Models\Store;

/*
|--------------------------------------------------------------------------
| Searching the screens listing
|--------------------------------------------------------------------------
|
| The box searches everything a row actually shows: the name, the device id and
| the pairing date. Searching only the name looked like it worked — until you
| typed the device id printed right there on the row and got nothing back.
|
*/

/** Two screens with distinct names, devices and pairing dates. */
function twoScreens(Store $store): array
{
    return [
        Screen::factory()->create([
            'store_id' => $store->id,
            'name' => 'Counter TV',
            'device_uuid' => '828739f6-8ae4-4099-821c-377b8a3f87bf',
            'paired_at' => '2026-09-07 14:08:48',
        ]),
        Screen::factory()->create([
            'store_id' => $store->id,
            'name' => 'Window Board',
            'device_uuid' => 'aa11bb22-cc33-dd44-ee55-158afcaa16d0',
            'paired_at' => '2026-08-15 09:30:00',
        ]),
    ];
}

/** @return list<string> the names the search returned */
function searchScreens(string $term): array
{
    return collect(test()->getJson('/screens/data?search='.urlencode($term))->assertOk()->json('screens'))
        ->pluck('name')->all();
}

beforeEach(function () {
    $this->store = Store::factory()->create();
    $this->actor = createStoreUser($this->store, ['screen-view']);
    [$this->counter, $this->window] = twoScreens($this->store);

    $this->actingAs($this->actor)->withSession(['current_store_id' => $this->store->id]);
});

test('a screen is found by its name, whatever case it is typed in', function () {
    expect(searchScreens('Counter'))->toBe(['Counter TV']);
    expect(searchScreens('counter'))->toBe(['Counter TV']);
});

test('a screen is found by the device id printed on its row', function () {
    // The listing shows only the LAST block of the uuid, so that is what somebody
    // reads off the row — and off the television, which prints the same block.
    expect(searchScreens('377b8a3f87bf'))->toBe(['Counter TV']);
    expect(searchScreens('158afcaa16d0'))->toBe(['Window Board']);
});

test('a screen is found by any part of its device id', function () {
    // Somebody copying from the database or a log has the whole uuid, not just the
    // tail of it.
    expect(searchScreens('828739f6'))->toBe(['Counter TV']);
    expect(searchScreens('828739f6-8ae4-4099-821c-377b8a3f87bf'))->toBe(['Counter TV']);
});

test('a screen is found by its pairing date, written either way', function () {
    // As stored, and as the panel prints it in a US-English browser. Both have to
    // work: the second is the one people can actually see on screen.
    expect(searchScreens('2026-09-07'))->toBe(['Counter TV']);
    expect(searchScreens('9/7/2026'))->toBe(['Counter TV']);
});

test('a whole month can be searched', function () {
    expect(searchScreens('2026-08'))->toBe(['Window Board']);
});

test('a term that means nothing finds nothing', function () {
    // The date parsing must not turn ordinary words into dates and start matching
    // rows at random — the round-trip check in datesMeaning() is what stops that.
    expect(searchScreens('nowhere'))->toBe([]);
    expect(searchScreens('99/99/9999'))->toBe([]);
});

test('search never reaches outside the store being worked in', function () {
    // The wall comes first: searching is not a way around it, whichever field the
    // term happens to match.
    $otherStore = Store::factory()->create();
    Screen::factory()->create([
        'store_id' => $otherStore->id,
        'name' => 'Counter TV',
        'device_uuid' => '828739f6-8ae4-4099-821c-377b8a3f87bf',
        'paired_at' => '2026-09-07 14:08:48',
    ]);

    expect(searchScreens('Counter'))->toBe(['Counter TV']);          // only ours
    expect(searchScreens('377b8a3f87bf'))->toBe(['Counter TV']);
    expect(searchScreens('9/7/2026'))->toBe(['Counter TV']);
});

test('an unpaired screen has no device or date to be found by', function () {
    $bare = Screen::factory()->unpaired()->create([
        'store_id' => $this->store->id,
        'name' => 'Entrance Display',
    ]);

    expect(searchScreens('Entrance'))->toBe(['Entrance Display']);
    expect(searchScreens('377b8a3f87bf'))->not->toContain($bare->name);
});
