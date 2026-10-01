<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The permissions of the ads the platform shares with every shop (owner, 2026-10-01: "sub khel permission ka honga, mein
 * duga toh woo mera kaam bhi delete kar sakte ha" — and, asked how to split them, "teen alag"): three, each doing one
 * thing.
 *
 *  - Update Shared Ads (`ad-shared-update`), new: make an ad for every shop, change it, publish it and take it off.
 *  - Delete Shared Ads (`ad-shared-destroy`), new: delete one, from every shop at once.
 *  - Delete Shared Assets (`ad-shared-asset-destroy`): the permission that was `ad-shared-destroy` until now
 *    (2026_09_29_130100), renamed so that `ad-shared-*` speaks of shared ads. The SAME row, so whoever held it keeps
 *    exactly what they had — the files shared with every shop — and nobody gains the new delete by it.
 *
 * The new two are granted to Super-Admin alone (which holds everything anyway, but its rows are kept honest): no store
 * role starts with them, the super admin gives them to the roles they choose. Written to be run twice, as a real
 * installation's migrations may be.
 */
return new class extends Migration
{
    private const ASSETS = 'ad-shared-asset-destroy';

    /** @var array<string, string> */
    private const ADDED = [
        'ad-shared-update' => 'Update Shared Ads',
        'ad-shared-destroy' => 'Delete Shared Ads',
    ];

    public function up(): void
    {
        DB::transaction(function () {
            $now = now();

            // Renamed first, so the name is free for the shared ads' own delete. Only while the new name is not there
            // yet: run again, the row already carries it and `ad-shared-destroy` is the ads' own.
            if (! DB::table('permissions')->where('name', self::ASSETS)->exists()) {
                DB::table('permissions')->where('name', 'ad-shared-destroy')
                    ->update(['name' => self::ASSETS, 'label' => 'Delete Shared Assets', 'updated_at' => $now]);
            }

            // Super-Admin is found by its exact name in PHP, never by a SQL comparison: MySQL's collation reads
            // "Súper-Admin" as the same string (see 02-project-conventions.md).
            $superAdminIds = DB::table('roles')
                ->where('is_global', true)
                ->get(['id', 'name'])
                ->filter(fn (object $role) => $role->name === 'Super-Admin')
                ->pluck('id');

            // The files' delete too, should the row ever have been taken away: Super-Admin held it from the start.
            foreach ([self::ASSETS => 'Delete Shared Assets', ...self::ADDED] as $name => $label) {
                if (! DB::table('permissions')->where('name', $name)->exists()) {
                    DB::table('permissions')->insert(['name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now]);
                }

                $permissionId = DB::table('permissions')->where('name', $name)->value('id');

                foreach ($superAdminIds as $roleId) {
                    $held = DB::table('role_has_permissions')
                        ->where('role_id', $roleId)->where('permission_id', $permissionId)->exists();

                    if (! $held) {
                        DB::table('role_has_permissions')->insert([
                            'role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            // The pivot rows go with the permissions (foreign key, cascade); then the files' delete takes its old name back.
            DB::table('permissions')->whereIn('name', array_keys(self::ADDED))->delete();
            DB::table('permissions')->where('name', self::ASSETS)->update(['name' => 'ad-shared-destroy', 'updated_at' => now()]);
        });
    }
};
