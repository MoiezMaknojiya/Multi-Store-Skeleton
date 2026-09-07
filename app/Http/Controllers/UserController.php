<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesCrudData;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    use HandlesCrudData;

    /** Render the users listing page */
    public function index(): View
    {
        return view('users.index', ['states' => Store::US_STATES]);
    }

    /** Return paginated, searchable user data as JSON.
     *  Uses the visibleTo scope: super-admins see all users, others see only users they created. */
    public function data(Request $request): JsonResponse
    {
        $roleNames = Role::pluck('name', 'id');
        $superAdminRoleId = (int) Role::where('name', 'Super-Admin')->value('id');

        return $this->paginatedResponse(
            $request,
            User::visibleTo(auth()->user()),
            ['first_name', 'last_name', 'email'],
            'users',
            ['id', 'first_name', 'last_name', 'email', 'phone', 'created_by'],
            null,
            // Batched enrichment: ONE store_user query serves the whole page instead
            // of one per row. Queried directly against store_user (not the stores()
            // relationship) so the global sentinel store_id = 0 rows are included —
            // they have no matching stores row, so a join would silently drop them.
            function ($users) use ($roleNames, $superAdminRoleId) {
                $assignments = DB::table('store_user')
                    ->whereIn('user_id', $users->pluck('id'))
                    ->get(['user_id', 'store_id', 'role_id'])
                    ->groupBy('user_id');

                $users->transform(function (User $u) use ($assignments, $roleNames, $superAdminRoleId) {
                    $rows = $assignments->get($u->id, collect());
                    $u->is_super_admin = $rows->contains(fn ($row) => (int) $row->role_id === $superAdminRoleId && $superAdminRoleId !== 0);
                    $u->is_global_user = $rows->contains(fn ($row) => (int) $row->store_id === 0);
                    $u->roles = $rows->pluck('role_id')
                        ->unique()
                        ->map(fn ($roleId) => $roleNames[$roleId] ?? null)
                        ->filter()
                        ->values();

                    return $u;
                });
            }
        );
    }

    /** Create a new user */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone' => 'required|numeric|digits:10',
            'email' => ['required', 'email', 'regex:/^\S+$/', 'unique:users'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'email.regex' => 'Email cannot contain spaces.',
        ]);

        $user = User::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'phone' => $validated['phone'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'created_by' => auth()->id(),
        ]);

        ActivityLog::record('user.created', $user, "Created user {$user->name} ({$user->email})");

        return response()->json(['message' => 'User created successfully', 'user' => $user]);
    }

    /** Onboard a new store owner: creates the user AND their store in one
     *  transaction and assigns the chosen role — the "new customer" flow.
     *  Route requires user-store + store-store + user-store-assign together.
     *  Everything is id-based; the role comes from the request (the UI merely
     *  pre-selects the owner role by name as a convenience). */
    public function onboardOwner(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone' => 'required|numeric|digits:10',
            'email' => ['required', 'email', 'regex:/^\S+$/', 'unique:users'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'store_name' => 'required|string|max:255',
            'street' => 'required|string|max:255',
            'suite' => 'nullable|string|max:100',
            'city' => 'required|string|max:100',
            'state' => ['required', 'string', 'size:2', Rule::in(array_keys(Store::US_STATES))],
            'zip_code' => ['required', 'string', 'max:10', 'regex:/^[0-9]+$/'],
            'country' => 'required|string|max:100',
            'role_id' => 'required|exists:roles,id',
        ], [
            'email.regex' => 'Email cannot contain spaces.',
            'zip_code.regex' => 'Zip code can only contain numbers.',
        ]);

        $role = Role::visibleTo(auth()->user())->findOrFail($validated['role_id']);

        // The owner lives IN the store — global roles don't belong here.
        if ($role->isGlobal()) {
            throw ValidationException::withMessages(['role_id' => 'A global role cannot be used for a store owner.']);
        }

        // This request creates a BRAND-NEW store, so the owner's role must be one
        // that works in any store. A store-scoped role belongs to the store it was
        // built in (roles never travel) — letting it govern the new store would put
        // the new owner's powers under someone else's store, which is exactly what
        // per-store isolation forbids.
        if ($role->store_id !== null) {
            throw ValidationException::withMessages([
                'role_id' => 'That role belongs to another store and cannot be used for a new one.',
            ]);
        }

        [$user, $store] = DB::transaction(function () use ($validated, $role) {
            $user = User::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'phone' => $validated['phone'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'created_by' => auth()->id(),
            ]);

            $store = Store::create([
                'name' => $validated['store_name'],
                'street' => $validated['street'],
                'suite' => $validated['suite'] ?? null,
                'city' => $validated['city'],
                'state' => $validated['state'],
                'zip_code' => $validated['zip_code'],
                'country' => $validated['country'],
                'is_active' => true,
                // The store is the OWNER's creation: deleting the owner cascades it away.
                'created_by' => $user->id,
            ]);

            $user->stores()->attach($store->id, ['role_id' => $role->id]);

            return [$user, $store];
        });

        ActivityLog::record('user.onboarded', $user,
            "Onboarded store owner {$user->name} ({$user->email}) with store {$store->name}");

        return response()->json([
            'message' => 'Store owner onboarded successfully',
            'user' => $user,
            'store' => $store,
        ]);
    }

    /** Update an existing user (cannot edit self through this endpoint).
     *  Scoped with visibleTo so a user can only reach targets they are allowed to see —
     *  anyone else 404s, even with a guessed id. */
    public function update(Request $request, User $user): JsonResponse
    {
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'You cannot edit your own account here.'], 403);
        }

        $user = User::visibleTo(auth()->user())->findOrFail($user->id);

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone' => 'required|numeric|digits:10',
            'email' => ['required', 'email', 'regex:/^\S+$/', Rule::unique('users')->ignore($user->id)],
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ], [
            'email.regex' => 'Email cannot contain spaces.',
        ]);

        $user->first_name = $validated['first_name'];
        $user->last_name = $validated['last_name'];
        $user->phone = $validated['phone'];
        $user->email = $validated['email'];

        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        ActivityLog::record('user.updated', $user, "Updated user {$user->name} ({$user->email})");

        return response()->json(['message' => 'User updated successfully', 'user' => $user]);
    }

    /** Delete a user (cannot delete self). Scoped with visibleTo — see update().
     *  Cascades by explicit owner decision: everything the user created goes too
     *  (their user subtree, their unassigned roles, their stores). */
    public function destroy(User $user): JsonResponse
    {
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'You cannot delete your own account.'], 403);
        }

        $user = User::visibleTo(auth()->user())->findOrFail($user->id);
        $name = $user->name;
        $email = $user->email;

        $counts = DB::transaction(fn () => $user->deleteCascade());

        ActivityLog::record('user.deleted', null,
            "Deleted user {$name} ({$email}) — cascade removed {$counts['users']} created users, {$counts['roles']} roles, {$counts['stores']} stores");

        return response()->json(['message' => 'User deleted successfully']);
    }

    /** Option list for the assign modal's store dropdown. Gated by user-store-assign
     *  alone (capability-complete: the assign permission is self-sufficient) and
     *  returns only id + name — no store management data. Global users span every
     *  store; store users only get their own. */
    public function assignableStores(): JsonResponse
    {
        $query = Store::query()->select('id', 'name')->orderBy('name');

        if (! auth()->user()->globalRole()) {
            $query->whereHas('users', fn ($q) => $q->where('user_id', auth()->id()));
        }

        return response()->json(['stores' => $query->get()]);
    }

    /** Option list for the assign modal's role dropdown — same idea as
     *  assignableStores(): gated by user-store-assign alone, minimal fields,
     *  scoped by the role visibility matrix (visibleTo). */
    public function assignableRoles(): JsonResponse
    {
        return response()->json([
            // store_id travels with each row so the onboard modal can drop
            // store-scoped roles — a new store needs a role that is not tied
            // to an existing one (see onboardOwner).
            'roles' => Role::visibleTo(auth()->user())
                ->select('id', 'name', 'is_global', 'store_id')
                ->orderBy('name')
                ->get(),
        ]);
    }

    /** Get all store assignments for a user.
     *  Queried against store_user directly (not the stores() relationship) so the
     *  Super-Admin's global sentinel row (store_id = 0) is included — it has no
     *  matching stores row, so a relationship join would silently drop it. */
    public function getStoreAssignments(User $user): JsonResponse
    {
        $user = User::visibleTo(auth()->user())->findOrFail($user->id);

        $roleNames = Role::pluck('name', 'id');
        $storeNames = Store::pluck('name', 'id');

        $assignments = DB::table('store_user')
            ->where('user_id', $user->id)
            ->orderBy('store_id')
            ->get()
            ->map(fn ($row) => [
                'store_id' => (int) $row->store_id,
                'store_name' => (int) $row->store_id === 0
                    ? 'Global (All Stores)'
                    : ($storeNames->get($row->store_id) ?? '—'),
                'role_id' => (int) $row->role_id,
                'role_name' => $roleNames->get($row->role_id),
            ])
            ->values();

        return response()->json(['assignments' => $assignments]);
    }

    /** Assign a user to a store with a specific role.
     *  Global roles (Super-Admin, or any role marked is_global) live on the store_id = 0
     *  sentinel row, so no store is asked for or stored. Every other role only exists in
     *  the context of a real store, so a store is required. */
    public function assignStore(Request $request, User $user): JsonResponse
    {
        $user = User::visibleTo(auth()->user())->findOrFail($user->id);

        if ($user->isSuperAdmin()) {
            return response()->json(['message' => 'Super Admin cannot be assigned to stores.'], 422);
        }

        $validated = $request->validate([
            'role_id' => 'required|exists:roles,id',
            'store_id' => 'nullable|exists:stores,id',
        ]);

        $role = Role::find($validated['role_id']);
        $isGlobalRole = $role->isGlobal();

        if ($isGlobalRole) {
            // Handing out a global role (Super-Admin included) is a super-admin-only
            // power — a custom global admin must never be able to mint peers.
            if (! auth()->user()->isSuperAdmin()) {
                return response()->json(['message' => 'Only a Super Admin can assign global roles.'], 403);
            }

            // The tiers stay separate: a store-assigned user cannot also be global.
            $hasStoreAssignments = DB::table('store_user')
                ->where('user_id', $user->id)
                ->where('store_id', '!=', 0)
                ->exists();

            if ($hasStoreAssignments) {
                return response()->json(['message' => 'This user is assigned to stores and cannot take a global role. Remove their store assignments first.'], 422);
            }

            $storeId = 0;
        } elseif (empty($validated['store_id'])) {
            throw ValidationException::withMessages(['store_id' => 'A store is required for this role.']);
        } else {
            $storeId = $validated['store_id'];

            // exists:stores,id ignores soft-deletes; reject a trashed store so no
            // phantom assignment to a dead store is created (the model query is
            // soft-delete scoped).
            if (! Store::whereKey($storeId)->exists()) {
                throw ValidationException::withMessages(['store_id' => 'The selected store is not available.']);
            }

            // Same rule as onboarding: a store role must be visible to the actor
            // (404 otherwise) — the dropdown filter is cosmetic, this is the
            // enforcement. Without it, any actor with user-store-assign could
            // hand out role ids they cannot see (permissions beyond their own).
            Role::visibleTo(auth()->user())->findOrFail($validated['role_id']);

            // The tiers stay separate: a global user cannot also be store-assigned.
            if ($user->globalRole()) {
                return response()->json(['message' => 'This user holds a global role and cannot be assigned to stores. Remove the global role first.'], 422);
            }

            // Permissions are per-store: the can: gate validated the CURRENT context
            // store, so a store user may only assign INTO that store — not into
            // another store they happen to belong to with a different role. (The
            // current store is always one they belong to; switching enforces that.)
            // Global users span every store. Asked first: "may you act in this store
            // at all?" comes before "is this role valid here?".
            if (! auth()->user()->globalRole() && (int) session('current_store_id') !== (int) $storeId) {
                return response()->json(['message' => 'You can only assign users within the store you are currently in.'], 403);
            }

            // A store-scoped role only exists inside its own store — it can never be
            // handed out in another store, not even by a global user or super admin
            // (store-less roles, e.g. the seeded ones, stay usable anywhere).
            if ($role->store_id !== null && (int) $role->store_id !== (int) $storeId) {
                return response()->json(['message' => 'That role belongs to a different store.'], 422);
            }
        }

        // Checked against store_user directly (not the stores() relationship) so the
        // store_id = 0 sentinel row is seen too — it has no matching stores row, so a
        // relationship join would silently drop it.
        $alreadyAssigned = DB::table('store_user')
            ->where('user_id', $user->id)
            ->where('store_id', $storeId)
            ->exists();

        if ($alreadyAssigned) {
            return response()->json(['message' => 'User already assigned to this store.'], 422);
        }

        $user->stores()->attach($storeId, [
            'role_id' => $validated['role_id'],
        ]);

        ActivityLog::record('user.assigned', $user, $isGlobalRole
            ? "Assigned global role to {$user->name} ({$user->email})"
            : "Assigned {$user->name} ({$user->email}) to store #{$storeId}");

        return response()->json(['message' => $isGlobalRole
            ? "Global role \"{$role->name}\" assigned."
            : 'User assigned to store successfully.']);
    }

    /** Remove a user from a store. Takes the raw store id (not a Store model) so the
     *  Super-Admin global sentinel row (store_id = 0) can be removed too — it has no
     *  stores row, so model binding would 404 on it. */
    public function unassignStore(User $user, string $store): JsonResponse
    {
        $storeId = (int) $store;

        if ($storeId === 0) {
            if (! auth()->user()->isSuperAdmin()) {
                return response()->json(['message' => 'Only a Super Admin can remove Super-Admin access.'], 403);
            }
            if ($user->id === auth()->id()) {
                return response()->json(['message' => 'You cannot remove your own Super-Admin access.'], 422);
            }
        }

        // Scoped after the guards above so self-removal still gets its friendly message;
        // any target the actor cannot see (e.g. the admin who promoted them) 404s.
        $user = User::visibleTo(auth()->user())->findOrFail($user->id);

        // Permissions are per-store: the can: gate validated the CURRENT context
        // store, so a store user may only remove an assignment FOR that store. An
        // assignment in another store they belong to must be managed from THAT
        // store's context. Global users span every store (store 0 is already
        // restricted to super admins above).
        if ($storeId !== 0 && ! auth()->user()->globalRole()
            && (int) session('current_store_id') !== $storeId) {
            return response()->json(['message' => 'You can only remove users from the store you are currently in.'], 403);
        }

        $user->stores()->detach($storeId);

        ActivityLog::record('user.unassigned', $user, $storeId === 0
            ? "Removed global role from {$user->name} ({$user->email})"
            : "Removed {$user->name} ({$user->email}) from store #{$storeId}");

        return response()->json(['message' => $storeId === 0
            ? 'Super-Admin access removed.'
            : 'User removed from store successfully.']);
    }
}
