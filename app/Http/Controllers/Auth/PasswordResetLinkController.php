<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // Send the link if there is an account for this address — and say the same thing either way
        // (owner's decision, 2026-09-17). Laravel's own answer named the outcome: "We can't find a
        // user with that email address" told anybody who asked which addresses have an account here,
        // and "please wait before retrying" said it just as loudly, because only a real account is
        // throttled. Somebody who mistyped their own address now gets the neutral line and no email,
        // which is the trade this buys. The throttle on the route (`password-reset`) is what stops a
        // list being walked; this stops it being read.
        Password::sendResetLink($request->only('email'));

        // Said honestly for both (a link comes only if there is an account), with where it may land, and the
        // address kept in its field so a typo can be seen and put right.
        return back()->withInput($request->only('email'))
            ->with('status', 'If an account uses that address, a link to choose a new password is on its way. It can take a minute — look in spam too.');
    }
}
