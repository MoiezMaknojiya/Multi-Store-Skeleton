<?php

namespace App\Services;

use App\Models\BuilderAd;
use Illuminate\Support\Str;

/**
 * Four finished ads to learn the Ad Builder from (`builder:examples`, docs/AD-BUILDER-SPEC.md §10).
 *
 * Each is an ordinary design document — exactly what the editor would have saved — and between them
 * they use most of stages 3 and 4: stacked background layers (gradients, a tiled picture, blend modes),
 * a framed and filtered picture, shapes with gradient fills, Google fonts, Urdu set right to left, every
 * entrance but blur with their eases, six of the eight loops (the owner's own example among them — a cup
 * that fades in and then floats 10 px up and down for ever), and a CTA that blinks. Not among them: a
 * video, an exit, a custom curve, and the shake and Ken Burns loops.
 *
 * The pictures are ExampleArtwork's; `$assets` maps each piece to its row on the store's shelf.
 */
class ExampleAds
{
    /** The Google families the examples set text in, installed before the ads are made. */
    public function families(): array
    {
        return ['Anton', 'Poppins', 'Playfair Display', 'Noto Nastaliq Urdu'];
    }

    /**
     * @param  array<string, int>  $assets  artwork piece => asset id on the store's shelf
     * @return array<string, array<string, mixed>> ad name => document
     */
    public function designs(array $assets, string $storeName): array
    {
        return [
            'Example · Winter Sale' => $this->winterSale($assets, $storeName),
            'Example · Fresh Coffee' => $this->freshCoffee($assets),
            'Example · Grand Opening' => $this->grandOpening($assets),
            'Example · Burger Deal (Urdu)' => $this->burgerDeal($assets, $storeName),
        ];
    }

    /** Stacked layers behind a framed photograph, a pulsing badge and snow drifting over everything. */
    private function winterSale(array $assets, string $storeName): array
    {
        $badgeMotion = [
            'in' => $this->in('rotate', 0.9, 1.3, ['degrees' => -180, 'ease' => 'back.out']),
            'loop' => $this->loop('pulse', ['amount' => 6, 'duration' => 1.1]),
        ];

        return $this->document('#0b1d3a', [
            $this->gradientLayer('bg_ws_sky', 'linear', 165, [['#0b1d3a', 0], ['#1d4e89', 55], ['#5fa8e8', 100]]),
            $this->imageLayer('bg_ws_flakes', $assets['snow-tile'], [
                'size' => 'custom', 'scale' => 12, 'repeat' => 'repeat', 'position' => 'left top',
                'opacity' => 0.25, 'blend' => 'screen',
            ]),
            $this->gradientLayer('bg_ws_glow', 'radial', 180, [['rgba(255,255,255,0.30)', 0], ['rgba(255,255,255,0)', 60]], blend: 'overlay'),
        ], [
            $this->text('ws_upto', 'UP TO', [150, 170, 720, 80], $this->type('Poppins', 600, 56, '#bfe3ff', ['letterSpacing' => 14]), [
                'in' => $this->in('fade', 0.6, 0.2),
            ]),
            $this->text('ws_off', '50% OFF', [140, 235, 1000, 320], $this->type('Anton', 400, 280, '#ffffff', [
                'lineHeight' => 1,
                'textShadow' => ['x' => 0, 'y' => 12, 'blur' => 30, 'color' => 'rgba(0,0,0,0.35)'],
            ]), [
                'in' => $this->in('zoom', 0.9, 0.35, ['scale' => 0.4, 'ease' => 'back.out']),
                'loop' => $this->loop('pulse', ['amount' => 3, 'duration' => 1.6]),
            ]),
            $this->text('ws_title', 'WINTER SALE', [150, 575, 1000, 170], $this->type('Anton', 400, 150, '#ffd166', ['letterSpacing' => 6, 'lineHeight' => 1.05]), [
                'in' => $this->in('slide', 0.8, 0.7, ['distance' => 100, 'ease' => 'power3.out']),
            ]),
            $this->text('ws_sub', 'Jackets • Sweaters • Boots — all winter wear', [155, 765, 1000, 70], $this->type('Poppins', 500, 40, '#e6f2ff'), [
                'in' => $this->in('fade', 0.8, 1.1),
            ]),
            $this->image('ws_photo', 'Winter landscape', $assets['landscape'], [1130, 180, 640, 400, 4], [
                'fit' => 'cover',
                'radius' => 28,
                'border' => ['width' => 10, 'style' => 'solid', 'color' => '#ffffff'],
                'shadow' => ['x' => 0, 'y' => 30, 'blur' => 60, 'spread' => 0, 'color' => 'rgba(0,0,0,0.45)'],
                'filters' => ['saturate' => 115, 'brightness' => 105],
            ], [
                'in' => $this->in('flip', 1.1, 0.5, ['direction' => 'left', 'ease' => 'power3.out']),
                'loop' => $this->loop('sway', ['amount' => 1.5, 'duration' => 3.2]),
            ]),
            $this->shape('ws_badge', 'Badge', [1520, 560, 300, 300], [
                'shape' => 'ellipse',
                'gradient' => $this->gradient('radial', 180, [['#ff6b6b', 0], ['#c9184a', 100]]),
                'border' => ['width' => 8, 'style' => 'solid', 'color' => '#ffffff'],
                'shadow' => ['x' => 0, 'y' => 18, 'blur' => 40, 'spread' => 0, 'color' => 'rgba(0,0,0,0.40)'],
            ], $badgeMotion),
            $this->text('ws_badge_text', "THIS WEEK\nONLY", [1520, 560, 300, 300], $this->type('Poppins', 700, 44, '#ffffff', [
                'align' => 'center', 'verticalAlign' => 'center', 'lineHeight' => 1.1,
            ]), $badgeMotion),
            $this->shape('ws_strip', 'Strip', [0, 960, 1920, 120], ['shape' => 'rect', 'fill' => 'rgba(255,255,255,0.12)'], [
                'in' => $this->in('wipe', 0.8, 1.6, ['direction' => 'right']),
            ]),
            $this->text('ws_strip_text', "{$storeName}  •  Sale ends Sunday", [0, 960, 1920, 120], $this->type('Poppins', 600, 42, '#ffffff', [
                'align' => 'center', 'verticalAlign' => 'center', 'letterSpacing' => 2,
            ]), [
                'in' => $this->in('fade', 0.6, 1.9),
            ]),
            $this->image('ws_snow', 'Falling snow', $assets['snowfall'], [0, -60, 1920, 1200, 0, 0.7], ['fit' => 'cover'], [
                'loop' => $this->loop('drift', ['amountX' => 40, 'amountY' => 60, 'duration' => 9]),
            ]),
        ]);
    }

