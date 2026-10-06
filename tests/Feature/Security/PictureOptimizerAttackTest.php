<?php

use App\Models\Media;
use App\Models\Organization;
use App\Services\PictureOptimizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\VideoFiles;

/*
|--------------------------------------------------------------------------
| Breaking the picture optimizer
|--------------------------------------------------------------------------
|
| The owner, 2026-10-05: "stress testing aur brute force ya out of box different type ki testing ki? break kar k
| dekha?" PictureOptimizer reads every uploaded picture's own structure in PHP — a JPEG's segments, a PNG's chunks,
| a WebP's RIFF — before GD opens it. Whatever a file claims, an upload ends stored (as it came, or lighter) or
| refused by the door's rules: never a 500, never a broken file stored, never a picture over 4K. Brute force by hand
| (30 000 broken pictures, two seeds) found one 500 — a header claiming two billion pixels a side — and a stress round
| (six big pictures at once on one CPU) found 2.1 GB held at one moment, which is why a picture waits for its turn.
|
*/

beforeEach(function () {
    Storage::fake('public');
    config(['signage.optimize_pictures' => true]);
});

function poaChunk(string $type, string $data): string
{
    return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
}

/** A PNG whose header claims $width × $height, carrying a little real data — a few hundred bytes. */
function poaClaimingPng(int $width, int $height): string
{
    $path = tempnam(sys_get_temp_dir(), 'poa');
    file_put_contents($path, "\x89PNG\r\n\x1a\n".poaChunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
        .poaChunk('IDAT', gzcompress(str_repeat("\0", 1000))).poaChunk('IEND', ''));

    return $path;
}

/** Uploaded to the Media page by an organization's person: the answer, and the row when one was made. */
function poaUpload(string $path, string $name, string $mime): array
{
    $organization = Organization::factory()->create();
    $person = createOrganizationUser($organization, ['media-view', 'media-store']);

    $response = test()->actingAs($person)->withSession(['current_organization_id' => $organization->id])
        ->postJson('/media', ['file' => new UploadedFile($path, $name, $mime, null, true)]);

    return [$response, Media::latest('id')->first()];
}

/** A picture drawn with GD, as its format's bytes: stripes of colour, or one flat colour. */
function poaPicture(int $width, int $height, string $format, bool $flat = false): string
{
    $image = imagecreatetruecolor($width, $height);

    for ($x = 0; $x < $width; $x += 8) {
        $colour = $flat ? 0x2563EB : (($x * 7) % 256 << 16) | (($x * 3) % 256 << 8) | (($x * 11) % 256);
        imagefilledrectangle($image, $x, 0, $x + 7, $height - 1, $colour);
    }

    ob_start();
    match ($format) {
        'png' => imagepng($image),
        'interlaced png' => imageinterlace($image, true) && imagepng($image),
        'progressive jpeg' => imageinterlace($image, true) && imagejpeg($image, null, 90),
        'webp' => imagewebp($image, null, 80),
        'lossless webp' => imagewebp($image, null, IMG_WEBP_LOSSLESS),
        default => imagejpeg($image, null, 90),
    };

    return (string) ob_get_clean();
}

/** Bytes written to a temporary file. */
function poaFile(string $bytes): string
{
    $path = tempnam(sys_get_temp_dir(), 'poa');
    file_put_contents($path, $bytes);

    return $path;
}

/** The JPEG with an EXIF block in front: how the camera was held, and where its first directory lies. */
function poaWithExif(string $jpeg, int $orientation, int $firstDirectory = 8, int $nextDirectory = 0): string
{
    $exif = "Exif\0\0"."MM\0\x2A".pack('N', $firstDirectory)."\0\x01"."\x01\x12\0\x03\0\0\0\x01".pack('n', $orientation)."\0\0".pack('N', $nextDirectory);

    return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
}

/**
 * A hostile or unusual picture, by name: [its bytes, its name, the type a browser would claim].
 *
 * @return array{0: string, 1: string, 2: string}
 */
