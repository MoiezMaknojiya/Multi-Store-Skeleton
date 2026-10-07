<?php

namespace App\Services;

use App\Models\BuilderFont;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Brings a Google font into the building (docs/AD-BUILDER-SPEC.md §7a, config/fonts.php).
 *
 * Picking a family in the editor runs this once: the stylesheet is fetched from Google, every font file
 * it points at is downloaded, and a stylesheet of our own is written beside them pointing at the local
 * copies. After that the editor loads the font from this server, and every published advert carries the
 * files it needs inside its own page (AdFontEmbedder, which reads this stylesheet) — a television in an
 * organization with no internet still shows the right typeface, which is the whole reason for not simply
 * linking to fonts.googleapis.com.
 *
 * Every weight the family has comes with it: Google is asked for all nine and answers with the ones it really has
 * (a static family's few, a variable family's whole range) — and `fonts:refresh` asks again every week, so a weight
 * Google adds later is added here too (owner, 2026-10-05: "google font k jese jese new weight aye dalte raho").
 *
 * Only families named in `config/fonts.php` can be installed: the list is ours, so nobody can make the
 * server fetch an arbitrary URL by typing a font name.
 */
class GoogleFontInstaller
{
    /** Google serves woff2 only to a browser it recognises; with PHP's own agent it answers with TTF. */
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    /** Every weight a family may have. Asked for all of them, Google answers with the ones the family has. */
    private const WEIGHTS = [100, 200, 300, 400, 500, 600, 700, 800, 900];

    /**
     * A guard, not a use case: a family needing more files than this is not worth a television's disk. Counted in
     * files, not in Google's blocks — a variable family sends a block per weight per subset, all for one file a subset.
     */
    private const MAX_FILES = 60;

    /** And a guard on the stylesheet itself: more blocks than this is not a font's. */
    private const MAX_BLOCKS = 1000;

    /**
     * Install a family, or return the one already installed.
     *
     * @throws ValidationException when the family is not on our list, or Google cannot be reached
     */
    public function install(string $family, ?int $userId = null): BuilderFont
    {
        $entry = $this->catalogueEntry($family);
        $slug = BuilderFont::slugFor($entry['name']);

        $installed = BuilderFont::firstWhere('slug', $slug);

        if ($installed !== null) {
            return $installed;
        }

        $fetched = $this->fetch($entry['name'], $slug, '');

        return BuilderFont::create([
            'family' => $entry['name'],
            'slug' => $slug,
            'kind' => $entry['kind'] ?? 'sans',
            ...$fetched,
            'installed_by' => $userId,
        ]);
    }

    /**
     * Bring an installed family up to what Google has now: when it has a weight this installation has not, the family
     * is fetched again whole, under new names (a page that already loaded the old stylesheet never meets a missing
     * file), the row is pointed at it, and the old files go. A weight Google no longer has stays as it was: a design
     * may be using it. Nothing is downloaded while the weights are the same.
     *
     * @return list<int> the weights added — none when nothing changed
     *
     * @throws ValidationException when Google cannot be reached or sends nothing usable
     */
    public function refresh(BuilderFont $font): array
    {
        $blocks = $this->parse($this->fetchStylesheet($font->family));
        $added = array_values(array_diff($this->weightsOf($blocks), $font->availableWeights()));

        if ($added === []) {
            return [];
        }

        $before = [...($font->files ?? []), $font->css_path];
        $fetched = $this->write($font->slug, $font->family, $blocks, '-'.now()->format('YmdHis'));

        // A working family is never swapped for part of one: a file that did not come keeps the old set as it was.
        if (count($fetched['files']) < min(self::MAX_FILES, count(array_unique(array_column($blocks, 'url'))))) {
            Storage::disk('public')->delete([...$fetched['files'], $fetched['css_path']]);

            throw ValidationException::withMessages([
                'family' => "Not every file of {$font->family} could be downloaded. It stays as it was; the next refresh tries again.",
            ]);
        }

        $font->update($fetched);
        Storage::disk('public')->delete(array_values(array_diff($before, [...$fetched['files'], $fetched['css_path']])));

        return $added;
    }

    /** The family's entry in our own list, or a refusal. */
    private function catalogueEntry(string $family): array
    {
        $entry = collect(config('fonts.families', []))
            ->first(fn (array $row) => strcasecmp($row['name'], trim($family)) === 0);

        if ($entry === null) {
            throw ValidationException::withMessages([
                'family' => 'That font is not on the list this app can install.',
            ]);
        }

        return $entry;
    }

    /**
     * Fetch the family from Google and write it here: its files and our stylesheet, named with $suffix.
     *
     * @return array{weights: list<int>, files: list<string>, css_path: string, size: int}
     */
    private function fetch(string $family, string $slug, string $suffix): array
    {
        return $this->write($slug, $family, $this->parse($this->fetchStylesheet($family)), $suffix);
    }

