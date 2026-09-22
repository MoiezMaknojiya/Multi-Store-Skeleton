<?php

use App\Models\PairingRequest;
use App\Models\Screen;
use App\Models\Store;
use App\Services\DevicePairing;

/* ── Registration ──────────────────────────────────────────────────────── */

test('a device with nothing can ask for a pairing code without logging in', function () {
    $response = $this->postJson('/device/register')->assertOk();

    $response->assertJsonStructure(['device_uuid', 'code', 'expires_at', 'poll_secret']);
    expect($response->json('code'))->toHaveLength(6);
    $this->assertDatabaseCount('pairing_requests', 1);
});

test('the code avoids characters that are misread on a TV', function () {
    // No I, O, 0 or 1, and never lowercase — it is read across a room. One random code could miss a
    // forbidden letter by luck, so two hundred are drawn; through the service rather than the endpoint,
    // which allows a single address thirty a minute.
    $pairing = app(DevicePairing::class);

    foreach (range(1, 200) as $draw) {
        expect($pairing->register()['code'])->toMatch('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{6}$/');
    }
});

test('re-registering the same device keeps the code already on screen', function () {
    $first = $this->postJson('/device/register')->assertOk()->json();

    $second = $this->postJson('/device/register', ['device_uuid' => $first['device_uuid']])->assertOk()->json();

    expect($second['code'])->toBe($first['code']);
    $this->assertDatabaseCount('pairing_requests', 1);

    // A fresh poll secret is issued, so an old one cannot keep watching.
    expect($second['poll_secret'])->not->toBe($first['poll_secret']);
});

test('a device whose code expired can simply ask for another one', function () {
    $first = $this->postJson('/device/register')->assertOk()->json();

    // Registering sweeps expired, unclaimed codes before anything else, so the dead
    // row is gone by the time the device asks again: it is given a new code under the
    // same uuid, and there is still only one row on file for it.
    PairingRequest::query()->update(['expires_at' => now()->subMinute()]);

    $second = $this->postJson('/device/register', ['device_uuid' => $first['device_uuid']])
        ->assertOk()->json();

    expect($second['code'])->not->toBe($first['code']);
    expect($second['device_uuid'])->toBe($first['device_uuid']);
    $this->assertDatabaseCount('pairing_requests', 1);

    // And the new code works.
    $this->getJson('/device/pair-status?'.http_build_query([
        'device_uuid' => $second['device_uuid'],
        'poll_secret' => $second['poll_secret'],
    ]))->assertOk()->assertJson(['status' => 'pending']);
});

test('a device that lost its token starts over instead of colliding', function () {
    $store = Store::factory()->create();
    $device = $this->postJson('/device/register')->assertOk()->json();
    $screen = Screen::factory()->unpaired()->create(['store_id' => $store->id]);
    app(DevicePairing::class)->claim($device['code'], $screen);

    // The TV never collected the token (browsing data cleared, say) and asks again.
    $fresh = $this->postJson('/device/register', ['device_uuid' => $device['device_uuid']])
        ->assertOk()->json();

    expect($fresh['code'])->not->toBe($device['code']);
    $this->assertDatabaseCount('pairing_requests', 1);
});

test('expired codes are swept up, but a claimed token waits for its device', function () {
    $store = Store::factory()->create();

    // Dead: nobody ever typed it in.
    $abandoned = $this->postJson('/device/register')->json();
    PairingRequest::where('device_uuid', $abandoned['device_uuid'])->update(['expires_at' => now()->subMinute()]);

    // Claimed, but the TV has not collected its token yet — it may just be slow.
    $claimed = $this->postJson('/device/register')->json();
    app(DevicePairing::class)->claim($claimed['code'], Screen::factory()->unpaired()->create(['store_id' => $store->id]));
    PairingRequest::where('device_uuid', $claimed['device_uuid'])->update(['expires_at' => now()->subMinute()]);

    app(DevicePairing::class)->pruneExpired();

    $this->assertDatabaseMissing('pairing_requests', ['device_uuid' => $abandoned['device_uuid']]);
    $this->assertDatabaseHas('pairing_requests', ['device_uuid' => $claimed['device_uuid']]);

    // The slow device still gets what it was promised.
    $this->getJson('/device/pair-status?'.http_build_query([
        'device_uuid' => $claimed['device_uuid'],
        'poll_secret' => $claimed['poll_secret'],
    ]))->assertOk()->assertJson(['status' => 'paired']);
});

/* ── Polling ───────────────────────────────────────────────────────────── */

test('an unclaimed code polls as pending', function () {
    $device = $this->postJson('/device/register')->json();

    $this->getJson('/device/pair-status?'.http_build_query([
        'device_uuid' => $device['device_uuid'],
        'poll_secret' => $device['poll_secret'],
    ]))->assertOk()->assertJson(['status' => 'pending']);
});

