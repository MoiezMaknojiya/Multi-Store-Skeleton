<?php

namespace App\Http\Requests\Builder;

use App\Models\BuilderAd;
use App\Services\AdAnimations;
use App\Services\AdCompiler;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * An ad's name and its design (docs/AD-BUILDER-SPEC.md §6).
 *
 * The document matters more than most payloads in this app, because it is the one thing a person writes
 * that later becomes HTML on a television. So its SHAPE is checked here — every key that reaches the
 * compiler is named, typed and bounded — and its CONTENT is escaped again when the HTML is written. Two
 * walls, because a single one here would put a `<script>` on a screen the day somebody finds a gap.
 *
 * Every key is named for a second reason: what is saved is `validated()`, and Laravel leaves out of it
 * any key inside a checked array that has no rule of its own. A key the editor writes but this list
 * forgets is therefore not refused — it silently disappears on save. `AdDocumentRulesTest` saves a
 * document holding every key the editor writes and expects all of them back.
 *
 * The numbers come from the same tables the compiler clamps with (AdCompiler::LIMITS,
 * AdAnimations::NUMBERS), and the editor is handed those tables too, so the three never disagree.
 */
class BuilderAdRequest extends FormRequest
{
    /** Elements one stage may hold. A guard against a runaway client, not a use case. */
    public const MAX_ELEMENTS = 200;

    /** Background layers one stage may stack. */
    public const MAX_LAYERS = 12;

    /** The most text one element may carry. */
    public const MAX_TEXT = 2000;

    /** Colour stops one gradient may have. */
    public const MAX_STOPS = 6;

    /** Guides one stage may keep, across and down each. */
    public const MAX_GUIDES = 50;

    /** Element kinds the compiler knows how to write. */
    public const TYPES = ['text', 'image', 'video', 'shape'];

    /** What a background layer may be. */
    public const LAYER_TYPES = ['color', 'gradient', 'image', 'video'];

    /** A colour, in whatever form: the compiler keeps hex, rgb() and rgba() and drops anything else. */
    private const COLOUR = ['nullable', 'string', 'max:64'];

