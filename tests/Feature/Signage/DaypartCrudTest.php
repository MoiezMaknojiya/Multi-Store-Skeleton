<?php

use App\Models\Daypart;
use App\Models\Store;

/*
|--------------------------------------------------------------------------
| Dayparts — the named windows of time a shop reuses
|--------------------------------------------------------------------------
|
| A daypart is shop inventory, like a media file: everyone working in the store
| shares it, whoever typed it in, and it is invisible from any other store. Its
| exceptions are replaced as a whole set on every save, the same way a playlist
| is, so a half-applied set of hours never survives.
|
*/

/** The body every write endpoint expects, with only the parts a test cares about set. */
function daypartPayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'Deli hours',
        'start_time' => '07:00',
        'end_time' => '20:00',
        'is_retired' => false,
        'exceptions' => [],
    ], $overrides);
}

beforeEach(function () {
    $this->store = Store::factory()->create();
    $this->actor = createStoreUser($this->store, [
        'daypart-view', 'daypart-store', 'daypart-update', 'daypart-destroy',
    ]);

    $this->actingAs($this->actor)->withSession(['current_store_id' => $this->store->id]);
});

test('guests cannot reach any daypart endpoint', function () {
    auth()->logout();

    // Every route under /dayparts, read from the route table — so one added later is asked too.
    $routes = routesUnder('dayparts');

    expect($routes)->not->toBeEmpty();

    foreach ($routes as [$method, $uri]) {
        expect($this->json($method, $uri)->status())->toBe(401, "{$method} {$uri}");
    }
});

test('the dayparts page itself renders', function () {
    // Worth its own test: every endpoint below can pass while the PAGE throws,
    // because the view needs the weekday list the controller hands it and nothing
    // else here would notice it missing.
    $this->get('/dayparts')
        ->assertOk()
        ->assertSee('Add Daypart')
        ->assertSee('Monday');
});

test('a daypart is created with its exceptions in one call', function () {
    $this->postJson('/dayparts', daypartPayload([
        'exceptions' => [
            ['weekday' => 7, 'start_time' => '09:00', 'end_time' => '16:00'],
            ['weekday' => 1, 'start_time' => null, 'end_time' => null],
        ],
    ]))->assertOk();

    $daypart = Daypart::where('name', 'Deli hours')->firstOrFail();

    expect($daypart->store_id)->toBe($this->store->id);
    expect($daypart->created_by)->toBe($this->actor->id);
    // "H:i" in PHP whichever database is underneath — see HasClockTimes.
    expect($daypart->start_time)->toBe('07:00');
    expect($daypart->end_time)->toBe('20:00');

    expect($daypart->windowFor(7))->toBe(['09:00', '16:00']);   // Sunday is different
    expect($daypart->windowFor(1))->toBeNull();                  // Monday is closed
    expect($daypart->windowFor(3))->toBe(['07:00', '20:00']);   // Wednesday follows the base
});

test('saving replaces the whole set of exceptions rather than adding to it', function () {
    $daypart = Daypart::factory()->create(['store_id' => $this->store->id]);
    $daypart->syncExceptions([
        ['weekday' => 1, 'start_time' => '10:00', 'end_time' => '12:00'],
        ['weekday' => 2, 'start_time' => '10:00', 'end_time' => '12:00'],
    ]);

    $this->putJson("/dayparts/{$daypart->id}", daypartPayload([
        'name' => $daypart->name,
        'exceptions' => [['weekday' => 7, 'start_time' => null, 'end_time' => null]],
    ]))->assertOk();

    $fresh = $daypart->fresh();

    // The old two are gone, not merged with the new one.
    expect($fresh->exceptions)->toHaveCount(1);
    expect($fresh->windowFor(7))->toBeNull();
    expect($fresh->windowFor(1))->toBe(['07:00', '20:00']);
});

test('an end time earlier than the start is accepted — that is how a window crosses midnight', function () {
    $this->postJson('/dayparts', daypartPayload([
        'name' => 'Late night', 'start_time' => '22:00', 'end_time' => '02:00',
    ]))->assertOk();

    expect(Daypart::where('name', 'Late night')->firstOrFail()->crossesMidnight())->toBeTrue();
});

