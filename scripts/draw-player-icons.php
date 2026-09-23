<?php

/**
 * Draws the player's web-app icons (docs/AD-BUILDER-SPEC.md §15) into public/player-icons/ with GD — the
 * panel's blue, a white play mark — so nothing binary has to be drawn by hand. Run once and commit:
 *
 *   php scripts/draw-player-icons.php
 */
$target = dirname(__DIR__).'/public/player-icons';

if (! is_dir($target) && ! mkdir($target, 0755, true) && ! is_dir($target)) {
    fwrite(STDERR, "Could not create {$target}\n");
    exit(1);
}

foreach ([192, 512] as $size) {
    $image = imagecreatetruecolor($size, $size);
    imagesavealpha($image, true);
    imagealphablending($image, false);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagealphablending($image, true);

    // A rounded square in the panel's blue (#2563eb), inset so a maskable icon keeps its mark.
    $blue = imagecolorallocate($image, 37, 99, 235);
    $inset = (int) round($size * 0.06);
    $radius = (int) round($size * 0.2);
    $right = $size - $inset - 1;

    imagefilledrectangle($image, $inset + $radius, $inset, $right - $radius, $right, $blue);
    imagefilledrectangle($image, $inset, $inset + $radius, $right, $right - $radius, $blue);

    foreach ([[$inset + $radius, $inset + $radius], [$right - $radius, $inset + $radius], [$inset + $radius, $right - $radius], [$right - $radius, $right - $radius]] as [$cx, $cy]) {
        imagefilledellipse($image, $cx, $cy, $radius * 2, $radius * 2, $blue);
    }

    // The play mark: a white triangle a little right of centre, where the eye expects it.
    $white = imagecolorallocate($image, 255, 255, 255);
    $half = (int) round($size * 0.2);
    $cx = (int) round($size * 0.54);
    $cy = (int) round($size * 0.5);

    imagefilledpolygon($image, [$cx - $half, $cy - $half, $cx - $half, $cy + $half, $cx + $half, $cy], $white);

    imagepng($image, "{$target}/icon-{$size}.png", 9);
    imagedestroy($image);

    echo "player-icons/icon-{$size}.png\n";
}
