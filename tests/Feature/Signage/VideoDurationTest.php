<?php

use App\Services\VideoDuration;
use Tests\Support\VideoFiles;

/*
|--------------------------------------------------------------------------
| A video's length, read from its bytes
|--------------------------------------------------------------------------
|
| The server measures every upload itself (owner's rule, 2026-09-28: 5 minutes in a library or a channel, 60
| seconds in the ads network), because the browser's word is only a request someone can write by hand. These
| are the layouts real files come in, and the ones a crafted file would try.
|
*/

function lengthOf(string $bytes): ?float
{
    $path = tempnam(sys_get_temp_dir(), 'length-');
    file_put_contents($path, $bytes);

    try {
        return (new VideoDuration)->seconds($path);
    } finally {
        @unlink($path);
    }
}

test('an MP4 says its length in its movie header, index first or last, 32 or 64 bits', function (array $options) {
    expect(lengthOf(VideoFiles::mp4(299.5, $options)))->toEqualWithDelta(299.5, 0.01);
})->with([
    'fast start' => [[]],
    'index at the end, as a phone writes it' => [['moov_at_end' => true]],
    'version 1 headers' => [['version' => 1]],
    'a 64-bit mdat' => [['largesize_mdat' => true, 'moov_at_end' => true]],
    'an edit list' => [['edit_seconds' => 299.5]],
    'padded with a free box' => [['pad_to' => 200_000]],
]);

test('the longest of what an MP4 says is its length: a short header cannot hide a long track', function () {
    // The movie header says a minute; the track's own media runs an hour.
    expect(lengthOf(VideoFiles::mp4(3600, ['mvhd_seconds' => 60])))->toEqualWithDelta(3600, 0.01)
        // …and an audio track longer than the pictures counts too, as a browser counts it.
        ->and(lengthOf(VideoFiles::mp4(100, ['audio_seconds' => 400])))->toEqualWithDelta(400, 0.01);
});

test('an edit list decides what a track shows, as the browser plays it', function () {
    // Twenty minutes of media, of which the edit list presents two: the file lasts two minutes.
    expect(lengthOf(VideoFiles::mp4(1200, ['edit_seconds' => 120, 'mvhd_seconds' => 120])))->toEqualWithDelta(120, 0.01);
});

test('a fragmented MP4, as a browser records one, is measured by its fragments', function (string $style) {
    expect(lengthOf(VideoFiles::mp4(0, ['fragments' => [100, 100, 101], 'fragment_style' => $style])))->toEqualWithDelta(301, 0.5);
})->with(['sample durations and tfdt' => 'durations', "tfhd's default" => 'defaults', "trex's default" => 'trex']);

test("a fragmented MP4's mehd counts as well", function () {
    expect(lengthOf(VideoFiles::mp4(0, ['fragments' => [10], 'mehd_seconds' => 900])))->toEqualWithDelta(900, 0.01);
});

test('a WebM says its length in Info, or by its last block when it has no Duration', function (array $options) {
    expect(lengthOf(VideoFiles::webm(300, $options)))->toEqualWithDelta(300, 0.01);
})->with([
    'Duration' => [[]],
    'no Duration, as a browser records it' => [['with_duration' => false]],
    'unknown sizes, as a browser streams it' => [['with_duration' => false, 'unknown_sizes' => true]],
    'block groups with their own durations' => [['with_duration' => false, 'block_group' => true]],
    'padded with a Void' => [['pad_to' => 100_000]],
    'another timecode scale' => [['scale' => 10_000_000]],
]);

test('the longest of what a WebM says is its length: a short Duration cannot hide an hour of clusters', function () {
    expect(lengthOf(VideoFiles::webm(3600, ['declared_seconds' => 30])))->toEqualWithDelta(3600, 0.01);
});

