<?php

namespace App\Services;

use Throwable;

/**
 * How long a video really is, read from the file itself — never from what the browser says (owner's rule,
 * 2026-09-28: no video over 5 minutes in a library or a channel, none over 60 seconds in the ads network).
 *
 * There is no ffmpeg on the server, and the two containers the panel accepts keep their length where a few
 * reads find it:
 *
 * - MP4 (ISO base media): the movie header (`mvhd`); each track's presentation — its edit list (`elst`), or
 *   with none all of its media (`mdhd`); the fragment duration (`mehd`); and, for a fragmented file, the end
 *   of each track's last fragment (`moof`: `tfdt` plus the sample durations of `trun`, with the `tfhd` and
 *   `trex` defaults). The index may sit at the end of the file, where a phone writes it.
 * - WebM (Matroska): the segment's `Duration`, and the last block of the last cluster — which is also how a
 *   file recorded in a browser, with no Duration at all, is measured.
 *
 * The LONGEST of what a file says is its length, so a short header cannot hide an hour of pictures behind
 * it. Anything malformed, truncated, negative or unrecognised is null, and the rule refuses it: a file the
 * server cannot measure is not one it lets onto a screen. Every loop is bounded, so a crafted file cannot keep
 * a request busy.
 */
class VideoDuration
{
    /** Boxes or elements read from one file, at most. */
    private const MAX_STEPS = 1_000_000;

    /** A moov or a moof larger than this is not read at all. */
    private const MAX_BOX_BYTES = 64 * 1024 * 1024;

    /** Matroska's Info, read whole, is never larger than this. */
    private const MAX_INFO_BYTES = 1024 * 1024;

    /** What a file measured before, by path, size and modification time: one request asks twice. */
    private array $memo = [];

    private int $steps = 0;

    /* What an MP4's boxes have said so far — reset for every file. */

    private bool $broken = false;

    private int $movieTimescale = 0;

    private ?int $headerTicks = null;

    private ?int $fragmentTicks = null;

    /** @var array<int, int> timescale by track id */
    private array $trackTimescales = [];

    /** @var list<int> each track's edit list, in the movie's timescale */
    private array $editTicks = [];

    /** @var list<float> each track with no edit list: all of its media */
    private array $trackSeconds = [];

    /** @var array<int, int> a fragment sample's default duration, by track id */
    private array $trexDurations = [];

    /** @var array<int, int> where each track's fragments have reached */
    private array $fragmentRunning = [];

    /** @var array<int, int> the furthest any fragment of each track reaches */
    private array $fragmentEnds = [];

    /** The file's length in seconds, or null when it cannot be read. */
    public function seconds(string $path): ?float
    {
        clearstatcache(true, $path);
        $size = @filesize($path);

        if ($size === false || $size < 8) {
            return null;
        }

        $key = $path.'|'.$size.'|'.@filemtime($path);

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $this->steps = 0;

        try {
            $head = (string) fread($handle, 12);

            $seconds = match (true) {
                str_starts_with($head, "\x1A\x45\xDF\xA3") => $this->matroska($handle, $size),
                strlen($head) >= 8 && in_array(substr($head, 4, 4), ['ftyp', 'styp', 'moov', 'mdat', 'free', 'skip', 'wide'], true) => $this->isoBmff($handle, $size),
                default => null,
            };
        } catch (Throwable) {
            $seconds = null;
        } finally {
            fclose($handle);
        }

        return $this->memo[$key] = ($seconds !== null && is_finite($seconds) && $seconds > 0 ? $seconds : null);
    }

    /* ── MP4 ─────────────────────────────────────────────────────────────── */

