<?php

namespace App\Services;

use GdImage;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The pictures the example ads are made of (`builder:examples`), drawn here with GD rather than
 * downloaded: nothing to license, nothing fetched, and the same pixels on every installation.
 *
 * GD does not smooth the edges of a filled shape, so every drawing with curves is made at twice its size
 * and scaled down. Scattered things (snow, confetti, sesame seeds) come from a seeded generator, so a
 * second run draws exactly the same picture.
 */
class ExampleArtwork
{
    /** Every piece: [title on the shelf, width, height]. */
    public const PIECES = [
        'snow-tile' => ['Snowflake pattern', 256, 256],
        'snowfall' => ['Falling snow', 1920, 1200],
        'landscape' => ['Winter landscape', 1200, 750],
        'coffee-cup' => ['Coffee cup', 900, 900],
        'steam' => ['Coffee steam', 480, 420],
        'beans-tile' => ['Coffee bean pattern', 240, 240],
        'sunburst' => ['Sunburst', 1600, 1600],
        'confetti-tile' => ['Confetti pattern', 320, 320],
        'burger' => ['Burger', 1000, 800],
        'halftone-tile' => ['Halftone dots', 48, 48],
    ];

    /** One piece as PNG bytes. */
    public function png(string $piece): string
    {
        $image = match ($piece) {
            'snow-tile' => $this->snowTile(),
            'snowfall' => $this->snowfall(),
            'landscape' => $this->landscape(),
            'coffee-cup' => $this->coffeeCup(),
            'steam' => $this->steam(),
            'beans-tile' => $this->beansTile(),
            'sunburst' => $this->sunburst(),
            'confetti-tile' => $this->confettiTile(),
            'burger' => $this->burger(),
            'halftone-tile' => $this->halftoneTile(),
        };

        ob_start();
        imagepng($image, null, 6);

        return (string) ob_get_clean();
    }

    /* ── Winter ───────────────────────────────────────────────────────── */

    private function snowTile(): GdImage
    {
        $image = $this->canvas(512, 512);
        $white = $this->colour($image, '#ffffff');

        foreach ([[128, 128, 70], [384, 300, 52], [200, 420, 40], [430, 96, 34]] as [$x, $y, $radius]) {
            $this->snowflake($image, $x, $y, $radius, $white, 8);
        }

        foreach ([[60, 300], [300, 60], [470, 470], [320, 200], [90, 470], [250, 280]] as [$x, $y]) {
            imagefilledellipse($image, $x, $y, 14, 14, $white);
        }

        return $this->halve($image);
    }

    private function snowfall(): GdImage
    {
        $image = $this->canvas(1920, 1200);
        $random = $this->random(1920);

        for ($i = 0; $i < 170; $i++) {
            $size = $random->getInt(4, 15);
            $colour = $this->colour($image, '#ffffff', $random->getInt(35, 95) / 100);

            imagefilledellipse($image, $random->getInt(0, 1919), $random->getInt(0, 1199), $size, $size, $colour);
        }

        return $image;
    }

    private function landscape(): GdImage
    {
        $image = $this->canvas(2400, 1500);

        // The sky, top to horizon.
        for ($y = 0; $y < 1200; $y++) {
            imageline($image, 0, $y, 2399, $y, $this->mix($image, '#6fa9e8', '#e6f1ff', $y / 1200));
        }

        imagefilledellipse($image, 1820, 330, 440, 440, $this->colour($image, '#fff6dc', 0.35));
        imagefilledellipse($image, 1820, 330, 250, 250, $this->colour($image, '#fff6dc', 0.95));

        // Two ranges of mountains, the far one paler, each with snow on its peaks.
        $this->polygon($image, [[0, 900], [300, 620], [520, 760], [820, 520], [1100, 740], [1400, 560], [1700, 780], [2000, 600], [2400, 820], [2400, 1150], [0, 1150]], '#a7bfe0');

        foreach ([[300, 620], [820, 520], [1400, 560], [2000, 600]] as [$x, $y]) {
            $this->polygon($image, [[$x, $y], [$x - 70, $y + 70], [$x - 25, $y + 55], [$x + 10, $y + 80], [$x + 70, $y + 62]], '#f4f8ff');
        }

        $this->polygon($image, [[0, 1060], [350, 780], [650, 990], [1000, 720], [1350, 1010], [1700, 800], [2100, 1030], [2400, 880], [2400, 1260], [0, 1260]], '#6d8dbd');

        foreach ([[350, 780], [1000, 720], [1700, 800]] as [$x, $y]) {
            $this->polygon($image, [[$x, $y], [$x - 90, $y + 80], [$x - 35, $y + 62], [$x + 5, $y + 92], [$x + 85, $y + 70]], '#ffffff');
        }

        // Snow on the ground, in two hills.
        $this->polygon($image, [[0, 1190], [400, 1120], [900, 1175], [1500, 1100], [2000, 1165], [2400, 1120], [2400, 1500], [0, 1500]], '#dfe9f7');
        $this->polygon($image, [[0, 1300], [600, 1230], [1200, 1290], [1800, 1220], [2400, 1280], [2400, 1500], [0, 1500]], '#f5f9ff');

        foreach ([[180, 1250, 300], [420, 1215, 380], [640, 1260, 250], [1580, 1200, 360], [1820, 1245, 280], [2080, 1215, 340], [2280, 1265, 230]] as [$x, $y, $height]) {
            $this->pine($image, $x, $y, $height);
        }

        return $this->halve($image);
    }

