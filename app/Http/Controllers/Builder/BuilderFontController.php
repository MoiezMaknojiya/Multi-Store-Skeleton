<?php

namespace App\Http\Controllers\Builder;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BuilderFont;
use App\Services\GoogleFontInstaller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The fonts the editor may set text in (docs/AD-BUILDER-SPEC.md §7a).
 *
 * Two answers: what is on offer, and "install this one". The list is `config/fonts.php` — ours, curated,
 * and the only thing that can be installed — so a font name typed by hand can never make the server
 * fetch an arbitrary URL. A font is not store content: once installed it belongs to the installation,
 * like a colour, and every shop may use it.
 */
class BuilderFontController extends Controller
{
    /**
     * The picker's whole list: the system faces, then ours, each saying whether it is ready to use. For
     * whoever may look at the ads or open the editor (Create Ads, Update Ads) — the editor needs the list
     * whether or not its designer also holds View Ads.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->canAny(['ad-view', 'ad-store', 'ad-update']), 403);

        $installed = BuilderFont::orderBy('family')->get()->keyBy('family');

        $system = collect(config('fonts.system', []))->map(fn (array $row) => [
            'family' => $row['name'],
            'kind' => 'system',
            'installed' => true,        // every device already has something close enough
            'weights' => [400, 700],
            'css_url' => null,
        ]);

        $google = collect(config('fonts.families', []))->map(function (array $row) use ($installed) {
            $font = $installed->get($row['name']);

            return [
                'family' => $row['name'],
                'kind' => $row['kind'] ?? 'sans',
                'installed' => $font !== null,
                'weights' => $font?->availableWeights() ?? array_map('intval', $row['weights'] ?? [400]),
                'css_url' => $font?->css_url,
            ];
        });

        return response()->json(['fonts' => $system->concat($google)->values()]);
    }

    /**
     * Install a family: fetch it from Google once, keep it here for good.
     *
     * Idempotent on purpose — two designers picking the same font at the same moment both get the one
     * that was installed, and the second pays nothing for it.
     *
     * Whoever may design may install: the picker sits in the editor, which a person opens to make an ad
     * (`ad-store`) or to change one (`ad-update`), and either needs the fonts — asked here, because a
     * route's `can:` names one permission only.
     */
    public function store(Request $request, GoogleFontInstaller $installer): JsonResponse
    {
        abort_unless($request->user()->canAny(['ad-store', 'ad-update']), 403);

        $validated = $request->validate([
            'family' => ['bail', 'required', 'string', 'max:120'],
        ]);

        $existed = BuilderFont::where('family', $validated['family'])->exists();
        $font = $installer->install($validated['family'], auth()->id());

        if (! $existed) {
            ActivityLog::record('ad_font.installed', null, "Installed the font {$font->family} for the ad builder");
        }

        return response()->json([
            'message' => $existed ? "{$font->family} is ready" : "{$font->family} installed",
            'font' => [
                'family' => $font->family,
                'kind' => $font->kind,
                'installed' => true,
                'weights' => $font->availableWeights(),
                'css_url' => $font->css_url,
            ],
        ]);
    }
}
