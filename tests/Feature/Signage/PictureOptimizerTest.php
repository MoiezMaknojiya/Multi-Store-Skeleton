<?php

use App\Models\BuilderAsset;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Services\OrganizationStorage;
use App\Services\PictureOptimizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\VideoFiles;

/*
|--------------------------------------------------------------------------
| Pictures are made light on upload
|--------------------------------------------------------------------------
|
| Owner, 2026-10-05: "upload par tasveer khud halki karne wala feature bana do, sub upload mein lagana aur yeh bhi
| dekhna k video bhi upload honti ha", and "Badi tasveer ko chhota karna yeh bhi bana do". At every door a picture
| bigger than 4K comes down to 3840 px, a phone photo is turned the way it was held, and what is stored — and counted,
| and sent to the televisions — is a WebP whenever that is lighter. What GD would spoil is left as it came, and a
| video is never touched. The Pest suite runs with this off (its storage tests count files by the sizes they claim);
| here it is on.
|
*/

beforeEach(function () {
    Storage::fake('public');
    config(['signage.optimize_pictures' => true]);
});

/** A picture drawn with GD and written to a temporary file: soft colour noise, so it weighs what a photo does. */
function poPicture(int $width, int $height, string $format = 'jpeg', ?Closure $paint = null): string
{
    $tile = imagecreatetruecolor(48, 27);
    for ($x = 0; $x < 48; $x++) {
        for ($y = 0; $y < 27; $y++) {
            imagesetpixel($tile, $x, $y, imagecolorallocate($tile, ($x * 37 + $y * 11) % 256, ($x * 7 + $y * 53) % 256, ($x * 91 + $y * 29) % 256));
        }
    }

    $image = imagecreatetruecolor($width, $height);
    imagecopyresampled($image, $tile, 0, 0, 0, 0, $width, $height, 48, 27);

    if ($paint) {
        $image = $paint($image);
    }

    $path = tempnam(sys_get_temp_dir(), 'po');
    match ($format) {
        'png' => imagepng($image, $path),
        'webp' => imagewebp($image, $path, 80),
        'gif' => imagegif($image, $path),
        default => imagejpeg($image, $path, 92),
    };

    return $path;
}

/**
 * A picture made like a photograph, written as a PNG: colour that changes gently across it, and its fine detail in light
 * and dark — where a camera's detail is. (poPicture's hues jump from cell to cell: a colour chart, which a lossy WebP
 * would change, and the optimizer measures so.)
 */
function poPhoto(int $width, int $height, ?Closure $paint = null): string
{
    $tile = imagecreatetruecolor(16, 9);
    for ($x = 0; $x < 16; $x++) {
        for ($y = 0; $y < 9; $y++) {
            imagesetpixel($tile, $x, $y, imagecolorallocate($tile, 150 + (int) (40 * sin($x / 3)), 120 + (int) (30 * sin(($x + $y) / 4)), 90 + (int) (30 * cos($y / 2))));
        }
    }

    $image = imagecreatetruecolor($width, $height);
    imagecopyresampled($image, $tile, 0, 0, 0, 0, $width, $height, 16, 9);

    // Grain in light alone: the same grey over every channel, half see-through.
    $grain = imagecreatetruecolor(61, 61);
    imagealphablending($grain, false);
    mt_srand(7);
    for ($x = 0; $x < 61; $x++) {
        for ($y = 0; $y < 61; $y++) {
            $grey = mt_rand(0, 255);
            imagesetpixel($grain, $x, $y, imagecolorallocatealpha($grain, $grey, $grey, $grey, 96));
        }
    }
    imagealphablending($image, true);
    imagesettile($image, $grain);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, IMG_COLOR_TILED);

    if ($paint) {
        $image = $paint($image);
    }

    $path = tempnam(sys_get_temp_dir(), 'po');
    imagepng($image, $path);

    return $path;
}

