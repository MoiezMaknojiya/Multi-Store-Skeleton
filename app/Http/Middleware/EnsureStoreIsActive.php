<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A store the platform has paused (its Active switch off; owner, 2026-09-30) is closed to its own people: a page of
 * the panel sends them to the dashboard, which says why, and a request a page makes is refused with the same words.
 * Going to another of their stores and leaving this one stay open. The platform works above the stores and is never
 * stopped here, so it can still look after the store and turn it back on; and the store's screens keep playing, since
 * the device API is not behind this. The role they hold there grants nothing meanwhile (User::contextPermissionNames),
 * so no page offers what this would refuse.
 */
class EnsureStoreIsActive
{
    /** What stays open in a paused store: switching to another store, and leaving this one. */
    private const OPEN = ['store.switch', 'members.leave'];

    public function handle(Request $request, Closure $next): Response
    {
        $store = $request->user()?->pausedStore();

        if ($store === null || $request->routeIs(...self::OPEN)) {
            return $next($request);
        }

        // `paused` tells a page left open (core/bootstrap.js) to go to the dashboard, which says why.
        if ($request->expectsJson()) {
            return response()->json(['message' => self::message($store->name), 'paused' => true], 403);
        }

        return redirect()->route('dashboard');
    }

    /** The words a paused store's people are told, on the dashboard and in every refusal. */
    public static function message(string $store): string
    {
        return "{$store} is paused. Contact support to turn it back on.";
    }
}
