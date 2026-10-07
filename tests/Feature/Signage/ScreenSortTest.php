<?php

use App\Models\Organization;
use App\Models\Screen;

/*
|--------------------------------------------------------------------------
| Sorting the screens listing
|--------------------------------------------------------------------------
|
| Owner, 2026-10-06: "Screens per sort by name, status, orientation, paired date lagao". Each heading sorts the
| list (x-crud.sort-header): by name, by status (the last heartbeat: online first), by orientation and by the
| pairing date — newest paired first until another is pressed, and anything else asked for is the order the list
| always opened in.
|
*/

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->actingAs(createOrganizationUser($this->organization, ['screen-view']))
        ->withSession(['current_organization_id' => $this->organization->id]);

    $screen = fn (array $attributes) => Screen::factory()->create(['organization_id' => $this->organization->id, ...$attributes]);
    $screen(['name' => 'bar TV', 'orientation' => 'portrait', 'paired_at' => '2026-09-02 10:00:00', 'last_seen_at' => now()->subMinute(), 'created_at' => now()->subDays(3)]);
    $screen(['name' => 'Counter TV', 'orientation' => 'landscape', 'paired_at' => '2026-09-05 10:00:00', 'last_seen_at' => now()->subHours(5), 'created_at' => now()->subDays(1)]);
    $screen(['name' => 'Aisle Board', 'orientation' => 'landscape_flipped', 'paired_at' => '2026-09-01 10:00:00', 'last_seen_at' => null, 'created_at' => now()->subDays(2)]);
});

/** @return list<string> the names in the order the listing returned them */
function screensSortedBy(string $query): array
{
    return collect(test()->getJson('/screens/data?'.$query)->assertOk()->json('screens'))->pluck('name')->all();
}

test('each heading sorts the list, either way round', function (string $query, array $names) {
    expect(screensSortedBy($query))->toBe($names);
})->with([
    'name, A to Z (whatever the case)' => ['sort=name&direction=asc', ['Aisle Board', 'bar TV', 'Counter TV']],
    'name, Z to A' => ['sort=name&direction=desc', ['Counter TV', 'bar TV', 'Aisle Board']],
    'status, online first, never seen last' => ['sort=status&direction=desc', ['bar TV', 'Counter TV', 'Aisle Board']],
    'status, never seen first' => ['sort=status&direction=asc', ['Aisle Board', 'Counter TV', 'bar TV']],
    'orientation' => ['sort=orientation&direction=asc', ['Counter TV', 'Aisle Board', 'bar TV']],
    'paired, newest first' => ['sort=paired&direction=desc', ['Counter TV', 'bar TV', 'Aisle Board']],
    'paired, oldest first' => ['sort=paired&direction=asc', ['Aisle Board', 'bar TV', 'Counter TV']],
]);

test('nothing asked for, or something that is not a heading, is the newest added first', function (string $query) {
    expect(screensSortedBy($query))->toBe(['Counter TV', 'Aisle Board', 'bar TV']);
})->with([
    'nothing' => [''],
    'a column that is not a heading' => ['sort=device_uuid&direction=asc'],
    'SQL as a column' => ['sort='.urlencode('name; drop table screens').'&direction=asc'],
    'arrays' => ['sort[]=name&direction[]=asc'],
]);

test('a sort and a search go together', function () {
    expect(screensSortedBy('search=TV&sort=name&direction=desc'))->toBe(['Counter TV', 'bar TV']);
});
