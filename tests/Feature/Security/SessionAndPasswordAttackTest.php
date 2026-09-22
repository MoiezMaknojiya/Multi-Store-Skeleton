<?php

use App\Models\Channel;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Sessions, passwords and the counters that protect them
|--------------------------------------------------------------------------
|
| Guessing a password one endpoint at a time, keeping a session after the account changed underneath it,
| and walking back into a store the person was removed from.
|
*/

beforeEach(function () {
    $this->store = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->owner = createStoreUser($this->store, [...Permission::STORE, 'store-view', 'store-destroy', 'channel-view', 'channel-destroy'], 'Everything');
    $this->staff = createStoreMember($this->store, Role::STAFF);
});

test('five wrong passwords stop the sixth, whichever endpoints they were spread across', function () {
    $role = Role::create(['name' => 'Spare', 'store_id' => $this->store->id]);
    $channel = Channel::factory()->create(['store_id' => $this->store->id]);

    $this->actingAs($this->owner)->withSession(['current_store_id' => $this->store->id]);

    $attempts = [
        fn () => $this->deleteJson("/members/{$this->staff->id}", ['password' => 'wrong-1']),
        fn () => $this->deleteJson("/roles/{$role->id}", ['password' => 'wrong-2']),
        fn () => $this->deleteJson("/channels/{$channel->id}", ['password' => 'wrong-3']),
        fn () => $this->delete('/settings/store', ['confirm_name' => 'Alpha Mart', 'password' => 'wrong-4']),
        fn () => $this->deleteJson("/members/{$this->staff->id}", ['password' => 'wrong-5']),
    ];

    foreach ($attempts as $attempt) {
        $attempt();
    }

    // The sixth try is refused even though the password is right.
    $blocked = $this->deleteJson("/members/{$this->staff->id}", ['password' => 'password']);
    expect($blocked->status())->toBe(429);

    expect(roleKeyIn($this->staff, $this->store))->toBe(Role::STAFF)
        ->and(Role::find($role->id))->not->toBeNull()
        ->and(Channel::find($channel->id))->not->toBeNull()
        ->and(Store::find($this->store->id))->not->toBeNull();
});

test('one person’s wrong passwords never lock another person out', function () {
    // Removing somebody needs every permission their role holds (StoreTeam::mayManage), so this
    // person gets the whole store's permissions: the counter is what is under test here.
    $second = createStoreUser($this->store, [...Permission::STORE], 'Remover');
    $victim = createStoreMember($this->store, Role::STAFF);

    $this->actingAs($this->owner)->withSession(['current_store_id' => $this->store->id]);
    for ($i = 0; $i < 6; $i++) {
        $this->deleteJson("/members/{$victim->id}", ['password' => 'nope-'.$i]);
    }

    // The other person's counter is untouched.
    $this->actingAs($second)->withSession(['current_store_id' => $this->store->id]);
    $this->deleteJson("/members/{$victim->id}", ['password' => 'password'])->assertOk();

    expect(roleKeyIn($victim, $this->store))->toBeNull();
});