function poaHostile(string $case): array
{
    $wide = poaPicture(3900, 60, 'jpeg');
    $widePng = poaPicture(3900, 60, 'png');

    return match ($case) {
        'a JPEG cut off half way' => [substr($wide, 0, intdiv(strlen($wide), 2)), 'cut.jpg', 'image/jpeg'],
        'a PNG cut off half way' => [substr($widePng, 0, intdiv(strlen($widePng), 2)), 'cut.png', 'image/png'],
        'a PNG whose colour profile inflates to 16 MB' => [substr($widePng, 0, 33).poaChunk('iCCP', "bomb\0\0".gzcompress(str_repeat("\0", 16 << 20), 9)).substr($widePng, 33), 'bomb.png', 'image/png'],
        'a JPEG held 65 535 ways' => [poaWithExif($wide, 65535), 'held.jpg', 'image/jpeg'],
        'a JPEG held no way at all' => [poaWithExif($wide, 0), 'held.jpg', 'image/jpeg'],
        'a JPEG whose EXIF points past its end' => [poaWithExif($wide, 6, 0x7FFFFF00), 'lost.jpg', 'image/jpeg'],
        'a JPEG whose EXIF points back at itself' => [poaWithExif($wide, 6, 8, 8), 'loop.jpg', 'image/jpeg'],
        'a JPEG of ten thousand empty segments' => [substr($wide, 0, 2).str_repeat("\xFF\xE1\x00\x02", 10001).substr($wide, 2), 'many.jpg', 'image/jpeg'],
        'a moving WebP' => ['RIFF'.pack('V', 4 + 18 + 14 + 8).'WEBP'.'VP8X'.pack('V', 10).chr(0x02)."\0\0\0".substr(pack('V', 99), 0, 3).substr(pack('V', 99), 0, 3)
            .'ANIM'.pack('V', 6).str_repeat("\0", 6).'ANMF'.pack('V', 0), 'moving.webp', 'image/webp'],
        'a progressive JPEG over 4K' => [poaPicture(3900, 60, 'progressive jpeg'), 'progressive.jpg', 'image/jpeg'],
        'an interlaced PNG over 4K' => [poaPicture(3900, 60, 'interlaced png'), 'interlaced.png', 'image/png'],
        'a lossless WebP over 4K' => [poaPicture(3900, 60, 'lossless webp'), 'lossless.webp', 'image/webp'],
        'a line 4000 px long' => [poaPicture(4000, 1, 'png'), 'line.png', 'image/png'],
        'a line 4000 px tall' => [poaPicture(1, 4000, 'png'), 'line.png', 'image/png'],
        'a picture 3840 px wide' => [poaPicture(3840, 60, 'jpeg'), 'edge.jpg', 'image/jpeg'],
        'a picture 3841 px wide' => [poaPicture(3841, 60, 'jpeg'), 'edge.jpg', 'image/jpeg'],
    };
}

test('a hostile or unusual picture ends stored or refused: never a 500, never a broken file, never over 4K', function (string $case) {
    [$bytes, $name, $mime] = poaHostile($case);
    [$response, $media] = poaUpload(poaFile($bytes), $name, $mime);

    expect($response->status())->toBeIn([200, 422]);

    if ($response->status() === 200) {
        $stored = Storage::disk('public')->get($media->path);
        $size = getimagesizefromstring($stored);

        // Kept byte for byte, or a WebP that every browser can draw, within a 4K screen.
        expect($stored === $bytes || ($media->mime_type === 'image/webp' && $size !== false && $size[0] <= 3840 && $size[1] <= 3840
            && imagecreatefromstring($stored) instanceof GdImage))->toBeTrue();
    }
})->with([
    'a JPEG cut off half way', 'a PNG cut off half way', 'a PNG whose colour profile inflates to 16 MB', 'a JPEG held 65 535 ways',
    'a JPEG held no way at all', 'a JPEG whose EXIF points past its end', 'a JPEG whose EXIF points back at itself',
    'a JPEG of ten thousand empty segments', 'a moving WebP', 'a progressive JPEG over 4K', 'an interlaced PNG over 4K',
    'a lossless WebP over 4K', 'a line 4000 px long', 'a line 4000 px tall', 'a picture 3840 px wide', 'a picture 3841 px wide',
]);