    /** @param  resource  $handle */
    private function isoBmff($handle, int $fileSize): ?float
    {
        $this->broken = false;
        $this->movieTimescale = 0;
        $this->headerTicks = null;
        $this->fragmentTicks = null;
        $this->trackTimescales = [];
        $this->editTicks = [];
        $this->trackSeconds = [];
        $this->trexDurations = [];
        $this->fragmentRunning = [];
        $this->fragmentEnds = [];

        for ($offset = 0; $offset + 8 <= $fileSize;) {
            if (++$this->steps > self::MAX_STEPS) {
                return null;
            }

            fseek($handle, $offset);
            [$type, $headerSize, $boxSize] = $this->boxHeader((string) fread($handle, 16), $fileSize - $offset);

            if ($type === null) {
                return null;
            }

            if ($type === 'moov' || $type === 'moof') {
                $payloadSize = $boxSize - $headerSize;

                if ($payloadSize > self::MAX_BOX_BYTES) {
                    return null;
                }

                fseek($handle, $offset + $headerSize);
                $payload = $payloadSize > 0 ? (string) fread($handle, $payloadSize) : '';

                if (strlen($payload) !== $payloadSize) {
                    return null;
                }

                $type === 'moov' ? $this->moov($payload) : $this->moof($payload);
            }

            $offset += $boxSize;
        }

        return $this->broken ? null : $this->longestMp4();
    }

    private function longestMp4(): ?float
    {
        $seconds = $this->trackSeconds;

        if ($this->movieTimescale > 0) {
            foreach (array_filter([$this->headerTicks, $this->fragmentTicks, ...$this->editTicks], fn (?int $ticks) => $ticks !== null) as $ticks) {
                $seconds[] = $ticks / $this->movieTimescale;
            }
        }

        foreach ($this->fragmentEnds as $trackId => $ticks) {
            $timescale = $this->trackTimescales[$trackId] ?? 0;

            if ($timescale > 0) {
                $seconds[] = $ticks / $timescale;
            }
        }

        return $seconds === [] ? null : (float) max($seconds);
    }

    /**
     * A box header: its type, how long the header is and how long the whole box is. A size of 1 is followed by
     * a 64-bit one; 0 runs to the end of what is left. Anything that does not add up is a null type.
     *
     * @return array{0: string|null, 1: int, 2: int}
     */
    private function boxHeader(string $bytes, int $left): array
    {
        if (strlen($bytes) < 8) {
            return [null, 0, 0];
        }

        $size = unpack('N', $bytes)[1];
        $type = substr($bytes, 4, 4);
        $headerSize = 8;

        if (preg_match('/^[\x20-\x7E]{4}$/', $type) !== 1) {
            return [null, 0, 0];
        }

        if ($size === 1) {
            if (strlen($bytes) < 16) {
                return [null, 0, 0];
            }

            $size = unpack('J', $bytes, 8)[1];
            $headerSize = 16;
        } elseif ($size === 0) {
            $size = $left;
        }

        if ($size < $headerSize || $size > $left) {
            return [null, 0, 0];
        }

        return [$type, $headerSize, $size];
    }

    /**
     * The boxes directly inside a payload, as [type, payload]. A level that stops adding up is read no further,
     * and nothing is ever read beyond the payload given.
     *
     * @return \Generator<int, array{0: string, 1: string}>
     */
    private function children(string $data): \Generator
    {
        $length = strlen($data);

        for ($offset = 0; $offset + 8 <= $length;) {
            if (++$this->steps > self::MAX_STEPS) {
                $this->broken = true;

                return;
            }

            [$type, $headerSize, $size] = $this->boxHeader(substr($data, $offset, 16), $length - $offset);

            if ($type === null) {
                return;
            }

            yield [$type, substr($data, $offset + $headerSize, $size - $headerSize)];

            $offset += $size;
        }
    }

    /** A 64-bit field: a value past 2^63 reads as negative in PHP, and no real file has one. */
    private function u64(string $bytes, int $at): int
    {
        $value = unpack('J', $bytes, $at)[1];

        if ($value < 0) {
            $this->broken = true;

            return 0;
        }

        return $value;
    }

    private function moov(string $payload): void
    {
        foreach ($this->children($payload) as [$type, $box]) {
            match ($type) {
                'mvhd' => $this->mvhd($box),
                'trak' => $this->trak($box),
                'mvex' => $this->mvex($box),
                default => null,
            };
        }
    }

    private function mvhd(string $box): void
    {
        [$timescale, $ticks] = $this->timescaleAndTicks($box);

        if ($timescale > 0) {
            $this->movieTimescale = $timescale;
            $this->headerTicks = $ticks;
        }
    }

