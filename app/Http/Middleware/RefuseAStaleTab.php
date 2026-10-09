<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A page left open in one tab while the session changed in another (owner, 2026-10-09, the QA round). Switching organization,
 * Log In As or Return to Super Admin elsewhere left the page showing one person in one organization while its requests ran as
 * another:
 *  - a rename answered 404;
 *  - the super admin's own profile form was sent as the person being viewed.
 *
 * Every signed-in page says whose it is (`<meta name="session-context">`). The context goes back with each request: axios and
 * the uploader send it as `X-Session-Context`, and a plain form as `_context` (core/form-guard.js). A request from a page of
 * another person or another organization is refused before it does anything: a page's own request gets 409 with the words, and
 * a plain form is sent back to its page, now as it is.
 */
class RefuseAStaleTab
{
    /** What changes the session on purpose, and so is sent from a page of the session it leaves. */
    private const CHANGES_THE_SESSION = ['organization.switch', 'users.impersonate', 'impersonate.stop', 'logout'];

    /** "{user}:{organization}" — who the page was drawn for, and in which organization (0 above the organizations). */
    public static function contextFor(Request $request): string
    {
        return ((int) $request->user()?->id).':'.(int) $request->session()->get('current_organization_id');
    }

    public function handle(Request $request, Closure $next): Response
    {
        $sent = $request->header('X-Session-Context') ?? $request->input('_context');
        $request->request->remove('_context');

        if (! is_string($sent) || $sent === '' || $request->user() === null || $request->routeIs(...self::CHANGES_THE_SESSION)
            || hash_equals(self::contextFor($request), $sent)) {
            return $next($request);
        }

        $message = $this->whatChanged($request, $sent);

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'stale_tab' => true], 409);
        }

        return redirect()->to(url()->previous(route('dashboard')))->with('stale_tab', $message);
    }

    /** Said in the words of what the page no longer is: another person in this browser, or another organization. */
    private function whatChanged(Request $request, string $sent): string
    {
        [$userId] = explode(':', $sent.':');

        if ((int) $userId !== (int) $request->user()->id) {
            return 'This page is out of date: this browser is now signed in as '.$request->user()->name.'. Nothing was changed. The page is reloaded.';
        }

        $organizationId = (int) $request->session()->get('current_organization_id');
        $where = $organizationId > 0 ? Organization::whereKey($organizationId)->value('name') : null;

        return 'This page is out of date: '.($where !== null ? "you switched to {$where}" : 'you left the organization').' in another tab. Nothing was changed. The page is reloaded.';
    }
}