test('what each unusual picture becomes: brought down at 3841 px and not at 3840, a line stays a line, a moving one stays as it came', function () {
    $stored = function (string $case): array {
        [, $media] = poaUpload(poaFile(poaHostile($case)[0]), 'picture', 'application/octet-stream');

        return [$media->mime_type, $media->width, $media->height];
    };

    expect($stored('a picture 3841 px wide'))->toBe(['image/webp', 3840, 60])
        ->and($stored('a picture 3840 px wide')[1])->toBe(3840)
        ->and($stored('a line 4000 px long'))->toBe(['image/webp', 3840, 1])
        ->and($stored('a line 4000 px tall'))->toBe(['image/webp', 1, 3840])
        ->and($stored('a moving WebP')[0])->toBe('image/webp')
        // A profile that would inflate past 4 MB is not carried, so the picture stays as it came.
        ->and($stored('a PNG whose colour profile inflates to 16 MB')[0])->toBe('image/png');
});

test('whatever hides after a picture’s end is gone once it is written again', function () {
    // A real JPEG with a program after its end, as a polyglot carries one: over 4K, so it is always written again.
    $bytes = poaPicture(3900, 60, 'jpeg')."<?php system(\$_GET['c']); ?>";
    [$response, $media] = poaUpload(poaFile($bytes), 'menu.jpg', 'image/jpeg');

    $response->assertOk();
    expect($media->mime_type)->toBe('image/webp')
        ->and(Storage::disk('public')->get($media->path))->not->toContain('<?php');
});

test('a picture made lighter, uploaded again, is stored as it came: no second loss', function () {
    $first = app(PictureOptimizer::class)->optimize(poaFile(poaPicture(3900, 60, 'jpeg')), 'image/jpeg')['bytes'];
    [, $media] = poaUpload(poaFile($first), 'again.webp', 'image/webp');

    expect(Storage::disk('public')->get($media->path))->toBe($first);
});

test('a PNG claiming a picture of two billion pixels a side is stored as it came, with no preview, never a 500', function (int $side) {
    [$response, $media] = poaUpload(poaClaimingPng($side, $side), 'bomb.png', 'image/png');

    $response->assertOk()->assertJsonPath('lighter', null);
    expect($media)->mime_type->toBe('image/png')->thumbnail_path->toBeNull();
})->with(['2^31 - 1' => 0x7FFFFFFF, '2^30' => 0x40000000, 'a mere 2^24' => 0x1000000]);

test('the memory a picture would need is reckoned without overflowing, however large it claims to be', function () {
    expect(PictureOptimizer::fitsInMemory(PHP_INT_MAX * 4.0))->toBeFalse()
        ->and(app(PictureOptimizer::class)->optimize(poaClaimingPng(0x7FFFFFFF, 0x7FFFFFFF), 'image/png'))->toBeNull();
});