    /**
     * An ad is saved only from where it can be seen: another shop's is not found (404) — before its
     * document is checked, so the answer never says the id exists.
     */
    public function authorize(): bool
    {
        $ad = $this->route('ad');

        abort_if($ad instanceof BuilderAd && ! BuilderAd::visibleTo($this->user())->whereKey($ad->id)->exists(), 404);

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $layer = 'document.stage.background.layers.*';
        $element = 'document.elements.*';
        $style = "{$element}.style";

        return [
            'name' => ['bail', 'required', 'string', 'max:120'],

            'document' => ['required', 'array'],
            'document.version' => ['required', 'integer', 'in:1'],

            // The frame is 1920×1080 and nothing may say otherwise (owner's rule): a television is that
            // shape, so a document claiming another size would be designed for a screen that does not exist.
            'document.stage' => ['required', 'array'],
            'document.stage.width' => ['required', 'integer', 'in:'.BuilderAd::STAGE_WIDTH],
            'document.stage.height' => ['required', 'integer', 'in:'.BuilderAd::STAGE_HEIGHT],
            'document.stage.background' => ['required', 'array'],
            'document.stage.background.color' => self::COLOUR,

            // The background's layers, first one furthest back. A LIST, because the order is the design
            // and an object with keys would come back in no particular order.
            'document.stage.background.layers' => ['present', 'list', 'max:'.self::MAX_LAYERS],
            $layer => ['array'],
            "{$layer}.id" => ['required', 'string', 'max:40'],
            "{$layer}.type" => ['required', Rule::in(self::LAYER_TYPES)],
            "{$layer}.visible" => ['nullable', 'boolean'],
            "{$layer}.opacity" => $this->limit('layer.opacity'),
            "{$layer}.blend" => ['nullable', Rule::in(AdCompiler::BLENDS)],
            "{$layer}.color" => self::COLOUR,
            ...$this->gradient("{$layer}.gradient"),
            "{$layer}.assetId" => ['nullable', 'integer', 'min:1'],
            "{$layer}.size" => ['nullable', Rule::in(AdCompiler::SIZES)],
            "{$layer}.scale" => $this->limit('layer.scale'),
            "{$layer}.position" => ['nullable', Rule::in(AdCompiler::POSITIONS)],
            "{$layer}.repeat" => ['nullable', Rule::in(AdCompiler::REPEATS)],
            "{$layer}.fit" => ['nullable', Rule::in(AdCompiler::VIDEO_FITS)],

            // The editor's guides (§10a): lines a person dragged out of the rulers to line things up on.
            // They belong to the design, so they travel with it; the compiler never draws them.
            'document.guides' => ['nullable', 'array'],
            'document.guides.x' => ['nullable', 'list', 'max:'.self::MAX_GUIDES],
            'document.guides.x.*' => $this->limit('x', required: true),
            'document.guides.y' => ['nullable', 'list', 'max:'.self::MAX_GUIDES],
            'document.guides.y.*' => $this->limit('y', required: true),

            'document.elements' => ['present', 'list', 'max:'.self::MAX_ELEMENTS],
            $element => ['array'],
            "{$element}.id" => ['required', 'string', 'max:40'],
            "{$element}.type" => ['required', Rule::in(self::TYPES)],
            "{$element}.name" => ['nullable', 'string', 'max:120'],

            // The box. Off-stage values are allowed on purpose — a person may park something just
            // outside the frame, or animate it in from there — but not absurd ones.
            "{$element}.x" => $this->limit('x', required: true),
            "{$element}.y" => $this->limit('y', required: true),
            "{$element}.w" => $this->limit('w', required: true),
            "{$element}.h" => $this->limit('h', required: true),
            "{$element}.rotation" => $this->limit('rotation'),
            "{$element}.opacity" => $this->limit('opacity'),
            "{$element}.z" => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_ELEMENTS],
            "{$element}.locked" => ['nullable', 'boolean'],
            "{$element}.visible" => ['nullable', 'boolean'],

            // Text is stored as the person typed it and escaped when the HTML is written.
            "{$element}.text" => ['nullable', 'string', 'max:'.self::MAX_TEXT],
            "{$element}.assetId" => ['nullable', 'integer', 'min:1'],

            // How it looks. One map for every kind of element — each kind simply uses its own keys.
            $style => ['nullable', 'array'],
            "{$style}.blend" => ['nullable', Rule::in(AdCompiler::BLENDS)],

            // Type (docs/AD-BUILDER-SPEC.md §7a).
            "{$style}.fontFamily" => ['nullable', 'string', 'max:100'],
            "{$style}.fontSize" => $this->limit('fontSize'),
            "{$style}.fontWeight" => $this->limit('fontWeight'),
            "{$style}.fontStyle" => ['nullable', Rule::in(AdCompiler::FONT_STYLES)],
            "{$style}.color" => self::COLOUR,
            "{$style}.align" => ['nullable', Rule::in(AdCompiler::ALIGNMENTS)],
            "{$style}.verticalAlign" => ['nullable', Rule::in(AdCompiler::VERTICAL)],
            "{$style}.lineHeight" => $this->limit('lineHeight'),
            "{$style}.letterSpacing" => $this->limit('letterSpacing'),
            "{$style}.wordSpacing" => $this->limit('wordSpacing'),
            "{$style}.textTransform" => ['nullable', Rule::in(AdCompiler::TRANSFORMS)],
            "{$style}.textDecoration" => ['nullable', Rule::in(AdCompiler::DECORATIONS)],
            "{$style}.padding" => $this->limit('padding'),
            "{$style}.background" => self::COLOUR,
            "{$style}.radius" => $this->limit('radius'),
            "{$style}.textShadow" => ['nullable', 'array'],
            "{$style}.textShadow.x" => $this->limit('textShadow.x'),
            "{$style}.textShadow.y" => $this->limit('textShadow.y'),
            "{$style}.textShadow.blur" => $this->limit('textShadow.blur'),
            "{$style}.textShadow.color" => self::COLOUR,
            "{$style}.textStroke" => ['nullable', 'array'],
            "{$style}.textStroke.width" => $this->limit('textStroke.width'),
            "{$style}.textStroke.color" => self::COLOUR,

            // Pictures and videos (stage 3).
            "{$style}.fit" => ['nullable', Rule::in(AdCompiler::FITS)],
            "{$style}.position" => ['nullable', Rule::in(AdCompiler::POSITIONS)],
            "{$style}.flipX" => ['nullable', 'boolean'],
            "{$style}.flipY" => ['nullable', 'boolean'],
            "{$style}.filters" => ['nullable', 'array'],
            ...collect(AdCompiler::FILTERS)->mapWithKeys(fn (array $filter, string $key) => [
                "{$style}.filters.{$key}" => $this->limit("filters.{$key}"),
            ])->all(),

            // Shapes.
            "{$style}.shape" => ['nullable', Rule::in(AdCompiler::SHAPES)],
            "{$style}.fill" => self::COLOUR,
            ...$this->gradient("{$style}.gradient"),

            // A frame and a shadow, for anything but text (which has its own shadow).
            "{$style}.border" => ['nullable', 'array'],
            "{$style}.border.width" => $this->limit('border.width'),
            "{$style}.border.style" => ['nullable', Rule::in(AdCompiler::BORDER_STYLES)],
            "{$style}.border.color" => self::COLOUR,
            "{$style}.shadow" => ['nullable', 'array'],
            "{$style}.shadow.x" => $this->limit('shadow.x'),
            "{$style}.shadow.y" => $this->limit('shadow.y'),
            "{$style}.shadow.blur" => $this->limit('shadow.blur'),
            "{$style}.shadow.spread" => $this->limit('shadow.spread'),
            "{$style}.shadow.color" => self::COLOUR,

            // How it moves (stage 4): arriving, while on screen, leaving.
            "{$element}.animations" => ['nullable', 'array'],
            ...$this->slot("{$element}.animations.in", 'in'),
            ...$this->slot("{$element}.animations.loop", 'loop'),
            ...$this->slot("{$element}.animations.out", 'out'),

            // The poster the editor captures, as a data URI. Optional: an ad saves without one.
            'thumbnail' => ['nullable', 'string', 'starts_with:data:image/', 'max:4000000'],

            // The shop a new ad is for, said by the platform team only (a store's person builds in the store
            // they stand in, and whatever they send here is not read). Whether it exists is the controller's.
            'store_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Give the ad a name.',
            'document.stage.width.in' => 'An ad is 1920 × 1080 — the size a television is.',
            'document.stage.height.in' => 'An ad is 1920 × 1080 — the size a television is.',
            'document.elements.max' => 'An ad may hold at most '.self::MAX_ELEMENTS.' elements.',
            'document.stage.background.layers.max' => 'A background may stack at most '.self::MAX_LAYERS.' layers.',
            'document.guides.x.max' => 'A stage may keep at most '.self::MAX_GUIDES.' guides each way.',
            'document.guides.y.max' => 'A stage may keep at most '.self::MAX_GUIDES.' guides each way.',
        ];
    }

    /**
     * Names a person can read in a message — "The letter spacing field must be between -100 and 100" —
     * rather than the document path the rule is written against.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $names = [];

        foreach (array_keys($this->rules()) as $key) {
            if (preg_match('/^document\.elements\.\*\.(.+)$/', $key, $match) === 1) {
                $names[$key] = $this->readable($match[1]);
            } elseif (preg_match('/^document\.stage\.background\.layers\.\*\.(.+)$/', $key, $match) === 1) {
                $names[$key] = 'background layer '.$this->readable($match[1]);
            } elseif (preg_match('/^document\.guides\.([xy])(\.\*)?$/', $key, $match) === 1) {
                $names[$key] = $match[1] === 'x' ? 'guide across' : 'guide down';
            }
        }

        return $names;
    }

    /** `style.textShadow.blur` → "text shadow blur", `animations.in.duration` → "entrance duration". */
    private function readable(string $path): string
    {
        $path = preg_replace(
            ['/^animations\.in\./', '/^animations\.loop\./', '/^animations\.out\./', '/^style\./', '/\.\*\./', '/\./'],
            ['entrance ', 'loop ', 'exit ', '', ' ', ' '],
            $path,
        ) ?? $path;

        return Str::snake($path, ' ');
    }

    /**
     * A number inside its row of AdCompiler::LIMITS.
     *
     * @return array<int, string>
     */
    private function limit(string $key, bool $required = false): array
    {
        [$min, $max] = AdCompiler::LIMITS[$key];

        return [$required ? 'required' : 'nullable', 'numeric', "between:{$min},{$max}"];
    }

    /**
     * A gradient: its kind, its angle, and two to six colour stops — each stop both a colour and a place,
     * so the list keeps the order the person put the stops in.
     *
     * @return array<string, array<int, mixed>>
     */
    private function gradient(string $path): array
    {
        return [
            $path => ['nullable', 'array'],
            "{$path}.kind" => ['nullable', Rule::in(AdCompiler::GRADIENTS)],
            "{$path}.angle" => $this->limit('gradient.angle'),
            "{$path}.stops" => ['nullable', 'list', 'max:'.self::MAX_STOPS],
            "{$path}.stops.*" => ['array'],
            "{$path}.stops.*.color" => ['required', 'string', 'max:64'],
            "{$path}.stops.*.at" => ['required', ...array_slice($this->limit('gradient.at'), 1)],
        ];
    }

    /**
     * One animation slot — its effect from the runtime's list, its numbers from AdAnimations::NUMBERS,
     * and an ease that is a name or a curve.
     *
     * @return array<string, array<int, mixed>>
     */
    private function slot(string $path, string $slot): array
    {
        $rules = [
            $path => ['nullable', 'array'],
            "{$path}.effect" => ['nullable', Rule::in($slot === 'loop' ? AdAnimations::LOOPS : AdAnimations::ENTRANCES)],
            "{$path}.ease" => ['bail', 'nullable', 'string', 'max:64', $this->ease()],
        ];

        if ($slot === 'loop') {
            $rules["{$path}.axis"] = ['nullable', Rule::in(AdAnimations::AXES)];
            $rules["{$path}.yoyo"] = ['nullable', 'boolean'];
        } else {
            $rules["{$path}.direction"] = ['nullable', Rule::in(AdAnimations::DIRECTIONS)];
        }

        foreach (AdAnimations::NUMBERS[$slot] as $key => [$min, $max]) {
            $rules["{$path}.{$key}"] = ['nullable', 'numeric', "between:{$min},{$max}"];
        }

        return $rules;
    }

    /** An ease the runtime knows by name, or a curve from the Ease Visualizer's handles. */
    private function ease(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! AdAnimations::isEase($value)) {
                $fail('The :attribute is not an ease the editor offers.');
            }
        };
    }
}