    /** A radial gradient, a bean pattern, and a cup that fades in and then floats 10 px for ever. */
    private function freshCoffee(array $assets): array
    {
        return $this->document('#120a06', [
            $this->gradientLayer('bg_fc_base', 'radial', 180, [['#7b4a2e', 0], ['#3b2216', 55], ['#120a06', 100]]),
            $this->imageLayer('bg_fc_beans', $assets['beans-tile'], [
                'size' => 'custom', 'scale' => 9, 'repeat' => 'repeat', 'position' => 'left top', 'opacity' => 0.3,
            ]),
            $this->gradientLayer('bg_fc_vignette', 'radial', 180, [['rgba(0,0,0,0)', 45], ['rgba(0,0,0,0.55)', 100]]),
        ], [
            $this->shape('fc_glow', 'Warm glow', [1040, 140, 860, 860], [
                'shape' => 'ellipse',
                'gradient' => $this->gradient('radial', 180, [['rgba(255,190,120,0.40)', 0], ['rgba(255,190,120,0)', 70]]),
            ], [
                'in' => $this->in('fade', 1.2, 0),
                'loop' => $this->loop('pulse', ['amount' => 4, 'duration' => 3]),
            ]),
            // The owner's example, exactly: fade in, then float up and down about 10 px, for ever.
            $this->image('fc_cup', 'Coffee cup', $assets['coffee-cup'], [1080, 170, 780, 780], ['fit' => 'contain'], [
                'in' => $this->in('fade', 1.0, 0.2),
                'loop' => $this->loop('float', ['axis' => 'y', 'amount' => 10, 'duration' => 2.4]),
            ]),
            $this->image('fc_steam', 'Steam', $assets['steam'], [1300, 214, 340, 298, 0, 0.55], ['fit' => 'contain'], [
                'in' => $this->in('fade', 1.2, 1.0),
                'loop' => $this->loop('blink', ['amount' => 35, 'duration' => 1.8]),
            ]),
            $this->text('fc_kicker', 'GOOD MORNING', [150, 215, 800, 70], $this->type('Poppins', 600, 40, '#d4a373', ['letterSpacing' => 10]), [
                'in' => $this->in('fade', 0.6, 0.1),
            ]),
            $this->text('fc_title', "Fresh\nCoffee", [140, 285, 900, 380], $this->type('Playfair Display', 700, 170, '#f5e6d3', [
                'fontStyle' => 'italic', 'lineHeight' => 1.0,
            ]), [
                'in' => $this->in('slide', 1.0, 0.25, ['direction' => 'right', 'distance' => 120, 'ease' => 'power3.out']),
            ]),
            $this->text('fc_sub', 'Brewed every morning with beans roasted this week.', [150, 680, 760, 120], $this->type('Poppins', 400, 36, '#e9d5c1', ['lineHeight' => 1.4]), [
                'in' => $this->in('fade', 0.8, 0.7),
            ]),
            $this->text('fc_price', 'Rs 350', [150, 845, 340, 110], $this->type('Poppins', 700, 60, '#2b1a12', [
                'align' => 'center', 'verticalAlign' => 'center', 'background' => '#d4a373', 'radius' => 55,
            ]), [
                'in' => $this->in('zoom', 0.7, 1.1, ['scale' => 0.5, 'ease' => 'back.out']),
                'loop' => $this->loop('pulse', ['amount' => 5, 'duration' => 1.4]),
            ]),
            $this->text('fc_note', 'Any size  •  Hot or iced', [520, 865, 640, 70], $this->type('Poppins', 500, 34, '#f5e6d3', ['verticalAlign' => 'center']), [
                'in' => $this->in('slide', 0.6, 1.3, ['direction' => 'right', 'distance' => 40]),
            ]),
        ]);
    }

