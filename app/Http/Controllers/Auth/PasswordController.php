<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    use ConfirmsPassword;

    /**
     * Update the user's password. The current one is checked last, through the same wrong-password
     * limit as the big deletes, so an open session cannot be used to guess it here either.
     *
     * Whoever held the old password is signed out everywhere else, as a reset does (User::endEverySession): every other
     * session of the account ends, and the "remember me" token changes, so a remembered sign-in on another device opens
     * nothing either. The browser that changed it stays signed in, and keeps its own "remember me".
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $this->confirmPassword($request, 'updatePassword', 'current_password');

        $user = $request->user();
        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'remember_token' => Str::random(60),
        ])->save();

        // Laravel's own "log out other devices": it writes this browser's remember-me cookie again with the new token, when
        // it had one (the password it re-hashes is the one just set).
        Auth::logoutOtherDevices($validated['password']);
        $user->endEverySession(except: $request->session()->getId());

        ActivityLog::record('password.changed', $user, 'Changed their own password, signing out every other device');

        return back()->with('status', 'password-updated');
    }
}