test('an end time equal to the start is refused, because it has no honest reading', function () {
    $this->postJson('/dayparts', daypartPayload(['start_time' => '09:00', 'end_time' => '09:00']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('end_time');

    expect(Daypart::count())->toBe(0);
});

test('two dayparts in one store cannot share a name, but two stores can', function () {
    $otherStore = Store::factory()->create();
    $neighbour = createStoreUser($otherStore, ['daypart-store'], 'Neighbour Role');

    $this->postJson('/dayparts', daypartPayload())->assertOk();

    // The same name in a different store is a different window entirely: the name check looks inside the
    // store the person works in, so it goes through the endpoint rather than the factory.
    $this->actingAs($neighbour)->withSession(['current_store_id' => $otherStore->id])
        ->postJson('/dayparts', daypartPayload())->assertOk();

    // Back in the first store, the name is taken.
    $this->actingAs($this->actor)->withSession(['current_store_id' => $this->store->id])
        ->postJson('/dayparts', daypartPayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    expect(Daypart::where('name', 'Deli hours')->orderBy('store_id')->pluck('store_id')->all())
        ->toBe([$this->store->id, $otherStore->id]);
});

test('renaming a daypart does not collide with its own name', function () {
    $daypart = Daypart::factory()->create(['store_id' => $this->store->id, 'name' => 'Deli hours']);

    $this->putJson("/dayparts/{$daypart->id}", daypartPayload([
        'name' => 'Deli hours', 'start_time' => '08:00',
    ]))->assertOk();

    expect($daypart->fresh()->start_time)->toBe('08:00');
});

test('one weekday cannot appear twice, and a lone time is refused', function () {
    $this->postJson('/dayparts', daypartPayload([
        'exceptions' => [
            ['weekday' => 7, 'start_time' => '09:00', 'end_time' => '16:00'],
            ['weekday' => 7, 'start_time' => '10:00', 'end_time' => '17:00'],
        ],
    ]))->assertStatus(422)->assertJsonValidationErrors('exceptions.1.weekday');

    // Both times, or neither. One alone cannot say anything.
    $this->postJson('/dayparts', daypartPayload([
        'exceptions' => [['weekday' => 7, 'start_time' => '09:00', 'end_time' => null]],
    ]))->assertStatus(422)->assertJsonValidationErrors('exceptions.0.end_time');

    expect(Daypart::count())->toBe(0);
});

test('retiring a daypart keeps it, it just leaves the pickers', function () {
    $daypart = Daypart::factory()->create(['store_id' => $this->store->id]);

    $this->putJson("/dayparts/{$daypart->id}", daypartPayload([
        'name' => $daypart->name, 'is_retired' => true,
    ]))->assertOk();

    expect($daypart->fresh()->is_retired)->toBeTrue();
    expect(Daypart::active()->count())->toBe(0);
    expect(Daypart::count())->toBe(1);
});

test('a store user only sees the dayparts of the store they are working in', function () {
    $mine = Daypart::factory()->create(['store_id' => $this->store->id, 'name' => 'Deli hours']);
    $theirs = Daypart::factory()->create(['store_id' => Store::factory()->create()->id, 'name' => 'Bakery hours']);

    $ids = collect($this->getJson('/dayparts/data')->assertOk()->json('dayparts'))->pluck('id');

    expect($ids)->toContain($mine->id);
    expect($ids)->not->toContain($theirs->id);
});

test('another store\'s daypart is unreachable, not merely hidden', function () {
    $theirs = Daypart::factory()->create([
        'store_id' => Store::factory()->create()->id, 'name' => 'Bakery hours',
    ]);

    // 404, never 403: from this store that daypart does not exist.
    $this->putJson("/dayparts/{$theirs->id}", daypartPayload(['name' => 'Hijacked']))->assertNotFound();
    $this->deleteJson("/dayparts/{$theirs->id}")->assertNotFound();

    $this->assertDatabaseHas('dayparts', ['id' => $theirs->id, 'name' => 'Bakery hours']);
});

test('a daypart belongs to a store, so one cannot be created without a store context', function () {
    // A super admin has the permission everywhere but is standing in no store, so
    // the gate lets them through and the controller is what has to say no.
    $globalActor = createSuperAdmin(['daypart-store']);

    $this->flushSession();

    $this->actingAs($globalActor)->postJson('/dayparts', daypartPayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    expect(Daypart::count())->toBe(0);
});

test('a daypart is shop inventory — a colleague in the same store can edit it', function () {
    $colleague = createStoreUser($this->store, ['daypart-view', 'daypart-update'], 'Colleague Role');
    $daypart = Daypart::factory()->create([
        'store_id' => $this->store->id, 'created_by' => $this->actor->id,
    ]);

    // "Deli hours" belongs to the deli, not to whoever typed it in.
    $this->actingAs($colleague)->withSession(['current_store_id' => $this->store->id])
        ->putJson("/dayparts/{$daypart->id}", daypartPayload(['name' => 'Deli hours (winter)']))
        ->assertOk();

    expect($daypart->fresh()->name)->toBe('Deli hours (winter)');
});

test('each daypart action needs its own permission', function () {
    $viewer = createStoreUser($this->store, ['daypart-view'], 'Viewer Role');
    $daypart = Daypart::factory()->create(['store_id' => $this->store->id]);

    $this->actingAs($viewer)->withSession(['current_store_id' => $this->store->id]);

    $this->getJson('/dayparts/data')->assertOk();
    $this->postJson('/dayparts', daypartPayload())->assertForbidden();
    $this->putJson("/dayparts/{$daypart->id}", daypartPayload())->assertForbidden();
    $this->deleteJson("/dayparts/{$daypart->id}")->assertForbidden();
});

test('deleting a daypart takes its exceptions with it', function () {
    $daypart = Daypart::factory()->create(['store_id' => $this->store->id]);
    $daypart->syncExceptions([['weekday' => 7, 'start_time' => '09:00', 'end_time' => '16:00']]);

    $this->deleteJson("/dayparts/{$daypart->id}")->assertOk();

    $this->assertDatabaseMissing('dayparts', ['id' => $daypart->id]);
    $this->assertDatabaseMissing('daypart_exceptions', ['daypart_id' => $daypart->id]);
});

test('dayparts are searchable by name', function () {
    Daypart::factory()->create(['store_id' => $this->store->id, 'name' => 'Deli hours']);
    Daypart::factory()->create(['store_id' => $this->store->id, 'name' => 'Bakery hours']);

    $names = collect($this->getJson('/dayparts/data?search=deli')->assertOk()->json('dayparts'))->pluck('name');

    expect($names->all())->toBe(['Deli hours']);
});

test('the listing carries each daypart\'s exceptions, so a row can show them without a second call', function () {
    $daypart = Daypart::factory()->create(['store_id' => $this->store->id]);
    $daypart->syncExceptions([['weekday' => 7, 'start_time' => '09:00', 'end_time' => '16:00']]);

    $row = collect($this->getJson('/dayparts/data')->assertOk()->json('dayparts'))->firstWhere('id', $daypart->id);

    expect($row['exceptions'])->toHaveCount(1);
    expect($row['exceptions'][0]['weekday'])->toBe(7);
    expect($row['exceptions'][0]['start_time'])->toBe('09:00');
});
