<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonateController extends Controller
{
    /** Log the current Super Admin in as another (non-Super-Admin) user */
    public function start(Request $request, User $user): RedirectResponse
    {
        if (! auth()->user()->isSuperAdmin()) {
            abort(403, 'Only Super Admins can impersonate.');
        }

        if ($user->isSuperAdmin()) {
            abort(403, 'Cannot impersonate another Super Admin.');
        }

        ActivityLog::record('impersonate.started', $user,
            'Logged in as '.$user->name.' ('.$user->email.')');

        session(['impersonating_original_id' => auth()->id()]);
        session()->forget('current_store_id');
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    /** Return from an impersonated session to the original Super Admin */
    public function stop(Request $request): RedirectResponse
    {
        $originalId = session('impersonating_original_id');
        if (! $originalId) {
            return redirect()->route('dashboard');
        }

        $admin = User::find($originalId);
        if (! $admin || ! $admin->isSuperAdmin()) {
            session()->forget('impersonating_original_id');
            Auth::logout();
            $request->session()->invalidate();

            return redirect()->route('login');
        }

        // Attribute the stop to the returning Super Admin (not the impersonated
        // user we are still authenticated as) so the audit trail names who acted.
        ActivityLog::record('impersonate.stopped', null,
            'Returned to '.$admin->name.' from impersonating '.auth()->user()->name, $admin);

        session()->forget(['impersonating_original_id', 'current_store_id']);
        Auth::login($admin);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
