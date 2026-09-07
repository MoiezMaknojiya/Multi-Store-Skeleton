<?php

use App\Providers\AppServiceProvider;

test('booting behind a reverse proxy that forwards https does not crash', function () {
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

    $boot = fn () => (new AppServiceProvider(app()))->boot();

    try {
        expect($boot)->not->toThrow(Throwable::class);
    } finally {
        unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
    }
});
