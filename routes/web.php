<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImpersonateController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

// -----------------------------------------------------------------------
// Dashboard
// -----------------------------------------------------------------------
Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('auth')->name('dashboard');
// Store selection page (auth only — it lists the user's OWN assignments, not the
// stores module, so it is not gated by store-view).
Route::get('/select-store', [DashboardController::class, 'selectStore'])->middleware('auth')->name('stores.select');

// -----------------------------------------------------------------------
// Profile  (all authenticated users)
// -----------------------------------------------------------------------
Route::middleware('auth')->group(function () {
    // Show Profile Edit Form
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    // Update Profile Information
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    // Delete User Account
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// -----------------------------------------------------------------------
// Admin Routes  (auth required on every group below; throttled per user)
// -----------------------------------------------------------------------
Route::middleware(['auth', 'throttle:240,1'])->group(function () {

    // -------------------------------------------------------------------
    // Users
    // -------------------------------------------------------------------
    Route::prefix('users')->group(function () {

        // Show Users List Page
        Route::get('/', [UserController::class, 'index'])->middleware('can:user-view')->name('users.view');
        // Get Paginated Users Data (AJAX)
        Route::get('/data', [UserController::class, 'data'])->middleware('can:user-view')->name('users.data');
        // Option lists for the assign modal — gated by the assign permission alone,
        // so user-store-assign is self-sufficient (no store-view/role-view needed)
        Route::get('/assignable-stores', [UserController::class, 'assignableStores'])->middleware('can:user-store-assign')->name('users.assignable-stores');
        Route::get('/assignable-roles', [UserController::class, 'assignableRoles'])->middleware('can:user-store-assign')->name('users.assignable-roles');
        // Onboard a new store owner: user + store + role assignment in one step —
        // needs all three capabilities together
        Route::post('/onboard', [UserController::class, 'onboardOwner'])->middleware(['can:user-store', 'can:store-store', 'can:user-store-assign'])->name('users.onboard');
        // Create New User
        Route::post('/', [UserController::class, 'store'])->middleware('can:user-store')->name('users.store');
        // Update Existing User
        Route::put('/{user}', [UserController::class, 'update'])->middleware('can:user-update')->name('users.update');
        // Delete User
        Route::delete('/{user}', [UserController::class, 'destroy'])->middleware('can:user-destroy')->name('users.destroy');
        // ---------------------------------------------------------------
        // User-Store Assignments
        // ---------------------------------------------------------------
        Route::prefix('/{user}/stores')->group(function () {
            // Get All Store Assignments For A User
            Route::get('/', [UserController::class, 'getStoreAssignments'])->middleware('can:user-store-view')->name('users.stores.view');
            // Assign User To A Store With A Role
            Route::post('/', [UserController::class, 'assignStore'])->middleware('can:user-store-assign')->name('users.stores.assign');
            // Remove User From A Store
            Route::delete('/{store}', [UserController::class, 'unassignStore'])->whereNumber('store')->middleware('can:user-store-unassign')->name('users.stores.unassign');

        });
        // Log in as this user (Super Admin only; enforced in the controller, not gated behind
        // a permission, since it's a role-level capability rather than a grantable permission)
        Route::post('/{user}/impersonate', [ImpersonateController::class, 'start'])->name('users.impersonate');
    });

    // -------------------------------------------------------------------
    // Stores
    // -------------------------------------------------------------------
    Route::prefix('stores')->group(function () {
        // Show Stores List Page
        Route::get('/', [StoreController::class, 'index'])->middleware('can:store-view')->name('stores.view');
        // Get Paginated Stores Data (AJAX)
        Route::get('/data', [StoreController::class, 'data'])->middleware('can:store-view')->name('stores.data');
        // Create New Store
        Route::post('/', [StoreController::class, 'store'])->middleware('can:store-store')->name('stores.store');
        // Update Existing Store
        Route::put('/{store}', [StoreController::class, 'update'])->middleware('can:store-update')->name('stores.update');
        // Delete Store
        Route::delete('/{store}', [StoreController::class, 'destroy'])->middleware('can:store-destroy')->name('stores.destroy');
        // Switch Store
        Route::post('/switch', [StoreController::class, 'switch'])->name('store.switch');
    });

    // -------------------------------------------------------------------
    // Roles
    // -------------------------------------------------------------------
    Route::prefix('roles')->group(function () {
        // Show Roles List Page
        Route::get('/', [RoleController::class, 'index'])->middleware('can:role-view')->name('roles.view');
        // Get Paginated Roles Data (AJAX)
        Route::get('/data', [RoleController::class, 'data'])->middleware('can:role-view')->name('roles.data');
        // Create New Role
        Route::post('/', [RoleController::class, 'store'])->middleware('can:role-store')->name('roles.store');
        // Update Existing Role
        Route::put('/{role}', [RoleController::class, 'update'])->middleware('can:role-update')->name('roles.update');
        // Delete Role
        Route::delete('/{role}', [RoleController::class, 'destroy'])->middleware('can:role-destroy')->name('roles.destroy');
        // Get All Permissions Assigned To A Role
        Route::get('/{role}/permissions', [PermissionController::class, 'permissions'])->middleware('can:role-view')->name('roles.permissions');
        // Get All Assignable Permissions
        Route::get('/assignable', [RoleController::class, 'assignable'])->middleware('can:role-view')->name('permissions.assignable');
    });

    // -------------------------------------------------------------------
    // Activity Log  (read-only audit trail)
    // -------------------------------------------------------------------
    Route::prefix('activity')->group(function () {
        Route::get('/', [ActivityLogController::class, 'index'])->middleware('can:activity-view')->name('activity.view');
        Route::get('/data', [ActivityLogController::class, 'data'])->middleware('can:activity-view')->name('activity.data');
        // Yearly partition lifecycle (panel shows status; maintenance is super-admin-only)
        Route::get('/partitions', [ActivityLogController::class, 'partitions'])->middleware('can:activity-view')->name('activity.partitions');
        Route::post('/partitions/maintain', [ActivityLogController::class, 'maintainPartitions'])->middleware('can:activity-view')->name('activity.partitions.maintain');
    });

    // -------------------------------------------------------------------
    // Permissions  (/permissions/*)
    // -------------------------------------------------------------------
    Route::prefix('permissions')->group(function () {
        // Show Permissions List Page
        Route::get('/', [PermissionController::class, 'index'])->middleware('can:permission-view')->name('permissions.view');
        // Get Paginated Permissions Data (AJAX)
        Route::get('/data', [PermissionController::class, 'data'])->middleware('can:permission-view')->name('permissions.data');
        // Create New Permission
        Route::post('/', [PermissionController::class, 'store'])->middleware('can:permission-store')->name('permissions.store');
        // Update Existing Permission
        Route::put('/{permission}', [PermissionController::class, 'update'])->middleware('can:permission-update')->name('permissions.update');
        // Delete Permission
        Route::delete('/{permission}', [PermissionController::class, 'destroy'])->middleware('can:permission-destroy')->name('permissions.destroy');
    });

    // -------------------------------------------------------------------
    // Impersonation — "stop" must stay reachable by the impersonated user,
    // who is not a Super Admin, so it cannot sit behind a permission gate.
    // -------------------------------------------------------------------
    Route::post('/impersonate/stop', [ImpersonateController::class, 'stop'])->name('impersonate.stop');

});

require __DIR__.'/auth.php';
