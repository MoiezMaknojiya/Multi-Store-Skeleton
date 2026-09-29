<?php

namespace Tests\Support;

use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;

/**
 * Real MP4 and WebM files for the tests — the containers only, built box by box and element by element.
 *
 * The server reads a video's length from its bytes (App\Services\VideoDuration), so a test needs bytes that
 * say how long they are, laid out the way a phone, a browser or an editor lays them out: the index first or
 * last, a 64-bit size, a fragmented file, a WebM with no Duration at all. None of them has a picture a browser
 * could draw — the server never decodes one, and PHP's own sniffer (finfo) reads them as the videos they say
 * they are, so `mimes:` judges them by their bytes as it does in production.
 */
final class VideoFiles
{
    /**
     * An MP4 that lasts $seconds.
     *
     * @param  array{version?: int, timescale?: int, track_timescale?: int, mvhd_seconds?: float|null,
     *     edit_seconds?: float|null, moov_at_end?: bool, largesize_mdat?: bool, mdat_bytes?: int,
     *     pad_to?: int|null, fragments?: list<float>|null, fragment_style?: string, mehd_seconds?: float|null,
     *     audio_seconds?: float|null}  $options
     */
    public static function mp4(float $seconds, array $options = []): string
    {
        $version = $options['version'] ?? 0;
        $movieScale = $options['timescale'] ?? 1000;
        $trackScale = $options['track_timescale'] ?? 12800;
        $fragments = $options['fragments'] ?? null;

        $ftyp = self::box('ftyp', 'isom', pack('N', 0x200), 'isom', 'iso2', 'avc1', 'mp41');

        $movieTicks = $fragments !== null ? 0 : (int) round(($options['mvhd_seconds'] ?? $seconds) * $movieScale);
        $tracks = [self::trak(1, $fragments !== null ? 0 : (int) round($seconds * $trackScale), $trackScale, $movieScale, $version, $options['edit_seconds'] ?? null)];

        if (($options['audio_seconds'] ?? null) !== null) {
            $tracks[] = self::trak(2, (int) round($options['audio_seconds'] * 48000), 48000, $movieScale, $version, null);
        }

        $mvex = '';

        if ($fragments !== null) {
            $mehd = ($options['mehd_seconds'] ?? null) !== null
                ? self::fullBox('mehd', 0, 0, pack('N', (int) round($options['mehd_seconds'] * $movieScale)))
                : '';
            // trex's default: one second of the track's timescale per sample.
            $mvex = self::box('mvex', $mehd, self::fullBox('trex', 0, 0, pack('N', 1), pack('N', 1), pack('N', $trackScale), pack('N', 0), pack('N', 0)));
        }

        $moov = self::box('moov', self::mvhd($movieTicks, $movieScale, $version), ...$tracks, ...($mvex !== '' ? [$mvex] : []));

        $body = str_repeat("\0", $options['mdat_bytes'] ?? 1024);
        $mdat = ($options['largesize_mdat'] ?? false)
            ? pack('N', 1).'mdat'.pack('J', 16 + strlen($body)).$body
            : self::box('mdat', $body);

        $parts = ($options['moov_at_end'] ?? false) ? [$ftyp, $mdat, $moov] : [$ftyp, $moov, $mdat];

        if ($fragments !== null) {
            $parts = [$ftyp, $moov, ...self::fragments($fragments, $trackScale, $options['fragment_style'] ?? 'durations')];
        }

        return self::padded(implode('', $parts), $options['pad_to'] ?? null, fn (int $bytes) => self::box('free', str_repeat("\0", max(0, $bytes - 8))));
    }