    /**
     * @param  array<int, array<string, string>>  $blocks
     * @return array{weights: list<int>, files: list<string>, css_path: string, size: int}
     */
    private function write(string $slug, string $family, array $blocks, string $suffix): array
    {
        if ($blocks === []) {
            throw ValidationException::withMessages([
                'family' => "Google sent nothing usable for {$family}. Try again in a moment.",
            ]);
        }

        [$files, $rules, $size, $weights] = $this->download($slug, $blocks, $family, $suffix);

        $cssPath = $this->directory($slug)."/font{$suffix}.css";
        Storage::disk('public')->put($cssPath, implode("\n", $rules)."\n");

        return ['weights' => $weights, 'files' => $files, 'css_path' => $cssPath, 'size' => $size];
    }

    /** Google's own stylesheet for every weight there is: it answers with the ones the family has. */
    private function fetchStylesheet(string $family): string
    {
        $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
            ->timeout((int) config('fonts.timeout', 20))
            ->get('https://fonts.googleapis.com/css2', [
                'family' => $family.':wght@'.implode(';', self::WEIGHTS),
                'display' => 'swap',
            ]);

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'family' => "Could not reach Google Fonts for {$family} (".$response->status().'). The design keeps the name; try installing again later.',
            ]);
        }

        return $response->body();
    }

    /**
     * Every @font-face Google sent, as {weight, style, range, url}.
     *
     * A family arrives as one block per weight PER SUBSET (latin, latin-ext, cyrillic…), each with its own
     * `unicode-range`. All of them are kept: dropping the subsets is how an advert with an accented or a
     * Cyrillic word ends up in a fallback face for exactly the characters it needed.
     *
     * @return array<int, array<string, string>>
     */
    private function parse(string $css): array
    {
        preg_match_all('/@font-face\s*\{([^}]+)\}/', $css, $matches);

        $blocks = [];

        foreach (array_slice($matches[1] ?? [], 0, self::MAX_BLOCKS) as $body) {
            if (preg_match('#src:\s*url\((https://fonts\.gstatic\.com/[^)]+\.woff2)\)#i', $body, $src) !== 1) {
                continue;
            }

            preg_match('/font-weight:\s*([0-9]+)/i', $body, $weight);
            preg_match('/font-style:\s*([a-z]+)/i', $body, $style);
            preg_match('/unicode-range:\s*([^;]+)/i', $body, $range);

            $blocks[] = [
                'url' => $src[1],
                'weight' => (string) ((int) ($weight[1] ?? 400)),
                'style' => strtolower($style[1] ?? 'normal') === 'italic' ? 'italic' : 'normal',
                'range' => trim($range[1] ?? ''),
            ];
        }

        return $blocks;
    }

    /**
     * The weights the blocks give, smallest first.
     *
     * @param  array<int, array<string, string>>  $blocks
     * @return list<int>
     */
    private function weightsOf(array $blocks): array
    {
        $weights = array_values(array_unique(array_map(fn (array $block) => (int) $block['weight'], $blocks)));
        sort($weights);

        return $weights;
    }

    /**
     * Fetch every file — each once, however many weights share it (a variable font's one file a subset) — and write
     * our own stylesheet against the local copies, one rule per block as Google sent them.
     *
     * @param  array<int, array<string, string>>  $blocks
     * @return array{0: list<string>, 1: list<string>, 2: int, 3: list<int>}
     */
    private function download(string $slug, array $blocks, string $family, string $suffix): array
    {
        $directory = $this->directory($slug);
        $local = [];
        $rules = ["/* {$family} — downloaded from Google Fonts and served from here. */"];
        $size = 0;
        $weights = [];

        foreach ($blocks as $index => $block) {
            if (! array_key_exists($block['url'], $local)) {
                $local[$block['url']] = null;

                if (count(array_filter($local)) >= self::MAX_FILES) {
                    continue;
                }

                $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                    ->timeout((int) config('fonts.timeout', 20))
                    ->get($block['url']);

                if (! $response->successful()) {
                    continue;
                }

                $path = "{$directory}/{$block['weight']}-{$block['style']}-{$index}{$suffix}.woff2";
                Storage::disk('public')->put($path, $response->body());
                $local[$block['url']] = $path;
                $size += strlen($response->body());
            }

            $path = $local[$block['url']];

            if ($path === null) {
                continue;
            }

            $weights[(int) $block['weight']] = (int) $block['weight'];
            $rules[] = sprintf(
                "@font-face{font-family:'%s';font-style:%s;font-weight:%s;font-display:swap;src:url('%s') format('woff2');%s}",
                $family,
                $block['style'],
                $block['weight'],
                Storage::disk('public')->url($path),
                $block['range'] === '' ? '' : "unicode-range:{$block['range']};",
            );
        }

        $files = array_values(array_filter($local));

        if ($files === []) {
            throw ValidationException::withMessages([
                'family' => "None of {$family}'s files could be downloaded. Try again later.",
            ]);
        }

        ksort($weights);

        return [$files, $rules, $size, array_values($weights)];
    }

    private function directory(string $slug): string
    {
        return trim((string) config('fonts.directory', 'fonts'), '/')."/{$slug}";
    }
}
