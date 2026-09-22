<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    /**
     * Display the password reset view: the token from the link, and the email address it carries.
     *
     * Only plain strings reach the view. A query parameter can be any shape — ?email[]=x arrives as an
     * array — and an array echoed into the form was a 500 on a page every guest can open.
     */
    public function create(Request $request, string $token): View
    {
        $email = $request->query('email');

        return view('auth.reset-password', [
            'token' => $token,
            'email' => is_string($email) ? $email : '',
        ]);
    }

    /**
     * Handle an incoming new password request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        // `string` on the token: token[]=x otherwise passed `required` and reached the hasher's
        // password_verify(), which throws on an array — a 500 anybody could cause by asking for a
        // link for an address and then posting an array for it.
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request): void {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                ])->save();

                // The user is not authenticated here, so pass them explicitly as the actor.
                ActivityLog::record('password.reset', $user, 'Reset their password via email link', $user);

                event(new PasswordReset($user));
            }
        );

        // If the password was reset, the person is sent to the sign-in page to sign in with it — the
        // link never signs anybody in. If there is an error we redirect them back to where they came
        // from with their error message.
        return $status == Password::PASSWORD_RESET
                    ? redirect()->route('login')->with('status', __($status))
                    : back()->withInput($request->only('email'))
                        ->withErrors(['email' => __($status)]);
    }
}
