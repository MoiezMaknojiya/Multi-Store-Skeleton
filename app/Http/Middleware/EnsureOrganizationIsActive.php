<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An organization the platform has paused (its Active switch off; owner, 2026-09-30) is closed to its own people: a page of
 * the panel sends them to the dashboard, which says why, and a request a page makes is refused with the same words.
 * Going to another of their organizations, leaving this one and answering an invitation to another stay open. The platform
 * works above the organizations and is never stopped here, so it can still look after the organization and turn it back on;
 * and the organization's screens keep playing, since the device API is not behind this. The role they hold there grants nothing meanwhile (User::contextPermissionNames),
 * so no page offers what this would refuse.
 */
class EnsureOrganizationIsActive
{
    /**
     * What stays open in a paused organization: switching to another organization, leaving this one, and answering on the
     * dashboard an invitation to another.
     */
    private const OPEN = ['organization.switch', 'members.leave', 'dashboard.invitations.accept', 'dashboard.invitations.decline'];

    public function handle(Request $request, Closure $next): Response
    {
        $organization = $request->user()?->pausedOrganization();

        if ($organization === null || $request->routeIs(...self::OPEN)) {
            return $next($request);
        }

        // `paused` tells a page left open (core/bootstrap.js) to go to the dashboard, which says why.
        if ($request->expectsJson()) {
            return response()->json(['message' => self::message($organization->name), 'paused' => true], 403);
        }

        return redirect()->route('dashboard');
    }

    /** The words a paused organization's people are told, on the dashboard and in every refusal. */
    public static function message(string $organization): string
    {
        return "{$organization} is paused. Contact support to turn it back on.";
    }
}
