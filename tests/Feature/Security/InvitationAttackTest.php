<?php

use App\Models\Invitation;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Invitation links, attacked on purpose
|--------------------------------------------------------------------------
|
| The link is the one door into a store, so every way of pushing at it: a token that was tampered with,
| one that expired, one already used, one opened by the wrong person, and one that would cross the wall
| between the platform team and a store.
|
*/

beforeEach(function () {
    $this->store = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->other = Store::factory()->create(['name' => 'Beta Deli']);
    $this->owner = createStoreMember($this->store, Role::OWNER);
});

test('a token that was guessed, tampered with or truncated opens nothing', function () {
    [$invitation, $token] = Invitation::open($this->store, 'new@example.com', Role::starter(Role::STAFF), $this->owner);

    $attempts = [
        'wrong length' => 'abc',
        'right length, wrong token' => str_repeat('a', 64),
        'one character changed' => substr($token, 0, -1).(str_ends_with($token, 'a') ? 'b' : 'a'),
        'truncated' => substr($token, 0, 60),
        'with a path' => $token.'/../../',
        'url encoded nonsense' => '%2e%2e%2f'.$token,
        'sql-ish' => "' OR 1=1 --",
    ];

    foreach ($attempts as $label => $bad) {
        // The route matches 64 alphanumerics and nothing else, so anything malformed never reaches
        // the controller at all; a well-formed guess lands on the "invalid link" page.
        $response = $this->get('/invitations/'.urlencode($bad));
        strlen($bad) === 64 && ctype_alnum($bad)
            ? $response->assertViewIs('invitations.invalid')
            : $response->assertNotFound();

        $status = $this->post('/invitations/'.urlencode($bad).'/register', [
            'first_name' => 'A', 'last_name' => 'B', 'phone' => '5550000000',
            'password' => 'Str0ng-Password!', 'password_confirmation' => 'Str0ng-Password!',
        ])->status();
        expect($status)->toBeIn([302, 403, 404, 419, 422], "{$label} answered {$status}");
    }

    expect(User::where('email', 'new@example.com')->exists())->toBeFalse()
        ->and(Invitation::find($invitation->id))->not->toBeNull()
        ->and(DB::table('store_user')->where('store_id', $this->store->id)->count())->toBe(1);
});

test('an expired link cannot be used, and the account it was for is never created', function () {
    [$invitation, $token] = Invitation::open($this->store, 'late@example.com', Role::starter(Role::STAFF), $this->owner);
    $invitation->update(['expires_at' => now()->subDay()]);

    $this->get("/invitations/{$token}")->assertViewIs('invitations.invalid');
    $this->post("/invitations/{$token}/register", [
        'first_name' => 'Late', 'last_name' => 'Comer', 'phone' => '5550000000',
        'password' => 'Str0ng-Password!', 'password_confirmation' => 'Str0ng-Password!',
    ]);

    expect(User::where('email', 'late@example.com')->exists())->toBeFalse()
        ->and(DB::table('store_user')->where('store_id', $this->store->id)->count())->toBe(1);
});

test('one link is used exactly once, however many times it is replayed', function () {
    [, $token] = Invitation::open($this->store, 'joiner@example.com', Role::starter(Role::STAFF), $this->owner);

    $this->post("/invitations/{$token}/register", [
        'first_name' => 'Jo', 'last_name' => 'Iner', 'phone' => '5550000000',
        'password' => 'Str0ng-Password!', 'password_confirmation' => 'Str0ng-Password!',
    ])->assertRedirect();

    $joiner = User::where('email', 'joiner@example.com')->sole();
    expect(DB::table('store_user')->where('user_id', $joiner->id)->count())->toBe(1);

    // Replay it: the row is gone, so the link is simply invalid now.
    auth()->logout();
    $this->get("/invitations/{$token}")->assertViewIs('invitations.invalid');
    $this->post("/invitations/{$token}/accept");
    $this->post("/invitations/{$token}/register", [
        'first_name' => 'Jo', 'last_name' => 'Iner', 'phone' => '5550000000',
        'password' => 'Str0ng-Password!', 'password_confirmation' => 'Str0ng-Password!',
    ]);

    expect(User::where('email', 'joiner@example.com')->count())->toBe(1)
        ->and(DB::table('store_user')->where('user_id', $joiner->id)->count())->toBe(1);
});

