<?php

namespace App\Http\Controllers\Advertising;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Screen;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Whether a shop and its televisions carry network advertising.
 *
 * This is the platform owner's setting, agreed in the deal — not something a
 * shopkeeper turns on one morning. Both flags start OFF: a shop that was never asked
 * has never agreed, and a television nobody chose is not a billboard.
 *
 * Two ways in, and they are gated differently on purpose:
 *
 * - INSIDE a shop (`store`, `screens`) — behind `network-ads-toggle`, true only in an
 *   IMPERSONATED session belonging to a live super admin. A super admin cannot enter a
 *   store directly in this app, so "Log in as" is the only way in, and the control sits
 *   exactly where they can reach it and nowhere a store user can.
 * - ACROSS shops (`stores`) — behind `campaign-manage`, the plain super-admin ability.
 *   The stores listing is the platform owner's own page, reached without impersonating
 *   anybody, so demanding an impersonated session there would shut the door on the one
 *   person it is meant for.
 *
 * Either way the answer to "may a shopkeeper touch this?" is no.
 */
class NetworkAdsController extends Controller
{
    /** Does this shop carry advertising at all? */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['accepts' => ['required', 'boolean']]);

        $store = $this->currentStore();
        $store->update(['accepts_network_ads' => $validated['accepts']]);

        ActivityLog::record(
            'store.network_ads_updated',
            $store,
            ($validated['accepts'] ? 'Enabled' : 'Disabled')." network advertising for store {$store->name}"
        );

        return response()->json([
            'message' => $validated['accepts']
                ? 'This store now carries network advertising.'
                : 'Network advertising is off for this store.',
            'accepts_network_ads' => $store->accepts_network_ads,
        ]);
    }

    /**
     * And which of its televisions do.
     *
     * Takes a list rather than one id, so "all the screens in this shop" and "just
     * this one" are the same call — the bulk switch is not a second endpoint with a
     * second set of rules to keep in step.
     */
    public function screens(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'screen_ids' => ['required', 'array', 'min:1'],
            'screen_ids.*' => ['integer', 'min:1'],
            'accepts' => ['required', 'boolean'],
        ]);

        $store = $this->currentStore();

        // Scoped to the store being worked in, so a stray id from another shop
        // changes nothing rather than reaching across the wall.
        $screens = Screen::where('store_id', $store->id)
            ->whereIn('id', $validated['screen_ids'])
            ->get();

        if ($screens->isEmpty()) {
            throw ValidationException::withMessages([
                'screen_ids' => 'None of those screens are in this store.',
            ]);
        }

        Screen::whereIn('id', $screens->pluck('id'))
            ->update(['accepts_network_ads' => $validated['accepts']]);

        ActivityLog::record(
            'screen.network_ads_updated',
            $store,
            ($validated['accepts'] ? 'Enabled' : 'Disabled').' network advertising on '
                .$screens->count().' screen'.($screens->count() === 1 ? '' : 's')
                .' in store '.$store->name
        );

        return response()->json([
            'message' => 'Updated '.$screens->count().' screen'.($screens->count() === 1 ? '' : 's'),
            'screen_ids' => $screens->pluck('id')->all(),
            'accepts' => $validated['accepts'],
        ]);
    }

    /**
     * Whole shops at once, from the stores listing — the way a deal is actually struck.
     *
     * The consent flags are ANDed at play time (shop AND television), so switching a
     * shop on alone would change nothing on any screen: every television starts off.
     * A bulk switch that quietly does nothing is worse than no bulk switch, so this
     * sets BOTH — the shop and every television in it — and the panel says so before
     * it is pressed. Afterwards a single set-apart screen is a trip inside; that is
     * the exception, and exceptions are worth a click.
     */
    public function stores(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_ids' => ['required', 'array', 'min:1'],
            'store_ids.*' => ['integer', 'min:1'],
            'accepts' => ['required', 'boolean'],
        ]);

        $stores = Store::whereIn('id', $validated['store_ids'])->get();

        if ($stores->isEmpty()) {
            throw ValidationException::withMessages([
                'store_ids' => 'None of those stores exist.',
            ]);
        }

        $accepts = $validated['accepts'];
        $ids = $stores->pluck('id');
        $screenCount = 0;

        // One transaction: a shop marked as carrying advertising whose televisions were
        // never switched is exactly the silent half-state this endpoint exists to avoid.
        DB::transaction(function () use ($ids, $accepts, &$screenCount) {
            Store::whereIn('id', $ids)->update(['accepts_network_ads' => $accepts]);
            $screenCount = Screen::whereIn('store_id', $ids)
                ->update(['accepts_network_ads' => $accepts]);
        });

        $shops = $stores->count().' shop'.($stores->count() === 1 ? '' : 's');
        $screens = $screenCount.' screen'.($screenCount === 1 ? '' : 's');

        ActivityLog::record(
            'store.network_ads_updated',
            $stores->count() === 1 ? $stores->first() : null,
            ($accepts ? 'Enabled' : 'Disabled')." network advertising for {$shops} ({$screens})"
        );

        return response()->json([
            'message' => ($accepts ? 'Advertising is on for ' : 'Advertising is off for ')."{$shops} — {$screens} updated.",
            'store_ids' => $ids->all(),
            'accepts' => $accepts,
        ]);
    }

    /**
     * The shop being worked in. "Log in as" starts with none chosen (ImpersonateController clears it, and the
     * dashboard then picks the person's only store or asks which), so a session with no store is told so.
     */
    private function currentStore(): Store
    {
        $storeId = (int) session('current_store_id');

        if (! $storeId) {
            throw ValidationException::withMessages([
                'accepts' => 'Select a store first — advertising is agreed per shop.',
            ]);
        }

        return Store::findOrFail($storeId);
    }
}
