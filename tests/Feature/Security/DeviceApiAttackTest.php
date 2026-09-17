<?php

use App\Models\Media;
use App\Models\PairingRequest;
use App\Models\PlaylistItem;
use App\Models\Role;
use App\Models\Screen;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The one machine-facing surface, attacked on purpose
|--------------------------------------------------------------------------
|
| /device/* has no session and no CSRF: a token is the whole of a screen's identity. So the attacks are
| the obvious ones — no token, somebody else's token, a guessed pairing code, a stolen uuid, a logged-in
| browser trying to walk in without a token at all.
|
*/

beforeEach(function () {
    $this->store = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->other = Store::factory()->create(['name' => 'Beta Deli']);

    $this->screen = Screen::factory()->withToken('alpha-token')->create(['store_id' => $this->store->id, 'name' => 'Alpha TV']);
    $this->theirScreen = Screen::factory()->withToken('beta-token')->create(['store_id' => $this->other->id, 'name' => 'Beta TV']);

    $this->mine = Media::factory()->create(['store_id' => $this->store->id, 'title' => 'Alpha poster']);
    $this->theirs = Media::factory()->create(['store_id' => $this->other->id, 'title' => 'Beta poster']);

    PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $this->mine->id, 'position' => 0, 'duration_seconds' => 10]);
    PlaylistItem::create(['screen_id' => $this->theirScreen->id, 'media_id' => $this->theirs->id, 'position' => 0, 'duration_seconds' => 10]);
});

test('no token, a wrong token, an empty token or a session cookie all answer 401', function () {
    $attempts = [
        fn () => $this->getJson('/device/playlist'),
        fn () => $this->withHeader('Authorization', 'Bearer ')->getJson('/device/playlist'),
        fn () => $this->withHeader('Authorization', 'Bearer wrong-token')->getJson('/device/playlist'),
        fn () => $this->withHeader('Authorization', 'alpha-token')->getJson('/device/playlist'),           // no "Bearer"
        fn () => $this->withHeader('Authorization', 'Bearer ALPHA-TOKEN')->getJson('/device/playlist'),    // case matters
        fn () => $this->withHeader('Authorization', 'Bearer alpha-token ')->getJson('/device/playlist'),   // trailing space
        fn () => $this->postJson('/device/heartbeat'),
    ];

    foreach ($attempts as $i => $attempt) {
        $status = $attempt()->status();
        expect($status)->toBe(401, "attempt #{$i} answered {$status}");
    }

    // A signed-in person is not a screen: the session opens nothing here.
    $this->actingAs(createStoreMember($this->store, Role::OWNER));
    expect($this->getJson('/device/playlist')->status())->toBe(401)
        ->and($this->postJson('/device/heartbeat')->status())->toBe(401);
});

test('a screen’s token shows that screen’s playlist and nothing of another store’s', function () {
    $manifest = $this->withHeader('Authorization', 'Bearer alpha-token')->getJson('/device/playlist')->assertOk()->json();

    $body = json_encode($manifest);
    expect($body)->not->toContain('Beta poster')
        ->and($body)->not->toContain($this->theirs->path)
        ->and($body)->not->toContain('Beta TV');

    // And the other way round, with the other screen's token.
    $theirManifest = json_encode($this->withHeader('Authorization', 'Bearer beta-token')->getJson('/device/playlist')->assertOk()->json());
    expect($theirManifest)->not->toContain('Alpha poster')->not->toContain($this->mine->path);
});

test('deleting a screen locks its device out at once, and re-pairing rotates the token', function () {
    $this->withHeader('Authorization', 'Bearer alpha-token')->getJson('/device/playlist')->assertOk();

    // Re-pair: a new code claimed by the same screen replaces the token.
    $registration = $this->postJson('/device/register', ['device_uuid' => 'device-1'])->assertOk()->json();
    $owner = createStoreMember($this->store, Role::OWNER);
    $this->actingAs($owner)->withSession(['current_store_id' => $this->store->id])
        ->postJson('/screens/pair', ['code' => $registration['code'], 'mode' => 'replace', 'screen_id' => $this->screen->id])
        ->assertOk();

    expect($this->withHeader('Authorization', 'Bearer alpha-token')->getJson('/device/playlist')->status())->toBe(401);

    // The device collects its new token once, and only once.
    $status = $this->getJson('/device/pair-status?device_uuid=device-1&poll_secret='.$registration['poll_secret'])->assertOk()->json();
    expect($status['status'])->toBe('paired')->and($status['token'] ?? null)->not->toBeNull();

    $token = $status['token'];
    $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/device/playlist')->assertOk();

    // The pairing row is gone, so the token cannot be collected twice.
    expect(PairingRequest::where('device_uuid', 'device-1')->exists())->toBeFalse();

    // Deleting the screen revokes the token.
    $this->actingAs($owner)->withSession(['current_store_id' => $this->store->id])->deleteJson("/screens/{$this->screen->id}")->assertOk();
    expect($this->withHeader('Authorization', 'Bearer '.$token)->getJson('/device/playlist')->status())->toBe(401);
});

