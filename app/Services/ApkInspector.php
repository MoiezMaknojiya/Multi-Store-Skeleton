<?php

namespace App\Services;

use ZipArchive;

/**
 * What an uploaded file says about itself as an Android app (Developer → TV App, owner 2026-10-10): whether it is an APK at
 * all — a zip holding AndroidManifest.xml and classes.dex — and, read from that manifest, the app's package, version code and
 * version name. The manifest inside an APK is Android's binary XML; its string pool and the manifest element's attributes are
 * read here, every offset checked against what is there, so a damaged or crafted file reads as "could not read", never as a
 * crash. The version is what the page offers to type by hand when it cannot be read.
 */
class ApkInspector
{
    /** A manifest larger than this is no app's (the TV app's is some 6 KB). */
    private const MAX_MANIFEST_BYTES = 2 * 1024 * 1024;

    private const XML = 0x0003;

    private const STRING_POOL = 0x0001;

    private const START_ELEMENT = 0x0102;

    private const UTF8_FLAG = 0x100;

    private const TYPE_STRING = 0x03;

    private const TYPE_INT_DEC = 0x10;

    private const TYPE_INT_HEX = 0x11;

    // android:versionCode and android:versionName, should a build keep the names out of its string pool.
    private const VERSION_CODE_ID = 0x0101021B;

    private const VERSION_NAME_ID = 0x0101021C;

    /**
     * @return array{is_apk: bool, package: ?string, version_code: ?int, version_name: ?string}
     */
    public function inspect(string $path): array
    {
        $none = ['is_apk' => false, 'package' => null, 'version_code' => null, 'version_name' => null];
        $zip = new ZipArchive;

        if (! is_file($path) || $zip->open($path, ZipArchive::RDONLY) !== true) {
            return $none;
        }

        try {
            $manifest = $zip->statName('AndroidManifest.xml');

            if ($manifest === false || $zip->locateName('classes.dex') === false) {
                return $none;
            }

            $read = ['is_apk' => true, 'package' => null, 'version_code' => null, 'version_name' => null];

            if ($manifest['size'] < 8 || $manifest['size'] > self::MAX_MANIFEST_BYTES) {
                return $read;
            }

            $bytes = $zip->getFromName('AndroidManifest.xml', self::MAX_MANIFEST_BYTES);

            return is_string($bytes) ? [...$read, ...$this->manifest($bytes)] : $read;
        } finally {
            $zip->close();
        }
    }

    /**
     * The manifest element's package, versionCode and versionName from binary XML — each null when it is not there or
     * cannot be read.
     *
     * @return array{package: ?string, version_code: ?int, version_name: ?string}
     */
    public function manifest(string $bytes): array
    {
        $unread = ['package' => null, 'version_code' => null, 'version_name' => null];
        $length = strlen($bytes);

        if ($length < 8 || $this->u16($bytes, 0) !== self::XML) {
            return $unread;
        }

        $strings = null;
        $resourceIds = [];
        $offset = $this->u16($bytes, 2);

        // Chunk after chunk, each of the size its header says, until the manifest element.
        for ($guard = 0; $offset + 8 <= $length && $guard < 10000; $guard++) {
            $type = $this->u16($bytes, $offset);
            $headerSize = $this->u16($bytes, $offset + 2);
            $size = $this->u32($bytes, $offset + 4);

            if ($size < 8 || $headerSize < 8 || $offset + $size > $length) {
                return $unread;
            }

            if ($type === self::STRING_POOL) {
                $strings = $this->stringPool($bytes, $offset, $size);
            } elseif ($type === 0x0180) {
                for ($i = $offset + $headerSize; $i + 4 <= $offset + $size; $i += 4) {
                    $resourceIds[] = $this->u32($bytes, $i);
                }
            } elseif ($type === self::START_ELEMENT) {
                if ($strings === null) {
                    return $unread;
                }

                return $this->manifestElement($bytes, $offset, $headerSize, $size, $strings, $resourceIds) ?? $unread;
            }

            $offset += $size;
        }

        return $unread;
    }