test('anything that is not a video it can read has no length at all', function (string $bytes) {
    expect(lengthOf($bytes))->toBeNull();
})->with([
    'empty' => [''],
    'too short' => ["\x00\x00\x00"],
    'a picture' => ["\x89PNG\r\n\x1a\n".str_repeat("\0", 64)],
    'text' => [str_repeat('not a video ', 100)],
    'a PHP file named .mp4' => ['<?php echo "hi"; ?>'.str_repeat(' ', 100)],
    'an MP4 with no moov' => [VideoFiles::box('ftyp', 'isom', pack('N', 0), 'isom').VideoFiles::box('mdat', str_repeat("\0", 64))],
    'a box longer than the file' => [VideoFiles::box('ftyp', 'isom', pack('N', 0), 'isom').pack('N', 999_999).'moov'.str_repeat("\0", 32)],
    'a box shorter than its own header' => [VideoFiles::box('ftyp', 'isom', pack('N', 0), 'isom').pack('N', 4).'moov'.str_repeat("\0", 32)],
    'a moov cut short' => [substr(VideoFiles::mp4(60), 0, -20)],
    'a box type that is not text' => [VideoFiles::box('ftyp', 'isom', pack('N', 0), 'isom').pack('N', 16)."\x00\x01\x02\x03".str_repeat("\0", 8)],
    'a movie with no timescale' => [VideoFiles::box('ftyp', 'isom', pack('N', 0), 'isom')
        .VideoFiles::box('moov', VideoFiles::fullBox('mvhd', 0, 0, pack('N4', 0, 0, 0, 60), str_repeat("\0", 80)))],
    'a WebM with no segment' => [substr(VideoFiles::webm(10), 0, 40)],
    'a WebM header of all ones' => ["\x1A\x45\xDF\xA3".str_repeat("\xFF", 64)],
]);

test('a 64-bit length past 2^63 is a broken file, never a short one', function () {
    // PHP reads such a number as negative; summed in, it would shrink an hour to nothing.
    $negative = VideoFiles::box('ftyp', 'isom', pack('N', 0), 'isom')
        .VideoFiles::box('moov', VideoFiles::fullBox('mvhd', 1, 0, pack('J', 0), pack('J', 0), pack('N', 1000), "\x80".str_repeat("\0", 7), str_repeat("\0", 80)));

    expect(lengthOf($negative))->toBeNull();
});

test('a file of millions of empty boxes is given up on, quickly', function () {
    $ftyp = VideoFiles::box('ftyp', 'isom', pack('N', 0), 'isom');
    $bytes = $ftyp.str_repeat(pack('N', 8).'free', 1_100_000);

    $started = microtime(true);
    expect(lengthOf($bytes))->toBeNull();
    expect(microtime(true) - $started)->toBeLessThan(10);
});

test('a WebM of millions of tiny elements is given up on, quickly', function () {
    // A segment of unknown size holding nothing but a million empty Voids.
    $webm = VideoFiles::element(0x1A45DFA3, VideoFiles::element(0x4282, 'webm'))
        ."\x18\x53\x80\x67\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF"
        .str_repeat("\xEC\x80", 1_100_000);

    $started = microtime(true);
    expect(lengthOf($webm))->toBeNull()
        ->and(microtime(true) - $started)->toBeLessThan(10);
});

test('one request reads a file once, however often it asks', function () {
    $path = tempnam(sys_get_temp_dir(), 'length-');
    file_put_contents($path, VideoFiles::mp4(42));
    $reader = new VideoDuration;

    try {
        expect($reader->seconds($path))->toEqualWithDelta(42, 0.01);

        // Changed on disk: the size moves, and the file is read again rather than remembered.
        file_put_contents($path, VideoFiles::mp4(43, ['mdat_bytes' => 2048]));
        expect($reader->seconds($path))->toEqualWithDelta(43, 0.01);
    } finally {
        @unlink($path);
    }
});

test("PHP's own sniffer reads these test files as the videos they are", function () {
    $finfo = new finfo(FILEINFO_MIME_TYPE);

    expect($finfo->buffer(VideoFiles::mp4(10)))->toBe('video/mp4')
        ->and($finfo->buffer(VideoFiles::mp4(10, ['moov_at_end' => true])))->toBe('video/mp4')
        ->and($finfo->buffer(VideoFiles::webm(10)))->toBe('video/webm');
});