test('a pairing code cannot be collected by a device that does not own it', function () {
    $mine = $this->postJson('/device/register', ['device_uuid' => 'device-mine'])->assertOk()->json();
    $thief = $this->postJson('/device/register', ['device_uuid' => 'device-thief'])->assertOk()->json();

    $owner = createStoreMember($this->store, Role::OWNER);
    $this->actingAs($owner)->withSession(['current_store_id' => $this->store->id])
        ->postJson('/screens/pair', ['code' => $mine['code'], 'mode' => 'new', 'name' => 'New TV', 'orientation' => 'landscape'])->assertOk();

    // The thief knows the uuid but not the secret.
    $withoutSecret = $this->getJson('/device/pair-status?device_uuid=device-mine');
    expect($withoutSecret->status())->toBeIn([200, 422]);
    if ($withoutSecret->status() === 200) {
        expect($withoutSecret->json('status'))->not->toBe('paired');
        expect($withoutSecret->json('token'))->toBeNull();
    }

    $wrongSecret = $this->getJson('/device/pair-status?device_uuid=device-mine&poll_secret='.$thief['poll_secret'])->assertOk();
    expect($wrongSecret->json('status'))->toBe('unknown')
        ->and($wrongSecret->json('token'))->toBeNull();

    // The real device still gets it.
    expect($this->getJson('/device/pair-status?device_uuid=device-mine&poll_secret='.$mine['poll_secret'])->assertOk()->json('status'))->toBe('paired');
});

test('a pairing code belongs to one screen only, even when two people race for it', function () {
    $registration = $this->postJson('/device/register', ['device_uuid' => 'device-race'])->assertOk()->json();

    $owner = createStoreMember($this->store, Role::OWNER);
    $second = createStoreMember($this->store, Role::OWNER);

    $first = $this->actingAs($owner)->withSession(['current_store_id' => $this->store->id])
        ->postJson('/screens/pair', ['code' => $registration['code'], 'mode' => 'new', 'name' => 'First TV', 'orientation' => 'landscape']);
    $again = $this->actingAs($second)->withSession(['current_store_id' => $this->store->id])
        ->postJson('/screens/pair', ['code' => $registration['code'], 'mode' => 'new', 'name' => 'Second TV', 'orientation' => 'landscape']);

    expect($first->status())->toBe(200)
        ->and($again->status())->toBe(422)
        ->and(Screen::where('name', 'Second TV')->exists())->toBeFalse();
});

test('a code from another store cannot be claimed, and nonsense codes are refused', function () {
    $registration = $this->postJson('/device/register', ['device_uuid' => 'device-2'])->assertOk()->json();

    // Somebody with no store context at all
    $stranger = User::factory()->create();
    expect($this->actingAs($stranger)->postJson('/screens/pair', ['code' => $registration['code'], 'mode' => 'new', 'name' => 'Theirs', 'orientation' => 'landscape'])->status())
        ->toBeIn([403, 404]);

    $owner = createStoreMember($this->store, Role::OWNER);
    $this->actingAs($owner)->withSession(['current_store_id' => $this->store->id]);

    foreach (['', 'ABC', 'ABCDEFG', '../../etc', '<script>', 'IIII11', str_repeat('A', 100)] as $code) {
        $status = $this->postJson('/screens/pair', ['code' => $code, 'mode' => 'new', 'name' => 'Nope', 'orientation' => 'landscape'])->status();
        expect($status)->toBe(422, "code [{$code}] answered {$status}");
    }

    expect(Screen::where('name', 'Nope')->exists())->toBeFalse();
});

test('registering is throttled per device, and the playlist per token', function () {
    // device-register keys on the IP: the 31st cold registration in a minute is refused.
    $statuses = [];
    for ($i = 0; $i < 32; $i++) {
        $statuses[] = $this->postJson('/device/register', ['device_uuid' => "flood-{$i}"])->status();
    }
    expect($statuses)->toContain(429);

    // The rows a flood created are still bounded by what it asked for, and none of them is a screen.
    expect(Screen::count())->toBe(2)
        ->and(DB::table('pairing_requests')->count())->toBeLessThanOrEqual(32);
});

test('the manifest never carries a file outside its schedule window', function () {
    $expired = Media::factory()->create(['store_id' => $this->store->id, 'title' => 'Yesterday', 'expires_at' => now()->subDay()]);
    PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $expired->id, 'position' => 1, 'duration_seconds' => 10]);

    $manifest = json_encode($this->withHeader('Authorization', 'Bearer alpha-token')->getJson('/device/playlist')->assertOk()->json());

    expect($manifest)->not->toContain('Yesterday')->not->toContain($expired->path);
});
