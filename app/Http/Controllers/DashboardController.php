<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Role;
use App\Services\DashboardSummary;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Landing page after login. Three audiences:
     *  - Global users (Super-Admin / custom global role): the platform's summary;
     *    they span every organization and never pick one.
     *  - Organization users with an organization in context: that ONE organization's summary — what it has,
     *    what needs doing and its first steps (DashboardSummary).
     *  - Organization users with no organization chosen yet: smart default — a single organization is
     *    auto-selected, several organizations send them to the selection page.
     * An organization the platform has paused shows why instead (EnsureOrganizationIsActive sends every page of it here).
     */
    public function index(DashboardSummary $summary): View|RedirectResponse
    {
        $user = auth()->user();

        if ($user->globalRole() !== null) {
            return view('dashboard.index', [
                'view' => 'global',
                'summary' => $summary->forPlatform($user),
            ]);
        }

        $organizations = $user->organizations()->get();

        if ($organizations->isEmpty()) {
            return view('dashboard.index', ['view' => 'empty']);
        }

        // Smart default: auto-select a single organization; several organizations need a pick. A flash that came
        // here with a redirect ("Invitation declined.", a deleted organization's goodbye) is kept for one
        // more request, so the picker shows it instead of it vanishing on the way through.
        $organization = $this->resolveCurrentOrganization($organizations);

        if ($organization === null) {
            session()->reflash();

            return redirect()->route('organizations.select');
        }

        if (! $organization->is_active) {
            return view('dashboard.index', [
                'view' => 'paused',
                'organization' => $organization,
                'hasOtherOrganizations' => $organizations->count() > 1,
            ]);
        }

        return view('dashboard.index', [
            'view' => 'organization',
            'summary' => $summary->forOrganization($organization, $user),
        ]);
    }

    /**
     * Organization selection page — every organization the user belongs to, as cards. Global
     * users have no organization to pick (their context is always global) and organization
     * users with a single organization never need this, so both are sent to the dashboard.
     */
    public function selectOrganization(): View|RedirectResponse
    {
        $user = auth()->user();

        if ($user->globalRole() !== null) {
            return redirect()->route('dashboard');
        }

        $organizations = $user->organizations()->get();

        if ($organizations->count() <= 1) {
            return redirect()->route('dashboard');
        }

        $roleNames = Role::whereIn('id', $organizations->pluck('pivot.role_id')->filter())->pluck('name', 'id');

        $myOrganizations = $organizations->map(fn (Organization $organization) => [
            'id' => $organization->id,
            'name' => $organization->name,
            'city' => $organization->city,
            'state' => $organization->state,
            'role' => $roleNames[$organization->pivot->role_id] ?? 'No role',
            'is_active' => $organization->is_active,
        ]);

        return view('dashboard.select-organization', ['myOrganizations' => $myOrganizations]);
    }

    /**
     * Resolve the organization in session context, applying the smart default: a single
     * organization is auto-selected (and remembered) so the user never has to pick.
     * Returns null only when several organizations exist and none is chosen yet.
     *
     * @param  Collection<int, Organization>  $organizations
     */
    private function resolveCurrentOrganization(Collection $organizations): ?Organization
    {
        $currentId = session('current_organization_id');

        if ($currentId) {
            $match = $organizations->firstWhere('id', (int) $currentId);
            if ($match !== null) {
                return $match;
            }
        }

        if ($organizations->count() === 1) {
            $only = $organizations->first();
            session(['current_organization_id' => $only->id]);

            return $only;
        }

        return null;
    }
}