    /**
     * A WebM that lasts $seconds: one block a second, a cluster every ten.
     *
     * @param  array{declared_seconds?: float|null, with_duration?: bool, unknown_sizes?: bool, scale?: int,
     *     pad_to?: int|null, block_group?: bool}  $options
     */
    public static function webm(float $seconds, array $options = []): string
    {
        $scale = $options['scale'] ?? 1_000_000;
        $perSecond = 1_000_000_000 / $scale;

        $header = self::element(0x1A45DFA3,
            self::element(0x4286, self::uint(1)).self::element(0x42F7, self::uint(1)).self::element(0x42F2, self::uint(4))
            .self::element(0x42F3, self::uint(8)).self::element(0x4282, 'webm').self::element(0x4287, self::uint(2))
            .self::element(0x4285, self::uint(2)));

        $info = self::element(0x2AD7B1, self::uint($scale)).self::element(0x4D80, 'tests').self::element(0x5741, 'tests');

        if ($options['with_duration'] ?? true) {
            $info .= self::element(0x4489, pack('E', ($options['declared_seconds'] ?? $seconds) * $perSecond));
        }

        $tracks = self::element(0x1654AE6B, self::element(0xAE,
            self::element(0xD7, self::uint(1)).self::element(0x73C5, self::uint(1)).self::element(0x83, self::uint(1))
            .self::element(0x86, 'V_VP8').self::element(0xE0, self::element(0xB0, self::uint(640)).self::element(0xBA, self::uint(360)))));

        $unknown = $options['unknown_sizes'] ?? false;
        $clusters = '';
        // A block that says how long it lasts ends a second after it starts; one that does not ends where it starts.
        $last = ($options['block_group'] ?? false) ? (int) ceil($seconds) - 1 : (int) floor($seconds);

        for ($start = 0; $start <= $last; $start += 10) {
            $blocks = '';

            for ($second = $start; $second < min($start + 10, $last + 1); $second++) {
                $relative = (int) round(($second - $start) * $perSecond);
                $block = "\x81".pack('n', $relative & 0xFFFF)."\x80".str_repeat("\x9D", 16);
                $blocks .= ($options['block_group'] ?? false)
                    ? self::element(0xA0, self::element(0xA1, $block).self::element(0x9B, self::uint((int) $perSecond)))
                    : self::element(0xA3, $block);
            }

            $content = self::element(0xE7, self::uint((int) round($start * $perSecond))).$blocks;
            $clusters .= $unknown ? self::id(0x1F43B675)."\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF".$content : self::element(0x1F43B675, $content);
        }

        $payload = self::element(0x1549A966, $info).$tracks.$clusters;
        $segment = $unknown
            ? self::id(0x18538067)."\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF".$payload
            : self::element(0x18538067, $payload, true);

        return self::padded($header.$segment, $options['pad_to'] ?? null, fn (int $bytes) => self::element(0xEC, str_repeat("\0", max(0, $bytes - 9)), true));
    }

    /**
     * An upload of these bytes under a name the client chose. The size it REPORTS may be larger than the
     * bytes (a Testing\File): the storage wall counts what a file weighs without the test writing it.
     */
    public static function upload(string $bytes, string $name, ?int $reportedKilobytes = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'video-');
        file_put_contents($path, $bytes);

        if ($reportedKilobytes === null) {
            return new UploadedFile($path, $name, null, null, true);
        }

        $file = new File($name, fopen($path, 'r+b'));
        $file->sizeToReport = $reportedKilobytes * 1024;

