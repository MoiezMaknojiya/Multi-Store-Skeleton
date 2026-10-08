<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Organization;
use App\Services\BillingSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Billing beside Edit on every row of the Organizations page (owner, 2026-10-08 — "har organization ki row per edit k barabar mein";
 * docs/BILLING-SPEC.md §4): the organization's summary, read with View Billing, and its two switches — Premium Templates and Platform
 * Channels — turned with Change Billing, until billing starts and Stripe turns them instead. Above the organizations alone (the routes'
 * global tier): an organization never unlocks itself.
 */
class OrganizationBillingController extends Controller
{
    public function __construct(private readonly BillingSummary $billing) {}

    public function show(Organization $organization): JsonResponse
    {
        return response()->json(['billing' => $this->billing->for($organization)]);
    }

    public function update(Request $request, Organization $organization): JsonResponse
    {
        $validated = $request->validate([
            'premium_templates_unlocked' => ['required', 'boolean'],
            'platform_channels_unlocked' => ['required', 'boolean'],
        ], [
            'premium_templates_unlocked.required' => 'Say whether Premium Templates are unlocked.',
            'premium_templates_unlocked.boolean' => 'Say whether Premium Templates are unlocked.',
            'platform_channels_unlocked.required' => 'Say whether Platform Channels are unlocked.',
            'platform_channels_unlocked.boolean' => 'Say whether Platform Channels are unlocked.',
        ]);

        $organization->forceFill([
            'premium_templates_unlocked' => (bool) $validated['premium_templates_unlocked'],
            'platform_channels_unlocked' => (bool) $validated['platform_channels_unlocked'],
        ])->save();

        $said = collect(['premium_templates_unlocked' => 'Premium Templates', 'platform_channels_unlocked' => 'Platform Channels'])
            ->filter(fn (string $feature, string $column) => $organization->wasChanged($column))
            ->map(fn (string $feature, string $column) => ($organization->{$column} ? 'Unlocked ' : 'Locked ').$feature)
            ->values();

        foreach ($said as $change) {
            ActivityLog::record('organization.billing_updated', $organization, "{$change} for {$organization->name}");
        }

        return response()->json([
            'message' => $said->isEmpty() ? 'Nothing changed.' : $said->implode(' and ').' for '.$organization->name.'.',
            'billing' => $this->billing->for($organization->fresh()),
        ]);
    }
}