    /* ── Coffee ───────────────────────────────────────────────────────── */

    private function coffeeCup(): GdImage
    {
        $image = $this->canvas(1800, 1800);

        imagefilledellipse($image, 900, 1530, 1500, 250, $this->colour($image, '#000000', 0.25));

        // The saucer.
        imagefilledellipse($image, 900, 1470, 1400, 300, $this->colour($image, '#d9c6b0'));
        imagefilledellipse($image, 900, 1448, 1300, 250, $this->colour($image, '#f4ebe0'));
        imagefilledellipse($image, 900, 1450, 760, 140, $this->colour($image, '#e7d7c5'));

        // The handle: a ring whose middle is cut out again, before the cup covers its left side.
        imagefilledellipse($image, 1380, 1030, 390, 430, $this->colour($image, '#f1e8de'));
        imagealphablending($image, false);
        imagefilledellipse($image, 1380, 1030, 210, 250, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagealphablending($image, true);

        // The cup: a body that narrows to a rounded foot, a shade down its right side, a band, a shine.
        $this->polygon($image, [[440, 760], [1360, 760], [1250, 1330], [1170, 1420], [630, 1420], [550, 1330]], '#fbf7f2');
        imagefilledellipse($image, 900, 1405, 560, 70, $this->colour($image, '#fbf7f2'));
        $this->polygon($image, [[1130, 760], [1360, 760], [1250, 1330], [1170, 1420], [1040, 1420]], '#ece3d8');
        $this->polygon($image, [[487, 1000], [1313, 1000], [1301, 1064], [499, 1064]], '#b9773f');
        $this->polygon($image, [[520, 830], [585, 830], [655, 1290], [615, 1290]], '#ffffff', 0.8);

        // The rim and the coffee in it, with a heart drawn in the milk.
        imagefilledellipse($image, 900, 760, 920, 190, $this->colour($image, '#ffffff'));
        imagefilledellipse($image, 900, 772, 830, 150, $this->colour($image, '#4a2a1a'));
        imagefilledellipse($image, 900, 776, 760, 124, $this->colour($image, '#6e4028'));
        imagefilledellipse($image, 862, 760, 118, 62, $this->colour($image, '#ecd3b8'));
        imagefilledellipse($image, 938, 760, 118, 62, $this->colour($image, '#ecd3b8'));
        $this->polygon($image, [[806, 768], [994, 768], [900, 818]], '#ecd3b8');

        return $this->halve($image);
    }

    private function steam(): GdImage
    {
        $image = $this->canvas(960, 840);
        $white = $this->colour($image, '#ffffff');

        foreach ([[300, 0.0], [480, 1.3], [660, 2.6]] as [$base, $phase]) {
            $points = [];

            for ($y = 790; $y >= 90; $y -= 14) {
                $rise = (790 - $y) / 700;
                $points[] = [$base + sin($rise * 7 + $phase) * 46 * (0.5 + $rise), $y];
            }

            $this->stroke($image, $points, 30, $white);
        }

        return $this->halve($image);
    }

    private function beansTile(): GdImage
    {
        $image = $this->canvas(480, 480);
        $bean = $this->colour($image, '#4a2c1d');
        $groove = $this->colour($image, '#24140b');

        foreach ([[120, 120, 30], [360, 110, -40], [130, 360, -20], [370, 360, 50]] as [$x, $y, $angle]) {
            imagefilledpolygon($image, $this->ellipsePoints($x, $y, 72, 46, $angle), $bean);

            // The groove down the middle, gently curved, turned with the bean.
            $turn = deg2rad($angle);
            $points = [];

            for ($t = -0.72; $t <= 0.72; $t += 0.08) {
                $along = $t * 72;
                $across = sin(($t + 0.72) / 1.44 * M_PI * 2) * 7;
                $points[] = [$x + $along * cos($turn) - $across * sin($turn), $y + $along * sin($turn) + $across * cos($turn)];
            }

            $this->stroke($image, $points, 9, $groove);
        }

        return $this->halve($image);
    }

    /* ── Grand opening ────────────────────────────────────────────────── */

    private function sunburst(): GdImage
    {
        $image = $this->canvas(1600, 1600);
        $white = $this->colour($image, '#ffffff');
        $rays = 24;

        for ($k = 0; $k < $rays; $k += 2) {
            $from = 2 * M_PI * $k / $rays;
            $to = 2 * M_PI * ($k + 1) / $rays;

            $this->polygon($image, [
                [800, 800],
                [800 + 1150 * cos($from), 800 + 1150 * sin($from)],
                [800 + 1150 * cos($to), 800 + 1150 * sin($to)],
            ], '#ffffff');
        }

        imagefilledellipse($image, 800, 800, 60, 60, $white);

        return $image;
    }

    private function confettiTile(): GdImage
    {
        $image = $this->canvas(640, 640);
        $random = $this->random(640);
        $palette = ['#ffd60a', '#f72585', '#4cc9f0', '#80ffdb', '#ffffff', '#ff9e00'];

        for ($i = 0; $i < 26; $i++) {
            $x = $random->getInt(40, 600);
            $y = $random->getInt(40, 600);
            $colour = $this->colour($image, $palette[$i % count($palette)]);

            if ($i % 3 === 0) {
                imagefilledellipse($image, $x, $y, 22, 22, $colour);
            } else {
                imagefilledpolygon($image, $this->rectanglePoints($x, $y, 44, 16, $random->getInt(0, 179)), $colour);
            }
        }

        return $this->halve($image);
    }

    /* ── Burger ───────────────────────────────────────────────────────── */

    private function burger(): GdImage
    {
        $image = $this->canvas(2000, 1600);

        imagefilledellipse($image, 1000, 1500, 1500, 150, $this->colour($image, '#000000', 0.22));

        // The bottom bun.
        imagefilledellipse($image, 1000, 1395, 1300, 180, $this->colour($image, '#c7772a'));
        $this->polygon($image, [[360, 1250], [1640, 1250], [1650, 1390], [350, 1390]], '#dc8e36');
        imagefilledellipse($image, 1000, 1252, 1290, 90, $this->colour($image, '#e9a24a'));

        // The patty, speckled.
        imagefilledellipse($image, 1000, 1172, 1420, 230, $this->colour($image, '#5b2c17'));
        $random = $this->random(2000);

        for ($i = 0; $i < 40; $i++) {
            $x = $random->getInt(420, 1580);
            $y = $random->getInt(1115, 1230);

            if ((($x - 1000) / 700) ** 2 + (($y - 1172) / 110) ** 2 < 0.85) {
                imagefilledellipse($image, $x, $y, 18, 12, $this->colour($image, '#43200f'));
            }
        }

        // Cheese, dripping.
        $this->polygon($image, [
            [330, 1058], [1670, 1058], [1670, 1110], [1560, 1110], [1520, 1180], [1480, 1110], [1180, 1110],
            [1130, 1200], [1080, 1110], [760, 1110], [720, 1170], [680, 1110], [330, 1110],
        ], '#ffc83d');

        foreach ([[1520, 1180, 44], [1130, 1200, 50], [720, 1170, 42]] as [$x, $y, $size]) {
            imagefilledellipse($image, $x, $y, $size, $size, $this->colour($image, '#ffc83d'));
        }

        // Lettuce: a wavy band with a lighter edge.
        $top = [];
        $bottom = [];

        for ($x = 300; $x <= 1700; $x += 25) {
            $top[] = [$x, 1000 + sin($x / 45) * 14];
            $bottom[] = [$x, 1076 + sin($x / 38 + 1) * 14];
        }

        $this->polygon($image, [...$top, ...array_reverse($bottom)], '#5ea63a');
        $this->stroke($image, array_map(fn (array $point) => [$point[0], $point[1] + 10], $top), 12, $this->colour($image, '#8ed35a'));

        // Tomato: a layer as wide as the bun — so nothing shows through between the lettuce and the
        // bun's rim — with two lighter slices on it.
        imagefilledellipse($image, 1000, 992, 1380, 100, $this->colour($image, '#df3b2e'));

        foreach ([780, 1220] as $x) {
            imagefilledellipse($image, $x, 984, 380, 50, $this->colour($image, '#f25c4d'));
        }

        // The top bun: a dome, a shine, and sesame seeds.
        $bun = $this->colour($image, '#efa23a');
        imagefilledarc($image, 1000, 960, 1360, 880, 180, 360, $bun, IMG_ARC_PIE);
        imagefilledellipse($image, 1000, 960, 1360, 110, $bun);
        imagefilledellipse($image, 790, 660, 520, 200, $this->colour($image, '#f8c56e', 0.85));

        for ($i = 0; $i < 60; $i++) {
            $x = $random->getInt(470, 1530);
            $y = $random->getInt(600, 900);

            if ((($x - 1000) / 680) ** 2 + (($y - 960) / 440) ** 2 < 0.8) {
                imagefilledpolygon($image, $this->ellipsePoints($x, $y, 20, 10, $random->getInt(-40, 40), 16), $this->colour($image, '#fff1cf'));
            }
        }

        return $this->halve($image);
    }

    private function halftoneTile(): GdImage
    {
        $image = $this->canvas(96, 96);

        imagefilledellipse($image, 48, 48, 38, 38, $this->colour($image, '#000000'));

        return $this->halve($image);
    }

    /* ── Drawing helpers ──────────────────────────────────────────────── */

    /** A transparent picture to draw on. */
    private function canvas(int $width, int $height): GdImage
    {
        $image = imagecreatetruecolor($width, $height);

        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagealphablending($image, true);

        return $image;
    }

    /** Half the size, smoothly — the reason everything with a curve is drawn twice as big. */
    private function halve(GdImage $big): GdImage
    {
        $width = intdiv(imagesx($big), 2);
        $height = intdiv(imagesy($big), 2);
        $small = imagecreatetruecolor($width, $height);

        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
        imagecopyresampled($small, $big, 0, 0, 0, 0, $width, $height, imagesx($big), imagesy($big));

        return $small;
    }

    private function colour(GdImage $image, string $hex, float $opacity = 1.0): int
    {
        [$red, $green, $blue] = sscanf(ltrim($hex, '#'), '%02x%02x%02x');

        return imagecolorallocatealpha($image, $red, $green, $blue, (int) round(127 * (1 - $opacity)));
    }

    /** A colour part of the way from one to another. */
    private function mix(GdImage $image, string $from, string $to, float $share): int
    {
        $a = sscanf(ltrim($from, '#'), '%02x%02x%02x');
        $b = sscanf(ltrim($to, '#'), '%02x%02x%02x');

        return imagecolorallocatealpha(
            $image,
            (int) round($a[0] + ($b[0] - $a[0]) * $share),
            (int) round($a[1] + ($b[1] - $a[1]) * $share),
            (int) round($a[2] + ($b[2] - $a[2]) * $share),
            0,
        );
    }

    /** @param  array<int, array{0: float|int, 1: float|int}>  $points */
    private function polygon(GdImage $image, array $points, string $hex, float $opacity = 1.0): void
    {
        $flat = [];

        foreach ($points as [$x, $y]) {
            $flat[] = (int) round($x);
            $flat[] = (int) round($y);
        }

        imagefilledpolygon($image, $flat, $this->colour($image, $hex, $opacity));
    }

    /**
     * A thick, round-ended line through points: a row of discs, which GD draws cleanly where its own
     * thick lines come out as jagged boxes. Opaque colours only — discs overlap.
     *
     * @param  array<int, array{0: float|int, 1: float|int}>  $points
     */
    private function stroke(GdImage $image, array $points, float $width, int $colour): void
    {
        $step = max(1.0, $width / 4);

        for ($i = 1; $i < count($points); $i++) {
            [$x1, $y1] = $points[$i - 1];
            [$x2, $y2] = $points[$i];
            $steps = max(1, (int) ceil(hypot($x2 - $x1, $y2 - $y1) / $step));

            for ($s = 0; $s <= $steps; $s++) {
                imagefilledellipse(
                    $image,
                    (int) round($x1 + ($x2 - $x1) * $s / $steps),
                    (int) round($y1 + ($y2 - $y1) * $s / $steps),
                    (int) round($width),
                    (int) round($width),
                    $colour,
                );
            }
        }
    }

    /** A six-armed snowflake with two pairs of branches on every arm. */
    private function snowflake(GdImage $image, float $cx, float $cy, float $radius, int $colour, float $width): void
    {
        for ($k = 0; $k < 6; $k++) {
            $angle = deg2rad(60 * $k - 90);

            $this->stroke($image, [[$cx, $cy], [$cx + $radius * cos($angle), $cy + $radius * sin($angle)]], $width, $colour);

            foreach ([[0.45, 0.3], [0.72, 0.22]] as [$at, $length]) {
                $x = $cx + $radius * $at * cos($angle);
                $y = $cy + $radius * $at * sin($angle);

                foreach ([-1, 1] as $side) {
                    $branch = $angle + $side * deg2rad(40);

                    $this->stroke($image, [[$x, $y], [$x + $radius * $length * cos($branch), $y + $radius * $length * sin($branch)]], $width * 0.8, $colour);
                }
            }
        }

        imagefilledellipse($image, (int) round($cx), (int) round($cy), (int) round($width * 2.4), (int) round($width * 2.4), $colour);
    }

    /** A pine tree of three tiers, snow on each. */
    private function pine(GdImage $image, float $x, float $baseY, float $height): void
    {
        $this->polygon($image, [[$x - $height * 0.05, $baseY], [$x + $height * 0.05, $baseY], [$x + $height * 0.05, $baseY - $height * 0.2], [$x - $height * 0.05, $baseY - $height * 0.2]], '#4b3a2a');

        foreach ([[0.15, 0.62, 0.42], [0.42, 0.5, 0.34], [0.66, 0.4, 0.26]] as [$from, $tall, $half]) {
            $bottom = $baseY - $height * $from;
            $top = $bottom - $height * $tall;

            $this->polygon($image, [[$x, $top], [$x + $height * $half, $bottom], [$x - $height * $half, $bottom]], '#2e5b4f');
            $this->polygon($image, [[$x, $top], [$x + $height * $half * 0.35, $top + $height * $tall * 0.35], [$x - $height * $half * 0.35, $top + $height * $tall * 0.35]], '#f5f9ff');
        }
    }

    /**
     * An ellipse turned by an angle, as polygon points — GD's own ellipses cannot turn.
     *
     * @return array<int, int>
     */
    private function ellipsePoints(float $cx, float $cy, float $rx, float $ry, float $degrees, int $segments = 36): array
    {
        $points = [];
        $turn = deg2rad($degrees);

        for ($i = 0; $i < $segments; $i++) {
            $a = 2 * M_PI * $i / $segments;
            $x = $rx * cos($a);
            $y = $ry * sin($a);
            $points[] = (int) round($cx + $x * cos($turn) - $y * sin($turn));
            $points[] = (int) round($cy + $x * sin($turn) + $y * cos($turn));
        }

        return $points;
    }

    /** @return array<int, int> */
    private function rectanglePoints(float $cx, float $cy, float $width, float $height, float $degrees): array
    {
        $points = [];
        $turn = deg2rad($degrees);

        foreach ([[-1, -1], [1, -1], [1, 1], [-1, 1]] as [$sx, $sy]) {
            $x = $sx * $width / 2;
            $y = $sy * $height / 2;
            $points[] = (int) round($cx + $x * cos($turn) - $y * sin($turn));
            $points[] = (int) round($cy + $x * sin($turn) + $y * cos($turn));
        }

        return $points;
    }

    /** The same scatter every time. */
    private function random(int $seed): Randomizer
    {
        return new Randomizer(new Mt19937($seed));
    }
}
