<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
            'signupOpen' => self::signupRole()->exists(),
        ]);
    }

    /**
     * Handle the sign-up: one transaction creates the user, their store, and the
     * assignment. The role is ALWAYS the server-side signup default — never taken
     * from the request, so nobody can sign themselves up into a powerful role.
     */
    public function store(Request $request): RedirectResponse
    {
        $role = self::signupRole()->first();

        if (! $role) {
            return back()->withInput()->withErrors([
                'email' => 'Registration is not available right now. Please contact the administrator.',
            ]);
        }

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone' => 'required|numeric|digits:10',
            'email' => ['required', 'email', 'regex:/^\S+$/', 'unique:users'],
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

        [$user, $store] = DB::transaction(function () use ($validated, $role) {
            $user = User::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'phone' => $validated['phone'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                // Self-registered owners are attributed to the super admin, so they
                // show up in (and are manageable from) the admin's user listing.
                'created_by' => User::firstSuperAdminId(),
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
                // The store is the owner's creation: deleting them cascades it away.
                'created_by' => $user->id,
            ]);

            $user->stores()->attach($store->id, ['role_id' => $role->id]);

            return [$user, $store];
        });

        Auth::login($user);
        $request->session()->regenerate();

        ActivityLog::record('user.registered', $user,
            "Self-registered: {$user->name} ({$user->email}) with store {$store->name}");

        return redirect()->route('dashboard');
    }

    /** The one role public signup may hand out. Every condition is defensive: the
     *  flag is the intent, while `is_global = false` and a NULL `store_id` make a
     *  mis-flagged role count as "no default" rather than as a security hole —
     *  signup creates a brand-new store, so the role must belong to no store and
     *  span none. A closed signup is the safe failure. */
    private static function signupRole(): Builder
    {
        return Role::where('is_signup_default', true)
            ->where('is_global', false)
            ->whereNull('store_id');
    }
}
