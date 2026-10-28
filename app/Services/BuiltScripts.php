<?php

namespace App\Services;

use Illuminate\Support\Facades\Vite;

/**
 * The built files a script loaded on demand needs: its own chunk and every chunk it imports, by the build's manifest.
 *
 * A page names them for whoever keeps its files: the player's worker keeps every built file the page names, and
 * nothing else (public/player-sw.js, keepShell), so a chunk the player imports only when it needs it — the Fire TV's
 * own video decoder, docs/TV-ORIENTATION-SPEC.md §12 — was never kept, and a set that started with no line had none.
 */
class BuiltScripts
{
    /** @var array<string, mixed>|null */
    private static ?array $manifest = null;

    /**
     * The addresses of the chunk built from this source and of every chunk it imports, its own first.
     *
     * @return list<string>
     */
    public static function withImports(string $source): array
    {
        if (Vite::isRunningHot()) {
            return [Vite::asset($source)];
        }

        $manifest = self::$manifest ??= (array) json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
        $keys = [];
        $walk = function (string $key) use (&$walk, &$keys, $manifest): void {
            if (in_array($key, $keys, true) || ! isset($manifest[$key])) {
                return;
            }

            $keys[] = $key;
            foreach ($manifest[$key]['imports'] ?? [] as $import) {
                $walk((string) $import);
            }
        };
        $walk($source);

        return array_map(fn (string $key): string => Vite::asset($key), $keys);
    }
}
