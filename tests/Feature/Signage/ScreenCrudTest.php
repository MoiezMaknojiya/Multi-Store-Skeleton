<?php

use App\Models\Screen;
use App\Models\Store;
use App\Services\DevicePairing;

/** Register a waiting device and return its pairing code. */
function waitingDeviceCode(): string
{
    return app(DevicePairing::class)->register()['code'];
}

test('guests cannot access any screen endpoint', function () {
    // Every route under /screens — the playlist, its picker and copy included — read from the route table,
    // so one added later is asked too.
    $routes = routesUnder('screens');

    expect($routes)->not->toBeEmpty();

    foreach ($routes as [$method, $uri]) {
        expect($this->json($method, $uri)->status())->toBe(401, "{$method} {$uri}");
    }
});

test('a store user only sees the screens of the store they are working in', function () {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    $actor = createStoreUser($storeA, ['screen-view']);

    $mine = Screen::factory()->create(['store_id' => $storeA->id, 'name' => 'Alpha Counter']);
    $theirs = Screen::factory()->create(['store_id' => $storeB->id, 'name' => 'Beta Counter']);

    $ids = collect($this->actingAs($actor)->withSession(['current_store_id' => $storeA->id])
        ->getJson('/screens/data')->assertOk()->json('screens'))->pluck('id');

    expect($ids)->toContain($mine->id);
    expect($ids)->not->toContain($theirs->id);
});

test('another store\'s screen is unreachable, not just hidden', function () {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    $actor = createStoreUser($storeA, ['screen-view', 'screen-update', 'screen-destroy']);
    $theirs = Screen::factory()->create(['store_id' => $storeB->id, 'name' => 'Beta Counter']);

    $this->actingAs($actor)->withSession(['current_store_id' => $storeA->id])
        ->putJson("/screens/{$theirs->id}", ['name' => 'Hacked', 'orientation' => 'landscape'])
        ->assertNotFound();

    $this->actingAs($actor)->withSession(['current_store_id' => $storeA->id])
        ->deleteJson("/screens/{$theirs->id}")->assertNotFound();

    $this->assertDatabaseHas('screens', ['id' => $theirs->id, 'name' => 'Beta Counter']);
});

test('the token hash never leaves the server', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['screen-view']);
    Screen::factory()->create(['store_id' => $store->id]);

    $payload = $this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->getJson('/screens/data')->assertOk()->json('screens.0');

    expect($payload)->not->toHaveKey('token_hash');
    expect($payload)->toHaveKey('is_online');
});

/* ── Pairing from the owner's own panel ────────────────────────────────── */

test('a store owner pairs their own TV without any admin help', function () {
    $store = Store::factory()->create();
    $owner = createStoreUser($store, ['screen-store'], 'Store Owner');
    $code = waitingDeviceCode();

    $this->actingAs($owner)->withSession(['current_store_id' => $store->id])
        ->postJson('/screens/pair', [
            'code' => $code,
            'mode' => 'new',
            'name' => 'Counter TV',
            'orientation' => 'portrait',
        ])->assertOk();

    $screen = Screen::firstOrFail();
    expect($screen->store_id)->toBe($store->id);
    expect($screen->name)->toBe('Counter TV');
    expect($screen->orientation)->toBe('portrait');
    expect($screen->isPaired())->toBeTrue();
    expect($screen->paired_by)->toBe($owner->id);
});

test('the pairing code is accepted in lower case, the way it is typed', function () {
    $store = Store::factory()->create();
    $owner = createStoreUser($store, ['screen-store']);
    $code = waitingDeviceCode();

    $this->actingAs($owner)->withSession(['current_store_id' => $store->id])
        ->postJson('/screens/pair', [
            'code' => strtolower($code),
            'mode' => 'new',
            'name' => 'Counter TV',
            'orientation' => 'landscape',
        ])->assertOk();

    expect(Screen::firstOrFail()->isPaired())->toBeTrue();
});

