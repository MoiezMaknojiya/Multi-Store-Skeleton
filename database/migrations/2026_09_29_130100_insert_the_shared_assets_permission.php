<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Delete Shared Assets (`ad-shared-destroy`): taking off the Ad Builder's shelf a picture or a video the platform
 * shares with every shop (owner, 2026-09-29: "agar permission du toh woo delete bhi kar sake"). A migration of its
 * own, never an edit to the baseline `2026_09_16_110200`, which is only what a fresh install starts with.
 *
 * Granted to Super-Admin alone (which holds everything anyway, but its rows are kept honest): a shared file goes from
 * every shop's shelf at once, so no store role starts with it — the super admin gives it to the roles they choose.
 */
return new class extends Migration
{
    private const NAME = 'ad-shared-destroy';

    private const LABEL = 'Delete Shared Assets';

    public function up(): void
    {
        DB::transaction(function () {
            $now = now();

            if (! DB::table('permissions')->where('name', self::NAME)->exists()) {
                DB::table('permissions')->insert([
                    'name' => self::NAME, 'label' => self::LABEL, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $permissionId = DB::table('permissions')->where('name', self::NAME)->value('id');

            // Super-Admin is found by its exact name in PHP, never by a SQL comparison: MySQL's collation reads
            // "Súper-Admin" as the same string (see 02-project-conventions.md).
            $superAdminIds = DB::table('roles')
                ->where('is_global', true)
                ->get(['id', 'name'])
                ->filter(fn ($role) => $role->name === 'Super-Admin')
                ->pluck('id');

            foreach ($superAdminIds as $roleId) {
                $held = DB::table('role_has_permissions')
                    ->where('role_id', $roleId)->where('permission_id', $permissionId)->exists();

                if (! $held) {
                    DB::table('role_has_permissions')->insert([
                        'role_id' => $roleId, 'permission_id' => $permissionId,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // The pivot rows go with the permission (foreign key, cascade).
        DB::table('permissions')->where('name', self::NAME)->delete();
    }
};
