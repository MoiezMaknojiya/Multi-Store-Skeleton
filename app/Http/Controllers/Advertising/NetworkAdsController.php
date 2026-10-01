<?php

namespace App\Http\Controllers\Advertising;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\Screen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Whether an organization and its televisions carry network advertising.
 *
 * This is the platform owner's setting, agreed in the deal — not something an
 * organization member turns on one morning. Both flags start OFF: an organization that was never asked
 * has never agreed, and a television nobody chose is not a billboard.
 *
 * Two ways in, and they are gated differently on purpose:
 *
 * - INSIDE an organization (`organization`, `screens`) — behind `network-ads-toggle`, true only in an
 *   IMPERSONATED session belonging to a live super admin. A super admin cannot enter an
 *   organization directly in this app, so "Log in as" is the only way in, and the control sits
 *   exactly where they can reach it and nowhere an organization user can.
 * - ACROSS organizations (`organizations`) — behind `campaign-manage`, the plain super-admin ability.
 *   The organizations listing is the platform owner's own page, reached without impersonating
 *   anybody, so demanding an impersonated session there would shut the door on the one
 *   person it is meant for.
 *
 * Either way the answer to "may an organization member touch this?" is no.
 */
class NetworkAdsController extends Controller
{
    /** Does this organization carry advertising at all? */
    public function organization(Request $request): JsonResponse
    {
        $validated = $request->validate(['accepts' => ['required', 'boolean']]);

        $organization = $this->currentOrganization();
        $organization->update(['accepts_network_ads' => $validated['accepts']]);

        ActivityLog::record(
            'organization.network_ads_updated',
            $organization,
            ($validated['accepts'] ? 'Enabled' : 'Disabled')." network advertising for organization {$organization->name}"
        );

        return response()->json([
            'message' => $validated['accepts']
                ? 'This organization now carries network advertising.'
                : 'Network advertising is off for this organization.',
            'accepts_network_ads' => $organization->accepts_network_ads,
        ]);
    }

    /**
     * And which of its televisions do.
     *
     * Takes a list rather than one id, so "all the screens in this organization" and "just
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

        $organization = $this->currentOrganization();

        // Scoped to the organization being worked in, so a stray id from another organization
        // changes nothing rather than reaching across the wall.
        $screens = Screen::where('organization_id', $organization->id)
            ->whereIn('id', $validated['screen_ids'])
            ->get();

        if ($screens->isEmpty()) {
            throw ValidationException::withMessages([
                'screen_ids' => 'None of those screens are in this organization.',
            ]);
        }

        Screen::whereIn('id', $screens->pluck('id'))
            ->update(['accepts_network_ads' => $validated['accepts']]);

        ActivityLog::record(
            'screen.network_ads_updated',
            $organization,
            ($validated['accepts'] ? 'Enabled' : 'Disabled').' network advertising on '
                .$screens->count().' screen'.($screens->count() === 1 ? '' : 's')
                .' in organization '.$organization->name
        );

        return response()->json([
            'message' => 'Updated '.$screens->count().' screen'.($screens->count() === 1 ? '' : 's'),
            'screen_ids' => $screens->pluck('id')->all(),
            'accepts' => $validated['accepts'],
        ]);
    }

    /**
     * Whole organizations at once, from the organizations listing — the way a deal is actually struck.
     *
     * The consent flags are ANDed at play time (organization AND television), so switching an
     * organization on alone would change nothing on any screen: every television starts off.
     * A bulk switch that quietly does nothing is worse than no bulk switch, so this
     * sets BOTH — the organization and every television in it — and the panel says so before
     * it is pressed. Afterwards a single set-apart screen is a trip inside; that is
     * the exception, and exceptions are worth a click.
     */
    public function organizations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_ids' => ['required', 'array', 'min:1'],
            'organization_ids.*' => ['integer', 'min:1'],
            'accepts' => ['required', 'boolean'],
        ]);

        $organizations = Organization::whereIn('id', $validated['organization_ids'])->get();

        if ($organizations->isEmpty()) {
            throw ValidationException::withMessages([
                'organization_ids' => 'None of those organizations exist.',
            ]);
        }

        $accepts = $validated['accepts'];
        $ids = $organizations->pluck('id');
        $screenCount = 0;

        // One transaction: an organization marked as carrying advertising whose televisions were
        // never switched is exactly the silent half-state this endpoint exists to avoid.
        DB::transaction(function () use ($ids, $accepts, &$screenCount) {
            Organization::whereIn('id', $ids)->update(['accepts_network_ads' => $accepts]);
            $screenCount = Screen::whereIn('organization_id', $ids)
                ->update(['accepts_network_ads' => $accepts]);
        });

        $inWords = $organizations->count().' organization'.($organizations->count() === 1 ? '' : 's');
        $screens = $screenCount.' screen'.($screenCount === 1 ? '' : 's');

        ActivityLog::record(
            'organization.network_ads_updated',
            $organizations->count() === 1 ? $organizations->first() : null,
            ($accepts ? 'Enabled' : 'Disabled')." network advertising for {$inWords} ({$screens})"
        );

        return response()->json([
            'message' => ($accepts ? 'Advertising is on for ' : 'Advertising is off for ')."{$inWords} — {$screens} updated.",
            'organization_ids' => $ids->all(),
            'accepts' => $accepts,
        ]);
    }

    /**
     * The organization being worked in. "Log in as" starts with none chosen (ImpersonateController clears it, and the
     * dashboard then picks the person's only organization or asks which), so a session with no organization is told so.
     */
    private function currentOrganization(): Organization
    {
        $organizationId = (int) session('current_organization_id');

        if (! $organizationId) {
            throw ValidationException::withMessages([
                'accepts' => 'Select an organization first — advertising is agreed per organization.',
            ]);
        }

        return Organization::findOrFail($organizationId);
    }
}
