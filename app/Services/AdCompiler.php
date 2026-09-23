<?php

namespace App\Services;

use App\Http\Requests\Builder\BuilderAdRequest;
use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use Illuminate\Support\Collection;

/**
 * Turns a design into the page a television shows (docs/AD-BUILDER-SPEC.md §9).
 *
 * The one rule that governs every line here: **nothing a person typed is ever written as code**. The
 * document is data — numbers, a colour, a word from a fixed list, a run of text — and each value is read
 * with a reader that can only produce something safe: `number()` returns a number, `colour()` returns a
 * colour or nothing at all, `oneOf()` returns a value from a list this file owns, text is escaped, and
 * the animations go through `AdAnimations` and reach the page as JSON the runtime parses, never runs.
 * A key nobody here knows about reaches nothing.
 *
 * The page it writes, from the bottom up:
 *
 *   .ad-stage                  the ad's size — 1920×1080, or 1080×1920 for a portrait ad (§12) — scaled
 *                              as one piece to whatever the screen is
 *     .ad-bg                   the stage's own colour, then its layers — colour, gradient, picture,
 *       .ad-layer …            video — each with its own opacity and blend mode
 *     .ad-el[data-anim-id]     one element: where it sits, how big, which way up, how solid
 *       .ad-anim               what the ANIMATIONS move — kept apart from the content so a blur-in
 *         (content)            never overwrites a picture's own filters, and a spin never fights its
 *                              rotation
 *
 * One self-contained file: no build step, no framework, no request anywhere but this server — because
 * it runs on a cheap box in a shop, possibly with no internet at all.
 */
class AdCompiler
{
    /** How a picture may sit in its box. */
    public const FITS = ['cover', 'contain', 'fill', 'none', 'scale-down'];

    /** How text may line up. */
    public const ALIGNMENTS = ['left', 'center', 'right', 'justify'];

    /** Where the words sit inside their box. */
    public const VERTICAL = ['flex-start', 'center', 'flex-end'];

    /** Upright or italic — nothing else is a font style. */
    public const FONT_STYLES = ['normal', 'italic'];

    /** The case a run of text may be forced into. */
    public const TRANSFORMS = ['none', 'uppercase', 'lowercase', 'capitalize'];

    /** The line a run of text may carry. */
    public const DECORATIONS = ['none', 'underline', 'line-through', 'overline'];

    /** How a layer or an element mixes with what is under it. */
    public const BLENDS = [
        'normal', 'multiply', 'screen', 'overlay', 'darken', 'lighten', 'color-dodge', 'color-burn',
        'hard-light', 'soft-light', 'difference', 'exclusion', 'hue', 'saturation', 'color', 'luminosity',
    ];

    /** How a picture layer fills the stage. */
    public const SIZES = ['cover', 'contain', 'auto', 'custom'];

    public const REPEATS = ['no-repeat', 'repeat', 'repeat-x', 'repeat-y'];

    /** The nine places a picture can be pinned to, as CSS says them. */
    public const POSITIONS = [
        'left top', 'center top', 'right top',
        'left center', 'center center', 'right center',
        'left bottom', 'center bottom', 'right bottom',
    ];

    public const BORDER_STYLES = ['solid', 'dashed', 'dotted', 'double'];

    /** What a shape may be. */
    public const SHAPES = ['rect', 'ellipse'];

    /** The two kinds of gradient. */
    public const GRADIENTS = ['linear', 'radial'];

    /** How a video layer fills the stage. */
    public const VIDEO_FITS = ['cover', 'contain'];

    /**
     * What an advert that moves loads, in order: Anime.js (MIT) and the runtime — the same two files the
     * editor's preview runs.
     */
    public const MOTION_SCRIPTS = ['ad-runtime/anime.min.js', 'ad-runtime/runtime.js'];

