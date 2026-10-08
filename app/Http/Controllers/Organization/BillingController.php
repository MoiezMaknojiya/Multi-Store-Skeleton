<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Services\BillingSummary;
use Illuminate\View\View;

/**
 * Settings → Billing (owner, 2026-10-08 — "organization mein billing profile k ander ho jese sub ki honti ha professional kaam chiya";
 * docs/BILLING-SPEC.md §3): what the organization the person works in uses and what it will cost once billing starts. Nothing is
 * charged yet and nothing is changed here: the Premium Templates and Platform Channels switches are the platform's.
 */
class BillingController extends Controller
{
    use ResolvesCurrentOrganization;

    public function show(BillingSummary $billing): View
    {
        // Inside an organization alone: the platform team reads each organization's billing on the Organizations page.
        abort_if(auth()->user()->globalRole() !== null, 404);

        return view('billing.show', ['billing' => $billing->for($this->currentOrganization())]);
    }
}