/** A photograph cut out of its background, as a menu's burger is: an ellipse of it, its edge fading over a few pixels. */
function poCutOut(GdImage $photo): GdImage
{
    $width = imagesx($photo);
    $height = imagesy($photo);
    $cut = imagecreatetruecolor($width, $height);
    imagealphablending($cut, false);
    imagesavealpha($cut, true);
    imagefill($cut, 0, 0, imagecolorallocatealpha($cut, 0, 0, 0, 127));

    for ($y = 0; $y < $height; $y += 1) {
        for ($x = (int) ($width * 0.2); $x < $width * 0.8; $x++) {
            // How far inside the ellipse, 1 at its edge: past it, nothing; within 2% of it, fading.
            $reach = (($x - $width / 2) / ($width * 0.3)) ** 2 + (($y - $height / 2) / ($height * 0.35)) ** 2;
            if ($reach < 1) {
                $rgb = imagecolorat($photo, $x, $y);
                $alpha = (int) round(127 * max(0, min(1, ($reach - 0.96) / 0.04)));
                imagesetpixel($cut, $x, $y, imagecolorallocatealpha($cut, ($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF, $alpha));
            }
        }
    }

    return $cut;
}

/** The same JPEG with one more marker segment right after its start (an EXIF or an ICC profile). */
function poWithSegment(string $path, int $marker, string $payload): string
{
    $bytes = file_get_contents($path);
    file_put_contents($path, substr($bytes, 0, 2).chr(0xFF).chr($marker).pack('n', strlen($payload) + 2).$payload.substr($bytes, 2));

    return $path;
}

/** An EXIF block that says how the camera was held (1 upright, 6 a quarter turn clockwise needed, …). */
function poExifOrientation(int $orientation): string
{
    return "Exif\0\0"."MM\0\x2A\0\0\0\x08"."\0\x01"."\x01\x12\0\x03\0\0\0\x01".pack('n', $orientation)."\0\0"."\0\0\0\0";
}

/** An ICC profile: a header naming its colour space (RGB, GRAY, CMYK…), and words that tell one test's from another. */
function poIccProfile(string $description, string $space = 'RGB '): string
{
    $body = pack('N', 0).'desc'.$description;
    $header = pack('N', 128 + strlen($body)).'none'.pack('N', 0x02100000).'mntr'.$space.'XYZ '.str_repeat("\0", 12).'acsp';

    return str_pad($header, 128, "\0").$body;
}

/** A JPEG's APP2 segment carrying an ICC profile, or one part of it. */
function poIccSegment(string $part, int $sequence = 1, int $count = 1): string
{
    return "ICC_PROFILE\0".chr($sequence).chr($count).$part;
}

/** A PNG chunk, with its length and its CRC. */
function poChunk(string $type, string $data): string
{
    return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
}

/** The same PNG with one more chunk right after its header (the signature and IHDR end at byte 33). */
function poWithChunk(string $path, string $type, string $data): string
{
    $bytes = file_get_contents($path);
    file_put_contents($path, substr($bytes, 0, 33).poChunk($type, $data).substr($bytes, 33));

    return $path;
}

/** A WebP's chunks, by name, in their order. */
function poWebpChunks(string $webp): array
{
    $chunks = [];
    $at = 12;

    while ($at + 8 <= strlen($webp)) {
        $length = unpack('V', substr($webp, $at + 4, 4))[1];
        $chunks[substr($webp, $at, 4)] = substr($webp, $at + 8, $length);
        $at += 8 + $length + ($length % 2);
    }

    return $chunks;
}

/** A file as a browser would post it: real bytes, whatever the name says. */
function poUpload(string $path, string $name, string $mime): UploadedFile
{
    return new UploadedFile($path, $name, $mime, null, true);
}

/** The picture the optimizer would store, decoded — or null when it keeps the upload as it came. */
function poOptimized(string $path, string $mime): ?GdImage
{
    $result = app(PictureOptimizer::class)->optimize($path, $mime);

    return $result === null ? null : imagecreatefromstring($result['bytes']);
}

/** The bytes the optimizer would store — or null when it keeps the upload as it came. */
function poOptimizedBytes(string $path, string $mime): ?string
{
    return app(PictureOptimizer::class)->optimize($path, $mime)['bytes'] ?? null;
}

test('a picture bigger than 4K comes down to 3840 px on its longer side, as a lighter WebP, and the uploader is told', function () {
    $organization = Organization::factory()->create();
    $actor = createOrganizationUser($organization, ['media-view', 'media-store']);
    $path = poPicture(5000, 2500);
    $sent = filesize($path);

    $response = $this->actingAs($actor)->withSession(['current_organization_id' => $organization->id])
        ->postJson('/media', ['file' => poUpload($path, 'storefront.jpg', 'image/jpeg')])
        ->assertOk();

    $media = Media::sole();

    expect($media->mime_type)->toBe('image/webp')
        ->and($media->path)->toEndWith('.webp')
        ->and([$media->width, $media->height])->toBe([3840, 1920])
        ->and($media->title)->toBe('storefront')
        ->and($media->size)->toBe(Storage::disk('public')->size($media->path))
        ->and($media->size)->toBeLessThan($sent)
        ->and($response->json('lighter'))->toBe(['from' => $sent, 'to' => $media->size]);

    Storage::disk('public')->assertExists($media->thumbnail_path);

    // What the organization is charged is the lighter file and its preview.
    expect(app(OrganizationStorage::class)->summary($organization->id)['used'])
        ->toBe($media->size + Storage::disk('public')->size($media->thumbnail_path));
});

test('a phone photo is turned the way the camera held it', function () {
    // 400 x 200 as stored, a red corner top-left; the camera says a quarter turn clockwise shows it upright.
    $path = poWithSegment(poPicture(400, 200, 'jpeg', function (GdImage $image) {
        imagefilledrectangle($image, 0, 0, 49, 49, imagecolorallocate($image, 255, 0, 0));

        return $image;
    }), 0xE1, poExifOrientation(6));

    $upright = poOptimized($path, 'image/jpeg');

    expect([imagesx($upright), imagesy($upright)])->toBe([200, 400]);

    // The red corner is now top-right.
    $corner = imagecolorsforindex($upright, imagecolorat($upright, 175, 25));
    expect($corner['red'])->toBeGreaterThan(200)->and($corner['green'])->toBeLessThan(80);
});

test('a picture over 4K held upright is brought down, then turned: its corner lands where the camera saw it', function () {
    // 4000 x 2000 as stored, a red corner top-left; the camera says a quarter turn clockwise shows it upright.
    $path = poWithSegment(poPicture(4000, 2000, 'jpeg', function (GdImage $image) {
        imagefilledrectangle($image, 0, 0, 399, 399, imagecolorallocate($image, 255, 0, 0));

        return $image;
    }), 0xE1, poExifOrientation(6));

    $upright = poOptimized($path, 'image/jpeg');

    expect([imagesx($upright), imagesy($upright)])->toBe([1920, 3840]);

    // The red corner is now top-right.
    $corner = imagecolorsforindex($upright, imagecolorat($upright, 1820, 100));
    expect($corner['red'])->toBeGreaterThan(200)->and($corner['green'])->toBeLessThan(80);
});

test('a big photo held upright needs no more memory than one lying flat: it is brought down before it is turned', function () {
    $optimizer = app(PictureOptimizer::class);
    $needed = fn (float $pixels, float $target, int $orientation) => (fn () => $this->memoryNeeded($pixels, $target, $orientation, $target < $pixels, false))->call($optimizer);

    // 48 MP, as a phone's full resolution writes it: upright as flat, some 290 MB — inside the live server's 384.
    expect($needed(8000.0 * 6000, 3840.0 * 2880, 6))->toBe($needed(8000.0 * 6000, 3840.0 * 2880, 1))
        ->and($needed(8000.0 * 6000, 3840.0 * 2880, 6))->toBeLessThan(300.0 * 1024 * 1024)
        // A picture that keeps its size is turned beside itself: one copy more.
        ->and($needed(8e6, 8e6, 6) - $needed(8e6, 8e6, 1))->toBe(8e6 * PictureOptimizer::BYTES_PER_PIXEL);
});

test('transparency is kept', function () {
    $path = poPicture(4000, 2000, 'png', function (GdImage $image) {
        $clear = imagecreatetruecolor(4000, 2000);
        imagealphablending($clear, false);
        imagesavealpha($clear, true);
        imagefill($clear, 0, 0, imagecolorallocatealpha($clear, 0, 0, 0, 127));
        imagefilledrectangle($clear, 1500, 500, 2500, 1500, imagecolorallocate($clear, 255, 0, 0));

        return $clear;
    });

    $webp = poOptimized($path, 'image/png');

    expect([imagesx($webp), imagesy($webp)])->toBe([3840, 1920])
        ->and(imagecolorsforindex($webp, imagecolorat($webp, 10, 10))['alpha'])->toBe(127)
        ->and(imagecolorsforindex($webp, imagecolorat($webp, 1920, 960)))->toMatchArray(['red' => 255, 'alpha' => 0]);
});

test('a picture already small and well packed stays as it came', function () {
    expect(app(PictureOptimizer::class)->optimize(poPicture(600, 400, 'webp'), 'image/webp'))->toBeNull();
});

test('a GIF and a moving PNG are left as they came', function () {
    expect(app(PictureOptimizer::class)->optimize(poPicture(4000, 2000, 'gif'), 'image/gif'))->toBeNull();

    // An animated PNG: GD would keep its first frame alone. (Wider than 4K, so only the reason kept it as it came.)
    expect(app(PictureOptimizer::class)->optimize(poWithChunk(poPicture(3900, 100, 'png'), 'acTL', pack('NN', 2, 0)), 'image/png'))->toBeNull()
        ->and(app(PictureOptimizer::class)->optimize(poPicture(3900, 100, 'png'), 'image/png'))->not->toBeNull();
});

test('a photo in another colour space than sRGB keeps it: the WebP carries the very same profile', function () {
    // An iPhone's Display P3 (an odd length, so the chunk is padded).
    $profile = poIccProfile('Display P3 ');
    $bytes = poOptimizedBytes(poWithSegment(poPicture(4000, 2000), 0xE2, poIccSegment($profile)), 'image/jpeg');
    $chunks = poWebpChunks($bytes);

    expect(strlen($profile) % 2)->toBe(1)
        ->and(array_keys($chunks))->toBe(['VP8X', 'ICCP', 'VP8 '])
        ->and(ord($chunks['VP8X'][0]) & 0x20)->toBe(0x20)
        ->and($chunks['ICCP'])->toBe($profile)
        ->and(unpack('V', substr($bytes, 4, 4))[1])->toBe(strlen($bytes) - 8)
        ->and(array_slice(getimagesizefromstring($bytes), 0, 2))->toBe([3840, 1920]);

    // libwebp — what the televisions' browsers decode with too — reads it.
    $decoded = imagecreatefromstring($bytes);
    expect([imagesx($decoded), imagesy($decoded)])->toBe([3840, 1920]);
});

test('a profile too big for one JPEG segment is joined in its numbered order', function () {
    $profile = poIccProfile('Adobe RGB (1998)');
    [$first, $second] = str_split($profile, (int) ceil(strlen($profile) / 2));

    // Written second first: the numbers decide, not the order in the file.
    $path = poWithSegment(poPicture(4000, 2000), 0xE2, poIccSegment($first, 1, 2));
    $path = poWithSegment($path, 0xE2, poIccSegment($second, 2, 2));

    expect(poWebpChunks(poOptimizedBytes($path, 'image/jpeg'))['ICCP'])->toBe($profile);

    // A part missing: there is no profile to carry, so the photo stays as it came.
    expect(poOptimizedBytes(poWithSegment(poPicture(4000, 2000), 0xE2, poIccSegment($first, 1, 2)), 'image/jpeg'))->toBeNull();
});

test('a cut-out keeps its colour profile and its transparency together', function () {
    // A logo-like shape of one colour on nothing, and a photograph cut out of its background (soft at its edge, as a
    // real cut-out is): whichever way each is written, both carry the profile and their transparency.
    $profile = poIccProfile('Display P3');
    $logo = poPicture(3900, 400, 'png', function (GdImage $image) {
        $clear = imagecreatetruecolor(3900, 400);
        imagealphablending($clear, false);
        imagesavealpha($clear, true);
        imagefill($clear, 0, 0, imagecolorallocatealpha($clear, 0, 0, 0, 127));
        imagefilledrectangle($clear, 1500, 100, 2400, 300, imagecolorallocate($clear, 255, 0, 0));

        return $clear;
    });
    $cutOut = poPhoto(3900, 400, fn (GdImage $photo) => poCutOut($photo));
    $written = [];

    foreach (['logo' => $logo, 'cut-out' => $cutOut] as $name => $path) {
        $bytes = poOptimizedBytes(poWithChunk($path, 'iCCP', "Display P3\0\0".gzcompress($profile)), 'image/png');
        $chunks = poWebpChunks($bytes);
        $decoded = imagecreatefromstring($bytes);
        $written[$name] = [array_keys($chunks), imagecolorsforindex($decoded, imagecolorat($decoded, 1920, 197))];

        expect(array_slice(array_keys($chunks), 0, 2))->toBe(['VP8X', 'ICCP'])
            ->and(ord($chunks['VP8X'][0]) & 0x30)->toBe(0x30)
            ->and($chunks['ICCP'])->toBe($profile)
            ->and(imagecolorsforindex($decoded, imagecolorat($decoded, 10, 10))['alpha'])->toBe(127)
            ->and($written[$name][1]['alpha'])->toBe(0);
    }

    // The logo's flat red, sharp against nothing, is kept exact; the photograph stays light: lossy, its transparency in
    // a layer of its own.
    expect($written['logo'][0])->toBe(['VP8X', 'ICCP', 'VP8L'])
        ->and($written['logo'][1])->toMatchArray(['red' => 255, 'green' => 0, 'blue' => 0])
        ->and($written['cut-out'][0])->toBe(['VP8X', 'ICCP', 'ALPH', 'VP8 ']);
});

test('small coloured words keep every pixel, while a photograph stays light', function () {
    // Thin red words on black, the kind a lossy WebP would turn maroon (seen on a real 4K menu): wider than 4K, so it is
    // always written — and written without loss.
    $words = function (bool $withWords): string {
        return poPhoto(3900, 600, function (GdImage $image) use ($withWords) {
            if ($withWords) {
                imagefilledrectangle($image, 0, 400, 3899, 599, imagecolorallocate($image, 0, 0, 0));
                for ($line = 0; $line < 10; $line++) {
                    imagestring($image, 5, 10, 410 + $line * 18, str_repeat('Prices and participation may vary. ', 12), imagecolorallocate($image, 229, 0, 5));
                }
            }

            return $image;
        });
    };

    $bytes = poOptimizedBytes($words(true), 'image/png');
    $decoded = imagecreatefromstring($bytes);
    $reddest = 0;
    for ($x = 10; $x < 1200; $x++) {
        for ($y = 400; $y < 590; $y++) {
            $colour = imagecolorsforindex($decoded, imagecolorat($decoded, $x, $y));
            $reddest = max($reddest, $colour['green'] < 40 ? $colour['red'] : 0);
        }
    }

    expect(array_keys(poWebpChunks($bytes)))->toBe(['VP8L'])
        ->and($reddest)->toBeGreaterThan(200)
        // The same photograph without the words is a photograph: lossy.
        ->and(array_keys(poWebpChunks(poOptimizedBytes($words(false), 'image/png'))))->toBe(['VP8 ']);
});

test('a JPEG is measured only when it kept its colour whole, as a camera’s does not', function () {
    $optimizer = app(PictureOptimizer::class);
    $about = fn (string $path) => (fn () => $this->about($path, 'image/jpeg'))->call($optimizer);
    $header = function (array $samplings): string {
        $components = '';
        foreach ($samplings as $id => $sampling) {
            $components .= chr($id + 1).chr($sampling).chr(0);
        }
        $frame = chr(8).pack('nn', 100, 100).chr(count($samplings)).$components;
        $path = tempnam(sys_get_temp_dir(), 'po');
        file_put_contents($path, "\xFF\xD8\xFF\xC0".pack('n', strlen($frame) + 2).$frame."\xFF\xDA");

        return $path;
    };

    // Photoshop's best quality: every component at one sampling. A camera's: colour at half. Grey: no colour at all.
    expect($about($header([0x11, 0x11, 0x11]))['fullColour'])->toBeTrue()
        ->and($about($header([0x22, 0x11, 0x11]))['fullColour'])->toBeFalse()
        ->and($about($header([0x11]))['fullColour'])->toBeFalse()
        // GD writes its JPEGs with colour at half resolution, as a camera does.
        ->and($about(poPicture(64, 64))['fullColour'])->toBeFalse();
});

test('colours GD cannot keep leave a picture as it came, and sRGB in any of its words does not', function () {
    // Pictures wider than 4K, which are always brought down — unless their colours are the reason they stay as they came.
    $jpeg = fn (string $profile) => poOptimizedBytes(poWithSegment(poPicture(3900, 100), 0xE2, poIccSegment($profile)), 'image/jpeg');
    $png = fn (string $type, string $data) => poOptimizedBytes(poWithChunk(poPicture(3900, 100, 'png'), $type, $data), 'image/png');

    // A grey or a CMYK profile on what GD reads as RGB pixels: a WebP could not carry it.
    expect($jpeg(poIccProfile('Dot Gain 20%', 'GRAY')))->toBeNull()
        ->and($jpeg(poIccProfile('U.S. Web Coated (SWOP) v2', 'CMYK')))->toBeNull()
        // Nor bytes that are not a profile at all.
        ->and($jpeg('not a profile'))->toBeNull();

    // A PNG naming a gamma or primaries of its own, which a browser honours and GD would drop.
    expect($png('gAMA', pack('N', 100000)))->toBeNull()
        ->and($png('cHRM', pack('N8', 31270, 32900, 64000, 33000, 21000, 71000, 15000, 6000)))->toBeNull();

    // sRGB said as a gamma of 1/2.2, as sRGB's own primaries, or with the sRGB chunk that outranks both.
    expect($png('gAMA', pack('N', 45455)))->not->toBeNull()
        ->and($png('cHRM', pack('N8', 31270, 32900, 64000, 33000, 30000, 60000, 15000, 6000)))->not->toBeNull()
        ->and(poOptimizedBytes(poWithChunk(poWithChunk(poPicture(3900, 100, 'png'), 'gAMA', pack('N', 100000)), 'sRGB', "\0"), 'image/png'))->not->toBeNull()
        ->and($jpeg(poIccProfile('sRGB IEC61966-2.1')))->not->toBeNull();
});

test('a drawing packed without loss is written without loss again, and brought down it is measured like any picture', function () {
    // A palette PNG of a few colours, as a logo or a price list is.
    $drawing = function (int $width, int $height): string {
        $palette = imagecreate($width, $height);
        imagecolorallocate($palette, 255, 255, 255);
        imagefilledrectangle($palette, intdiv($width, 4), intdiv($height, 4), intdiv($width * 3, 4), intdiv($height * 3, 4), imagecolorallocate($palette, 200, 16, 46));
        imagestring($palette, 5, 20, 20, 'MENU 12.99', imagecolorallocate($palette, 37, 99, 235));
        $path = tempnam(sys_get_temp_dir(), 'po');
        imagepng($palette, $path);

        return $path;
    };

    // Its flat colours come back exact.
    $bytes = poOptimizedBytes($drawing(1920, 1080), 'image/png');
    $decoded = imagecreatefromstring($bytes);

    expect(array_keys(poWebpChunks($bytes)))->toBe(['VP8L'])
        ->and(imagecolorsforindex($decoded, imagecolorat($decoded, 960, 540)))->toMatchArray(['red' => 200, 'green' => 16, 'blue' => 46])
        ->and(imagecolorsforindex($decoded, imagecolorat($decoded, 5, 5)))->toMatchArray(['red' => 255, 'green' => 255, 'blue' => 255]);

    // Brought down, its pixels change anyway, so it is measured like any picture of whole colour: the drawing's red and
    // blue edges would change, so it stays lossless; a lossless WebP of a photograph has nothing of the sort, and is made
    // lossy — a lossless copy of resampled photo pixels weighs several times what came.
    $lossless = poPhoto(4000, 2000);
    imagewebp(imagecreatefrompng($lossless), $lossless, IMG_WEBP_LOSSLESS);

    expect(array_keys(poWebpChunks(poOptimizedBytes($drawing(4000, 2000), 'image/png'))))->toBe(['VP8L'])
        ->and(array_keys(poWebpChunks(poOptimizedBytes($lossless, 'image/webp'))))->toBe(['VP8 ']);
});

test('a small file claiming a huge picture is stored as it came, with no preview, and nothing falls over', function () {
    $organization = Organization::factory()->create();
    $actor = createOrganizationUser($organization, ['media-view', 'media-store']);

    // 30 000 x 30 000 pixels would take 3.6 GB to open; the file is a few hundred bytes.
    $path = tempnam(sys_get_temp_dir(), 'po');
    file_put_contents($path, "\x89PNG\r\n\x1a\n".poChunk('IHDR', pack('NNCCCCC', 30000, 30000, 8, 6, 0, 0, 0))
        .poChunk('IDAT', gzcompress(str_repeat("\0", 1000))).poChunk('IEND', ''));

    $this->actingAs($actor)->withSession(['current_organization_id' => $organization->id])
        ->postJson('/media', ['file' => poUpload($path, 'bomb.png', 'image/png')])
        ->assertOk()
        ->assertJsonPath('lighter', null);

    expect(Media::sole())->mime_type->toBe('image/png')->thumbnail_path->toBeNull();
});

test('a video is never touched', function () {
    $organization = Organization::factory()->create();
    $actor = createOrganizationUser($organization, ['media-view', 'media-store']);
    $bytes = VideoFiles::mp4(8);

    $this->actingAs($actor)->withSession(['current_organization_id' => $organization->id])
        ->postJson('/media', ['file' => VideoFiles::upload($bytes, 'promo.mp4')])
        ->assertOk()
        ->assertJsonPath('lighter', null);

    $media = Media::sole();

    expect($media->type)->toBe(Media::TYPE_VIDEO)
        ->and($media->mime_type)->toBe('video/mp4')
        ->and($media->path)->toEndWith('.mp4')
        ->and($media->size)->toBe(strlen($bytes))
        ->and(Storage::disk('public')->get($media->path))->toBe($bytes);
});

test('every door makes a picture lighter: the Ad Builder’s shelf, a channel’s Upload and an advert too', function () {
    // The Ad Builder's shelf, which also says by how much.
    $organization = Organization::factory()->create();
    $designer = createOrganizationUser($organization, ['ad-view', 'ad-store']);
    $this->actingAs($designer)->withSession(['current_organization_id' => $organization->id])
        ->postJson('/builder/assets', ['file' => poUpload(poPicture(4000, 2000), 'backdrop.jpg', 'image/jpeg')])
        ->assertOk()
        ->assertJsonStructure(['lighter' => ['from', 'to']]);

    expect(BuilderAsset::sole())->path->toEndWith('.webp')->width->toBe(3840);

    // A channel's Upload, into the platform's library.
    $admin = createSuperAdmin(['channel-view', 'channel-update']);
    $channel = Channel::factory()->create(['name' => 'GAMA']);
    $this->actingAs($admin)->flushSession()
        ->post("/channels/{$channel->id}/ads", ['file' => poUpload(poPicture(4000, 2000), 'coke.jpg', 'image/jpeg'), 'seconds' => 10], ['Accept' => 'application/json'])
        ->assertOk();

    expect(ChannelAd::sole()->media)->mime_type->toBe('image/webp')->width->toBe(3840);

    // An advert of the ads network.
    $this->actingAs(createSuperAdmin())->postJson('/campaigns', [
        'name' => 'Coca-Cola Ramadan', 'advertiser_name' => 'Coca-Cola', 'duration_seconds' => 15, 'is_active' => true,
        'screen_ids' => [], 'file' => poUpload(poPicture(4000, 2000), 'ramadan.jpg', 'image/jpeg'),
    ])->assertOk();

    expect(Campaign::sole())->mime_type->toBe('image/webp')->width->toBe(3840);
});

test('with the setting off, a picture is stored exactly as it came', function () {
    config(['signage.optimize_pictures' => false]);

    expect(app(PictureOptimizer::class)->optimize(poPicture(5000, 2500), 'image/jpeg'))->toBeNull();
});
