<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonateController extends Controller
{
    /** The session keys of an impersonation: who to return to, and whom they are viewing as. */
    public const SESSION_KEYS = ['impersonating_original_id', 'impersonating_user_id'];

    /** Log the current Super Admin in as another (non-Super-Admin) user */
    public function start(Request $request, User $user): RedirectResponse
    {
        $admin = auth()->user();

        if (! $admin->isSuperAdmin()) {
            abort(403, 'Only Super Admins can impersonate.');
        }

        if ($user->isSuperAdmin()) {
            abort(403, 'Cannot impersonate another Super Admin.');
        }

        ActivityLog::record('impersonate.started', $user,
            'Logged in as '.$user->name.' ('.$user->email.')');

        session()->forget('current_store_id');
        Auth::login($user);
        $request->session()->regenerate();

        // Written AFTER the login: every sign-in clears these keys (AppServiceProvider), so a
        // session that outlives the impersonated account never hands its way back to whoever
        // signs in on that browser next.
        session([
            'impersonating_original_id' => $admin->id,
            'impersonating_user_id' => $user->id,
        ]);

        return redirect()->route('dashboard');
    }

    /** Return from an impersonated session to the original Super Admin */
    public function stop(Request $request): RedirectResponse
    {
        $originalId = session('impersonating_original_id');
        if (! $originalId) {
            return redirect()->route('dashboard');
        }

        // The way back belongs to the account being viewed as, and to nobody else.
        if ((int) session('impersonating_user_id') !== auth()->id()) {
            session()->forget(self::SESSION_KEYS);

            return redirect()->route('dashboard');
        }

        $admin = User::find($originalId);
        if (! $admin || ! $admin->isSuperAdmin()) {
            session()->forget(self::SESSION_KEYS);
            Auth::logout();
            $request->session()->invalidate();

            return redirect()->route('login');
        }

        // Attribute the stop to the returning Super Admin (not the impersonated
        // user we are still authenticated as) so the audit trail names who acted.
        ActivityLog::record('impersonate.stopped', null,
            'Returned to '.$admin->name.' from impersonating '.auth()->user()->name, $admin);

        session()->forget([...self::SESSION_KEYS, 'current_store_id']);
        Auth::login($admin);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
