<?php

use App\Http\Controllers\Device\DeviceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Device routes  (prefix: /device)
|--------------------------------------------------------------------------
|
| The only machine-facing surface in the app. A TV has no session and no
| cookie, so these routes are stateless and carry no CSRF: identity comes from
| the token handed over at pairing.
|
| Two of them must be reachable by a device that has nothing yet, so they are
| open — but "open" means "no login", not "no protection": both are throttled,
| and the poll additionally demands the secret issued at registration.
|
| Everything that returns real content sits behind device.token.
|
| Every throttle here is a NAMED limiter (see AppServiceProvider) and never the
| bare `throttle:n,1` form. That form keys on domain and IP alone for a request
| with no logged-in user, so every route using it shares one counter — which
| meant a shop's own TVs could spend the signup form's budget and lock a new
| screen out of pairing. The named limiters below count per device instead.
|
*/

// A screen with no token asks for a pairing code. Throttled because this is the
// one endpoint anyone on the internet can call cold — but not so tightly that a
// shop with several TVs behind one router locks itself out. Guessing a code is
// not the threat this defends against (32^6 possibilities, alive for 15 minutes);
// filling the table is.
Route::post('/register', [DeviceController::class, 'register'])
    ->middleware('throttle:device-register')
    ->name('device.register');

// "Has my code been claimed?" — needs device_uuid + the poll secret from register,
// so a guessed uuid alone cannot steal a freshly minted token.
Route::get('/pair-status', [DeviceController::class, 'pairStatus'])
    ->middleware('throttle:device-pair')
    ->name('device.pair-status');

// Everything below is a paired screen speaking.
// Throttle FIRST, then the token: a wrong or missing token must be rate limited
// too, or the one endpoint that answers 401 becomes the free one to hammer.
Route::middleware(['throttle:device-api', 'device.token'])->group(function () {
    Route::get('/playlist', [DeviceController::class, 'playlist'])->name('device.playlist');
    Route::post('/heartbeat', [DeviceController::class, 'heartbeat'])->name('device.heartbeat');
});