test('a guessed device id cannot steal a freshly minted token', function () {
    $store = Store::factory()->create();
    $device = $this->postJson('/device/register')->json();
    $screen = Screen::factory()->unpaired()->create(['store_id' => $store->id]);

    expect(app(DevicePairing::class)->claim($device['code'], $screen))->toBeTrue();

    // The uuid alone is not enough — the poll secret is the second half.
    $this->getJson('/device/pair-status?'.http_build_query([
        'device_uuid' => $device['device_uuid'],
        'poll_secret' => 'wrong-secret',
    ]))->assertOk()->assertJson(['status' => 'unknown']);

    // And the real device still gets its token.
    $this->getJson('/device/pair-status?'.http_build_query([
        'device_uuid' => $device['device_uuid'],
        'poll_secret' => $device['poll_secret'],
    ]))->assertOk()->assertJson(['status' => 'paired']);
});

test('the token is handed over exactly once and the request is destroyed', function () {
    $store = Store::factory()->create();
    $device = $this->postJson('/device/register')->json();
    $screen = Screen::factory()->unpaired()->create(['store_id' => $store->id]);
    app(DevicePairing::class)->claim($device['code'], $screen);

    $query = http_build_query([
        'device_uuid' => $device['device_uuid'],
        'poll_secret' => $device['poll_secret'],
    ]);

    $first = $this->getJson("/device/pair-status?{$query}")->assertOk();
    expect($first->json('token'))->toBeString()->toHaveLength(64);
    $this->assertDatabaseCount('pairing_requests', 0);

    // A replayed poll gets nothing.
    $this->getJson("/device/pair-status?{$query}")->assertOk()->assertJson(['status' => 'unknown']);
});

test('an expired code reports itself so the TV can fetch a new one', function () {
    $device = $this->postJson('/device/register')->json();
    PairingRequest::query()->update(['expires_at' => now()->subMinute()]);

    $this->getJson('/device/pair-status?'.http_build_query([
        'device_uuid' => $device['device_uuid'],
        'poll_secret' => $device['poll_secret'],
    ]))->assertOk()->assertJson(['status' => 'expired']);
});

test('an expired code can no longer be claimed', function () {
    $store = Store::factory()->create();
    $device = $this->postJson('/device/register')->json();
    PairingRequest::query()->update(['expires_at' => now()->subMinute()]);

    $screen = Screen::factory()->unpaired()->create(['store_id' => $store->id]);

    expect(app(DevicePairing::class)->claim($device['code'], $screen))->toBeFalse();
    expect($screen->fresh()->token_hash)->toBeNull();
});

/* ── Authenticated device endpoints ────────────────────────────────────── */

test('the playlist and heartbeat refuse a device with no token', function () {
    $this->getJson('/device/playlist')->assertUnauthorized();
    $this->postJson('/device/heartbeat')->assertUnauthorized();
});

test('a wrong token is refused', function () {
    Screen::factory()->create(['store_id' => Store::factory()]);

    $this->withHeader('Authorization', 'Bearer not-a-real-token')
        ->getJson('/device/playlist')->assertUnauthorized();
});

test('a paired screen gets an envelope it can already build against', function () {
    $screen = Screen::factory()->withToken('device-token-abc')->create([
        'store_id' => Store::factory(),
        'name' => 'Counter TV',
        'orientation' => 'portrait',
    ]);

    $response = $this->withHeader('Authorization', 'Bearer device-token-abc')
        ->getJson('/device/playlist')->assertOk();

    $response->assertJsonStructure(['screen' => ['id', 'name', 'orientation'], 'server_time', 'version', 'items']);
    expect($response->json('screen.name'))->toBe('Counter TV');
    expect($response->json('screen.orientation'))->toBe('portrait');
    // Nothing is on this screen's playlist yet, so the player shows "No content".
    expect($response->json('items'))->toBe([]);
    expect($response->json('screen.id'))->toBe($screen->id);
});

test('a heartbeat marks the screen online', function () {
    $screen = Screen::factory()->offline()->withToken('tok')->create(['store_id' => Store::factory()]);
    expect($screen->is_online)->toBeFalse();

    $this->withHeader('Authorization', 'Bearer tok')->postJson('/device/heartbeat')->assertOk();

    expect($screen->fresh()->is_online)->toBeTrue();
});

test('deleting a screen locks its device out on the very next request', function () {
    $screen = Screen::factory()->withToken('tok')->create(['store_id' => Store::factory()]);

    $this->withHeader('Authorization', 'Bearer tok')->getJson('/device/playlist')->assertOk();

    $screen->delete();

    // The player treats this as "wipe the token and show a code again".
    $this->withHeader('Authorization', 'Bearer tok')->getJson('/device/playlist')->assertUnauthorized();
});

test('re-pairing rotates the token so the old device stops playing', function () {
    $store = Store::factory()->create();
    $screen = Screen::factory()->withToken('old-token')->create(['store_id' => $store->id]);

    $device = $this->postJson('/device/register')->json();
    expect(app(DevicePairing::class)->claim($device['code'], $screen))->toBeTrue();

    $this->withHeader('Authorization', 'Bearer old-token')->getJson('/device/playlist')->assertUnauthorized();

    $newToken = $this->getJson('/device/pair-status?'.http_build_query([
        'device_uuid' => $device['device_uuid'],
        'poll_secret' => $device['poll_secret'],
    ]))->json('token');

    $this->withHeader('Authorization', "Bearer {$newToken}")->getJson('/device/playlist')->assertOk();
});