    /**
     * Every number a design may carry, as [min, max]. The compiler clamps to these, the request refuses
     * anything outside them (BuilderAdRequest builds its rules from this table), and the editor holds its
     * inputs inside them (the page hands it the same table) — one list, so the three never disagree.
     */
    public const LIMITS = [
        // Where an element sits and how big it is. Off-stage is allowed on purpose: a person may park
        // something just outside the frame, or animate it in from there.
        'x' => [-20000, 20000],
        'y' => [-20000, 20000],
        'w' => [1, 20000],
        'h' => [1, 20000],
        'rotation' => [-360, 360],
        'opacity' => [0, 1],

        // Type.
        'fontSize' => [1, 2000],
        'fontWeight' => [100, 900],
        'lineHeight' => [0.5, 10],
        'letterSpacing' => [-100, 100],
        'wordSpacing' => [-200, 200],
        'padding' => [0, 400],
        'radius' => [0, 999],
        'textShadow.x' => [-200, 200],
        'textShadow.y' => [-200, 200],
        'textShadow.blur' => [0, 200],
        'textStroke.width' => [0, 40],

        // Frames, shadows and picture filters.
        'border.width' => [0, 200],
        'shadow.x' => [-500, 500],
        'shadow.y' => [-500, 500],
        'shadow.blur' => [0, 500],
        'shadow.spread' => [-500, 500],
        'filters.blur' => [0, 100],
        'filters.brightness' => [0, 300],
        'filters.contrast' => [0, 300],
        'filters.saturate' => [0, 300],
        'filters.grayscale' => [0, 100],
        'filters.sepia' => [0, 100],
        'filters.hueRotate' => [-360, 360],
        'filters.invert' => [0, 100],

        // Gradients and the stage's background layers.
        'gradient.angle' => [0, 360],
        'gradient.at' => [0, 100],
        'layer.opacity' => [0, 1],
        'layer.scale' => [1, 1000],
    ];

    /**
     * The picture filters in the order CSS applies them, each with its CSS name, unit and the value that
     * leaves a picture as it was — a filter at that value is not written at all.
     */
    public const FILTERS = [
        'blur' => ['blur', 'px', 0],
        'brightness' => ['brightness', '%', 100],
        'contrast' => ['contrast', '%', 100],
        'saturate' => ['saturate', '%', 100],
        'grayscale' => ['grayscale', '%', 0],
        'sepia' => ['sepia', '%', 0],
        'hueRotate' => ['hue-rotate', 'deg', 0],
        'invert' => ['invert', '%', 0],
    ];

    public function __construct(
        private readonly AdAnimations $animations,
        private readonly AdFontEmbedder $fonts,
    ) {}

    /** Compile the design into a whole HTML document. */
    public function compile(BuilderAd $ad): string
    {
        $document = is_array($ad->document) ? $ad->document : [];
        $stage = is_array($document['stage'] ?? null) ? $document['stage'] : [];

        // The elements as the tree they are painted as (§13): siblings by z, a group's children inside it.
        // What a walk from the top never reaches — a hidden group's subtree, an element deeper than
        // groups go — is not on the page, so its fonts, pictures and animations are not either.
        $tree = $this->tree(is_array($document['elements'] ?? null) ? $document['elements'] : []);
        $elements = $this->reachable($tree);

        // Asset ids are resolved against THIS AD'S STORE, never against the ids alone: a document
        // carrying another shop's asset id must compile to a missing picture, not a borrowed one.
        $assets = $this->assetsFor($ad, $elements, $stage);

        $animations = $this->animations->forElements($elements);
        $visited = [];
        $body = $this->children($tree, null, $assets, $animations, ['x' => 0, 'y' => 0], 0, $visited);
        // The stage's own colour is the page's too: a screen of the other shape shows bars beside (or
        // above) the design, and they are then the ad's ground rather than black (§12).
        $colour = $this->stageColour($stage);
        $background = $this->background($stage, $assets, $colour);
        $title = e($ad->name);
        $fonts = $this->fonts->styleFor($elements);
        $motion = $this->motionScripts($animations);

        // The size comes from the ad's orientation, never from the document: the request holds the two to
        // the same value, and the column is the one a picker and a media row are told.
        $width = $ad->stageWidth();
        $height = $ad->stageHeight();

        return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$title}</title>
        {$fonts}
        <style>
        html, body { margin: 0; padding: 0; height: 100%; background: {$colour}; overflow: hidden; }
        .ad-stage { position: absolute; top: 0; left: 0; width: {$width}px; height: {$height}px; overflow: hidden;
                    transform-origin: top left; background: {$colour}; }
        .ad-bg, .ad-layer { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
        .ad-bg { z-index: 0; overflow: hidden; }
        video.ad-layer { object-fit: cover; }
        .ad-el { position: absolute; margin: 0; }
        .ad-anim { width: 100%; height: 100%; }
        .ad-content { width: 100%; height: 100%; box-sizing: border-box; display: block; }
        .ad-text { white-space: pre-wrap; word-break: break-word; }
        /* An element with an entrance waits hidden until its animation takes it over, so it never
           flashes in its final place first. The boot script lifts this once the runtime has set each
           element's own starting state — or at once when the runtime could not load, so a box with no
           animation library still shows the whole design. */
        .ad-pending > .ad-anim { opacity: 0; }
        </style>
        </head>
        <body>
        <div class="ad-stage" id="ad-stage">
        {$background}
        {$body}
        </div>
        <script>
        /* A design is {$width}x{$height}. A screen may not be, so the whole stage is scaled and centred as one
           piece, never reflowed: a reflowed advert is a different advert. */
        (function () {
            var stage = document.getElementById('ad-stage');
            function fit() {
                var scale = Math.min(window.innerWidth / {$width}, window.innerHeight / {$height});
                var x = (window.innerWidth - {$width} * scale) / 2;
                var y = (window.innerHeight - {$height} * scale) / 2;
                stage.style.transform = 'translate(' + x + 'px,' + y + 'px) scale(' + scale + ')';
            }
            window.addEventListener('resize', fit);
            fit();
        })();
        </script>
        {$motion}
        </body>
        </html>
        HTML;
    }