test('signing in is throttled, and a wrong password never says which half was wrong', function () {
    // Frozen, so "try again in … seconds" is one exact sentence.
    $this->freezeTime();

    // An address with no account and a real address with the wrong password read exactly alike.
    $this->from('/login')->post('/login', ['email' => 'nobody-here@example.com', 'password' => 'wrong'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);

    for ($i = 0; $i < 5; $i++) {
        $this->from('/login')->post('/login', ['email' => $this->staff->email, 'password' => 'wrong'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);
    }

    // Five wrong passwords (LoginRequest counts per address and visitor): the sixth try is refused even
    // though the password is right, and the sign-in page it lands back on says why.
    $this->from('/login')->followingRedirects()
        ->post('/login', ['email' => $this->staff->email, 'password' => 'password'])
        ->assertOk()
        ->assertSee(trans('auth.throttle', ['seconds' => 60, 'minutes' => 1]))
        ->assertDontSee(trans('auth.failed'));
    $this->assertGuest();

    // The account is still fine, and still signs in with its real password once the window passes.
    expect(Hash::check('password', $this->staff->fresh()->password))->toBeTrue();

    $this->travel(61)->seconds();
    $this->post('/login', ['email' => $this->staff->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($this->staff);
});

test('a member removed mid-session loses everything on the very next request', function () {
    $this->actingAs($this->staff)->withSession(['current_store_id' => $this->store->id]);
    $this->getJson('/screens/data')->assertOk();

    DB::table('store_user')->where('user_id', $this->staff->id)->where('store_id', $this->store->id)->delete();

    // The next request builds the person from the session's id afresh, so it reads the membership
    // that is no longer there. (A User instance memoises its permissions for the life of ONE request
    // — see User::$permissionNamesMemo — and a test would otherwise keep the first request's answer.)
    $this->actingAs($this->staff->fresh())->withSession(['current_store_id' => $this->store->id]);

    // The session still names the store, but the membership it was read through is gone — so every
    // permission goes with it, with no sign-out in between.
    foreach (['screens', 'media', 'dayparts', 'channels', 'members'] as $resource) {
        expect($this->getJson("/{$resource}/data")->status())->toBe(403, "/{$resource}/data");
    }

    $this->get('/members')->assertForbidden();
    $this->get('/settings/store')->assertForbidden();
});

test('a role stripped of its permissions mid-session stops opening the pages it opened', function () {
    $role = Role::firstWhere('name', 'Everything');
    $this->actingAs($this->owner)->withSession(['current_store_id' => $this->store->id]);
    $this->get('/members')->assertOk();

    $role->permissions()->detach();

    // A fresh instance of the same person: the page is shut now.
    $this->actingAs($this->owner->fresh())->withSession(['current_store_id' => $this->store->id]);
    $this->get('/members')->assertForbidden();
    $this->get('/screens')->assertForbidden();
    $this->getJson('/media/data')->assertForbidden();
});

test('signing in starts a brand-new session, so anything planted in the old one is worthless', function () {
    $cookie = config('session.cookie');
    $planted = Str::random(40);     // a well-formed session id the attacker already knows
    $admin = createSuperAdmin();

    // The attacker's half: a session under that id holding the way back into a super admin's account
    // that "Log in as" leaves behind — and the victim's browser made to carry its cookie.
    $this->withCookie($cookie, $planted)
        ->withSession(['impersonating_original_id' => $admin->id, 'impersonating_user_id' => $this->staff->id])
        ->get('/login')->assertOk();

    // The app really does take the id a cookie names; without that, nothing below would prove anything.
    expect(session()->getId())->toBe($planted);

    $response = $this->withCookie($cookie, $planted)
        ->post('/login', ['email' => $this->staff->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($this->staff);

    // A new id, handed back in the cookie, and the planted session is gone from the store — so the id the
    // attacker knows opens nothing, and the way back that was planted in it went with the sign-in.
    expect(session()->getId())->not->toBe($planted)
        ->and($response->getCookie($cookie)->getValue())->toBe(session()->getId())
        ->and(session()->getHandler()->read($planted))->toBe('')
        ->and(session('impersonating_original_id'))->toBeNull()
        ->and(session('impersonating_user_id'))->toBeNull();

    // So "stop" has nobody to hand over: the person stays who they signed in as.
    $this->withCookie($cookie, session()->getId())->post('/impersonate/stop')->assertRedirect(route('dashboard'));
    expect(auth()->id())->toBe($this->staff->id);
});

test('the password change and the reset link cannot be used to take an account over', function () {
    $this->actingAs($this->staff);

    // The current password is required, and a wrong one changes nothing.
    $this->from('/profile')->put('/password', [
        'current_password' => 'not-it', 'password' => 'Hijacked!2345', 'password_confirmation' => 'Hijacked!2345',
    ])->assertSessionHasErrors();
    expect(Hash::check('password', $this->staff->fresh()->password))->toBeTrue();

    // A reset token belongs to one email, and a forged one does nothing. Asked for as a guest,
    // because /reset-password is behind `guest` — a signed-in visitor is simply sent away.
    auth()->logout();
    $this->post('/reset-password', [
        'token' => str_repeat('a', 64), 'email' => $this->staff->email,
        'password' => 'Hijacked!2345', 'password_confirmation' => 'Hijacked!2345',
    ])->assertSessionHasErrors('email');
    expect(Hash::check('password', $this->staff->fresh()->password))->toBeTrue();

    // Nobody sets somebody else's password: there is no endpoint for it.
    $this->actingAs($this->owner)->withSession(['current_store_id' => $this->store->id]);
    expect($this->putJson("/members/{$this->staff->id}", ['password' => 'Hijacked!2345'])->status())->toBeIn([403, 404, 422])
        ->and($this->putJson("/users/{$this->staff->id}", ['password' => 'Hijacked!2345'])->status())->toBeIn([403, 404, 405])
        ->and(Hash::check('password', $this->staff->fresh()->password))->toBeTrue();
});

test('a platform account carrying a store id in its session gains nothing from it', function () {
    $support = createPlatformUser(['user-view', 'store-view'], 'Support');

    $this->actingAs($support)->withSession(['current_store_id' => $this->store->id]);

    $this->get('/members')->assertForbidden();
    $this->get('/settings/store')->assertNotFound();
    $this->postJson('/dayparts', ['name' => 'Theirs', 'start_time' => '07:00', 'end_time' => '08:00'])->assertForbidden();
    // What their platform role does give still works.
    $this->getJson('/users/data')->assertOk();
    $this->getJson('/stores/data')->assertOk();
});

test('the forgotten-password form is not a mail cannon, nor a way to read an address list quickly', function () {
    // Ten a minute per visitor (the `password-reset` limiter). The password broker has a throttle of
    // its own, but it only stops the SAME address being mailed twice within a minute — walking a
    // list sends one email per address (the answer itself is the same for every address).
    $statuses = [];
    for ($i = 0; $i < 13; $i++) {
        $statuses[] = $this->post('/forgot-password', ['email' => "someone{$i}@example.com"])->status();
    }

    expect($statuses)->toContain(429)
        ->and(array_slice($statuses, 0, 10))->not->toContain(429);

    // One counter covers the reset form as well, so a token cannot be walked from the same address.
    expect($this->post('/reset-password', [
        'token' => str_repeat('a', 64), 'email' => $this->staff->email,
        'password' => 'Str0ng-Password!', 'password_confirmation' => 'Str0ng-Password!',
    ])->status())->toBe(429);
});

test('the forgotten-password form never says whether an address has an account', function () {
    Notification::fake();

    // A real address and an invented one are answered identically (owner's decision, 2026-09-17),
    // so the form cannot be used to read back which addresses are accounts here.
    $real = $this->post('/forgot-password', ['email' => $this->staff->email]);
    $invented = $this->post('/forgot-password', ['email' => 'nobody-here@example.com']);

    expect($real->status())->toBe($invented->status())
        ->and(session('status'))->not->toBeNull();

    $real->assertSessionHasNoErrors();
    $invented->assertSessionHasNoErrors();

    // Asking twice in a row says the same thing too — being throttled by the broker is itself a
    // yes, because only a real account can be throttled.
    $again = $this->post('/forgot-password', ['email' => $this->staff->email]);
    $again->assertSessionHasNoErrors();

    // And only the real address is actually written to.
    Notification::assertSentTo($this->staff, ResetPasswordNotification::class);
    Notification::assertCount(1);

    // A malformed address is still a plain validation error: that gives nothing away.
    $this->post('/forgot-password', ['email' => 'not-an-email'])->assertSessionHasErrors('email');
});
