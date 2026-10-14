<?php

namespace Tests\Support;

use App\Models\PlayerAppRelease;
use ZipArchive;

/**
 * APKs for the tests of Developer → TV App: a zip holding classes.dex and an AndroidManifest.xml in Android's binary XML,
 * written here the way aapt2 writes one — a UTF-8 string pool, the attribute resource ids, and the manifest element with its
 * package, versionCode and versionName — so App\Services\ApkInspector reads them as it reads the real app's.
 */
final class ApkFiles
{
    /**
     * A manifest saying the package and version given; a null leaves that attribute out. With no `$versionCode`, a
     * `$versionCodeText` writes the version code as words in the string pool, as a build may.
     */
    public static function manifest(?string $package = PlayerAppRelease::PACKAGE, ?int $versionCode = 2, ?string $versionName = '1.1', ?string $versionCodeText = null): string
    {
        $strings = ['versionCode', 'versionName', 'manifest', 'package', $package ?? '', $versionName ?? ''];
        if ($versionCodeText !== null) {
            $strings[] = $versionCodeText;
        }
        $index = array_flip(['versionCode', 'versionName', 'manifest', 'package']);
        $packageIndex = 4;
        $nameIndex = 5;

        // The string pool: offsets, then each string as its UTF-16 length, its UTF-8 length, its bytes and a zero.
        $data = '';
        $offsets = '';
        foreach ($strings as $string) {
            $offsets .= pack('V', strlen($data));
            $data .= chr(mb_strlen($string)).chr(strlen($string)).$string."\0";
        }
        $data .= str_repeat("\0", (4 - strlen($data) % 4) % 4);
        $poolHeader = 28;
        $stringsStart = $poolHeader + strlen($offsets);
        $pool = pack('vvV', 0x0001, $poolHeader, $stringsStart + strlen($data))
            .pack('VVVVV', count($strings), 0, 0x100, $stringsStart, 0).$offsets.$data;

        // The resource ids of the first two strings: android:versionCode and android:versionName.
        $map = pack('vvV', 0x0180, 8, 16).pack('VV', 0x0101021B, 0x0101021C);

        $attributes = [];
        if ($package !== null) {
            $attributes[] = pack('VVVvCCV', 0xFFFFFFFF, $index['package'], $packageIndex, 8, 0, 0x03, $packageIndex);
        }
        if ($versionCode !== null) {
            $attributes[] = pack('VVVvCCV', 0xFFFFFFFF, $index['versionCode'], 0xFFFFFFFF, 8, 0, 0x10, $versionCode);
        } elseif ($versionCodeText !== null) {
            $attributes[] = pack('VVVvCCV', 0xFFFFFFFF, $index['versionCode'], 6, 8, 0, 0x03, 6);
        }
        if ($versionName !== null) {
            $attributes[] = pack('VVVvCCV', 0xFFFFFFFF, $index['versionName'], $nameIndex, 8, 0, 0x03, $nameIndex);
        }

        $element = pack('VV', 1, 0xFFFFFFFF)
            .pack('VVvvvvvv', 0xFFFFFFFF, $index['manifest'], 20, 20, count($attributes), 0, 0, 0)
            .implode('', $attributes);
        $element = pack('vvV', 0x0102, 16, 8 + strlen($element)).$element;

        $body = $pool.$map.$element;

        return pack('vvV', 0x0003, 8, 8 + strlen($body)).$body;
    }

    /**
     * The bytes of an APK: a zip with the manifest given (the TV app's 1.1, code 2, by default) and classes.dex. Without a
     * manifest or without the dex it is a zip that is no app.
     */
    public static function apk(?string $manifest = null, bool $withManifest = true, bool $withDex = true, int $padding = 0): string
    {
        $path = tempnam(sys_get_temp_dir(), 'apk');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        if ($withManifest) {
            $zip->addFromString('AndroidManifest.xml', $manifest ?? self::manifest());
        }
        if ($withDex) {
            $zip->addFromString('classes.dex', "dex\n035\0".str_repeat("\0", 64));
        }
        if ($padding > 0) {
            // Bytes that do not compress, so the file is about the size asked for.
            $zip->addFromString('assets/padding.bin', random_bytes($padding));
            $zip->setCompressionName('assets/padding.bin', ZipArchive::CM_STORE);
        }

        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);

        return $bytes;
    }
}
