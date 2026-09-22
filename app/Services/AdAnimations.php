<?php

namespace App\Services;

/**
 * What an element's animations may be (docs/AD-BUILDER-SPEC.md §8).
 *
 * The server's copy of the rules the runtime (`public/ad-runtime/runtime.js`) also enforces: an effect is
 * a name from a list, a number is clamped, an ease is a name from a list or four numbers from the Ease
 * Visualizer's handles. The compiled page carries only what comes out of here, as JSON the runtime
 * parses — never evaluates — so a document with code in it produces an advert with no animation, not an
 * advert that runs the code.
 *
 * Keep the lists in step with the runtime's: a name here that the runtime does not know is harmless (it
 * falls back), a name the runtime knows that is missing here simply never reaches a page.
 */
class AdAnimations
{
    public const EASES = [
        'none',
        'power1.in', 'power1.out', 'power1.inOut',
        'power2.in', 'power2.out', 'power2.inOut',
        'power3.in', 'power3.out', 'power3.inOut',
        'power4.in', 'power4.out', 'power4.inOut',
        'sine.in', 'sine.out', 'sine.inOut',
        'expo.in', 'expo.out', 'expo.inOut',
        'circ.in', 'circ.out', 'circ.inOut',
        'back.in', 'back.out', 'back.inOut',
        'elastic.in', 'elastic.out', 'elastic.inOut',
        'bounce.in', 'bounce.out', 'bounce.inOut',
    ];

    /** How an element arrives (and, run backwards, leaves). */
    public const ENTRANCES = ['fade', 'slide', 'zoom', 'rotate', 'blur', 'flip', 'wipe', 'bounce'];

    /** What it keeps doing while the advert is on screen. */
    public const LOOPS = ['float', 'pulse', 'sway', 'drift', 'spin', 'blink', 'shake', 'kenburns'];

    public const DIRECTIONS = ['up', 'down', 'left', 'right'];

    public const AXES = ['x', 'y'];

    /**
     * Every number a slot may carry, as [min, max, default]. The sanitizer clamps to these, the request
     * refuses anything outside them and the editor's inputs are held inside them — one table for all
     * three, and the runtime's own clamps say the same.
     */
    public const NUMBERS = [
        'in' => [
            'distance' => [0, 2000, 80],
            'scale' => [0, 5, 0.6],
            'degrees' => [-720, 720, -90],
            'blur' => [0, 100, 20],
            'duration' => [0.05, 60, 0.8],
            'delay' => [0, 600, 0],
        ],
        'loop' => [
            'amount' => [-2000, 2000, 10],
            'amountX' => [-2000, 2000, 0],
            'amountY' => [-2000, 2000, 0],
            'duration' => [0.1, 120, 2],
            'delay' => [0, 600, 0],
        ],
        'out' => [
            'distance' => [0, 2000, 80],
            'scale' => [0, 5, 0.6],
            'degrees' => [-720, 720, -90],
            'blur' => [0, 100, 20],
            'at' => [0, 3600, 5],
            'duration' => [0.05, 60, 0.6],
        ],
    ];

    /** A custom curve's handles: x stays inside the animation's time, y may overshoot a little. */
    public const CUBIC_BOUNDS = [[0, 1, 0.25], [-1, 2, 0.1], [0, 1, 0.25], [-1, 2, 1]];

    /**
     * Every element's animations that are worth sending, keyed by a safe element id.
     *
     * @param  array<int, array<string, mixed>>  $elements
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function forElements(array $elements): array
    {
        $config = [];

        foreach ($elements as $element) {
            $id = self::safeId($element['id'] ?? null);
            $animations = $this->sanitize($element['animations'] ?? null);

            if ($id !== '' && $animations !== []) {
                $config[$id] = $animations;
            }
        }

        return $config;
    }

    /**
     * One element's animations, with nothing left in them that the rules did not write.
     *
     * @return array<string, array<string, mixed>>
     */
    public function sanitize(mixed $animations): array
    {
        if (! is_array($animations)) {
            return [];
        }

        return array_filter([
            'in' => $this->entrance($animations['in'] ?? null),
            'loop' => $this->loop($animations['loop'] ?? null),
            'out' => $this->exit($animations['out'] ?? null),
        ]);
    }