    /**
     * The timescale and duration of an mvhd or an mdhd, which begin alike. A duration of all ones is unknown.
     *
     * @return array{0: int, 1: int|null}
     */
    private function timescaleAndTicks(string $box): array
    {
        if (ord($box[0] ?? "\0") === 1) {
            if (strlen($box) < 32) {
                return [0, null];
            }

            $ticks = unpack('J', $box, 24)[1];

            return [unpack('N', $box, 20)[1], $ticks === -1 ? null : $this->u64($box, 24)];
        }

        if (strlen($box) < 20) {
            return [0, null];
        }

        $ticks = unpack('N', $box, 16)[1];

        return [unpack('N', $box, 12)[1], $ticks === 0xFFFFFFFF ? null : $ticks];
    }

    private function trak(string $payload): void
    {
        $trackId = null;
        $mediaTimescale = 0;
        $mediaTicks = null;
        $editTicks = null;

        foreach ($this->children($payload) as [$type, $box]) {
            if ($type === 'tkhd') {
                $at = ord($box[0] ?? "\0") === 1 ? 20 : 12;
                $trackId = strlen($box) >= $at + 4 ? unpack('N', $box, $at)[1] : null;
            } elseif ($type === 'edts') {
                foreach ($this->children($box) as [$inner, $elst]) {
                    if ($inner === 'elst') {
                        $editTicks = $this->elstTicks($elst);
                    }
                }
            } elseif ($type === 'mdia') {
                foreach ($this->children($box) as [$inner, $mdhd]) {
                    if ($inner === 'mdhd') {
                        [$mediaTimescale, $mediaTicks] = $this->timescaleAndTicks($mdhd);
                    }
                }
            }
        }

        if ($trackId !== null && $mediaTimescale > 0) {
            $this->trackTimescales[$trackId] = $mediaTimescale;
        }

        // What the track shows: its edit list when it has one, else all of its media.
        if ($editTicks !== null && $editTicks > 0) {
            $this->editTicks[] = $editTicks;
        } elseif ($mediaTimescale > 0 && $mediaTicks !== null) {
            $this->trackSeconds[] = $mediaTicks / $mediaTimescale;
        }
    }

    /** The sum of an edit list's segments, in the movie's timescale. */
    private function elstTicks(string $box): ?int
    {
        if (strlen($box) < 8) {
            return null;
        }

        $version = ord($box[0]);
        $count = unpack('N', $box, 4)[1];
        $entry = $version === 1 ? 20 : 12;

        if ($count * $entry > strlen($box) - 8) {
            $this->broken = true;

            return null;
        }

        $ticks = 0;

        for ($i = 0; $i < $count; $i++) {
            $at = 8 + $i * $entry;
            $ticks += $version === 1 ? $this->u64($box, $at) : unpack('N', $box, $at)[1];
        }

        return $ticks;
    }

    private function mvex(string $payload): void
    {
        foreach ($this->children($payload) as [$type, $box]) {
            if ($type === 'mehd' && strlen($box) >= 8) {
                $this->fragmentTicks = ord($box[0]) === 1 && strlen($box) >= 12 ? $this->u64($box, 4) : unpack('N', $box, 4)[1];
            } elseif ($type === 'trex' && strlen($box) >= 16) {
                $this->trexDurations[unpack('N', $box, 4)[1]] = unpack('N', $box, 12)[1];
            }
        }
    }

    private function moof(string $payload): void
    {
        foreach ($this->children($payload) as [$type, $traf]) {
            if ($type === 'traf') {
                $this->traf($traf);
            }
        }
    }

    private function traf(string $payload): void
    {
        $trackId = null;
        $default = null;
        $start = null;
        $runs = [];

        foreach ($this->children($payload) as [$type, $box]) {
            if ($type === 'tfhd' && strlen($box) >= 8) {
                $flags = unpack('N', $box)[1] & 0xFFFFFF;
                $trackId = unpack('N', $box, 4)[1];
                $at = 8 + ($flags & 0x1 ? 8 : 0) + ($flags & 0x2 ? 4 : 0);

                if ($flags & 0x8 && strlen($box) >= $at + 4) {
                    $default = unpack('N', $box, $at)[1];
                }
            } elseif ($type === 'tfdt' && strlen($box) >= 8) {
                $start = ord($box[0]) === 1 && strlen($box) >= 12 ? $this->u64($box, 4) : unpack('N', $box, 4)[1];
            } elseif ($type === 'trun') {
                $runs[] = $box;
            }
        }

        if ($trackId === null) {
            return;
        }

        // The runs are summed once the track's own default is known, whatever order the boxes came in.
        $default ??= $this->trexDurations[$trackId] ?? 0;
        $ticks = array_sum(array_map(fn (string $run) => $this->trunTicks($run, $default), $runs));

        $begin = $start ?? ($this->fragmentRunning[$trackId] ?? 0);
        $this->fragmentRunning[$trackId] = $begin + $ticks;
        $this->fragmentEnds[$trackId] = max($this->fragmentEnds[$trackId] ?? 0, $begin + $ticks);
    }

