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
 * files it needs inside its own page (AdFontEmbedder, which reads this stylesheet) — a television in a
 * shop with no internet still shows the right typeface, which is the whole reason for not simply
 * linking to fonts.googleapis.com.
 *
 * Only families named in `config/fonts.php` can be installed: the list is ours, so nobody can make the
 * server fetch an arbitrary URL by typing a font name.
 */
class GoogleFontInstaller
{
    /** Google serves woff2 only to a browser it recognises; with PHP's own agent it answers with TTF. */
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    /** A guard, not a use case: a family with more subsets than this is not worth a television's disk. */
    private const MAX_FILES = 60;

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

        $css = $this->fetchStylesheet($entry);
        $blocks = $this->parse($css);

        if ($blocks === []) {
            throw ValidationException::withMessages([
                'family' => "Google sent nothing usable for {$entry['name']}. Try again in a moment.",
            ]);
        }

        [$files, $rules, $size] = $this->download($slug, $blocks, $entry['name']);

        $cssPath = $this->directory($slug).'/font.css';
        Storage::disk('public')->put($cssPath, implode("\n", $rules)."\n");

        return BuilderFont::create([
            'family' => $entry['name'],
            'slug' => $slug,
            'kind' => $entry['kind'] ?? 'sans',
            'weights' => array_values(array_unique(array_map('intval', $entry['weights'] ?? [400]))),
            'files' => $files,
            'css_path' => $cssPath,
            'size' => $size,
            'installed_by' => $userId,
        ]);
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

    /** Google's own stylesheet for the weights we want. */
    private function fetchStylesheet(array $entry): string
    {
        $weights = implode(';', array_map('intval', $entry['weights'] ?? [400]));

        $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
            ->timeout((int) config('fonts.timeout', 20))
            ->get('https://fonts.googleapis.com/css2', [
                'family' => $entry['name'].':wght@'.$weights,
                'display' => 'swap',
            ]);

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'family' => "Could not reach Google Fonts for {$entry['name']} (".$response->status().'). The design keeps the name; try installing again later.',
            ]);
        }

        return $response->body();
    }

    /**
     * Every @font-face Google sent, as {weight, style, range, url}.
     *
     * A family arrives as one block per weight PER SUBSET (latin, latin-ext, arabic…), each with its own
     * `unicode-range`. All of them are kept: dropping the subsets is how an Urdu or Arabic advert ends
     * up in a fallback face for exactly the characters it needed.
     *
     * @return array<int, array<string, string>>
     */
    private function parse(string $css): array
    {
        preg_match_all('/@font-face\s*\{([^}]+)\}/', $css, $matches);

        $blocks = [];

        foreach ($matches[1] ?? [] as $body) {
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

            if (count($blocks) >= self::MAX_FILES) {
                break;
            }
        }

        return $blocks;
    }

    /**
     * Fetch every file and write our own stylesheet against the local copies.
     *
     * @return array{0: array<int, string>, 1: array<int, string>, 2: int}
     */
    private function download(string $slug, array $blocks, string $family): array
    {
        $directory = $this->directory($slug);
        $files = [];
        $rules = ["/* {$family} — downloaded from Google Fonts and served from here. */"];
        $size = 0;

        foreach ($blocks as $index => $block) {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout((int) config('fonts.timeout', 20))
                ->get($block['url']);

            if (! $response->successful()) {
                continue;
            }

            $path = "{$directory}/{$block['weight']}-{$block['style']}-{$index}.woff2";
            Storage::disk('public')->put($path, $response->body());

            $files[] = $path;
            $size += strlen($response->body());

            $rules[] = sprintf(
                "@font-face{font-family:'%s';font-style:%s;font-weight:%s;font-display:swap;src:url('%s') format('woff2');%s}",
                $family,
                $block['style'],
                $block['weight'],
                Storage::disk('public')->url($path),
                $block['range'] === '' ? '' : "unicode-range:{$block['range']};",
            );
        }

        if ($files === []) {
            throw ValidationException::withMessages([
                'family' => "None of {$family}'s files could be downloaded. Try again later.",
            ]);
        }

        return [$files, $rules, $size];
    }

    private function directory(string $slug): string
    {
        return trim((string) config('fonts.directory', 'fonts'), '/')."/{$slug}";
    }
}