        return $file;
    }

    /* ── MP4 boxes ───────────────────────────────────────────────────────── */

    public static function box(string $type, string ...$payloads): string
    {
        $body = implode('', $payloads);

        return pack('N', 8 + strlen($body)).$type.$body;
    }

    public static function fullBox(string $type, int $version, int $flags, string ...$payloads): string
    {
        return self::box($type, chr($version).substr(pack('N', $flags), 1), ...$payloads);
    }

    private static function matrix(): string
    {
        return pack('N9', 0x00010000, 0, 0, 0, 0x00010000, 0, 0, 0, 0x40000000);
    }

    private static function mvhd(int $ticks, int $scale, int $version): string
    {
        $times = $version === 1 ? pack('J', 0).pack('J', 0).pack('N', $scale).pack('J', $ticks) : pack('N', 0).pack('N', 0).pack('N', $scale).pack('N', $ticks);

        return self::fullBox('mvhd', $version, 0, $times, pack('N', 0x00010000), pack('n', 0x0100), str_repeat("\0", 10), self::matrix(), str_repeat("\0", 24), pack('N', 3));
    }

    private static function trak(int $id, int $mediaTicks, int $trackScale, int $movieScale, int $version, ?float $editSeconds): string
    {
        $presentation = $editSeconds !== null ? (int) round($editSeconds * $movieScale) : (int) round($mediaTicks / $trackScale * $movieScale);
        $tkhd = $version === 1
            ? self::fullBox('tkhd', 1, 3, pack('J', 0), pack('J', 0), pack('N', $id), pack('N', 0), pack('J', $presentation), str_repeat("\0", 8), pack('n4', 0, 0, 0, 0), self::matrix(), pack('N', 640 << 16), pack('N', 360 << 16))
            : self::fullBox('tkhd', 0, 3, pack('N', 0), pack('N', 0), pack('N', $id), pack('N', 0), pack('N', $presentation), str_repeat("\0", 8), pack('n4', 0, 0, 0, 0), self::matrix(), pack('N', 640 << 16), pack('N', 360 << 16));
        $mdhd = $version === 1
            ? self::fullBox('mdhd', 1, 0, pack('J', 0), pack('J', 0), pack('N', $trackScale), pack('J', $mediaTicks), pack('n', 0x55C4), pack('n', 0))
            : self::fullBox('mdhd', 0, 0, pack('N', 0), pack('N', 0), pack('N', $trackScale), pack('N', $mediaTicks), pack('n', 0x55C4), pack('n', 0));
        $edts = $editSeconds !== null
            ? self::box('edts', self::fullBox('elst', 0, 0, pack('N', 1), pack('N', $presentation), pack('N', 0), pack('n', 1), pack('n', 0)))
            : '';
        $hdlr = self::fullBox('hdlr', 0, 0, pack('N', 0), $id === 1 ? 'vide' : 'soun', str_repeat("\0", 12), "Handler\0");

        return self::box('trak', ...array_filter([$tkhd, $edts, self::box('mdia', $mdhd, $hdlr)], fn (string $part) => $part !== ''));
    }

    /**
     * One moof and mdat per fragment. Styles: `durations` — tfdt and a duration on every sample; `defaults` —
     * tfhd's default duration and no tfdt (the fragments add up); `trex` — neither, so trex's default counts.
     *
     * @param  list<float>  $fragments  seconds per fragment
     * @return list<string>
     */
    private static function fragments(array $fragments, int $trackScale, string $style): array
    {
        $boxes = [];
        $start = 0;

        foreach ($fragments as $index => $seconds) {
            $samples = max(1, (int) round($seconds));
            $each = (int) round($seconds * $trackScale / $samples);

            $tfhd = $style === 'defaults'
                ? self::fullBox('tfhd', 0, 0x8, pack('N', 1), pack('N', $each))
                : self::fullBox('tfhd', 0, 0, pack('N', 1));
            $tfdt = $style === 'durations' ? self::fullBox('tfdt', 1, 0, pack('J', $start)) : '';
            $trun = $style === 'durations'
                ? self::fullBox('trun', 0, 0x100 | 0x200, pack('N', $samples), str_repeat(pack('N', $each).pack('N', 16), $samples))
                : self::fullBox('trun', 0, 0x200, pack('N', $samples), str_repeat(pack('N', 16), $samples));

            $boxes[] = self::box('moof', self::fullBox('mfhd', 0, 0, pack('N', $index + 1)),
                self::box('traf', ...array_filter([$tfhd, $tfdt, $trun], fn (string $part) => $part !== '')));
            $boxes[] = self::box('mdat', str_repeat("\0", 16 * $samples));
            $start += $samples * $each;
        }

        return $boxes;
    }

    /* ── WebM elements ───────────────────────────────────────────────────── */

    /** An element with the shortest size that holds its payload — as every real muxer writes it — or 8 bytes of size. */
    public static function element(int $id, string $payload, bool $eightByteSize = false): string
    {
        return self::id($id).self::size(strlen($payload), $eightByteSize).$payload;
    }

    private static function id(int $id): string
    {
        $bytes = '';

        while ($id > 0) {
            $bytes = chr($id & 0xFF).$bytes;
            $id >>= 8;
        }

        return $bytes;
    }

    private static function size(int $size, bool $eightBytes): string
    {
        for ($length = $eightBytes ? 8 : 1; $length <= 8; $length++) {
            if ($size < (1 << (7 * $length)) - 1) {
                $bytes = '';
                $value = $size;

                for ($i = 0; $i < $length; $i++) {
                    $bytes = chr($value & 0xFF).$bytes;
                    $value >>= 8;
                }

                return chr(ord($bytes[0]) | (0x80 >> ($length - 1))).substr($bytes, 1);
            }
        }

        throw new \InvalidArgumentException('too big');
    }

    private static function uint(int $value): string
    {
        $bytes = '';

        do {
            $bytes = chr($value & 0xFF).$bytes;
            $value >>= 8;
        } while ($value > 0);

        return $bytes;
    }

    /** The file grown to $padTo bytes with a box or element that says nothing. */
    private static function padded(string $file, ?int $padTo, \Closure $filler): string
    {
        return $padTo !== null && $padTo > strlen($file) + 16 ? $file.$filler($padTo - strlen($file)) : $file;
    }
}
