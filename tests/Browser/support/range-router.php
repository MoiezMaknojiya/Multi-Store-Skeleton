<?php

/*
|--------------------------------------------------------------------------
| A router for PHP's built-in web server that answers range requests
|--------------------------------------------------------------------------
|
| Test-only, never deployed. nginx answers `Range: bytes=…` for a file in public/ with a 206 and just those
| bytes; PHP's built-in server ignores the header and always sends the file whole. The browser tests that
| watch the player's worker fetch a big file in pieces (docs/AD-BUILDER-SPEC.md §15) run their own server
| with this router, so the pieces are real. Everything else goes to Laravel's own router, as `php artisan
| serve` does — run it with public/ as the working directory.
|
*/

$root = getcwd();
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$file = $root.$path;
$range = $_SERVER['HTTP_RANGE'] ?? '';

if ($path !== '/' && ! str_contains($path, '..') && is_file($file) && preg_match('/^bytes=(\d+)-(\d*)$/', $range, $match) === 1) {
    $size = filesize($file);
    $start = (int) $match[1];
    $end = $match[2] === '' ? $size - 1 : min((int) $match[2], $size - 1);

    if ($start >= $size || $start > $end) {
        http_response_code(416);
        header("Content-Range: bytes */{$size}");

        return true;
    }

    $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'html' => 'text/html; charset=utf-8', 'js' => 'text/javascript', 'css' => 'text/css'];

    http_response_code(206);
    header('Content-Type: '.($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    header("Content-Range: bytes {$start}-{$end}/{$size}");
    header('Content-Length: '.($end - $start + 1));
    header('Accept-Ranges: bytes');

    $handle = fopen($file, 'rb');
    fseek($handle, $start);
    echo fread($handle, $end - $start + 1);
    fclose($handle);

    // Said on the server's own error stream (never to a log file php.ini may name), where the test counts the pieces.
    file_put_contents('php://stderr', "range {$start}-{$end}/{$size} {$path}\n");

    return true;
}

return require $root.'/../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