    /* ── The stage's background ─────────────────────────────────────────── */

    /** The stage's own colour — the ground under its layers, and the page's colour around it. */
    private function stageColour(array $stage): string
    {
        $settings = is_array($stage['background'] ?? null) ? $stage['background'] : [];

        return $this->colour($settings['color'] ?? null) ?? '#000000';
    }

    /** The stage's own colour, and its layers stacked on it — first layer furthest back. */
    private function background(array $stage, Collection $assets, string $colour): string
    {
        $settings = is_array($stage['background'] ?? null) ? $stage['background'] : [];
        $layers = [];

        foreach ($settings['layers'] ?? [] as $layer) {
            if (is_array($layer) && ($layer['visible'] ?? true) !== false) {
                $layers[] = $this->layer($layer, $assets);
            }
        }

        return sprintf(
            '<div class="ad-bg" style="background-color:%s;">%s</div>',
            $colour,
            implode('', array_filter($layers)),
        );
    }

    /** One background layer: a colour, a gradient, a picture or a video. */
    private function layer(array $layer, Collection $assets): string
    {
        $common = sprintf(
            'opacity:%s;mix-blend-mode:%s;',
            $this->limited('layer.opacity', $layer['opacity'] ?? 1),
            $this->oneOf($layer['blend'] ?? null, self::BLENDS, 'normal'),
        );

        $asset = $assets->get((int) ($layer['assetId'] ?? 0));

        return match ($layer['type'] ?? null) {
            'color' => ($colour = $this->colour($layer['color'] ?? null)) === null ? ''
                : sprintf('<div class="ad-layer" style="%sbackground-color:%s;"></div>', $common, $colour),

            'gradient' => ($gradient = $this->gradient($layer['gradient'] ?? null)) === null ? ''
                : sprintf('<div class="ad-layer" style="%sbackground-image:%s;"></div>', $common, $gradient),

            'image' => $asset === null || $asset->isVideo() ? '' : sprintf(
                '<div class="ad-layer" style="%sbackground-image:url(\'%s\');background-size:%s;background-position:%s;background-repeat:%s;"></div>',
                $common,
                $this->url($asset->url),
                $this->layerSize($layer),
                $this->oneOf($layer['position'] ?? null, self::POSITIONS, 'center center'),
                $this->oneOf($layer['repeat'] ?? null, self::REPEATS, 'no-repeat'),
            ),

            'video' => $asset === null || ! $asset->isVideo() ? '' : sprintf(
                '<video class="ad-layer" src="%s" autoplay muted loop playsinline style="%sobject-fit:%s;"></video>',
                $this->url($asset->url),
                $common,
                $this->oneOf($layer['fit'] ?? null, self::VIDEO_FITS, 'cover'),
            ),

            default => '',
        };
    }

    /** How big a picture layer is drawn: cover/contain/auto, or a percentage for a tiled pattern. */
    private function layerSize(array $layer): string
    {
        $size = $this->oneOf($layer['size'] ?? null, self::SIZES, 'cover');

        return $size === 'custom'
            ? $this->limited('layer.scale', $layer['scale'] ?? 100).'%'
            : $size;
    }

    /**
     * A gradient from its parts — kind, angle and two to six stops — or nothing when the parts are not
     * all real colours and positions. Rebuilt, never copied, so the CSS is always this method's own.
     */
    private function gradient(mixed $gradient): ?string
    {
        if (! is_array($gradient)) {
            return null;
        }

        $stops = [];

        foreach (array_slice($gradient['stops'] ?? [], 0, BuilderAdRequest::MAX_STOPS) as $stop) {
            $colour = is_array($stop) ? $this->colour($stop['color'] ?? null) : null;

            if ($colour !== null) {
                $stops[] = $colour.' '.$this->limited('gradient.at', $stop['at'] ?? 0).'%';
            }
        }

        if (count($stops) < 2) {
            return null;
        }

        return $this->oneOf($gradient['kind'] ?? null, self::GRADIENTS, 'linear') === 'radial'
            ? 'radial-gradient(circle at center, '.implode(', ', $stops).')'
            : 'linear-gradient('.$this->limited('gradient.angle', $gradient['angle'] ?? 180).'deg, '.implode(', ', $stops).')';
    }