    /** A spinning sunburst, headlines that zoom in on an elastic ease, a ribbon that wipes in, a blinking CTA. */
    private function grandOpening(array $assets): array
    {
        $headline = fn (string $colour, array $more = []) => $this->type('Anton', 400, 240, $colour, [
            'align' => 'center', 'lineHeight' => 1.05,
            'textShadow' => ['x' => 0, 'y' => 14, 'blur' => 40, 'color' => 'rgba(0,0,0,0.35)'],
            ...$more,
        ]);

        $sparkle = fn (string $id, int $x, int $y, float $every) => $this->shape($id, 'Sparkle', [$x, $y, 28, 28], [
            'shape' => 'ellipse',
            'fill' => '#ffffff',
            'shadow' => ['x' => 0, 'y' => 0, 'blur' => 24, 'spread' => 4, 'color' => '#ffffff'],
        ], [
            'in' => $this->in('zoom', 0.5, 0.8, ['scale' => 0]),
            'loop' => $this->loop('blink', ['amount' => 10, 'duration' => $every]),
        ]);

        return $this->document('#12002b', [
            $this->gradientLayer('bg_go_base', 'linear', 135, [['#3a0ca3', 0], ['#7209b7', 50], ['#f72585', 100]]),
            $this->gradientLayer('bg_go_spot', 'radial', 180, [['rgba(255,255,255,0.35)', 0], ['rgba(255,255,255,0)', 60]], blend: 'soft-light'),
            $this->imageLayer('bg_go_confetti', $assets['confetti-tile'], [
                'size' => 'custom', 'scale' => 17, 'repeat' => 'repeat', 'position' => 'left top', 'opacity' => 0.6,
            ]),
        ], [
            $this->image('go_rays', 'Sunburst', $assets['sunburst'], [160, -260, 1600, 1600, 0, 0.14], ['fit' => 'contain'], [
                'in' => $this->in('fade', 1.5, 0),
                'loop' => $this->loop('spin', ['amount' => 1, 'duration' => 40, 'yoyo' => false, 'ease' => 'none']),
            ]),
            $sparkle('go_sparkle_1', 330, 210, 0.8),
            $sparkle('go_sparkle_2', 1560, 300, 1.1),
            $sparkle('go_sparkle_3', 1420, 860, 1.4),
            $this->text('go_welcome', 'WELCOME TO THE', [0, 140, 1920, 90], $this->type('Poppins', 600, 52, '#ffd6ff', [
                'align' => 'center', 'letterSpacing' => 18,
            ]), [
                'in' => $this->in('fade', 0.6, 0.2),
            ]),
            $this->text('go_grand', 'GRAND', [0, 215, 1920, 260], $headline('#ffffff'), [
                'in' => $this->in('zoom', 1.1, 0.4, ['scale' => 0.3, 'ease' => 'elastic.out']),
            ]),
            $this->text('go_opening', 'OPENING', [0, 455, 1920, 260], $headline('#ffd60a', ['textStroke' => ['width' => 4, 'color' => '#7209b7']]), [
                'in' => $this->in('zoom', 1.1, 0.6, ['scale' => 0.3, 'ease' => 'elastic.out']),
            ]),
            $this->shape('go_ribbon', 'Ribbon', [560, 750, 800, 110, -3], [
                'shape' => 'rect',
                'radius' => 18,
                'gradient' => $this->gradient('linear', 90, [['#ffd60a', 0], ['#ffb703', 100]]),
                'shadow' => ['x' => 0, 'y' => 16, 'blur' => 36, 'spread' => 0, 'color' => 'rgba(0,0,0,0.35)'],
            ], [
                'in' => $this->in('wipe', 0.8, 1.2, ['direction' => 'right']),
            ]),
            $this->text('go_date', 'SATURDAY  •  10 AM', [560, 750, 800, 110, -3], $this->type('Poppins', 700, 50, '#3a0ca3', [
                'align' => 'center', 'verticalAlign' => 'center', 'letterSpacing' => 4,
            ]), [
                'in' => $this->in('fade', 0.5, 1.8),
            ]),
            $this->text('go_cta', 'Free gift for the first 100 customers', [0, 920, 1920, 80], $this->type('Poppins', 600, 42, '#ffffff', ['align' => 'center']), [
                'in' => $this->in('fade', 0.6, 2.0),
                'loop' => $this->loop('blink', ['amount' => 35, 'duration' => 0.9]),
            ]),
        ]);
    }

