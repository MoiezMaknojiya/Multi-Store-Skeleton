<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Developer → TV App's three permissions (owner, 2026-10-10: the super admin alone sees it), held by Super-Admin and by no
 * other role: they are in Permission::SUPER_ADMIN_ONLY, so no role can be given them. A role is never touched otherwise.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'player-app-view' => 'View TV App',
        'player-app-store' => 'Upload TV App',
        'player-app-destroy' => 'Delete TV App Versions',
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
