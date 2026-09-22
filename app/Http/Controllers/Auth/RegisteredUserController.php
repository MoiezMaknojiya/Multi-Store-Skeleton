<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /** Public sign-up page: the new owner's details and their store, together. */
    public function create(): View
    {
        return view('auth.register', [
            'states' => Store::US_STATES,
            'signupOpen' => Role::where('key', Role::OWNER)->exists(),
        ]);
    }

    /**
     * Handle the sign-up: one transaction creates the account, the store, and the person's
     * membership of it as its Owner (docs/STORE-ORGANIZATION-SPEC.md rule 17). The role is
     * never taken from the request.
     */
    public function store(Request $request): RedirectResponse
    {
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
            'store_name' => 'required|string|max:255',
            'street' => 'required|string|max:255',
            'suite' => 'nullable|string|max:100',
            'city' => 'required|string|max:100',
            'state' => ['required', 'string', 'size:2', Rule::in(array_keys(Store::US_STATES))],
            'zip_code' => ['required', 'string', 'max:10', 'regex:/^[0-9]+$/'],
        ], [
            'email.regex' => 'Email cannot contain spaces.',
            'zip_code.regex' => 'Zip code can only contain numbers.',
        ]);

        [$user, $store] = DB::transaction(function () use ($validated, $role): array {
            $user = User::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'phone' => $validated['phone'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            $store = Store::create([
                'name' => $validated['store_name'],
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

            $user->stores()->attach($store->id, ['role_id' => $role->id]);

            return [$user, $store];
        });

        Auth::login($user);
        $request->session()->regenerate();

        ActivityLog::record('user.registered', $user,
            "Self-registered: {$user->name} ({$user->email}) with store {$store->name}", storeId: $store->id);

        return redirect()->route('dashboard');
    }
}
