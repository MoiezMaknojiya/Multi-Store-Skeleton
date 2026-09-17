<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Controllers\Concerns\HandlesCrudData;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PermissionController extends Controller
{
    use ConfirmsPassword, HandlesCrudData;

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

        $this->confirmPassword($request);

        $permission->delete();

        ActivityLog::record('permission.deleted', null, "Deleted permission {$permission->name}");

        return response()->json(['message' => 'Permission deleted successfully']);
    }
}