    /**
     * @param  list<?string>  $strings
     * @param  list<int>  $resourceIds
     * @return array{package: ?string, version_code: ?int, version_name: ?string}|null
     */
    private function manifestElement(string $bytes, int $offset, int $headerSize, int $size, array $strings, array $resourceIds): ?array
    {
        $ext = $offset + $headerSize;

        if ($ext + 20 > $offset + $size || ($strings[$this->u32($bytes, $ext + 4)] ?? null) !== 'manifest') {
            return null;
        }

        $attributesAt = $ext + $this->u16($bytes, $ext + 8);
        $attributeSize = $this->u16($bytes, $ext + 10);
        $count = $this->u16($bytes, $ext + 12);

        if ($attributeSize < 20) {
            return null;
        }

        $found = ['package' => null, 'version_code' => null, 'version_name' => null];

        for ($i = 0; $i < $count; $i++) {
            $at = $attributesAt + $i * $attributeSize;

            if ($at + 20 > $offset + $size) {
                return null;
            }

            $nameIndex = $this->u32($bytes, $at + 4);
            $name = $strings[$nameIndex] ?? null;
            $resourceId = $resourceIds[$nameIndex] ?? null;
            $raw = $this->u32($bytes, $at + 8);
            $dataType = ord($bytes[$at + 15]);
            $data = $this->u32($bytes, $at + 16);
            $text = $dataType === self::TYPE_STRING ? ($strings[$data] ?? null) : ($raw !== 0xFFFFFFFF ? ($strings[$raw] ?? null) : null);

            if ($name === 'package') {
                $found['package'] = $text;
            } elseif ($name === 'versionCode' || $resourceId === self::VERSION_CODE_ID) {
                $found['version_code'] = in_array($dataType, [self::TYPE_INT_DEC, self::TYPE_INT_HEX], true) && $data >= 1 && $data <= 2100000000
                    ? $data
                    : (is_string($text) && ctype_digit($text) ? (int) $text : null);
            } elseif ($name === 'versionName' || $resourceId === self::VERSION_NAME_ID) {
                $found['version_name'] = $text;
            }
        }

        $name = $found['version_name'];
        $found['version_name'] = is_string($name) && preg_match('/^[\pL\pN ._+-]{1,32}$/u', $name) ? $name : null;
        $found['package'] = is_string($found['package']) && preg_match('/^[A-Za-z][\w.]{0,199}$/', $found['package']) ? $found['package'] : null;

        return $found;
    }

    /**
     * Every string of a pool, UTF-8 or UTF-16 as its flags say; a string that cannot be read is null.
     *
     * @return list<?string>
     */
    private function stringPool(string $bytes, int $offset, int $size): array
    {
        if ($size < 28) {
            return [];
        }

        $count = min($this->u32($bytes, $offset + 8), 100000);
        $utf8 = ($this->u32($bytes, $offset + 16) & self::UTF8_FLAG) !== 0;
        $stringsStart = $offset + $this->u32($bytes, $offset + 20);
        $offsetsAt = $offset + $this->u16($bytes, $offset + 2);
        $end = $offset + $size;
        $strings = [];

        for ($i = 0; $i < $count; $i++) {
            if ($offsetsAt + 4 * $i + 4 > $end) {
                break;
            }

            $at = $stringsStart + $this->u32($bytes, $offsetsAt + 4 * $i);
            $strings[] = $at < $end ? ($utf8 ? $this->utf8String($bytes, $at, $end) : $this->utf16String($bytes, $at, $end)) : null;
        }

        return $strings;
    }

    private function utf8String(string $bytes, int $at, int $end): ?string
    {
        // The length in UTF-16 units, then in bytes, each one byte or two.
        $at += (ord($bytes[$at]) & 0x80) !== 0 ? 2 : 1;

        if ($at + 1 >= $end) {
            return null;
        }

        $length = ord($bytes[$at]);
        if (($length & 0x80) !== 0) {
            $length = (($length & 0x7F) << 8) | ord($bytes[$at + 1]);
            $at += 2;
        } else {
            $at += 1;
        }

        if ($at + $length > $end) {
            return null;
        }

        $text = substr($bytes, $at, $length);

        return mb_check_encoding($text, 'UTF-8') ? $text : null;
    }

    private function utf16String(string $bytes, int $at, int $end): ?string
    {
        if ($at + 2 > $end) {
            return null;
        }

        $length = $this->u16($bytes, $at);
        $at += 2;

        if (($length & 0x8000) !== 0) {
            if ($at + 2 > $end) {
                return null;
            }
            $length = (($length & 0x7FFF) << 16) | $this->u16($bytes, $at);
            $at += 2;
        }

        if ($at + 2 * $length > $end) {
            return null;
        }

        $text = mb_convert_encoding(substr($bytes, $at, 2 * $length), 'UTF-8', 'UTF-16LE');

        return mb_check_encoding($text, 'UTF-8') ? $text : null;
    }

    private function u16(string $bytes, int $at): int
    {
        return $at >= 0 && $at + 2 <= strlen($bytes) ? unpack('v', $bytes, $at)[1] : 0;
    }

    private function u32(string $bytes, int $at): int
    {
        return $at >= 0 && $at + 4 <= strlen($bytes) ? unpack('V', $bytes, $at)[1] : 0;
    }
}