    /* ── The tree ───────────────────────────────────────────────────────── */

    /**
     * The shown elements keyed by the group they are inside ('' for the top level), each list back to
     * front. A parent that is not a group of this design — or is the element itself — counts as none: the
     * request refuses such a document, and an older page must still compile rather than lose the element.
     *
     * @param  array<int, mixed>  $elements
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function tree(array $elements): array
    {
        $groups = [];

        foreach ($elements as $element) {
            if (is_array($element) && ($element['type'] ?? null) === 'group' && is_string($element['id'] ?? null)) {
                $groups[$element['id']] = true;
            }
        }

        $tree = [];

        foreach ($elements as $element) {
            if (! is_array($element) || ($element['visible'] ?? true) === false) {
                continue;
            }

            $parent = $element['parentId'] ?? null;
            $key = is_string($parent) && isset($groups[$parent]) && $parent !== ($element['id'] ?? null) ? $parent : '';

            $tree[$key][] = $element;
        }

        foreach ($tree as &$siblings) {
            usort($siblings, fn (array $a, array $b) => ($a['z'] ?? 0) <=> ($b['z'] ?? 0));
        }

        return $tree;
    }

    /**
     * Every element the page will hold, in paint order: reached from the top, no id twice, and never
     * inside more groups than the rules allow.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $tree
     * @return array<int, array<string, mixed>>
     */
    private function reachable(array $tree, ?string $parentId = null, int $depth = 0, array &$visited = []): array
    {
        $found = [];

        foreach ($tree[$parentId ?? ''] ?? [] as $element) {
            $id = $element['id'] ?? null;

            if (! is_string($id) || isset($visited[$id])) {
                continue;
            }

            $visited[$id] = true;
            $found[] = $element;

            if (($element['type'] ?? null) === 'group' && $depth < BuilderAdRequest::MAX_GROUP_DEPTH) {
                array_push($found, ...$this->reachable($tree, $id, $depth + 1, $visited));
            }
        }

        return $found;
    }

    /**
     * The elements inside one group (or on the stage itself), written back to front, each placed against
     * the group's own origin.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $tree
     * @param  array<string, array<string, array<string, mixed>>>  $animations  every element's, by safe id
     * @param  array{x: float|int, y: float|int}  $origin
     * @param  array<string, bool>  $visited
     */
    private function children(array $tree, ?string $parentId, Collection $assets, array $animations, array $origin, int $depth, array &$visited): string
    {
        $html = [];

        foreach ($tree[$parentId ?? ''] ?? [] as $element) {
            $id = $element['id'] ?? null;

            if (! is_string($id) || isset($visited[$id])) {
                continue;
            }

            $visited[$id] = true;
            $html[] = $this->element($element, $assets, $animations[AdAnimations::safeId($id)] ?? [], $origin, $tree, $depth, $visited);
        }

        return implode("\n", array_filter($html));
    }

    /**
     * A group's box is the box around what it holds — rotated corners counted, groups inside it by their
     * own — never the numbers it carries: the editor keeps those in step, but a page is written from what
     * is actually there. Null for a group with nothing shown in it.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $tree
     * @return array{x: float, y: float, w: float, h: float}|null
     */
    private function groupBox(array $tree, string $groupId, int $depth): ?array
    {
        if ($depth >= BuilderAdRequest::MAX_GROUP_DEPTH) {
            return null;
        }

        $boxes = [];

        foreach ($tree[$groupId] ?? [] as $child) {
            $id = $child['id'] ?? null;

            if (! is_string($id) || $id === $groupId) {
                continue;
            }

            $box = ($child['type'] ?? null) === 'group' ? $this->groupBox($tree, $id, $depth + 1) : $this->visualBox($child);

            if ($box !== null) {
                $boxes[] = $box;
            }
        }

        if ($boxes === []) {
            return null;
        }

        $left = min(array_column($boxes, 'x'));
        $top = min(array_column($boxes, 'y'));
        $right = max(array_map(fn (array $box) => $box['x'] + $box['w'], $boxes));
        $bottom = max(array_map(fn (array $box) => $box['y'] + $box['h'], $boxes));

        return ['x' => $left, 'y' => $top, 'w' => $right - $left, 'h' => $bottom - $top];
    }

