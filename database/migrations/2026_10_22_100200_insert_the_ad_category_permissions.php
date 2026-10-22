<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Ad Builder's Categories page (owner, 2026-10-22: one list, the super admin's): four platform permissions, held by
 * Super-Admin. They are in Permission::PLATFORM and not ORGANIZATION_SCOPED, so no organization's role may carry them, and the
 * routes keep the global-tier lock besides. A role is never touched otherwise.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'ad-category-view' => 'View Ad Categories',
        'ad-category-store' => 'Create Ad Categories',
        'ad-category-update' => 'Update Ad Categories',
        'ad-category-destroy' => 'Delete Ad Categories',
    ];

    public function up(): void
    {
        DB::transaction(function () {
            $now = now();

            foreach (self::PERMISSIONS as $name => $label) {
                if (! DB::table('permissions')->where('name', $name)->exists()) {
                    DB::table('permissions')->insert(['name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now]);
                }
            }

            // The Super-Admin anchor is its exact name, compared here and not in SQL (a collation folds look-alikes together).
            $superAdmin = DB::table('roles')->where('is_global', true)->orderBy('id')->get(['id', 'name'])
                ->first(fn (object $role) => $role->name === 'Super-Admin');

            if ($superAdmin === null) {
                return;
            }

            foreach (DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id') as $permissionId) {
                if (! DB::table('role_has_permissions')->where('role_id', $superAdmin->id)->where('permission_id', $permissionId)->exists()) {
                    DB::table('role_has_permissions')->insert(['role_id' => $superAdmin->id, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        });
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
