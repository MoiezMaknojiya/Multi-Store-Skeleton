<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| A folder in public/ must never share its name with a route (caused a real bug)
|--------------------------------------------------------------------------
|
| The web server hands a request to Laravel only when no FILE or FOLDER of that name exists in public/ —
| Apache's rewrite rule says `!-d`, and `php artisan serve` does the same. The Ad Builder's runtime once
| lived in public/builder/, and from that moment `/builder` (the Ads tab) and `POST /builder` (saving a
| new ad) answered with the folder instead of the app: a 404 under `php artisan serve`, a directory
| listing or a 403 under Apache — while every feature test, which never goes through a web server,
| still passed. Only a browser test caught it.
|
*/

test('no folder or file in public/ is named like the first part of a route', function () {
    $segments = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => explode('/', trim($route->uri(), '/'))[0])
        ->reject(fn (string $segment) => $segment === '' || str_starts_with($segment, '{'))
        ->unique()
        ->values();

    $collisions = $segments->filter(fn (string $segment) => file_exists(public_path($segment)))->values()->all();

    expect($segments)->not->toBeEmpty()
        ->and($collisions)->toBe([]);
});