test('two hundred broken pictures, broken by chance the same way every run, end stored or refused: never a 500', function () {
    $organization = Organization::factory()->create();
    $person = createOrganizationUser($organization, ['media-view', 'media-store']);
    $profile = str_pad(pack('N', 132).'none'.pack('N', 0x02100000).'mntr'.'RGB '.'XYZ '.str_repeat("\0", 12).'acsp', 132, "\0");
    $seeds = [
        'jpeg' => poaPicture(3900, 8, 'jpeg'), 'png' => poaPicture(3900, 8, 'png'), 'flat png' => poaPicture(64, 48, 'png', true),
        'webp' => poaPicture(3900, 8, 'webp'), 'lossless webp' => poaPicture(64, 48, 'lossless webp'),
        'profiled jpeg' => substr(poaPicture(3900, 8, 'jpeg'), 0, 2)."\xFF\xE2".pack('n', strlen($profile) + 16)."ICC_PROFILE\0\x01\x01".$profile
            .substr(poaPicture(3900, 8, 'jpeg'), 2),
    ];
    $names = array_keys($seeds);
    $failures = [];
    mt_srand(2026);

    for ($case = 1; $case <= 200; $case++) {
        $seed = $names[mt_rand(0, count($names) - 1)];
        $bytes = $seeds[$seed];
        $at = mt_rand(0, strlen($bytes) - 1);
        $how = mt_rand(0, 5);
        $bytes = match ($how) {
            0 => substr($bytes, 0, $at),
            1 => substr_replace($bytes, chr(mt_rand(0, 255)), mt_rand(0, 63), 1),
            2 => substr_replace($bytes, chr(mt_rand(0, 255)), $at, 1),
            3 => substr_replace($bytes, "\xFF\xFF\xFF\xF0", mt_rand(0, 60), 4),
            4 => $bytes.str_repeat(chr(mt_rand(0, 255)), mt_rand(1, 4096)),
            default => substr($bytes, 0, $at).substr($bytes, $at + mt_rand(1, 64)),
        };

        $path = poaFile($bytes);
        $status = $this->actingAs($person)->withSession(['current_organization_id' => $organization->id])
            ->postJson('/media', ['file' => new UploadedFile($path, "case-{$case}", 'application/octet-stream', null, true)])
            ->status();
        @unlink($path);

        if (! in_array($status, [200, 422], true)) {
            $failures[] = "case {$case}: {$seed}, broken the {$how} way, answered {$status}";
        }
    }

    expect($failures)->toBe([]);
});

/** The server's one picture at a time (MediaStorage::onePictureAtATime), held by this test as another upload would hold it. */
function poaHoldTheTurn()
{
    $turn = fopen(storage_path('framework/cache/picture-turn.lock'), 'c');
    expect(flock($turn, LOCK_EX | LOCK_NB))->toBeTrue();

    return $turn;
}

test('six uploads at once: a picture waits for its turn, and one whose turn never comes is stored as it came', function () {
    Sleep::fake();
    $turn = poaHoldTheTurn();

    try {
        [$response, $media] = poaUpload(poaFile(poaPicture(3900, 60, 'jpeg')), 'menu.jpg', 'image/jpeg');
    } finally {
        fclose($turn);
    }

    // A minute and a half, a tenth of a second at a time; then it is stored as it came — no lighter file, the file its
    // own preview.
    $response->assertOk()->assertJsonPath('lighter', null);
    expect($media)->mime_type->toBe('image/jpeg')->thumbnail_path->toBeNull();
    Sleep::assertSleptTimes(900);

    // The turn free again, the next picture is made lighter at once.
    [$response, $media] = poaUpload(poaFile(poaPicture(3900, 60, 'jpeg')), 'menu.jpg', 'image/jpeg');

    $response->assertOk();
    expect($media)->mime_type->toBe('image/webp')->width->toBe(3840)->thumbnail_path->not->toBeNull();
    Sleep::assertSleptTimes(900);
});

test('a video never waits for a picture’s turn: there is nothing to work on', function () {
    Sleep::fake();
    $turn = poaHoldTheTurn();
    $organization = Organization::factory()->create();
    $person = createOrganizationUser($organization, ['media-view', 'media-store']);

    try {
        $this->actingAs($person)->withSession(['current_organization_id' => $organization->id])
            ->postJson('/media', ['file' => VideoFiles::upload(VideoFiles::mp4(8), 'promo.mp4')])
            ->assertOk();
    } finally {
        fclose($turn);
    }

    Sleep::assertNeverSlept();
});
