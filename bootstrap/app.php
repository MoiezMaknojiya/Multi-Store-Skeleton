<?php

use App\Http\Middleware\AuthenticateDevice;
use App\Http\Middleware\RejectMalformedText;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // The device API. Deliberately NOT routes/api.php and NOT in the web
            // group: a TV has no session and no CSRF token, so these routes get
            // their own stateless stack. See routes/device.php.
            Route::prefix('device')->group(base_path('routes/device.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Before anything else, on every route — the device API's too: text that is not UTF-8 is refused
        // at the door rather than met by whatever would choke on it (a JSON answer, MySQL, a limiter's key).
        $middleware->prepend(RejectMalformedText::class);

        $middleware->alias([
            'device.token' => AuthenticateDevice::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