    /**
     * The box around one element as it is seen: its own box turned by its rotation about its centre.
     *
     * @return array{x: float, y: float, w: float, h: float}
     */
    private function visualBox(array $element): array
    {
        $x = (float) $this->limited('x', $element['x'] ?? 0);
        $y = (float) $this->limited('y', $element['y'] ?? 0);
        $w = (float) $this->limited('w', $element['w'] ?? 100);
        $h = (float) $this->limited('h', $element['h'] ?? 100);
        $rotation = (float) $this->limited('rotation', $element['rotation'] ?? 0);

        if (fmod($rotation, 360.0) === 0.0) {
            return ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
        }

        $radians = deg2rad($rotation);
        $cos = cos($radians);
        $sin = sin($radians);
        $centreX = $x + $w / 2;
        $centreY = $y + $h / 2;
        $xs = [];
        $ys = [];

        foreach ([[-$w / 2, -$h / 2], [$w / 2, -$h / 2], [$w / 2, $h / 2], [-$w / 2, $h / 2]] as [$dx, $dy]) {
            $xs[] = $centreX + $dx * $cos - $dy * $sin;
            $ys[] = $centreY + $dx * $sin + $dy * $cos;
        }

        return ['x' => min($xs), 'y' => min($ys), 'w' => max($xs) - min($xs), 'h' => max($ys) - min($ys)];
    }

    /* ── Elements ───────────────────────────────────────────────────────── */

    /**
     * One element: its box, the node its animations move, and what it shows — or, for a group, what it
     * holds, each child placed against the group's own origin (§13).
     *
     * @param  array<string, array<string, mixed>>  $animations  this element's, already sanitized
     * @param  array{x: float|int, y: float|int}  $origin  the box this element is placed against
     * @param  array<string, array<int, array<string, mixed>>>  $tree
     * @param  array<string, bool>  $visited
     */
    private function element(array $element, Collection $assets, array $animations, array $origin, array $tree, int $depth, array &$visited): string
    {
        $style = is_array($element['style'] ?? null) ? $element['style'] : [];
        $asset = $assets->get((int) ($element['assetId'] ?? 0));

        if (($element['type'] ?? null) === 'group') {
            $id = $element['id'];
            $box = $this->groupBox($tree, $id, $depth);
            $inner = $box === null ? '' : $this->children($tree, $id, $assets, [], ['x' => $box['x'], 'y' => $box['y']], $depth + 1, $visited);

            // The children's own animations were handed out by the caller's table, not this empty one.
            return $inner === '' ? '' : sprintf(
                '<div class="ad-el%s" data-anim-id="%s" style="%s"><div class="ad-anim">%s</div></div>',
                isset($animations['in']) ? ' ad-pending' : '',
                e(AdAnimations::safeId($id)),
                $this->boxStyle(['x' => $box['x'], 'y' => $box['y'], 'w' => $box['w'], 'h' => $box['h'], 'rotation' => 0, 'opacity' => $element['opacity'] ?? 1, 'z' => $element['z'] ?? 0], $style, false, $origin),
                $inner,
            );
        }

        $content = match ($element['type'] ?? null) {
            'text' => $this->text($element, $style),
            'image' => $asset === null || $asset->isVideo() ? '' : sprintf(
                '<img class="ad-content" src="%s" alt="" style="%s">',
                $this->url($asset->url),
                $this->pictureStyle($style),
            ),
            'video' => $asset === null || ! $asset->isVideo() ? '' : sprintf(
                '<video class="ad-content" src="%s" autoplay muted loop playsinline style="%s"></video>',
                $this->url($asset->url),
                $this->pictureStyle($style),
            ),
            'shape' => sprintf('<div class="ad-content" style="%s"></div>', $this->shapeStyle($style)),
            default => '',
        };

        if ($content === '') {
            return '';
        }

        return sprintf(
            '<div class="ad-el%s" data-anim-id="%s" style="%s"><div class="ad-anim">%s</div></div>',
            isset($animations['in']) ? ' ad-pending' : '',
            e(AdAnimations::safeId($element['id'] ?? null)),
            $this->boxStyle($element, $style, ($animations['loop']['effect'] ?? null) === 'kenburns', $origin),
            $content,
        );
    }

