<?php

namespace App\Services;

use GdImage;
use Throwable;

/**
 * Makes an uploaded picture light enough for a television (owner, 2026-10-05: "upload par tasveer khud halki karne
 * wala feature bana do, sub upload mein lagana", and "Badi tasveer ko chhota karna yeh bhi bana do"). A picture wider
 * or taller than a 4K screen comes down to 3840 px on its longer side, a phone photograph is turned the way its camera
 * says it was held, and the result is written as WebP — which every television this panel serves can show, Chrome
 * 80's WebView included — whenever that is lighter than what came. Transparency is kept, and so is the colour profile a
 * picture came with (an iPhone's Display P3, a designer's Adobe RGB): the WebP carries the very same one, so a screen
 * reads its colours exactly as it read the upload's. A picture packed without loss — a PNG of 256 colours or fewer, a
 * lossless WebP — is written without loss again unless it had to be brought down; a photograph at a quality nobody
 * sees on a screen. And because lossy WebP keeps colour at half resolution — which nearly every JPEG has already done,
 * but a PNG has not — a picture whose own colour is that sharp is measured: where small coloured detail would change
 * (a menu's thin red words on black came out maroon, a logo's edges would blur), every pixel is kept instead.
 *
 * Left exactly as it came: a GIF, and a PNG or WebP that moves (GD would keep its first frame alone); a CMYK JPEG (GD
 * reads its colours wrong); a picture whose colours GD cannot keep — a grey or CMYK profile on what GD reads as RGB, a
 * PNG that names a gamma or primaries other than sRGB's; a file not built as its format says; anything GD cannot read;
 * and a picture too big to open in the memory one request has. Nothing here ever refuses an upload: whatever goes
 * wrong, the picture is stored as it came. A video is never touched: there is no ffmpeg on the server.
 *
 * Every upload door reaches it through MediaStorage::put (the Media page, a channel's Upload, the Ad Builder's shelf
 * and an advert), so what is stored, counted to an organization's 512 MB and sent to the televisions is the lighter file.
 */
class PictureOptimizer
{
    /** The longest side a picture is kept at: a 4K television's width. */
    public const MAX_EDGE = 3840;

    /** WebP quality for a photograph (a JPEG) — and for a drawing or a cut-out (PNG, WebP), whose edges show more. */
    public const PHOTO_QUALITY = 85;

    public const DRAWING_QUALITY = 90;

    /** A picture written again only for its weight must come out at least this much lighter, or it stays as it came. */
    public const MIN_SAVING = 0.1;

    /** What GD holds for each pixel of a true-colour picture. */
    public const BYTES_PER_PIXEL = 4;

    /** What is left alone for the rest of the request. */
    private const MEMORY_MARGIN = 32 * 1024 * 1024;

    /** The biggest colour profile carried over: a picture with a bigger one stays as it came. */
    private const MAX_PROFILE_BYTES = 4 * 1024 * 1024;

    /** How many segments or chunks are read, at most, before a picture's own data begins. */
    private const MAX_PARTS = 10000;

    /**
     * How a lossy WebP is checked against a picture whose own colour is sharper than half resolution: in blocks this
     * many pixels square, every other pixel each way, a pixel whose colour (Cb/Cr) moved more than COLOUR_STEP is one a
     * person may see changed; a block where COLOUR_SHARE of them did is detail that changed (a thin coloured letter,
     * a logo's edge), and COLOUR_BLOCKS of those keep the picture without loss. Set on 33 real pictures, 2026-10-05:
     * 24 of the 26 photographs and menu cut-outs stayed lossy (a photo with sharp red and green edges went lossless, and
     * a JPEG of full colour stayed as it came, its lossless copy being heavier), and every picture with words — the
     * owner's own menus, a 4K menu and a screenshot of this panel — was written without loss, not a pixel changed.
     */
    private const COLOUR_BLOCK = 16;

    private const COLOUR_STEP = 24;

    private const COLOUR_SHARE = 0.1;

    private const COLOUR_BLOCKS = 2;

    /** A PNG's primaries and white point, as sRGB has them (cHRM, in hundred-thousandths): white, red, green, blue. */
    private const SRGB_PRIMARIES = [31270, 32900, 64000, 33000, 30000, 60000, 15000, 6000];