    /** An element id as the page's `data-anim-id` may carry it: letters, digits, `_` and `-` only. */
    public static function safeId(mixed $id): string
    {
        return is_string($id) ? substr((string) preg_replace('/[^A-Za-z0-9_\-]/', '', $id), 0, 40) : '';
    }

    /** Whether a value is an ease this file would keep: a name from the list, or `cubic(a,b,c,d)`. */
    public static function isEase(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        if (in_array($value, self::EASES, true)) {
            return true;
        }

        $parts = self::cubicParts($value);

        return $parts !== null && collect($parts)->every(fn (string $part) => is_numeric($part));
    }

    private function entrance(mixed $slot): ?array
    {
        if (! is_array($slot) || ! in_array($slot['effect'] ?? null, self::ENTRANCES, true)) {
            return null;
        }

        return [
            'effect' => $slot['effect'],
            'direction' => $this->oneOf($slot['direction'] ?? null, self::DIRECTIONS, 'up'),
            ...$this->numbers('in', $slot),
            'ease' => $this->ease($slot['ease'] ?? null, $slot['effect'] === 'bounce' ? 'bounce.out' : 'power2.out'),
        ];
    }

    private function loop(mixed $slot): ?array
    {
        if (! is_array($slot) || ! in_array($slot['effect'] ?? null, self::LOOPS, true)) {
            return null;
        }

        return [
            'effect' => $slot['effect'],
            'axis' => $this->oneOf($slot['axis'] ?? null, self::AXES, 'y'),
            ...$this->numbers('loop', $slot),
            'yoyo' => ($slot['yoyo'] ?? true) !== false,
            'ease' => $this->ease($slot['ease'] ?? null, 'sine.inOut'),
        ];
    }

    private function exit(mixed $slot): ?array
    {
        if (! is_array($slot) || ! in_array($slot['effect'] ?? null, self::ENTRANCES, true)) {
            return null;
        }

        return [
            'effect' => $slot['effect'],
            'direction' => $this->oneOf($slot['direction'] ?? null, self::DIRECTIONS, 'up'),
            ...$this->numbers('out', $slot),
            'ease' => $this->ease($slot['ease'] ?? null, 'power2.in'),
        ];
    }

    /**
     * A slot's numbers, each clamped to its row of NUMBERS — and the row's default, not a bound, when
     * the document holds no number at all.
     *
     * @return array<string, float>
     */
    private function numbers(string $slot, array $values): array
    {
        $numbers = [];

        foreach (self::NUMBERS[$slot] as $key => [$min, $max, $default]) {
            $numbers[$key] = $this->number($values[$key] ?? null, $min, $max, $default);
        }

        return $numbers;
    }

    /**
     * An ease: a name from the list, or `cubic(x1,y1,x2,y2)` rebuilt from four clamped numbers — so
     * whatever the document said, the page carries a string this method wrote itself.
     */
    private function ease(mixed $value, string $default): string
    {
        $parts = is_string($value) ? self::cubicParts($value) : null;

        if ($parts !== null) {
            $numbers = [];

            foreach (self::CUBIC_BOUNDS as $index => [$min, $max, $fallback]) {
                $numbers[] = $this->number($parts[$index], $min, $max, $fallback);
            }

            return 'cubic('.implode(',', $numbers).')';
        }

        return $this->oneOf($value, self::EASES, $default);
    }

    /**
     * The four parts inside `cubic(…)`, or null when the value is not one.
     *
     * @return array<int, string>|null
     */
    private static function cubicParts(string $value): ?array
    {
        if (preg_match('/^cubic\(([^)]*)\)$/', $value, $match) !== 1) {
            return null;
        }

        $parts = array_map('trim', explode(',', $match[1]));

        return count($parts) === 4 ? $parts : null;
    }

    /** A number inside its bounds — and the slot's default, not the bound, when it is no number at all. */
    private function number(mixed $value, float $min, float $max, float $default): float
    {
        $number = is_numeric($value) ? (float) $value : $default;

        return round(max($min, min($max, $number)), 3);
    }

    private function oneOf(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }
}
