<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->first_name = $request->first_name;
        $user->last_name = $request->last_name;
        $user->phone = $request->phone;
        $user->email = $request->email;

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $changed = $user->isDirty();
        $user->save();

        if ($changed) {
            ActivityLog::record('profile.updated', $user, 'Updated their own profile');
        }

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        // A super admin's account anchors the whole system and its self-deletion
        // would cascade away every account they created (all self-registered
        // owners). Super admins cannot delete their own account — the UI hides the
        // control and the backend enforces it for any crafted request.
        abort_if($request->user()->isSuperAdmin(), 403, 'Super Admins cannot delete their own account.');

        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        // Logged while still authenticated so the actor snapshot is correct.
        ActivityLog::record('account.deleted', null,
            "Deleted their own account ({$user->email})");

        Auth::logout();

        // Self-deletion follows the same owner rule as any deletion: everything
        // the user created (their user subtree, roles, stores) goes with them.
        DB::transaction(fn () => $user->deleteCascade());

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
