<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A person with one organization works in it from the first request (QA round, 2026-10-09). The smart default used to be the
 * dashboard's alone, so somebody who signed in from a link (an email, a bookmark) and landed straight on a screen or a file
 * was told "Your role does not allow it": no organization was chosen yet, so their role there granted nothing. The same was
 * said to somebody taken out of the organization their session was in. The platform team works above the organizations and is
 * never put in one; somebody with several organizations chooses (bootstrap/app.php sends a refused page to the chooser, and the
 * choice returns there).
 */
class ChooseTheOnlyOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->globalRole() !== null) {
            return $next($request);
        }

        $mine = $user->organizations()->pluck('organizations.id')->map(fn (int|string $id): int => (int) $id);
        $current = (int) $request->session()->get('current_organization_id');

        // An organization the person is no longer in (taken out mid-session) is forgotten, so they are not left with a role that
        // grants nothing.
        if ($current !== 0 && ! $mine->contains($current)) {
            $request->session()->forget('current_organization_id');
            $current = 0;
        }

        if ($current === 0 && $mine->count() === 1) {
            $request->session()->put('current_organization_id', $mine->first());
        }

        return $next($request);
    }
}