test('the player page is reachable without logging in', function () {
    $this->get('/player')->assertOk()->assertSee('Add Screen', false);
});

/* ── Telling a returning device where to go ────────────────────────────── */

test('a device nobody has seen before is sent to Add Screen', function () {
    $this->postJson('/device/register')->assertOk()->assertJson(['known_device' => false]);
});

test('a device that already belongs to a screen is sent to Replace device', function () {
    $store = Store::factory()->create();
    $device = $this->postJson('/device/register')->json();
    $screen = Screen::factory()->unpaired()->create(['store_id' => $store->id, 'name' => 'Counter TV']);
    app(DevicePairing::class)->claim($device['code'], $screen);

    // The television lost its token — a bad deploy, a 401, cleared site data —
    // but it still knows its own uuid, so it asks again with the same one.
    $again = $this->postJson('/device/register', ['device_uuid' => $device['device_uuid']])->assertOk();

    // Getting this wrong is what costs the shop: told to Add Screen, the owner
    // makes a SECOND screen and the real one is stranded with all its playlist.
    $again->assertJson(['known_device' => true]);

    // The name never travels. This endpoint is open, and holding a uuid is no
    // reason to learn what a shop calls its televisions.
    expect($again->json())->not->toHaveKey('screen_name');
    expect(json_encode($again->json()))->not->toContain('Counter TV');
});

test('once the screen is gone the device is a stranger again', function () {
    $store = Store::factory()->create();
    $device = $this->postJson('/device/register')->json();
    $screen = Screen::factory()->unpaired()->create(['store_id' => $store->id]);
    app(DevicePairing::class)->claim($device['code'], $screen);

    $screen->delete();

    // There is nothing left to replace, so Add Screen is the honest instruction.
    $this->postJson('/device/register', ['device_uuid' => $device['device_uuid']])
        ->assertOk()
        ->assertJson(['known_device' => false]);
});

test('a device replaced by another one is a stranger again too', function () {
    $store = Store::factory()->create();
    $screen = Screen::factory()->unpaired()->create(['store_id' => $store->id]);
    $pairing = app(DevicePairing::class);

    $first = $this->postJson('/device/register')->json();
    $pairing->claim($first['code'], $screen);

    // A different television takes that screen over.
    $second = $this->postJson('/device/register')->json();
    $pairing->claim($second['code'], $screen);

    // The old device really is a new device now: the screen it used to be is
    // somebody else's, so it has nothing to replace.
    $this->postJson('/device/register', ['device_uuid' => $first['device_uuid']])
        ->assertOk()
        ->assertJson(['known_device' => false]);

    $this->postJson('/device/register', ['device_uuid' => $second['device_uuid']])
        ->assertOk()
        ->assertJson(['known_device' => true]);
});

/* ── One code, one screen ──────────────────────────────────────────────── */

test('a code answers to exactly one screen — the second claim is refused', function () {
    $store = Store::factory()->create();
    // Unpaired on purpose: these are screens the owner just created and is
    // about to point at a TV. The factory default is an ALREADY paired screen.
    $first = Screen::factory()->unpaired()->create(['store_id' => $store->id, 'name' => 'Counter TV']);
    $second = Screen::factory()->unpaired()->create(['store_id' => $store->id, 'name' => 'Window TV']);

    $pairing = app(DevicePairing::class);
    $code = $pairing->register()['code'];

    expect($pairing->claim($code, $first))->toBeTrue();

    // The TV is already spoken for. The second attempt must be told so, not
    // handed a token of its own — only one token can be stored, so a screen that
    // "succeeded" here would carry a hash nobody holds and never come online.
    expect($pairing->claim($code, $second))->toBeFalse();

    expect($first->fresh()->isPaired())->toBeTrue();
    expect($second->fresh()->isPaired())->toBeFalse();

    // And the token on file is the FIRST screen's, so the device that polls gets
    // the screen the owner actually paired.
    $claimedToken = PairingRequest::where('code', $code)->value('claimed_token');
    expect($pairing->screenForToken($claimedToken)?->id)->toBe($first->id);
});

test('a screen left unpaired by a refused claim keeps its settings', function () {
    $store = Store::factory()->create();
    $taken = Screen::factory()->unpaired()->create(['store_id' => $store->id]);
    $loser = Screen::factory()->unpaired()->create([
        'store_id' => $store->id, 'name' => 'Window TV', 'orientation' => 'portrait',
    ]);

    $pairing = app(DevicePairing::class);
    $code = $pairing->register()['code'];
    $pairing->claim($code, $taken);

    $pairing->claim($code, $loser);

    // A refusal must leave the row alone rather than half-writing it: the owner
    // fixes this by pairing again, and their name and orientation should still
    // be waiting for them.
    $fresh = $loser->fresh();
    expect($fresh->name)->toBe('Window TV');
    expect($fresh->orientation)->toBe('portrait');
    expect($fresh->paired_at)->toBeNull();
    expect($fresh->device_uuid)->toBeNull();
});
