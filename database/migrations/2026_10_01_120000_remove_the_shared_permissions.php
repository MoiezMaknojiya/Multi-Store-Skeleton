<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * What the platform shares with every shop is the platform's alone to change and delete (owner, 2026-10-01: "store walay
 * mera asset dekh sakta ha aur use kar sakta ha aur ads bhi use kar sakta ha aur dekh sakta ha, srif delete nahi kar
 * sakta ha ... delete shared ads aur delete shared asset ki permission hata do aur update shared ad ki bhi"). A shop's
 * people see the shared ads and files, use them and copy the ads into their own, and nothing more; above the stores the
 * ordinary Update Ads and Delete Ads look after them, as the platform's own library and channels are looked after.
 *
 * So Update Shared Ads, Delete Shared Ads and Delete Shared Assets go, and every role's hold on them with them (the pivot
 * rows cascade). On every database that ever had them, only Super-Admin held them.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const REMOVED = [
        'ad-shared-update' => 'Update Shared Ads',
        'ad-shared-destroy' => 'Delete Shared Ads',
        'ad-shared-asset-destroy' => 'Delete Shared Assets',
    ];

    public function up(): void
    {
        DB::table('permissions')->whereIn('name', array_keys(self::REMOVED))->delete();
    }

    /** Back to how 2026_10_01_110100 left them: the three, held by Super-Admin — the only role that ever held them. */
    public function down(): void
    {
        DB::transaction(function () {
            $now = now();

            // Super-Admin is found by its exact name in PHP, never by a SQL comparison (see 02-project-conventions.md).
            $superAdminIds = DB::table('roles')
                ->where('is_global', true)
                ->get(['id', 'name'])
                ->filter(fn (object $role) => $role->name === 'Super-Admin')
                ->pluck('id');

            foreach (self::REMOVED as $name => $label) {
                if (DB::table('permissions')->where('name', $name)->exists()) {
                    continue;
                }

                $permissionId = DB::table('permissions')->insertGetId(['name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now]);

                foreach ($superAdminIds as $roleId) {
                    DB::table('role_has_permissions')->insert([
                        'role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        });
    }
};
