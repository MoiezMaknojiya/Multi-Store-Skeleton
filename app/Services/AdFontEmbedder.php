<?php

namespace App\Services;

use App\Models\BuilderFont;
use Illuminate\Support\Facades\Storage;

/**
 * The fonts an advert is set in, carried INSIDE its page (docs/AD-BUILDER-SPEC.md §7a).
 *
 * A television plays the page in a sandboxed frame, and the draft preview is sandboxed too: both have an
 * opaque origin. A browser fetches a font with CORS, so from there every font file on our own server
 * counted as another site's and was refused — the advert fell back to a system face on every screen.
 * A `data:` URL is never a CORS request, so the files are embedded instead: nothing to configure on
 * any web server, and nothing to fetch on the day the shop's network is down.
 *
 * Only what the design can show is embedded: for each family, the installed weight a browser would pick
 * for each weight the design asks for (CSS font matching), and of those only the subsets — latin,
 * arabic, devanagari… — whose `unicode-range` covers a character the design writes. A Latin advert set
 * in an Urdu face does not carry the Urdu glyphs.
 */
class AdFontEmbedder
{
    /**
     * `<style>` with an embedded @font-face for every file the texts need, or '' when they need none.
     *
     * @param  array<int, array<string, mixed>>  $elements  the visible elements of the design
     */
    public function styleFor(array $elements): string
    {
        $wanted = $this->wanted($elements);

        if ($wanted === []) {
            return '';
        }

        $faces = [];

        // Matched in PHP, without regard to case, the way a browser matches a family: a design saying
        // "poppins" is set in Poppins. (MySQL's collation would also match it in a WHERE, SQLite's would
        // not — and a lookup keyed on the exact spelling afterwards is how that once became a 500.) There
        // are only ever a few dozen installed families.
        foreach (BuilderFont::query()->orderBy('family')->get() as $font) {
            $key = mb_strtolower(AdCompiler::familyName($font->family));

            if (isset($wanted[$key])) {
                $faces = [...$faces, ...$this->facesFor($font, $wanted[$key])];
            }
        }

        return $faces === [] ? '' : "<style>\n".implode("\n", $faces)."\n</style>";
    }

    /**
     * What each family is asked for — keyed by its name in lower case, as the page's styles write it —:
     * the [weight, style] pairs, and every character written in it.
     *
     * @return array<string, array{looks: array<string, array{0: int, 1: string}>, text: string}>
     */
    private function wanted(array $elements): array
    {
        $wanted = [];

        foreach ($elements as $element) {
            $style = $element['style'] ?? null;

            if (($element['type'] ?? '') !== 'text' || ! is_array($style)) {
                continue;
            }

            $family = mb_strtolower(AdCompiler::familyName($style['fontFamily'] ?? null));

            if ($family === '') {
                continue;
            }

            $weight = max(100, min(900, (int) ($style['fontWeight'] ?? 400)));
            $italic = ($style['fontStyle'] ?? 'normal') === 'italic' ? 'italic' : 'normal';
            $text = is_string($element['text'] ?? null) ? $element['text'] : '';

            $wanted[$family]['looks']["{$weight}-{$italic}"] = [$weight, $italic];
            // Both cases: text-transform may draw either, whatever was typed.
            $wanted[$family]['text'] = ($wanted[$family]['text'] ?? '').$text.mb_strtoupper($text).mb_strtolower($text);
        }

        return $wanted;
    }

    /**
     * The embedded rules for one family.
     *
     * @param  array{looks: array<string, array{0: int, 1: string}>, text: string}  $wanted
     * @return list<string>
     */
    private function facesFor(BuilderFont $font, array $wanted): array
    {
        $disk = Storage::disk('public');
        $rules = $this->rules($font, (string) $disk->get($font->css_path));

        $chosen = [];

        foreach ($wanted['looks'] as [$weight, $style]) {
            // A face in the style asked for if the family has one, otherwise the browser leans the upright one.
            $inStyle = array_filter($rules, fn (array $rule) => $rule['style'] === $style) ?: $rules;
            $face = $this->closestWeight($weight, array_unique(array_column($inStyle, 'weight')));
            $chosen[$face.'-'.($inStyle === $rules ? 'any' : $style)] = [$face, $inStyle];
        }

        $codepoints = $this->codepoints($wanted['text']);
        $files = [];

        foreach ($chosen as [$weight, $candidates]) {
            foreach ($candidates as $rule) {
                if ($rule['weight'] !== $weight || ! $this->covers($rule['ranges'], $codepoints)) {
                    continue;
                }

                $bytes = $disk->get($rule['path']);

                if (! is_string($bytes) || $bytes === '') {
                    continue;
                }

                // A variable font is one file for every weight (Noto Nastaliq Urdu's 233 KB, for one):
                // the same bytes for the same subset are carried once, for the whole range of weights.
                $key = $rule['style'].'|'.$this->rangeText($rule['ranges']).'|'.md5($bytes);
                $files[$key] ??= ['style' => $rule['style'], 'ranges' => $rule['ranges'], 'bytes' => $bytes, 'weights' => []];
                $files[$key]['weights'][$rule['weight']] = $rule['weight'];
            }
        }

        return array_map(fn (array $file) => sprintf(
            "@font-face{font-family:'%s';font-style:%s;font-weight:%s;font-display:block;src:url(data:font/woff2;base64,%s) format('woff2');%s}",
            AdCompiler::familyName($font->family),
            $file['style'],
            min($file['weights']) === max($file['weights']) ? min($file['weights']) : min($file['weights']).' '.max($file['weights']),
            base64_encode($file['bytes']),
            $file['ranges'] === [] ? '' : 'unicode-range:'.$this->rangeText($file['ranges']).';',
        ), array_values($files));
    }

