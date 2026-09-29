<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * A tus 1.0 client for the tests, speaking to /uploads the way the browser's Uppy does (docs/UPLOADS-SPEC.md):
 * open an upload, send it in chunks, ask how far it got, give it up. Every request carries its headers itself, so
 * nothing leaks into the next request of a test.
 */
final class Tus
{
    /** Upload-Metadata for $meta: "key base64,key base64". */
    public static function metadata(array $meta): string
    {
        return collect($meta)->map(fn (mixed $value, string $key) => $key.' '.base64_encode((string) $value))->implode(',');
    }

    /** POST /uploads, with the size and the metadata (and any header overridden). */
    public static function open(TestCase $test, int $size, array $meta, array $headers = []): TestResponse
    {
        return $test->call('POST', '/uploads', [], [], [], self::server([
            'Tus-Resumable' => '1.0.0',
            'Upload-Length' => (string) $size,
            'Upload-Metadata' => self::metadata($meta),
            ...$headers,
        ]));
    }

    /** The upload's id, from the Location a 201 carries. */
    public static function idOf(TestResponse $opened): string
    {
        $opened->assertCreated();

        return Str::afterLast((string) $opened->headers->get('Location'), '/');
    }

    /** PATCH one chunk at $offset. */
    public static function send(TestCase $test, string $id, int $offset, string $chunk, array $headers = []): TestResponse
    {
        return $test->call('PATCH', "/uploads/{$id}", [], [], [], self::server([
            'Tus-Resumable' => '1.0.0',
            'Content-Type' => 'application/offset+octet-stream',
            'Upload-Offset' => (string) $offset,
            'Content-Length' => (string) strlen($chunk),
            ...$headers,
        ]), $chunk);
    }

    /** HEAD: how far the upload got. */
    public static function ask(TestCase $test, string $id): TestResponse
    {
        return $test->call('HEAD', "/uploads/{$id}", [], [], [], self::server(['Tus-Resumable' => '1.0.0']));
    }

    /** DELETE: give it up. */
    public static function giveUp(TestCase $test, string $id): TestResponse
    {
        return $test->call('DELETE', "/uploads/{$id}", [], [], [], self::server(['Tus-Resumable' => '1.0.0']));
    }

    /** Open an upload of $bytes and send all of it in chunks of $chunk; its id. */
    public static function upload(TestCase $test, string $bytes, array $meta, int $chunk = 64 * 1024): string
    {
        $id = self::idOf(self::open($test, strlen($bytes), $meta));

        for ($offset = 0; $offset < strlen($bytes); $offset += $chunk) {
            self::send($test, $id, $offset, substr($bytes, $offset, $chunk))->assertNoContent();
        }

        return $id;
    }

    /**
     * Headers as the server variables a request is made of — Content-Type and Content-Length without the HTTP_
     * prefix, as PHP gives them.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private static function server(array $headers): array
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];

        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
        }

        return $server;
    }
}
