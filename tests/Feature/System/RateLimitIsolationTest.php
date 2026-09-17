<?php

use App\Models\Screen;
use App\Models\Store;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| Rate limit isolation
|--------------------------------------------------------------------------
|
| These tests exist because of a real defect. Every route used the bare
| `throttle:n,1` form, which for a visitor with no session keys on the domain
| and IP ONLY — the route is not in the key — so all of them counted into one
| shared bucket per IP.
|
| A shop's screens and the owner's laptop sit behind one router, so they share
| one public IP. Each paired screen sends three requests a minute, which meant a
| handful of TVs could exhaust the signup form's budget and stop a new screen
| getting a pairing code, while the screens already playing carried on as normal
| and hid the problem.
|
| Each test below fails against the old shared-bucket setup.
|
*/

beforeEach(function () {
    RateLimiter::clear('');
    cache()->clear();
});

test('a shop full of screens cannot spend the signup form\'s budget', function () {
    $store = Store::factory()->create();
    Screen::factory()->withToken('shop-tv')->create(['store_id' => $store->id]);

    // Well past signup's limit of 10 a minute, all from the same IP — exactly
    // what a shop with a few TVs looks like from the outside.
    for ($i = 0; $i < 40; $i++) {
        $this->withHeader('Authorization', 'Bearer shop-tv')
            ->getJson('/device/playlist')
            ->assertOk();
    }

    // Someone on that connection can still reach the signup form.
    $this->post('/register', [])->assertStatus(302);
});

test('one screen hammering does not stop the screen next to it', function () {
    $store = Store::factory()->create();
    Screen::factory()->withToken('noisy-tv')->create(['store_id' => $store->id]);
    Screen::factory()->withToken('quiet-tv')->create(['store_id' => $store->id]);

    // The noisy one talks until it is cut off.
    $blocked = false;
    for ($i = 0; $i < 80; $i++) {
        if ($this->withHeader('Authorization', 'Bearer noisy-tv')->getJson('/device/playlist')->status() === 429) {
            $blocked = true;
            break;
        }
    }

    expect($blocked)->toBeTrue('the per-device limit never engaged');

    // The screen beside it, same shop, same IP, is untouched.
    $this->withHeader('Authorization', 'Bearer quiet-tv')
        ->getJson('/device/playlist')
        ->assertOk();
});

test('pairing screens each get their own budget, keyed on the device they claim to be', function () {
    // A screen waiting to be claimed polls every four seconds. Two screens
    // setting up side by side must not eat into each other.
    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/device/pair-status?device_uuid=tv-one&poll_secret=x');
    }

    $this->getJson('/device/pair-status?device_uuid=tv-one&poll_secret=x')->assertStatus(429);
    $this->getJson('/device/pair-status?device_uuid=tv-two&poll_secret=x')->assertOk();
});

test('a screen that keeps asking is eventually told to wait, and told for how long', function () {
    $store = Store::factory()->create();
    Screen::factory()->withToken('busy-tv')->create(['store_id' => $store->id]);

    for ($i = 0; $i < 60; $i++) {
        $this->withHeader('Authorization', 'Bearer busy-tv')->getJson('/device/playlist');
    }

    $response = $this->withHeader('Authorization', 'Bearer busy-tv')
        ->getJson('/device/playlist')
        ->assertStatus(429);

    // The player reads this to decide how long to back off instead of hammering.
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

test('a wrong token is throttled too, so 401 is not the cheap endpoint to hammer', function () {
    // The throttle sits in front of the token check for exactly this reason.
    $blocked = false;
    for ($i = 0; $i < 80; $i++) {
        if ($this->withHeader('Authorization', 'Bearer not-a-real-token')->getJson('/device/playlist')->status() === 429) {
            $blocked = true;
            break;
        }
    }

    expect($blocked)->toBeTrue('unauthenticated device traffic was never rate limited');
});

test('registering is still capped per IP, since a new screen has nothing else to be known by', function () {
    for ($i = 0; $i < 30; $i++) {
        $this->postJson('/device/register', [])->assertOk();
    }

    $this->postJson('/device/register', [])->assertStatus(429);
});