    /** The samples of one run: their own durations when the run carries them, else the default each. */
    private function trunTicks(string $box, int $default): int
    {
        if (strlen($box) < 8) {
            return 0;
        }

        $flags = unpack('N', $box)[1] & 0xFFFFFF;
        $count = unpack('N', $box, 4)[1];
        $at = 8 + ($flags & 0x1 ? 4 : 0) + ($flags & 0x4 ? 4 : 0);

        if (! ($flags & 0x100)) {
            return $count * $default;
        }

        $perSample = 4 * (1 + ($flags & 0x200 ? 1 : 0) + ($flags & 0x400 ? 1 : 0) + ($flags & 0x800 ? 1 : 0));

        if ($count * $perSample > strlen($box) - $at) {
            $this->broken = true;

            return 0;
        }

        $ticks = 0;

        for ($i = 0; $i < $count; $i++) {
            $ticks += unpack('N', $box, $at + $i * $perSample)[1];
        }

        return $ticks;
    }

    /* ── WebM ────────────────────────────────────────────────────────────── */

    /** @param  resource  $handle */
    private function matroska($handle, int $fileSize): ?float
    {
        [$id, $size, $dataStart] = $this->element($handle, 0, $fileSize);

        if ($id !== 0x1A45DFA3 || $size === null) {
            return null;
        }

        // The segment, past any Void or CRC a muxer left after the header.
        for ($offset = $dataStart + $size; ;) {
            if (++$this->steps > self::MAX_STEPS) {
                return null;
            }

            [$id, $size, $dataStart] = $this->element($handle, $offset, $fileSize);

            if (($id === 0xEC || $id === 0xBF) && $size !== null) {
                $offset = $dataStart + $size;

                continue;
            }

            break;
        }

        if ($id !== 0x18538067) {
            return null;
        }

        $end = $size === null ? $fileSize : min($fileSize, $dataStart + $size);
        $scale = 1_000_000;
        $declared = null;
        $clusterTime = null;
        $blockStart = null;
        $lastTick = null;

        for ($offset = $dataStart; $offset < $end;) {
            if (++$this->steps > self::MAX_STEPS) {
                return null;
            }

            [$id, $size, $dataStart] = $this->element($handle, $offset, $end);

            if ($id === null) {
                break;
            }

            // A cluster and a block group are walked into, whatever their size; everything else is stepped over.
            if ($id === 0x1F43B675 || $id === 0xA0) {
                if ($id === 0x1F43B675) {
                    $clusterTime = null;
                }

                $offset = $dataStart;

                continue;
            }

            if ($size === null || $dataStart + $size > $end) {
                break;
            }

            if ($id === 0x1549A966 && $size <= self::MAX_INFO_BYTES) {
                fseek($handle, $dataStart);
                [$scale, $declared] = $this->info((string) fread($handle, $size), $scale, $declared);
            } elseif ($id === 0xE7 && $size >= 1 && $size <= 8) {
                fseek($handle, $dataStart);
                $clusterTime = $this->unsigned((string) fread($handle, $size));
            } elseif (($id === 0xA3 || $id === 0xA1) && $clusterTime !== null) {
                fseek($handle, $dataStart);
                $relative = $this->blockTimecode((string) fread($handle, min($size, 12)));

                if ($relative !== null) {
                    $blockStart = $clusterTime + $relative;
                    $lastTick = max($lastTick ?? 0, $blockStart);
                }
            } elseif ($id === 0x9B && $blockStart !== null && $size >= 1 && $size <= 8) {
                fseek($handle, $dataStart);
                $lastTick = max($lastTick ?? 0, $blockStart + $this->unsigned((string) fread($handle, $size)));
            }

            $offset = $dataStart + $size;
        }

        if ($scale <= 0) {
            return null;
        }

        $longest = max($declared ?? 0.0, (float) ($lastTick ?? 0));

        return $longest > 0 ? $longest * $scale / 1e9 : null;
    }

