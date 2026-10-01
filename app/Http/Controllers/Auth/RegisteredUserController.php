<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /** Public sign-up page: the new owner's details and their organization, together. */
    public function create(): View
    {
        return view('auth.register', [
            'states' => Organization::US_STATES,
            'signupOpen' => Role::where('key', Role::OWNER)->exists(),
        ]);
    }

    /**
     * Handle the sign-up: one transaction creates the account, the organization, and the person's
     * membership of it as its Owner (docs/ORGANIZATION-SPEC.md rule 17). The role is
     * never taken from the request.
     */
    public function store(Request $request): RedirectResponse
    {
        // A robot, not a person: nothing is written and no email goes out. Said like any form to send again, so a
        // person the trap ever caught by mistake simply sends it again, and a robot learns nothing.
        if ($this->looksLikeARobot($request)) {
            return back()
                ->withInput($request->except(['password', 'password_confirmation', 'website', 'form_started']))
                ->withErrors(['form' => 'Please try again.']);
        }

        $role = Role::where('key', Role::OWNER)->first();

        if (! $role) {
            return back()->withInput()->withErrors([
                'email' => 'Registration is not available right now. Please contact the administrator.',
            ]);
        }

        // One address is one account whatever its capitals: kept lowercased, the way invitations and
        // the profile keep it, so "Sana@" and "sana@" can never become two people on any database.
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower($request->input('email'))]);
        }

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone' => 'required|numeric|digits:10',
            // max:255 is the column's size: an address with a 300-character local part is still a valid
            // email, and it failed only at the INSERT, as a 500. `bail`, so an address already refused
            // never reaches the unique query.
            'email' => ['bail', 'required', 'email', 'max:255', 'regex:/^\S+$/', 'unique:users'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'organization_name' => 'required|string|max:255',
            'street' => 'required|string|max:255',
            'suite' => 'nullable|string|max:100',
            'city' => 'required|string|max:100',
            'state' => ['required', 'string', 'size:2', Rule::in(array_keys(Organization::US_STATES))],
            'zip_code' => ['required', 'string', 'max:10', 'regex:/^[0-9]+$/'],
        ], [
            'email.regex' => 'Email cannot contain spaces.',
            'zip_code.regex' => 'Zip code can only contain numbers.',
        ]);

        [$user, $organization] = DB::transaction(function () use ($validated, $role): array {
            $user = User::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'phone' => $validated['phone'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            $organization = Organization::create([
                'name' => $validated['organization_name'],
                'street' => $validated['street'],
                'suite' => $validated['suite'] ?? null,
                'city' => $validated['city'],
                'state' => $validated['state'],
                'zip_code' => $validated['zip_code'],
                // Signup doesn't ask for a country — everything is US-based.
                'country' => 'USA',
                'is_active' => true,
                'created_by' => $user->id,
            ]);

            $user->organizations()->attach($organization->id, ['role_id' => $role->id]);

            return [$user, $organization];
        });

        Auth::login($user);
        $request->session()->regenerate();

        ActivityLog::record('user.registered', $user,
            "Self-registered: {$user->name} ({$user->email}) with organization {$organization->name}", organizationId: $organization->id);

        // The address has to be confirmed before anything that uses the server's space (owner's rule, 2026-09-29):
        // the link goes out now, and "Check your inbox" says where. A link that could not go out — a mail server
        // that refuses, a visitor over the budget — is said there too: the account stands, and the page sends the
        // link again.
        $problem = $user->sendVerificationLink();

        return $problem === null
            ? redirect()->route('verification.notice')
            : redirect()->route('verification.notice')->with('error', $problem);
    }

    /**
     * The form's two traps (components/auth/robot-trap.blade.php; owner, 2026-09-30: made-up names signed up all
     * day, each sending a confirmation email to somebody's real address): the field no person sees is filled in,
     * or — while a least time is set — the form came back sooner than a person can fill it in, too late to be the
     * page just opened, or without the sealed moment it was opened (a robot posting straight to the address).
     */
    private function looksLikeARobot(Request $request): bool
    {
        if (filled($request->input('website'))) {
            return true;
        }

        $least = (int) config('signage.signup_min_seconds');

        if ($least <= 0) {
            return false;
        }

        $sealed = $request->input('form_started');

        if (! is_string($sealed) || $sealed === '') {
            return true;
        }

        try {
            $opened = (int) Crypt::decryptString($sealed);
        } catch (DecryptException) {
            return true;
        }

        $waited = now()->getTimestamp() - $opened;

        return $waited < $least || $waited > (int) config('signage.signup_form_lifetime_seconds');
    }
}