    /**
     * The picture to store in place of the upload at $path, or null to store the upload as it came.
     *
     * @return array{bytes: string, mime: string, extension: string}|null
     */
    public function optimize(string $path, string $mime): ?array
    {
        if (! config('signage.optimize_pictures') || ! function_exists('imagewebp')
            || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }

        try {
            return $this->lighter($path, $mime);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{bytes: string, mime: string, extension: string}|null */
    private function lighter(string $path, string $mime): ?array
    {
        $info = @getimagesize($path);

        if ($info === false || $info[0] < 1 || $info[1] < 1 || ($info['channels'] ?? 3) === 4) {
            return null;
        }

        $about = $this->about($path, $mime);

        if ($about === null) {
            return null;
        }

        [$width, $height] = $info;
        $orientation = $mime === 'image/jpeg' ? $this->orientation($path) : 1;
        $onItsSide = in_array($orientation, [5, 6, 7, 8], true);
        $scale = min(1, self::MAX_EDGE / max($width, $height));
        $targetWidth = max(1, (int) round(($onItsSide ? $height : $width) * $scale));
        $targetHeight = max(1, (int) round(($onItsSide ? $width : $height) * $scale));

        // Without loss while its pixels are the ones that came; brought down they change anyway, and the measuring
        // below decides.
        $lossless = $about['lossless'] && $scale === 1;
        $measured = ! $lossless && $about['fullColour'];

        if (! self::fitsInMemory($this->memoryNeeded($width * $height, $targetWidth * $targetHeight, $orientation, $scale < 1, $measured))) {
            return null;
        }

        $image = $this->read($path, $mime);

        if ($image === null) {
            return null;
        }

        $image = $this->turn($image, $orientation);

        if ($scale < 1) {
            $image = $this->scaled($image, $targetWidth, $targetHeight);
        }

        $bytes = $this->webp($image, $lossless ? null : ($mime === 'image/jpeg' ? self::PHOTO_QUALITY : self::DRAWING_QUALITY));

        // A lossy WebP keeps colour at half resolution. A picture that still has it whole is measured, and where small
        // coloured detail would change, every pixel is kept instead — only where it would: a lossless copy of a photo
        // weighs five to ten times the lossy one (measured on the owner's menu cut-outs).
        if ($bytes !== null && $measured && $this->losesColour($image, $bytes)) {
            $bytes = $this->webp($image, null);
        }

        if ($bytes !== null && $about['profile'] !== null) {
            $bytes = $this->withProfile($bytes, $about['profile'], imagesx($image), imagesy($image));
        }

        if ($bytes === null) {
            return null;
        }

        // Lighter, or it stays as it came — unless it had to be made smaller or turned, which a television cannot do for
        // itself (an old WebView shows a phone photo on its side); a picture bigger than 4K is always brought down, as
        // WordPress brings down whatever is over its own threshold.
        if ($scale === 1 && $orientation === 1 && strlen($bytes) > (int) @filesize($path) * (1 - self::MIN_SAVING)) {
            return null;
        }

        return ['bytes' => $bytes, 'mime' => 'image/webp', 'extension' => 'webp'];
    }

    /**
     * Whether this many bytes of pictures can be opened in the memory this request has left — asked before GD allocates
     * for them, so a small file claiming a huge picture cannot take the request down with it.
     */
    public static function fitsInMemory(int $bytes): bool
    {
        $limit = self::memoryLimit();

        return $limit === null || memory_get_usage(true) + $bytes + self::MEMORY_MARGIN < $limit;
    }

    /** PHP's memory limit in bytes, or null when there is none. */
    private static function memoryLimit(): ?int
    {
        $value = trim((string) ini_get('memory_limit'));

        if ($value === '' || $value === '-1') {
            return null;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * Everything GD holds on the way, at 4 bytes a pixel, added up — PHP keeps what a step frees for later instead of
     * handing it back, so the steps do not take turns (measured: a 48 MP photograph peaks at 278 MB): the picture as
     * read (twice when it turns — a turn is a copy), its smaller copy, the copy GD hands the WebP encoder and the WebP;
     * and when its colour is measured, the lossy WebP read back and a lossless WebP beside it.
     */
    private function memoryNeeded(int $pixels, int $targetPixels, int $orientation, bool $scaled, bool $measured): int
    {
        $picture = $pixels * self::BYTES_PER_PIXEL;
        $target = $targetPixels * self::BYTES_PER_PIXEL;

        return $picture * (in_array($orientation, [3, 5, 6, 7, 8], true) ? 2 : 1)
            + ($scaled ? $target : 0)
            + $target + $targetPixels * 2
            + ($measured ? $target + $targetPixels * 2 : 0);
    }

    /**
     * Whether a lossy WebP of the picture would visibly change small coloured detail: the WebP is read back and compared
     * with the picture in blocks (COLOUR_BLOCK), every other pixel each way, in colour alone (Cb/Cr) — light and dark
     * keep their full resolution in a WebP. A pixel nobody sees (more than half transparent) is not counted.
     */
    private function losesColour(GdImage $picture, string $webp): bool
    {
        $lossy = @imagecreatefromstring($webp);

        if (! $lossy instanceof GdImage || imagesx($lossy) !== imagesx($picture) || imagesy($lossy) !== imagesy($picture)) {
            return true;
        }

        $width = imagesx($picture);
        $height = imagesy($picture);
        $columns = intdiv($width + self::COLOUR_BLOCK - 1, self::COLOUR_BLOCK);
        $seen = [];
        $moved = [];
        $step = self::COLOUR_STEP ** 2;

        for ($y = 0; $y < $height; $y += 2) {
            $row = intdiv($y, self::COLOUR_BLOCK) * $columns;

            for ($x = 0; $x < $width; $x += 2) {
                $p = imagecolorat($picture, $x, $y);

                if ((($p >> 24) & 0x7F) > 64) {
                    continue;
                }

                $q = imagecolorat($lossy, $x, $y);
                $red = (($p >> 16) & 0xFF) - (($q >> 16) & 0xFF);
                $green = (($p >> 8) & 0xFF) - (($q >> 8) & 0xFF);
                $blue = ($p & 0xFF) - ($q & 0xFF);
                $cb = -0.168736 * $red - 0.331264 * $green + 0.5 * $blue;
                $cr = 0.5 * $red - 0.418688 * $green - 0.081312 * $blue;
                $block = $row + intdiv($x, self::COLOUR_BLOCK);
                $seen[$block] = ($seen[$block] ?? 0) + 1;

                if ($cb * $cb + $cr * $cr > $step) {
                    $moved[$block] = ($moved[$block] ?? 0) + 1;
                }
            }
        }

        $changed = 0;

        foreach ($moved as $block => $count) {
            if ($seen[$block] >= 16 && $count >= $seen[$block] * self::COLOUR_SHARE && ++$changed >= self::COLOUR_BLOCKS) {
                return true;
            }
        }

        return false;
    }

    /**
     * What a file says about itself before it is opened — the colour profile its colours are read by (null: none, so
     * sRGB), whether it was packed without loss, and whether its colour is still at full resolution (a PNG, a lossless
     * WebP, a JPEG that kept every component whole) — or null when it must stay as it came: it moves, its colours could
     * not be kept, or it is not built as its format says.
     *
     * @return array{profile: ?string, lossless: bool, fullColour: bool}|null
     */
    private function about(string $path, string $mime): ?array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            return match ($mime) {
                'image/jpeg' => $this->aboutJpeg($handle),
                'image/png' => $this->aboutPng($handle),
                default => $this->aboutWebp($handle),
            };
        } finally {
            fclose($handle);
        }
    }

    /**
     * A JPEG's colour profile — its APP2 segments before the picture's data, joined in their order when the profile was
     * too big for one — and, from its frame header, whether its colour components were kept as whole as its light.
     *
     * @param  resource  $handle
     * @return array{profile: ?string, lossless: bool, fullColour: bool}|null
     */
    private function aboutJpeg($handle): ?array
    {
        if ($this->take($handle, 2) !== "\xFF\xD8") {
            return null;
        }

        $parts = [];
        $count = 0;
        $collected = 0;
        $fullColour = false;

        for ($i = 0; $i < self::MAX_PARTS; $i++) {
            if ($this->take($handle, 1) !== "\xFF") {
                return null;
            }

            do {
                $code = $this->take($handle, 1);
            } while ($code === "\xFF");

            if ($code === null) {
                return null;
            }

            $code = ord($code);

            // The picture's data begins (or the file ends): every profile segment comes before it.
            if ($code === 0xDA || $code === 0xD9) {
                return $this->withJpegProfile($parts, $count, $fullColour);
            }

            // A marker that stands alone, with no length.
            if ($code === 0x01 || ($code >= 0xD0 && $code <= 0xD7)) {
                continue;
            }

            $bytes = $this->take($handle, 2);
            $length = $bytes === null ? -1 : unpack('n', $bytes)[1] - 2;

            if ($length < 0) {
                return null;
            }

            // A frame header: each component's sampling. Three components sampled alike kept their colour whole
            // (a camera's JPEG halves it; Photoshop's best quality does not).
            if (in_array($code, [0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF], true)) {
                $frame = $this->take($handle, $length);

                if ($frame === null || strlen($frame) < 6) {
                    return null;
                }

                $samplings = [];

                for ($component = 0; $component < ord($frame[5]) && 7 + $component * 3 < strlen($frame); $component++) {
                    $samplings[] = ord($frame[7 + $component * 3]);
                }

                $fullColour = count($samplings) >= 3 && count(array_unique($samplings)) === 1;

                continue;
            }

            if ($code === 0xE2 && $length >= 14) {
                $segment = $this->take($handle, $length);
                $collected += $length;

                if ($segment === null || $collected > self::MAX_PROFILE_BYTES) {
                    return null;
                }

                if (str_starts_with($segment, "ICC_PROFILE\0")) {
                    $parts[ord($segment[12])] = substr($segment, 14);
                    $count = ord($segment[13]);
                }

                continue;
            }

            if ($length > 0 && fseek($handle, $length, SEEK_CUR) !== 0) {
                return null;
            }
        }

        return null;
    }

    /**
     * The profile a JPEG's segments make, numbered 1 to their count — or null (as it came) when one is missing.
     *
     * @param  array<int, string>  $parts
     * @return array{profile: ?string, lossless: bool, fullColour: bool}|null
     */
    private function withJpegProfile(array $parts, int $count, bool $fullColour): ?array
    {
        if ($parts === []) {
            return $this->carried(null, false, $fullColour);
        }

        ksort($parts);

        if ($count < 1 || array_keys($parts) !== range(1, $count)) {
            return null;
        }

        return $this->carried(implode('', $parts), false, $fullColour);
    }

    /**
     * A PNG's colours, from the chunks before its data: a profile (iCCP) is carried over, sRGB (or nothing said) is
     * what a WebP is read as anyway, and a gamma or primaries of its own — which a browser honours and GD drops — keep
     * it as it came. A palette PNG is written without loss again. A PNG's colour is always whole.
     *
     * @param  resource  $handle
     * @return array{profile: ?string, lossless: bool, fullColour: bool}|null
     */
    private function aboutPng($handle): ?array
    {
        if ($this->take($handle, 8) !== "\x89PNG\r\n\x1a\n") {
            return null;
        }

        $chunks = [];

        for ($i = 0; $i < self::MAX_PARTS; $i++) {
            $header = $this->take($handle, 8);

            if ($header === null) {
                return null;
            }

            $length = unpack('N', substr($header, 0, 4))[1];
            $type = substr($header, 4, 4);

            if ($type === 'IDAT' || $type === 'IEND') {
                break;
            }

            // A PNG that moves: its animation control comes before the first frame's data.
            if ($type === 'acTL') {
                return null;
            }

            if (in_array($type, ['IHDR', 'iCCP', 'sRGB', 'gAMA', 'cHRM'], true)) {
                $data = $length > self::MAX_PROFILE_BYTES ? null : $this->take($handle, $length);

                if ($data === null || $this->take($handle, 4) === null) {
                    return null;
                }

                $chunks[$type] = $data;

                continue;
            }

            if (fseek($handle, $length + 4, SEEK_CUR) !== 0) {
                return null;
            }
        }

        if (! isset($chunks['IHDR']) || strlen($chunks['IHDR']) < 13) {
            return null;
        }

        $lossless = ord($chunks['IHDR'][9]) === 3;

        if (isset($chunks['iCCP'])) {
            $name = strstr($chunks['iCCP'], "\0", true);
            $profile = $name === false ? false : @gzuncompress(substr($chunks['iCCP'], strlen($name) + 2), self::MAX_PROFILE_BYTES);

            return is_string($profile) ? $this->carried($profile, $lossless, true) : null;
        }

        $otherColours = (isset($chunks['cHRM']) && ! $this->srgbPrimaries($chunks['cHRM']))
            || (isset($chunks['gAMA']) && ! $this->srgbGamma($chunks['gAMA']));

        if ($otherColours && ! isset($chunks['sRGB'])) {
            return null;
        }

        return $this->carried(null, $lossless, true);
    }

    /** A cHRM chunk that names sRGB's own primaries and white point, give or take a hundredth. */
    private function srgbPrimaries(string $chunk): bool
    {
        if (strlen($chunk) !== 32) {
            return false;
        }

        foreach (array_values(unpack('N8', $chunk)) as $at => $value) {
            if (abs($value - self::SRGB_PRIMARIES[$at]) > 1000) {
                return false;
            }
        }

        return true;
    }

    /** A gAMA chunk that names sRGB's gamma, about 1/2.2 (in hundred-thousandths). */
    private function srgbGamma(string $chunk): bool
    {
        return strlen($chunk) === 4 && abs(unpack('N', $chunk)[1] - 45455) <= 1000;
    }

    /**
     * A WebP's colour profile (ICCP), whether it moves (ANIM, ANMF, or the flag in its VP8X header), and whether its
     * picture is lossless (VP8L) — whose colour is whole, where a lossy one's is already at half resolution.
     *
     * @param  resource  $handle
     * @return array{profile: ?string, lossless: bool, fullColour: bool}|null
     */
    private function aboutWebp($handle): ?array
    {
        $header = $this->take($handle, 12);

        if ($header === null || ! str_starts_with($header, 'RIFF') || substr($header, 8, 4) !== 'WEBP') {
            return null;
        }

        $profile = null;

        for ($i = 0; $i < self::MAX_PARTS; $i++) {
            $chunk = $this->take($handle, 8);

            if ($chunk === null) {
                return null;
            }

            $type = substr($chunk, 0, 4);
            $length = unpack('V', substr($chunk, 4, 4))[1];

            if ($type === 'ANIM' || $type === 'ANMF') {
                return null;
            }

            // The picture itself: every chunk that describes it comes first.
            if ($type === 'VP8 ' || $type === 'VP8L' || $type === 'ALPH') {
                return $this->carried($profile, $type === 'VP8L', $type === 'VP8L');
            }

            // A chunk is padded to an even length.
            $skip = $length + ($length & 1);

            if ($type === 'VP8X' || $type === 'ICCP') {
                $data = $length > self::MAX_PROFILE_BYTES ? null : $this->take($handle, $length);

                if ($data === null || ($type === 'VP8X' && (strlen($data) < 10 || (ord($data[0]) & 0x02) !== 0))) {
                    return null;
                }

                if ($type === 'ICCP') {
                    $profile = $data;
                }

                $skip = $length & 1;
            }

            if ($skip > 0 && fseek($handle, $skip, SEEK_CUR) !== 0) {
                return null;
            }
        }

        return null;
    }

    /**
     * What a file says about itself, with the profile to carry over — none, or one a WebP of RGB pixels can carry (an
     * ICC profile describing RGB) — or null (as it came) for any other profile.
     *
     * @return array{profile: ?string, lossless: bool, fullColour: bool}|null
     */
    private function carried(?string $profile, bool $lossless, bool $fullColour): ?array
    {
        $carriable = $profile === null || (strlen($profile) >= 132 && strlen($profile) <= self::MAX_PROFILE_BYTES
            && substr($profile, 36, 4) === 'acsp' && substr($profile, 16, 4) === 'RGB ');

        return $carriable ? ['profile' => $profile, 'lossless' => $lossless, 'fullColour' => $fullColour] : null;
    }

    /**
     * Exactly so many bytes from where the file is, or null when it ends first.
     *
     * @param  resource  $handle
     */
    private function take($handle, int $length): ?string
    {
        if ($length === 0) {
            return '';
        }

        $bytes = fread($handle, $length);

        return is_string($bytes) && strlen($bytes) === $length ? $bytes : null;
    }

    /** Which way the camera held the picture (EXIF Orientation, 1-8); 1 when it does not say. */
    private function orientation(string $path): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data($path, 'IFD0');
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    private function read(string $path, string $mime): ?GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => false,
        };

        if (! $image instanceof GdImage) {
            return null;
        }

        // A palette PNG becomes true colour, keeping every colour and its transparency; nothing is blended away.
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }

    /** The picture the right way up, as EXIF Orientation describes how it was stored. */
    private function turn(GdImage $image, int $orientation): GdImage
    {
        if (in_array($orientation, [3, 5, 6, 7, 8], true)) {
            // imagerotate turns anticlockwise: 270 is a quarter turn clockwise.
            $angle = match ($orientation) {
                3 => 180,
                8 => 90,
                default => 270,
            };
            $turned = imagerotate($image, $angle, 0);

            if ($turned instanceof GdImage) {
                $image = $turned;
            }
        }

        match ($orientation) {
            2, 5 => imageflip($image, IMG_FLIP_HORIZONTAL),
            4, 7 => imageflip($image, IMG_FLIP_VERTICAL),
            default => null,
        };

        return $image;
    }

    /** The picture brought down to the given size, its transparency kept. */
    private function scaled(GdImage $image, int $width, int $height): GdImage
    {
        $scaled = imagecreatetruecolor($width, $height);
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        imagefill($scaled, 0, 0, (int) imagecolorallocatealpha($scaled, 0, 0, 0, 127));
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

        return $scaled;
    }

    /** The picture as a WebP at the quality given, or without loss (no quality) — null when GD cannot write that. */
    private function webp(GdImage $image, ?int $quality): ?string
    {
        if ($quality === null && ! defined('IMG_WEBP_LOSSLESS')) {
            return null;
        }

        ob_start();

        try {
            $written = imagewebp($image, null, $quality ?? IMG_WEBP_LOSSLESS);
        } finally {
            $bytes = (string) ob_get_clean();
        }

        return $written && $bytes !== '' ? $bytes : null;
    }