    /** A run of words, escaped, with the type set the way the panel said. */
    private function text(array $element, array $style): string
    {
        $css = sprintf(
            'font-family:%s;font-size:%spx;font-weight:%s;font-style:%s;color:%s;text-align:%s;'
            .'line-height:%s;letter-spacing:%spx;word-spacing:%spx;text-transform:%s;text-decoration:%s;',
            $this->fontFamily($style['fontFamily'] ?? null),
            $this->limited('fontSize', $style['fontSize'] ?? 48),
            (int) $this->limited('fontWeight', $style['fontWeight'] ?? 400),
            $this->oneOf($style['fontStyle'] ?? null, self::FONT_STYLES, 'normal'),
            $this->colour($style['color'] ?? null) ?? '#ffffff',
            $this->oneOf($style['align'] ?? null, self::ALIGNMENTS, 'left'),
            $this->limited('lineHeight', $style['lineHeight'] ?? 1.2),
            $this->limited('letterSpacing', $style['letterSpacing'] ?? 0),
            $this->limited('wordSpacing', $style['wordSpacing'] ?? 0),
            $this->oneOf($style['textTransform'] ?? null, self::TRANSFORMS, 'none'),
            $this->oneOf($style['textDecoration'] ?? null, self::DECORATIONS, 'none'),
        );

        // How the words sit inside their box, which is what makes a headline look placed rather than
        // dropped: the vertical anchor, and the padding a coloured panel needs to breathe.
        $css .= sprintf(
            'display:flex;flex-direction:column;justify-content:%s;padding:%spx;',
            $this->oneOf($style['verticalAlign'] ?? null, self::VERTICAL, 'flex-start'),
            $this->limited('padding', $style['padding'] ?? 0),
        );

        // A panel behind the text — a price tag, a ribbon — is a background and a radius, no more.
        $background = $this->colour($style['background'] ?? null);

        if ($background !== null) {
            $css .= sprintf('background:%s;border-radius:%spx;', $background, $this->limited('radius', $style['radius'] ?? 0));
        }

        $shadow = $this->textShadow($style['textShadow'] ?? null);

        if ($shadow !== null) {
            $css .= "text-shadow:{$shadow};";
        }

        $stroke = $this->textStroke($style['textStroke'] ?? null);

        if ($stroke !== null) {
            $css .= $stroke;
        }

        $css .= $this->border($style['border'] ?? null);

        return sprintf(
            // dir="auto": the browser reads the direction from the words themselves, so Urdu and Arabic
            // run right to left without anybody having to say so.
            '<div class="ad-content ad-text" dir="auto" style="%s">%s</div>',
            $css,
            e((string) ($element['text'] ?? '')),
        );
    }

    /** `{x, y, blur, color}` → one text-shadow, or nothing at all. */
    private function textShadow(mixed $shadow): ?string
    {
        if (! is_array($shadow)) {
            return null;
        }

        $colour = $this->colour($shadow['color'] ?? null);

        if ($colour === null) {
            return null;
        }

        return sprintf(
            '%spx %spx %spx %s',
            $this->limited('textShadow.x', $shadow['x'] ?? 0),
            $this->limited('textShadow.y', $shadow['y'] ?? 0),
            $this->limited('textShadow.blur', $shadow['blur'] ?? 0),
            $colour,
        );
    }

    /** `{width, color}` → an outline around the letters, the way a poster does it. */
    private function textStroke(mixed $stroke): ?string
    {
        if (! is_array($stroke)) {
            return null;
        }

        $colour = $this->colour($stroke['color'] ?? null);
        $width = $this->limited('textStroke.width', $stroke['width'] ?? 0);

        if ($colour === null || $width === '0') {
            return null;
        }

        // -webkit-text-stroke is what every browser a signage box runs actually supports.
        return "-webkit-text-stroke:{$width}px {$colour};paint-order:stroke fill;";
    }

    /** How a picture or a video looks inside its box: fit, corners, frame, shadow, filters, mirror. */
    private function pictureStyle(array $style): string
    {
        $css = sprintf(
            'object-fit:%s;object-position:%s;border-radius:%spx;',
            $this->oneOf($style['fit'] ?? null, self::FITS, 'cover'),
            $this->oneOf($style['position'] ?? null, self::POSITIONS, 'center center'),
            $this->limited('radius', $style['radius'] ?? 0),
        );

        $css .= $this->border($style['border'] ?? null);
        $css .= $this->boxShadow($style['shadow'] ?? null);

        $filters = $this->filters($style['filters'] ?? null);

        if ($filters !== null) {
            $css .= "filter:{$filters};";
        }

        $flip = sprintf(
            'scale(%s, %s)',
            ($style['flipX'] ?? false) === true ? '-1' : '1',
            ($style['flipY'] ?? false) === true ? '-1' : '1',
        );

        return $flip === 'scale(1, 1)' ? $css : $css."transform:{$flip};";
    }

    /** A rectangle or an ellipse, filled with a colour or a gradient, with a frame and a shadow. */
    private function shapeStyle(array $style): string
    {
        $gradient = $this->gradient($style['gradient'] ?? null);
        $fill = $gradient !== null
            ? "background-image:{$gradient};"
            : 'background-color:'.($this->colour($style['fill'] ?? null) ?? '#2563eb').';';

        $radius = $this->oneOf($style['shape'] ?? null, self::SHAPES, 'rect') === 'ellipse'
            ? '50%'
            : $this->limited('radius', $style['radius'] ?? 0).'px';

        return $fill."border-radius:{$radius};"
            .$this->border($style['border'] ?? null)
            .$this->boxShadow($style['shadow'] ?? null);
    }

