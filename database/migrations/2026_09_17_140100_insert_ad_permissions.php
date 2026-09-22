<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Ad Builder's permissions (docs/AD-BUILDER-SPEC.md §4). A migration of its own, never an edit to the
 * baseline `2026_09_16_110200`, which is only what a fresh install starts with.
 *
 * They are store permissions: a store's own work, and on a platform role they reach every store. Assets get
 * no permission of their own — uploading a picture is part of making an ad, and a permission has to be
 * enough for its own job (the capability-complete rule).
 *
 * Granted to Super-Admin (which holds everything anyway, but its rows are kept honest) and to the Owner and
 * Admin starter roles where an installation still has them. Nothing else is touched: a super admin may have
 * renamed or deleted those roles, and a re-run must not undo that.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'ad-view' => 'View Ads',
        'ad-store' => 'Create Ads',
        'ad-update' => 'Update Ads',
        'ad-destroy' => 'Delete Ads',
    ];

    /** The starter roles that should hold them from the start, by key. */
    private const STARTER_KEYS = ['owner', 'admin'];

    public function up(): void
    {
        DB::transaction(function () {
            $now = now();

            foreach (self::PERMISSIONS as $name => $label) {
                if (! DB::table('permissions')->where('name', $name)->exists()) {
                    DB::table('permissions')->insert([
                        'name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }

            $permissionIds = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');

            // Super-Admin is found by its exact name in PHP, never by a SQL comparison: MySQL's collation
            // reads "Súper-Admin" as the same string (see 02-project-conventions.md).
            $roleIds = DB::table('roles')
                ->where('is_global', true)
                ->get(['id', 'name'])
                ->filter(fn ($role) => $role->name === 'Super-Admin')
                ->pluck('id')
                ->merge(DB::table('roles')->whereIn('key', self::STARTER_KEYS)->pluck('id'));

            foreach ($roleIds as $roleId) {
                foreach ($permissionIds as $permissionId) {
                    $held = DB::table('role_has_permissions')
                        ->where('role_id', $roleId)->where('permission_id', $permissionId)->exists();

                    if (! $held) {
                        DB::table('role_has_permissions')->insert([
                            'role_id' => $roleId, 'permission_id' => $permissionId,
                            'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        // The pivot rows go with the permissions (foreign key, cascade).
        DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->delete();
    }
};