    /**
     * The WebP GD wrote, carrying the colour profile the picture came with: in the extended format, whose VP8X header
     * says it has one, with the ICCP chunk straight after it — the container's own order. Null when what GD wrote is not
     * a WebP this knows how to extend.
     */
    private function withProfile(string $webp, string $profile, int $width, int $height): ?string
    {
        if (strlen($webp) < 30 || ! str_starts_with($webp, 'RIFF') || substr($webp, 8, 4) !== 'WEBP') {
            return null;
        }

        $chunks = substr($webp, 12);
        $first = substr($chunks, 0, 4);

        if ($first === 'VP8X') {
            $header = 'VP8X'.pack('V', 10).chr(ord($chunks[8]) | 0x20).substr($chunks, 9, 9);
            $chunks = substr($chunks, 18);
        } elseif ($first === 'VP8 ' || $first === 'VP8L') {
            // A lossless picture says in its own header whether it uses transparency (bit 28 after its signature).
            $alpha = $first === 'VP8L' && ((unpack('V', substr($chunks, 9, 4))[1] >> 28) & 1) === 1;
            $header = 'VP8X'.pack('V', 10).chr(0x20 | ($alpha ? 0x10 : 0))."\0\0\0"
                .substr(pack('V', $width - 1), 0, 3).substr(pack('V', $height - 1), 0, 3);
        } else {
            return null;
        }

        $body = 'WEBP'.$header.'ICCP'.pack('V', strlen($profile)).$profile.(strlen($profile) % 2 === 1 ? "\0" : '').$chunks;

        return 'RIFF'.pack('V', strlen($body)).$body;
    }
}
