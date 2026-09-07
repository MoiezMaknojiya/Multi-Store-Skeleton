<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesCrudData;
use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PermissionController extends Controller
{
    use HandlesCrudData;

    /** Render the permissions listing page */
    public function index(): View
    {
        return view('permissions.index');
    }

    /** Return paginated, searchable permission data as JSON */
    public function data(Request $request): JsonResponse
    {
        return $this->paginatedResponse($request, Permission::query(), ['name'], 'permissions');
    }

    /** Create a new permission */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:permissions,name',
            'label' => ['nullable', 'string', 'max:255', 'regex:/^[a-zA-Z0-9 ]*$/'],
        ], [
            'label.regex' => 'Label can only contain letters, numbers, and spaces.',
        ]);

        $permission = Permission::create($validated);

        ActivityLog::record('permission.created', $permission, "Created permission {$permission->name}");

        return response()->json(['message' => 'Permission created successfully', 'permission' => $permission]);
    }

    /** Update an existing permission */
    public function update(Request $request, Permission $permission): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('permissions')->ignore($permission->id)],
            'label' => ['nullable', 'string', 'max:255', 'regex:/^[a-zA-Z0-9 ]*$/'],
        ], [
            'label.regex' => 'Label can only contain letters, numbers, and spaces.',
        ]);

        $permission->update($validated);

        ActivityLog::record('permission.updated', $permission, "Updated permission {$permission->name}");

        return response()->json(['message' => 'Permission updated successfully', 'permission' => $permission]);
    }

    /** Delete a permission (blocked while assigned to a role; requires the acting user's current password) */
    public function destroy(Request $request, Permission $permission): JsonResponse
    {
        if ($permission->roles()->exists()) {
            return response()->json([
                'message' => 'This permission is assigned to one or more roles and cannot be deleted. Remove it from those roles first.',
            ], 422);
        }

        $request->validate([
            'password' => ['required', 'current_password'],
        ], [
            'password.current_password' => 'Password is wrong.',
        ]);

        $permission->delete();

        ActivityLog::record('permission.deleted', null, "Deleted permission {$permission->name}");

        return response()->json(['message' => 'Permission deleted successfully']);
    }

    /** Get all permissions assigned to a specific role.
     *  Scoped with visibleTo so a caller cannot read the capability set of a role
     *  outside their visibility (another tenant's roles, global roles, Super-Admin)
     *  by guessing ids — mirrors the write endpoints. */
    public function permissions(Role $role): JsonResponse
    {
        $role = Role::visibleTo(auth()->user())->findOrFail($role->id);

        $permissions = $role->permissions()->select('permissions.id', 'permissions.name', 'permissions.label')->get();

        return response()->json($permissions);
    }
}