    /**
     * The family's stylesheet (the one GoogleFontInstaller writes: one rule per file, one weight and one
     * subset each), read rule by rule — a missing style or weight means what it means to a browser.
     *
     * @return list<array{style: string, weight: int, path: string, ranges: list<array{0: int, 1: int}>}>
     */
    private function rules(BuilderFont $font, string $stylesheet): array
    {
        preg_match_all('/@font-face\s*\{([^}]*)\}/i', $stylesheet, $blocks);

        // Only a file this family's own row names is ever read — never a path taken from the stylesheet.
        $files = collect($font->files ?? [])->keyBy(fn (string $path) => basename($path));
        $rules = [];

        foreach ($blocks[1] as $body) {
            if (preg_match('/src:\s*url\(\s*[\'"]?([^\'")]+)/i', $body, $source) !== 1) {
                continue;
            }

            $path = $files->get(basename((string) parse_url($source[1], PHP_URL_PATH)));

            if (! is_string($path)) {
                continue;
            }

            preg_match('/font-weight:\s*(\d+)/i', $body, $weight);
            preg_match('/unicode-range:\s*([^;]+)/i', $body, $range);

            $rules[] = [
                'style' => preg_match('/font-style:\s*italic/i', $body) === 1 ? 'italic' : 'normal',
                'weight' => (int) ($weight[1] ?? 400),
                'path' => $path,
                'ranges' => $this->ranges($range[1] ?? ''),
            ];
        }

        return $rules;
    }

    /**
     * The weight a browser draws when a design asks for one the family may not have (CSS Fonts 4, §5.2):
     * between 400 and 500 it looks up to 500, then lighter, then heavier; below 400 lighter first;
     * above 500 heavier first.
     *
     * @param  array<int, int>  $available
     */
    private function closestWeight(int $desired, array $available): int
    {
        sort($available);

        if ($available === [] || in_array($desired, $available, true)) {
            return $desired;
        }

        $lighter = array_values(array_filter($available, fn (int $weight) => $weight < $desired));
        $heavier = array_values(array_filter($available, fn (int $weight) => $weight > $desired));

        if ($desired >= 400 && $desired <= 500) {
            $upTo500 = array_values(array_filter($heavier, fn (int $weight) => $weight <= 500));

            return $upTo500[0] ?? ($lighter !== [] ? end($lighter) : $heavier[0]);
        }

        if ($desired < 400) {
            return $lighter !== [] ? end($lighter) : $heavier[0];
        }

        return $heavier[0] ?? end($lighter);
    }

    /**
     * `U+0000-00FF, U+0131, U+4??` as [start, end] pairs; anything else in it is ignored.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function ranges(string $text): array
    {
        $ranges = [];

        foreach (explode(',', $text) as $token) {
            if (preg_match('/^\s*U\+([0-9A-Fa-f?]{1,6})(?:-([0-9A-Fa-f]{1,6}))?\s*$/', $token, $parts) !== 1) {
                continue;
            }

            $start = hexdec(str_replace('?', '0', $parts[1]));
            $end = isset($parts[2]) ? hexdec($parts[2]) : hexdec(str_replace('?', 'F', $parts[1]));
            $ranges[] = [(int) $start, (int) $end];
        }

        return $ranges;
    }

    /** @param  list<array{0: int, 1: int}>  $ranges */
    private function rangeText(array $ranges): string
    {
        return implode(', ', array_map(
            fn (array $range) => $range[0] === $range[1]
                ? sprintf('U+%04X', $range[0])
                : sprintf('U+%04X-%04X', $range[0], $range[1]),
            $ranges,
        ));
    }

    /**
     * Every character written, as code points — the line breaks and tabs aside.
     *
     * @return array<int, true>
     */
    private function codepoints(string $text): array
    {
        $codepoints = [];

        foreach (mb_str_split($text) as $character) {
            $codepoint = mb_ord($character);

            if ($codepoint !== false && $codepoint >= 0x20) {
                $codepoints[$codepoint] = true;
            }
        }

        return $codepoints;
    }

    /**
     * Whether a subset draws any of the characters. A rule with no range covers everything.
     *
     * @param  list<array{0: int, 1: int}>  $ranges
     * @param  array<int, true>  $codepoints
     */
    private function covers(array $ranges, array $codepoints): bool
    {
        if ($ranges === []) {
            return true;
        }

        foreach (array_keys($codepoints) as $codepoint) {
            foreach ($ranges as [$start, $end]) {
                if ($codepoint >= $start && $codepoint <= $end) {
                    return true;
                }
            }
        }

        return false;
    }
}
