<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesCrudData;
use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoleController extends Controller
{
    use HandlesCrudData;

    /** Render the roles listing page */
    public function index()
    {
        return view('roles.index');
    }

    /** Return paginated, searchable role data as JSON.
     *  Uses the visibleTo scope to restrict non-super-admins to their own roles. */
    public function data(Request $request)
    {
        return $this->paginatedResponse(
            $request,
            Role::visibleTo(auth()->user()),
            ['name'],
            'roles'
        );
    }

    /** Create a new role with permission assignments */
    public function store(Request $request)
    {
        $validated = $request->validate([
            // Role names are NOT unique — everything joins on the role id. One owner
            // may keep several roles named "Cashier", one per store, each with its
            // own permission set. Only the Super-Admin name is reserved (below).
            'name' => ['required', 'string', 'max:255'],
            'permissions' => 'required|array',
            'permissions.*' => 'exists:permissions,id',
            'is_global' => 'sometimes|boolean',
            'is_signup_default' => 'sometimes|boolean',
        ]);

        $this->assertRoleNameIsNotReserved($validated['name']);

        $this->assertPermissionsAreAssignable($validated['permissions']);

        $isGlobal = auth()->user()->isSuperAdmin() && $request->boolean('is_global');

        // Per-store isolation: a store user's role belongs to the store they are
        // working in. Global roles — and roles made by super/global admins, who
        // have no store context — stay store-less (NULL).
        $storeId = $isGlobal ? null : session('current_store_id');

        // Signup default: super-admin-only, never on a global role, and never on a
        // store-scoped one — public signup creates a BRAND-NEW store, so its role
        // must be usable anywhere (mirrors the onboarding guard).
        $isSignupDefault = auth()->user()->isSuperAdmin()
            && $request->boolean('is_signup_default')
            && ! $isGlobal
            && $storeId === null;

        // Transaction: create + permission sync land together or not at all.
        $role = DB::transaction(function () use ($validated, $isGlobal, $isSignupDefault, $storeId) {
            if ($isSignupDefault) {
                Role::where('is_signup_default', true)->update(['is_signup_default' => false]);
            }

            $role = Role::create([
                'name' => $validated['name'],
                'created_by' => auth()->id(),
                // Only a super admin may create global (store-less) roles.
                'is_global' => $isGlobal,
                'is_signup_default' => $isSignupDefault,
                'store_id' => $storeId,
            ]);
            $role->permissions()->sync($validated['permissions']);

            return $role;
        });

        ActivityLog::record('role.created', $role, "Created role {$role->name}");

        return response()->json(['message' => 'Role created', 'role' => $role]);
    }

    /** Update an existing role and sync its permissions.
     *  Scoped with visibleTo so non-super-admins can only reach roles they created. */
    public function update(Request $request, Role $role)
    {
        $role = Role::visibleTo(auth()->user())->findOrFail($role->id);

        // Seeing a role is not editing it: only its creator (or a super admin)
        // may modify it — a global user can see roles they must not touch.
        if (! auth()->user()->isSuperAdmin() && $role->created_by !== auth()->id()) {
            return response()->json(['message' => 'You can only modify roles you created.'], 403);
        }

        $validated = $request->validate([
            // Names are not unique (see store()) — the id is the identity.
            'name' => ['required', 'string', 'max:255'],
            'permissions' => 'required|array',
            'permissions.*' => 'exists:permissions,id',
            'is_global' => 'sometimes|boolean',
            'is_signup_default' => 'sometimes|boolean',
        ]);

        if ($role->name !== 'Super-Admin') {
            $this->assertRoleNameIsNotReserved($validated['name']);
        }

        // The Super-Admin role NAME anchors the whole authorization system
        // (isSuperAdmin() and friends match on it) — renaming it would strip
        // every super admin of their powers at once. Hard-locked.
        if ($role->name === 'Super-Admin' && $validated['name'] !== 'Super-Admin') {
            return response()->json(['message' => 'The Super-Admin role is a system role and cannot be renamed.'], 422);
        }

        $this->assertPermissionsAreAssignable($validated['permissions']);

        // Only a super admin may change where a role applies; and never while the
        // role is assigned — existing rows would contradict the new setting.
        $isGlobal = auth()->user()->isSuperAdmin() ? $request->boolean('is_global') : (bool) $role->is_global;

        if ($isGlobal !== (bool) $role->is_global && $role->users()->exists()) {
            return response()->json([
                'message' => 'This role is assigned to one or more users; remove those assignments before changing where it applies.',
            ], 422);
        }

        // A global role spans every store, so it must not stay pinned to the store
        // it was born in — otherwise its creator would keep seeing it there only.
        $storeId = $isGlobal ? null : $role->store_id;

        // Signup default: super-admin-only, never on a global role, and never on a
        // store-scoped one (public signup creates a brand-new store); setting it
        // here clears it from whichever role held it before (only one default).
        $isSignupDefault = auth()->user()->isSuperAdmin()
            ? ($request->boolean('is_signup_default') && ! $isGlobal && $storeId === null)
            : (bool) $role->is_signup_default;

        DB::transaction(function () use ($role, $validated, $isGlobal, $isSignupDefault, $storeId) {
            if ($isSignupDefault) {
                Role::where('id', '!=', $role->id)->where('is_signup_default', true)->update(['is_signup_default' => false]);
            }

            $role->update([
                'name' => $validated['name'],
                'is_global' => $isGlobal,
                'is_signup_default' => $isSignupDefault,
                'store_id' => $storeId,
            ]);
            $role->permissions()->sync($validated['permissions']);
        });

        ActivityLog::record('role.updated', $role, "Updated role {$role->name}");

        return response()->json(['message' => 'Role updated', 'role' => $role]);
    }

    /** The Super-Admin NAME is the authorization anchor (isSuperAdmin() and friends
     *  match on it) — and MySQL's case-insensitive collation would treat a
     *  look-alike as the real thing, silently minting super admins. Now that role
     *  names are only unique per creator, the name itself must be reserved. */
    private function assertRoleNameIsNotReserved(string $name): void
    {
        // The Super-Admin NAME is the authorization anchor — isSuperAdmin() matches
        // on it with a DB query, so the guard must be at least as broad as BOTH:
        //  (a) the intended case-insensitive reservation, on every driver, and
        //  (b) the DB's OWN string equality used by isSuperAdmin() — MySQL's
        //      utf8mb4_unicode_ci is case-, accent- AND pad-space-insensitive
        //      ('Super-Ádmin', 'Super-Admin ', trailing NBSP all equal 'Super-Admin').
        // PHP strcasecmp+trim cannot fold accents/NBSP, so a plain DB comparison is
        // added: it mirrors isSuperAdmin()'s own collation exactly, closing the
        // accent/collation-equivalent escalation the ASCII check alone would miss.
        $reserved = 'Super-Admin';

        $matchesAnchor = strcasecmp(trim($name), $reserved) === 0
            || (bool) DB::selectOne('select (? = ?) as m', [$name, $reserved])->m;

        if ($matchesAnchor) {
            throw ValidationException::withMessages([
                'name' => 'This role name is reserved.',
            ]);
        }
    }

    /** Server-side twin of the assignable() filter: every submitted permission must be
     *  one the current user is allowed to hand out (a super admin can hand out all).
     *  The UI already limits the checklist, but the API must not trust the client. */
    private function assertPermissionsAreAssignable(array $permissionIds): void
    {
        $assignableIds = auth()->user()->assignablePermissions()->pluck('permissions.id');

        if (collect($permissionIds)->diff($assignableIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permissions' => 'You can only assign permissions that you hold yourself.',
            ]);
        }
    }

    /** Delete a role (blocked while assigned to any user) and detach all permission
     *  associations. Scoped with visibleTo — see update(). */
    public function destroy(Role $role)
    {
        $role = Role::visibleTo(auth()->user())->findOrFail($role->id);

        // Same rule as update(): only the creator or a super admin may delete.
        if (! auth()->user()->isSuperAdmin() && $role->created_by !== auth()->id()) {
            return response()->json(['message' => 'You can only modify roles you created.'], 403);
        }

        if ($role->users()->exists()) {
            return response()->json([
                'message' => 'This role is assigned to one or more users and cannot be deleted. Reassign or remove those users first.',
            ], 422);
        }

        DB::transaction(function () use ($role) {
            $role->permissions()->detach();
            $role->delete();
        });

        ActivityLog::record('role.deleted', null, "Deleted role {$role->name}");

        return response()->json(['message' => 'Role deleted']);
    }

    /** Get all permissions the current user is authorized to assign */
    public function assignable(): JsonResponse
    {
        $permissions = auth()->user()
            ->assignablePermissions()
            ->select('permissions.id', 'permissions.name', 'permissions.label')
            ->orderBy('permissions.name')
            ->get();

        return response()->json($permissions);
    }
}
