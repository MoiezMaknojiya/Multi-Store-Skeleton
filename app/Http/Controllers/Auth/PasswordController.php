<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    use ConfirmsPassword;

    /**
     * Update the user's password. The current one is checked last, through the same wrong-password
     * limit as the big deletes, so an open session cannot be used to guess it here either.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $this->confirmPassword($request, 'updatePassword', 'current_password');

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        ActivityLog::record('password.changed', $request->user(), 'Changed their own password');

        return back()->with('status', 'password-updated');
    }
}