test('a link is not a way to join as somebody else', function () {
    $stranger = createStoreMember($this->other, Role::STAFF);
    [$invitation, $token] = Invitation::open($this->store, 'wanted@example.com', Role::starter(Role::STAFF), $this->owner);

    // Signed in with another address: told so, and nothing happens.
    $this->actingAs($stranger)->post("/invitations/{$token}/accept");

    expect(DB::table('store_user')->where('user_id', $stranger->id)->where('store_id', $this->store->id)->exists())->toBeFalse()
        ->and(Invitation::find($invitation->id))->not->toBeNull();

    // And the register form cannot be used to claim it while signed in as somebody else.
    $this->actingAs($stranger)->post("/invitations/{$token}/register", [
        'first_name' => 'S', 'last_name' => 'T', 'phone' => '5550000000',
        'password' => 'Str0ng-Password!', 'password_confirmation' => 'Str0ng-Password!',
    ]);

    expect(User::where('email', 'wanted@example.com')->exists())->toBeFalse();
});

test('the two tiers cannot be crossed with an invitation', function () {
    $primary = createSuperAdmin();
    $platformRole = Role::create(['name' => 'Support', 'is_global' => true]);

    // A store member cannot be invited to the platform team…
    $member = createStoreMember($this->store, Role::STAFF);
    $this->actingAs($primary)->postJson('/users/invitations', ['email' => $member->email, 'role_id' => $platformRole->id])
        ->assertStatus(422);

    // …and a platform account cannot be invited into a store.
    $support = createPlatformUser(['user-view'], 'Support Staff');
    $this->actingAs($this->owner)->withSession(['current_store_id' => $this->store->id])
        ->postJson('/members/invitations', ['email' => $support->email, 'role_id' => Role::starter(Role::STAFF)->id])
        ->assertStatus(422);

    // A platform role cannot be offered from inside a store, nor a store role from the platform's form.
    $this->actingAs($this->owner)->withSession(['current_store_id' => $this->store->id])
        ->postJson('/members/invitations', ['email' => 'x@example.com', 'role_id' => $platformRole->id])
        ->assertStatus(422);
    $this->actingAs($primary)->postJson('/users/invitations', ['email' => 'y@example.com', 'role_id' => Role::starter(Role::STAFF)->id])
        ->assertStatus(422);

    expect(DB::table('store_user')->where('user_id', $member->id)->where('store_id', 0)->exists())->toBeFalse()
        ->and(DB::table('store_user')->where('user_id', $support->id)->where('store_id', $this->store->id)->exists())->toBeFalse()
        ->and(Invitation::whereIn('email', ['x@example.com', 'y@example.com'])->exists())->toBeFalse();
});

test('an invitation cannot be reached, resent or revoked from another store', function () {
    $theirs = Invitation::factory()->create(['store_id' => $this->other->id]);
    $attacker = createStoreUser($this->store, ['member-view', 'member-invite'], 'Nosy');

    $this->actingAs($attacker)->withSession(['current_store_id' => $this->store->id]);

    $this->deleteJson("/members/invitations/{$theirs->id}")->assertNotFound();
    $this->postJson("/members/invitations/{$theirs->id}/resend")->assertNotFound();

    $listed = collect($this->getJson('/members/data')->assertOk()->json('invitations'))->pluck('id');
    expect($listed)->not->toContain($theirs->id)
        ->and(Invitation::find($theirs->id))->not->toBeNull();
});

test('brute forcing the link is throttled', function () {
    // Twenty well-formed guesses a minute per visitor (the `invitation-response` limiter), then the
    // door is shut. A 64-character token is unguessable anyway — this is the belt to that braces.
    $statuses = [];
    for ($i = 0; $i < 25; $i++) {
        $statuses[] = $this->get('/invitations/'.str_pad((string) $i, 64, 'a', STR_PAD_LEFT))->status();
    }

    expect($statuses)->toContain(429)
        ->and(array_slice($statuses, 0, 20))->not->toContain(429);
});
