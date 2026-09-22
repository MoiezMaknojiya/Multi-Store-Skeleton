<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\Store;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Landing page after login. Three audiences:
     *  - Global users (Super-Admin / custom global role): the aggregate stats view;
     *    they span every store and never pick one.
     *  - Store users with a store in context: that ONE store's dashboard.
     *  - Store users with no store chosen yet: smart default — a single store is
     *    auto-selected, several stores send them to the selection page.
     */
    public function index(): View|RedirectResponse
    {
        $user = auth()->user();

        if ($user->globalRole() !== null) {
            return view('dashboard.index', [
                'view' => 'global',
                'totalStores' => Store::count(),
            ]);
        }

        $stores = $user->stores()->get();

        if ($stores->isEmpty()) {
            return view('dashboard.index', ['view' => 'empty']);
        }

        // Smart default: auto-select a single store; several stores need a pick. A flash that came
        // here with a redirect ("Invitation declined.", a deleted store's goodbye) is kept for one
        // more request, so the picker shows it instead of it vanishing on the way through.
        if ($this->resolveCurrentStore($stores) === null) {
            session()->reflash();

            return redirect()->route('stores.select');
        }

        // The active store is shown by the header switcher; the dashboard body is a
        // clean slate for future store-specific content.
        return view('dashboard.index', ['view' => 'store']);
    }

    /**
     * Store selection page — every store the user belongs to, as cards. Global
     * users have no store to pick (their context is always global) and store
     * users with a single store never need this, so both are sent to the dashboard.
     */
    public function selectStore(): View|RedirectResponse
    {
        $user = auth()->user();

        if ($user->globalRole() !== null) {
            return redirect()->route('dashboard');
        }

        $stores = $user->stores()->get();

        if ($stores->count() <= 1) {
            return redirect()->route('dashboard');
        }

        $roleNames = Role::whereIn('id', $stores->pluck('pivot.role_id')->filter())->pluck('name', 'id');

        $myStores = $stores->map(fn (Store $store) => [
            'id' => $store->id,
            'name' => $store->name,
            'slug' => $store->slug,
            'city' => $store->city,
            'state' => $store->state,
            'role' => $roleNames[$store->pivot->role_id] ?? 'No role',
            'is_active' => $store->is_active,
        ]);

        return view('dashboard.select-store', ['myStores' => $myStores]);
    }

    /**
     * Resolve the store in session context, applying the smart default: a single
     * store is auto-selected (and remembered) so the user never has to pick.
     * Returns null only when several stores exist and none is chosen yet.
     *
     * @param  Collection<int, Store>  $stores
     */
    private function resolveCurrentStore(Collection $stores): ?Store
    {
        $currentId = session('current_store_id');

        if ($currentId) {
            $match = $stores->firstWhere('id', (int) $currentId);
            if ($match !== null) {
                return $match;
            }
        }

        if ($stores->count() === 1) {
            $only = $stores->first();
            session(['current_store_id' => $only->id]);

            return $only;
        }

        return null;
    }
}