    /** Urdu, right to left, in Nastaliq: a burger that drops in and bounces, then floats; a spinning-in price badge. */
    private function burgerDeal(array $assets, string $storeName): array
    {
        $badgeMotion = [
            'in' => $this->in('rotate', 0.9, 1.1, ['degrees' => -180, 'ease' => 'back.out']),
            'loop' => $this->loop('pulse', ['amount' => 5, 'duration' => 1.0]),
        ];

        return $this->document('#2b0a00', [
            $this->gradientLayer('bg_bd_base', 'linear', 120, [['#ff8a00', 0], ['#ff3d00', 55], ['#b5121b', 100]]),
            $this->imageLayer('bg_bd_dots', $assets['halftone-tile'], [
                'size' => 'custom', 'scale' => 2.5, 'repeat' => 'repeat', 'position' => 'left top',
                'opacity' => 0.14, 'blend' => 'multiply',
            ]),
            $this->gradientLayer('bg_bd_glow', 'radial', 180, [['rgba(255,236,179,0.55)', 0], ['rgba(255,236,179,0)', 50]], blend: 'screen'),
        ], [
            $this->image('bd_burger', 'Burger', $assets['burger'], [80, 170, 920, 736], ['fit' => 'contain'], [
                'in' => $this->in('bounce', 1.1, 0.2, ['direction' => 'down', 'distance' => 280]),
                'loop' => $this->loop('float', ['axis' => 'y', 'amount' => 12, 'duration' => 2.2]),
            ]),
            $this->text('bd_title', 'زبردست ڈیل', [980, 150, 820, 230], $this->type('Noto Nastaliq Urdu', 700, 120, '#ffffff', [
                'align' => 'right', 'lineHeight' => 1.8,
                'textShadow' => ['x' => 0, 'y' => 8, 'blur' => 24, 'color' => 'rgba(0,0,0,0.35)'],
            ]), [
                'in' => $this->in('slide', 0.9, 0.4, ['direction' => 'left', 'distance' => 120, 'ease' => 'power3.out']),
            ]),
            $this->text('bd_items', 'برگر + فرائز + ڈرنک', [980, 390, 820, 150], $this->type('Noto Nastaliq Urdu', 400, 64, '#ffe8d6', [
                'align' => 'right', 'lineHeight' => 2,
            ]), [
                'in' => $this->in('fade', 0.8, 0.8),
            ]),
            $this->shape('bd_badge', 'Price badge', [1400, 560, 380, 380], [
                'shape' => 'ellipse',
                'fill' => '#ffd60a',
                'border' => ['width' => 12, 'style' => 'solid', 'color' => '#ffffff'],
                'shadow' => ['x' => 0, 'y' => 24, 'blur' => 50, 'spread' => 0, 'color' => 'rgba(0,0,0,0.35)'],
            ], $badgeMotion),
            $this->text('bd_price', "صرف 999\nروپے", [1400, 560, 380, 380], $this->type('Noto Nastaliq Urdu', 700, 64, '#9d0208', [
                'align' => 'center', 'verticalAlign' => 'center', 'lineHeight' => 1.7,
            ]), $badgeMotion),
            $this->text('bd_strip', "Burger Deal  •  {$storeName}  •  Today only", [0, 985, 1920, 95], $this->type('Poppins', 600, 40, '#ffffff', [
                'align' => 'center', 'verticalAlign' => 'center', 'background' => 'rgba(0,0,0,0.28)',
            ]), [
                'in' => $this->in('wipe', 0.8, 1.6, ['direction' => 'right']),
            ]),
        ]);
    }