    /**
     * An element's id and size at $offset, and where its data starts. A size of all ones is unknown (null).
     *
     * @param  resource  $handle
     * @return array{0: int|null, 1: int|null, 2: int}
     */
    private function element($handle, int $offset, int $end): array
    {
        if ($offset + 2 > $end) {
            return [null, null, 0];
        }

        fseek($handle, $offset);
        $bytes = (string) fread($handle, 12);
        $idLength = $this->vintLength(ord($bytes[0] ?? "\0"), 4);

        if ($idLength === null || strlen($bytes) < $idLength + 1) {
            return [null, null, 0];
        }

        $sizeLength = $this->vintLength(ord($bytes[$idLength]), 8);

        if ($sizeLength === null || strlen($bytes) < $idLength + $sizeLength) {
            return [null, null, 0];
        }

        [$size, $unknown] = $this->vintValue(substr($bytes, $idLength, $sizeLength));

        return [$this->unsigned(substr($bytes, 0, $idLength)), $unknown ? null : $size, $offset + $idLength + $sizeLength];
    }

    /** How many bytes a variable-length number takes, from its first byte, or null past $longest. */
    private function vintLength(int $first, int $longest): ?int
    {
        for ($length = 1; $length <= $longest; $length++) {
            if ($first & (0x80 >> ($length - 1))) {
                return $length;
            }
        }

        return null;
    }

    /**
     * A size: its value without the length marker, and whether every bit of it is set (unknown).
     *
     * @return array{0: int, 1: bool}
     */
    private function vintValue(string $raw): array
    {
        $marker = 0x80 >> (strlen($raw) - 1);
        $value = ord($raw[0]) & ($marker - 1);
        $allOnes = $value === $marker - 1;

        for ($i = 1; $i < strlen($raw); $i++) {
            $value = $value * 256 + ord($raw[$i]);
            $allOnes = $allOnes && ord($raw[$i]) === 0xFF;
        }

        return [$value, $allOnes];
    }

    private function unsigned(string $bytes): int
    {
        $value = 0;

        foreach (str_split($bytes) as $byte) {
            $value = $value * 256 + ord($byte);
        }

        return $value;
    }

    /**
     * The TimecodeScale and the Duration inside Info.
     *
     * @return array{0: int, 1: float|null}
     */
    private function info(string $data, int $scale, ?float $declared): array
    {
        $length = strlen($data);

        for ($offset = 0; $offset + 2 <= $length;) {
            $idLength = $this->vintLength(ord($data[$offset]), 4);

            if ($idLength === null || $offset + $idLength >= $length) {
                break;
            }

            $sizeLength = $this->vintLength(ord($data[$offset + $idLength]), 8);

            if ($sizeLength === null || $offset + $idLength + $sizeLength > $length) {
                break;
            }

            $id = $this->unsigned(substr($data, $offset, $idLength));
            [$size] = $this->vintValue(substr($data, $offset + $idLength, $sizeLength));
            $start = $offset + $idLength + $sizeLength;

            if ($start + $size > $length) {
                break;
            }

            $value = substr($data, $start, $size);

            if ($id === 0x2AD7B1 && $size >= 1 && $size <= 8) {
                $scale = $this->unsigned($value);
            } elseif ($id === 0x4489 && ($size === 4 || $size === 8)) {
                $float = unpack($size === 4 ? 'G' : 'E', $value)[1];
                $declared = is_finite($float) && $float > 0 ? $float : $declared;
            }

            $offset = $start + $size;
        }

        return [$scale, $declared];
    }

    /** A block's timecode relative to its cluster: after its track number, a signed 16-bit number. */
    private function blockTimecode(string $bytes): ?int
    {
        $trackLength = $this->vintLength(ord($bytes[0] ?? "\0"), 8);

        if ($trackLength === null || strlen($bytes) < $trackLength + 2) {
            return null;
        }

        return unpack('n', $bytes, $trackLength)[1] << 48 >> 48;
    }
}