test('a dead code creates no screen at all', function () {
    $store = Store::factory()->create();
    $owner = createStoreUser($store, ['screen-store']);

    $this->actingAs($owner)->withSession(['current_store_id' => $store->id])
        ->postJson('/screens/pair', [
            'code' => 'ZZZZZZ',
            'mode' => 'new',
            'name' => 'Counter TV',
            'orientation' => 'landscape',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);

    // The half-made screen must be rolled back, not left as a ghost row.
    expect(Screen::count())->toBe(0);
});

test('the same code cannot be used twice', function () {
    $store = Store::factory()->create();
    $owner = createStoreUser($store, ['screen-store']);
    $code = waitingDeviceCode();

    $payload = ['code' => $code, 'mode' => 'new', 'name' => 'First', 'orientation' => 'landscape'];

    $this->actingAs($owner)->withSession(['current_store_id' => $store->id])
        ->postJson('/screens/pair', $payload)->assertOk();

    $this->flushSession();
    $this->actingAs($owner)->withSession(['current_store_id' => $store->id])
        ->postJson('/screens/pair', [...$payload, 'name' => 'Second'])
        ->assertStatus(422);

    expect(Screen::count())->toBe(1);
});

test('pairing without a store selected is refused with a helpful message', function () {
    $admin = createSuperAdmin(['screen-store']);
    $code = waitingDeviceCode();

    $this->actingAs($admin)->postJson('/screens/pair', [
        'code' => $code,
        'mode' => 'new',
        'name' => 'Counter TV',
        'orientation' => 'landscape',
    ])->assertStatus(422)->assertJsonValidationErrors(['code']);

    expect(Screen::count())->toBe(0);
});

test('a user without screen-store cannot pair anything', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['screen-view']);

    $this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->postJson('/screens/pair', [
            'code' => waitingDeviceCode(),
            'mode' => 'new',
            'name' => 'Counter TV',
            'orientation' => 'landscape',
        ])->assertForbidden();
});

test('an unknown orientation is rejected', function () {
    $store = Store::factory()->create();
    $owner = createStoreUser($store, ['screen-store']);

    $this->actingAs($owner)->withSession(['current_store_id' => $store->id])
        ->postJson('/screens/pair', [
            'code' => waitingDeviceCode(),
            'mode' => 'new',
            'name' => 'Counter TV',
            'orientation' => 'sideways',
        ])->assertStatus(422)->assertJsonValidationErrors(['orientation']);
});

/* ── Replacing the device behind a screen ──────────────────────────────── */

test('replacing a device keeps the screen and its settings', function () {
    $store = Store::factory()->create();
    $owner = createStoreUser($store, ['screen-store']);
    $screen = Screen::factory()->withToken('old-token')->create([
        'store_id' => $store->id,
        'name' => 'Counter TV',
        'orientation' => 'portrait',
    ]);

    $this->actingAs($owner)->withSession(['current_store_id' => $store->id])
        ->postJson('/screens/pair', [
            'code' => waitingDeviceCode(),
            'mode' => 'replace',
            'screen_id' => $screen->id,
        ])->assertOk();

    $screen->refresh();
    expect(Screen::count())->toBe(1);          // no second screen appeared
    expect($screen->name)->toBe('Counter TV'); // settings untouched
    expect($screen->orientation)->toBe('portrait');
    expect($screen->token_hash)->not->toBe(hash('sha256', 'old-token'));  // token rotated
});

test('a screen from another store cannot be re-paired', function () {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    $owner = createStoreUser($storeA, ['screen-store']);
    $theirs = Screen::factory()->create(['store_id' => $storeB->id]);

    $this->actingAs($owner)->withSession(['current_store_id' => $storeA->id])
        ->postJson('/screens/pair', [
            'code' => waitingDeviceCode(),
            'mode' => 'replace',
            'screen_id' => $theirs->id,
        ])->assertNotFound();
});

/* ── Edit and delete ───────────────────────────────────────────────────── */

test('a user with screen-update can rename a screen and re-orient it', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['screen-update']);
    $screen = Screen::factory()->create(['store_id' => $store->id]);

    $this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->putJson("/screens/{$screen->id}", [
            'name' => 'Window Board',
            'orientation' => 'portrait_flipped',
        ])->assertOk();

    $screen->refresh();
    expect($screen->name)->toBe('Window Board');
    expect($screen->orientation)->toBe('portrait_flipped');
});

test('a user with screen-destroy can delete a screen', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['screen-destroy']);
    $screen = Screen::factory()->create(['store_id' => $store->id]);

    $this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->deleteJson("/screens/{$screen->id}")->assertOk();

    $this->assertDatabaseMissing('screens', ['id' => $screen->id]);
});

test('a user without screen-destroy cannot delete a screen', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['screen-view']);
    $screen = Screen::factory()->create(['store_id' => $store->id]);

    $this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->deleteJson("/screens/{$screen->id}")->assertForbidden();

    $this->assertDatabaseHas('screens', ['id' => $screen->id]);
});

test('the online chip follows the last heartbeat', function () {
    $store = Store::factory()->create();

    $fresh = Screen::factory()->create(['store_id' => $store->id, 'last_seen_at' => now()->subMinute()]);
    $stale = Screen::factory()->offline()->create(['store_id' => $store->id]);
    $never = Screen::factory()->unpaired()->create(['store_id' => $store->id]);

    expect($fresh->is_online)->toBeTrue();
    expect($stale->is_online)->toBeFalse();
    expect($never->is_online)->toBeFalse();
});

test('deleting a store takes its screens with it', function () {
    $store = Store::factory()->create();
    $screen = Screen::factory()->create(['store_id' => $store->id]);

    $store->delete();

    $this->assertDatabaseMissing('screens', ['id' => $screen->id]);
});