    /* ── Building blocks, shaped exactly as the editor writes them ─────── */

    /** A document: the elements are listed back to front, and their depth is their place in the list. */
    private function document(string $colour, array $layers, array $elements): array
    {
        foreach ($elements as $depth => $element) {
            $elements[$depth]['z'] = $depth;
        }

        return [
            'version' => 1,
            'stage' => [
                'width' => BuilderAd::STAGE_WIDTH,
                'height' => BuilderAd::STAGE_HEIGHT,
                'background' => ['color' => $colour, 'layers' => $layers],
            ],
            'elements' => array_values($elements),
        ];
    }

    /** @param  array{0: int, 1: int, 2: int, 3: int, 4?: int|float, 5?: float}  $box  x, y, w, h, rotation, opacity */
    private function box(array $box): array
    {
        return [
            'x' => $box[0], 'y' => $box[1], 'w' => $box[2], 'h' => $box[3],
            'rotation' => $box[4] ?? 0, 'opacity' => $box[5] ?? 1, 'locked' => false, 'visible' => true,
        ];
    }

    private function text(string $id, string $text, array $box, array $style, array $animations): array
    {
        return [
            'id' => $id, 'type' => 'text', 'name' => Str::limit(str_replace("\n", ' ', $text), 40),
            ...$this->box($box), 'text' => $text, 'style' => $style, 'animations' => $animations,
        ];
    }

    private function image(string $id, string $name, int $assetId, array $box, array $style, array $animations): array
    {
        return [
            'id' => $id, 'type' => 'image', 'name' => $name, ...$this->box($box),
            'assetId' => $assetId, 'style' => $style, 'animations' => $animations,
        ];
    }

    private function shape(string $id, string $name, array $box, array $style, array $animations): array
    {
        return ['id' => $id, 'type' => 'shape', 'name' => $name, ...$this->box($box), 'style' => $style, 'animations' => $animations];
    }

    /** Type, with the editor's defaults for whatever is not said. */
    private function type(string $family, int $weight, int $size, string $colour, array $more = []): array
    {
        return [
            'fontFamily' => $family, 'fontWeight' => $weight, 'fontSize' => $size, 'color' => $colour,
            'align' => 'left', 'lineHeight' => 1.2, 'letterSpacing' => 0, ...$more,
        ];
    }

    /** @param  array<int, array{0: string, 1: int}>  $stops  colour, position */
    private function gradient(string $kind, int $angle, array $stops): array
    {
        return [
            'kind' => $kind,
            'angle' => $angle,
            'stops' => array_map(fn (array $stop) => ['color' => $stop[0], 'at' => $stop[1]], $stops),
        ];
    }

    private function gradientLayer(string $id, string $kind, int $angle, array $stops, string $blend = 'normal'): array
    {
        return [
            'id' => $id, 'type' => 'gradient', 'visible' => true, 'opacity' => 1.0, 'blend' => $blend,
            'gradient' => $this->gradient($kind, $angle, $stops),
        ];
    }

    private function imageLayer(string $id, int $assetId, array $options): array
    {
        return [
            'id' => $id, 'type' => 'image', 'visible' => true, 'opacity' => 1, 'blend' => 'normal', 'assetId' => $assetId,
            'size' => 'cover', 'scale' => 100, 'position' => 'center center', 'repeat' => 'no-repeat', ...$options,
        ];
    }

    /** An entrance, whole — every key the editor's Animation tab writes. */
    private function in(string $effect, float $duration, float $delay, array $more = []): array
    {
        return [
            'effect' => $effect, 'direction' => 'up', ...$this->defaults('in'),
            'duration' => $duration, 'delay' => $delay, 'ease' => $effect === 'bounce' ? 'bounce.out' : 'power2.out',
            ...$more,
        ];
    }

    /** A loop, whole. */
    private function loop(string $effect, array $values): array
    {
        return [
            'effect' => $effect, 'axis' => 'y', ...$this->defaults('loop'),
            'yoyo' => true, 'ease' => 'sine.inOut', ...$values,
        ];
    }

    /**
     * A slot's numbers at their defaults, in AdAnimations::NUMBERS' order — the table the editor is handed
     * too, so an example starts from exactly the values a new animation does.
     *
     * @return array<string, int|float>
     */
    private function defaults(string $slot): array
    {
        return array_map(fn (array $row) => $row[2], AdAnimations::NUMBERS[$slot]);
    }
}