    /** `{width, color, style}` → a frame, or nothing. */
    private function border(mixed $border): string
    {
        if (! is_array($border)) {
            return '';
        }

        $colour = $this->colour($border['color'] ?? null);
        $width = $this->limited('border.width', $border['width'] ?? 0);

        if ($colour === null || $width === '0') {
            return '';
        }

        return sprintf('border:%spx %s %s;', $width, $this->oneOf($border['style'] ?? null, self::BORDER_STYLES, 'solid'), $colour);
    }

    /** `{x, y, blur, spread, color}` → a drop shadow under the element, or nothing. */
    private function boxShadow(mixed $shadow): string
    {
        if (! is_array($shadow)) {
            return '';
        }

        $colour = $this->colour($shadow['color'] ?? null);

        if ($colour === null) {
            return '';
        }

        return sprintf(
            'box-shadow:%spx %spx %spx %spx %s;',
            $this->limited('shadow.x', $shadow['x'] ?? 0),
            $this->limited('shadow.y', $shadow['y'] ?? 0),
            $this->limited('shadow.blur', $shadow['blur'] ?? 0),
            $this->limited('shadow.spread', $shadow['spread'] ?? 0),
            $colour,
        );
    }

    /**
     * The picture filters, only the ones changed from neutral, in CSS's own words — or nothing.
     * Each one is a named function with a clamped number; a filter name nobody here wrote is dropped.
     */
    private function filters(mixed $filters): ?string
    {
        if (! is_array($filters)) {
            return null;
        }

        $parts = [];

        foreach (self::FILTERS as $key => [$function, $unit, $neutral]) {
            if (! array_key_exists($key, $filters)) {
                continue;
            }

            $value = $this->limited("filters.{$key}", $filters[$key]);

            if ((float) $value !== (float) $neutral) {
                $parts[] = "{$function}({$value}{$unit})";
            }
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * Where the element sits — against the origin of whatever holds it: the stage, or its group (§13) —
     * how big it is, which way up, how solid, and how it mixes.
     *
     * A Ken Burns loop zooms a photograph INSIDE its frame, the way television does it, so that element's
     * box clips what it holds — along the picture's own rounded corners.
     *
     * @param  array{x: float|int, y: float|int}  $origin
     */
    private function boxStyle(array $element, array $style, bool $clipsItsContent, array $origin): string
    {
        $css = sprintf(
            'left:%spx;top:%spx;width:%spx;height:%spx;opacity:%s;z-index:%d;',
            $this->number((float) $this->limited('x', $element['x'] ?? 0) - (float) $origin['x'], -40000, 40000),
            $this->number((float) $this->limited('y', $element['y'] ?? 0) - (float) $origin['y'], -40000, 40000),
            $this->limited('w', $element['w'] ?? 100),
            $this->limited('h', $element['h'] ?? 100),
            $this->limited('opacity', $element['opacity'] ?? 1),
            // Above the background (which sits at 0), in the order the person stacked them.
            1 + (int) $this->number($element['z'] ?? 0, 0, 1000),
        );

        $rotation = $this->limited('rotation', $element['rotation'] ?? 0);

        if ($rotation !== '0') {
            $css .= "transform:rotate({$rotation}deg);";
        }

        if ($clipsItsContent) {
            $css .= sprintf('overflow:hidden;border-radius:%spx;', $this->limited('radius', $style['radius'] ?? 0));
        }

        $blend = $this->oneOf($style['blend'] ?? null, self::BLENDS, 'normal');

        return $blend === 'normal' ? $css : $css."mix-blend-mode:{$blend};";
    }

    /* ── Motion ─────────────────────────────────────────────────────────── */

    /**
     * The animation library, the runtime and the design's animations — only when something moves.
     *
     * The animations travel as JSON inside a `type="application/json"` block, encoded with every HTML
     * character escaped (`<`, `>`, `&`, quotes), so nothing inside can close the script tag; the boot
     * script `JSON.parse`s it. A static advert gets none of this and loads nothing extra.
     */
    private function motionScripts(array $animations): string
    {
        if ($animations === []) {
            return '';
        }

        $json = json_encode($animations, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);

        return implode("\n", [
            '<script type="application/json" id="ad-animations">'.$json.'</script>',
            ...array_map(
                fn (string $path) => '<script src="'.e(self::scriptUrl($path)).'"></script>',
                self::MOTION_SCRIPTS,
            ),
            <<<'JS'
            <script>
            /* Start the design's animations the moment the advert is really on screen. The player loads
               the next item a beat early, behind the one showing, and an entrance played back there would
               be over before anybody saw it — so the stage is watched, and the clock starts when it
               appears (at once, when the page is opened on its own). Whatever happens, every element
               ends up visible: a box that could not load the library shows the advert standing still
               rather than half of it. */
            (function () {
                var stage = document.getElementById('ad-stage');
                var pending = document.querySelectorAll('.ad-pending');
                var started = false;
                function reveal() {
                    for (var i = 0; i < pending.length; i++) pending[i].classList.remove('ad-pending');
                }
                function start() {
                    if (started) return;
                    started = true;
                    try {
                        var config = JSON.parse(document.getElementById('ad-animations').textContent);
                        window.AdRuntime.run(stage, config);
                    } catch (error) { /* fall through to reveal */ }
                    reveal();
                }
                if (!window.anime || !window.AdRuntime) { reveal(); return; }
                if (!('IntersectionObserver' in window)) { start(); return; }
                var observer = new IntersectionObserver(function (entries) {
                    for (var i = 0; i < entries.length; i++) {
                        if (entries[i].isIntersecting) { observer.disconnect(); start(); return; }
                    }
                });
                observer.observe(stage);
                /* A frame that never says it is visible still starts. */
                setTimeout(function () { observer.disconnect(); start(); }, 4000);
            })();
            </script>
            JS,
        ]);
    }

    /**
     * A script under public/ with its content's fingerprint on the address, so a screen — or the editor —
     * never keeps running a copy it cached before an update.
     */
    public static function scriptUrl(string $path): string
    {
        $file = public_path($path);
        $version = is_file($file) ? substr((string) md5_file($file), 0, 10) : '';

        return asset($path).($version !== '' ? '?v='.$version : '');
    }

    /* ── Readers ───────────────────────────────────────────────────────── */

    /** Every asset the design names, keyed by id — this store's only. */
    private function assetsFor(BuilderAd $ad, array $elements, array $stage): Collection
    {
        $layers = is_array($stage['background']['layers'] ?? null) ? $stage['background']['layers'] : [];

        $ids = collect($elements)->pluck('assetId')
            ->merge(collect($layers)->filter(fn (mixed $layer) => is_array($layer))->pluck('assetId'))
            ->filter(fn (mixed $id) => is_int($id) || (is_string($id) && ctype_digit($id)))
            ->map(fn (int|string $id) => (int) $id)
            ->unique();

        if ($ids->isEmpty()) {
            return collect();
        }

        return BuilderAsset::whereIn('id', $ids)
            ->where('store_id', $ad->store_id)
            ->get()
            ->keyBy('id');
    }

    /**
     * A number, and only a number.
     *
     * Everything that reaches the stylesheet goes through here, so a value like `10px; background:url(…)`
     * can never close a declaration and open another one.
     */
    private function number(mixed $value, float $min, float $max): string
    {
        $number = is_numeric($value) ? (float) $value : 0.0;
        $number = max($min, min($max, $number));

        // Whole numbers without a trailing ".0", so the CSS reads the way a person wrote it.
        return rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.') ?: '0';
    }

    /** A number held inside its entry in LIMITS. */
    private function limited(string $key, mixed $value): string
    {
        [$min, $max] = self::LIMITS[$key];

        return $this->number($value, $min, $max);
    }

    /** A colour, or nothing. Hex, rgb() and rgba() only — never an arbitrary string. */
    private function colour(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) === 1) {
            return $value;
        }

        if (preg_match('/^rgba?\(\s*[\d.]+\s*,\s*[\d.]+\s*,\s*[\d.]+\s*(,\s*[\d.]+\s*)?\)$/', $value) === 1) {
            return $value;
        }

        return null;
    }

    /** One of the values this file knows, or the default. */
    private function oneOf(mixed $value, array $allowed, ?string $default): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    /** A font family name, quoted, with the device's own sans-serif behind it. */
    private function fontFamily(mixed $value): string
    {
        $name = self::familyName($value);

        return $name === '' ? 'sans-serif' : "'{$name}', sans-serif";
    }

    /**
     * A font family's name as a page may carry it — '' when nothing is left.
     *
     * Only letters, numbers, spaces and hyphens survive, which is every real family name and no CSS. One
     * reader for an element's style and for the @font-face the page embeds (AdFontEmbedder), so the two
     * can never spell a family differently.
     */
    public static function familyName(mixed $value): string
    {
        $name = is_string($value) ? preg_replace('/[^A-Za-z0-9 \-]/', ' ', $value) : '';
        $name = trim(preg_replace('/\s+/', ' ', (string) $name) ?? '');

        return mb_substr($name, 0, 64);
    }

    /** A URL for an attribute: our own storage path, with quotes and brackets removed. */
    private function url(string $url): string
    {
        return e(str_replace(["'", '"', '(', ')', ' '], ['%27', '%22', '%28', '%29', '%20'], $url));
    }
}
