<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The permission catalogue and the organization roles an installation begins with (docs/ORGANIZATION-SPEC.md §3–§4).
 *
 * Self-contained on purpose: the lists below are this migration's own copy, never the models' constants, so a later
 * change to Permission::LABELS or Role::STARTERS cannot change what this did. A permission added later ships a
 * migration of its own. The Super-Admin role and account come from DatabaseSeeder.
 *
 * The starter roles are only a starting point: the super admin renames them, changes what they allow and deletes
 * all but the Owner role (`key = owner`), and nothing ever touches an existing role again — which is also why an
 * installation that already has its catalogue is left exactly as it is.
 */
return new class extends Migration
{
    /** Every permission the application ships with, with its label. */
    private const PERMISSIONS = [
        'organization-update' => 'Update Organization Details',
        'member-view' => 'View Members',
        'member-invite' => 'Invite Members',
        'member-update' => 'Change Member Roles',
        'member-remove' => 'Remove Members',
        'role-view' => 'View Roles',
        'role-store' => 'Create Roles',
        'role-update' => 'Update Roles',
        'role-destroy' => 'Delete Roles',
        'screen-view' => 'View Screens',
        'screen-store' => 'Pair Screens',
        'screen-update' => 'Update Screens',
        'screen-destroy' => 'Delete Screens',
        'screen-playlist' => 'Change Playlists',
        'media-view' => 'View Media Library',
        'media-store' => 'Upload Media',
        'media-update' => 'Update Media',
        'media-destroy' => 'Delete Media',
        'daypart-view' => 'View Dayparts',
        'daypart-store' => 'Create Dayparts',
        'daypart-update' => 'Update Dayparts',
        'daypart-destroy' => 'Delete Dayparts',
        'user-view' => 'View Accounts',
        'user-destroy' => 'Delete Accounts',
        'organization-view' => 'View Organizations',
        'organization-store' => 'Create Organizations',
        'organization-destroy' => 'Delete Organizations',
        'channel-view' => 'View Channels',
        'channel-store' => 'Create Channels',
        'channel-update' => 'Update Channels',
        'channel-destroy' => 'Delete Channels',
        'activity-view' => 'View Activity Log',
        'activity-destroy' => 'Delete Old Activity Logs',
        'permission-view' => 'View Permissions',
        'permission-store' => 'Create Permissions',
        'permission-update' => 'Update Permissions',
        'permission-destroy' => 'Delete Permissions',
        'ad-view' => 'View Ads',
        'ad-store' => 'Create Ads',
        'ad-update' => 'Update Ads',
        'ad-destroy' => 'Delete Ads',
    ];

    /** An organization's own work: what Owner and Admin start with in full. */
    private const ORGANIZATION_PERMISSIONS = [
        'organization-update',
        'member-view', 'member-invite', 'member-update', 'member-remove',
        'role-view', 'role-store', 'role-update', 'role-destroy',
        'screen-view', 'screen-store', 'screen-update', 'screen-destroy', 'screen-playlist',
        'media-view', 'media-store', 'media-update', 'media-destroy',
        'daypart-view', 'daypart-store', 'daypart-update', 'daypart-destroy',
        'ad-view', 'ad-store', 'ad-update', 'ad-destroy',
    ];

    /** The starter roles by key, with the permissions each starts with. Only the Owner's key means anything. */
    private const STARTERS = [
        'owner' => ['name' => 'Owner', 'permissions' => [...self::ORGANIZATION_PERMISSIONS, 'organization-view', 'organization-destroy']],
        'admin' => ['name' => 'Admin', 'permissions' => [...self::ORGANIZATION_PERMISSIONS, 'organization-view']],
        'staff' => ['name' => 'Staff', 'permissions' => [
            'screen-view', 'screen-playlist', 'media-view', 'media-store', 'media-update', 'media-destroy', 'daypart-view',
        ]],
        'viewer' => ['name' => 'Viewer', 'permissions' => ['screen-view', 'media-view', 'daypart-view']],
    ];

    public function up(): void
    {
        // An installation that has its catalogue already keeps it: its permissions and roles are the super admin's.
        if (DB::table('permissions')->exists()) {
            $this->forgetTheMigrationsTheseReplaced();

            return;
        }

        DB::transaction(function () {
            $now = now();

            DB::table('permissions')->insert(array_map(
                fn (string $name, string $label) => ['name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now],
                array_keys(self::PERMISSIONS),
                self::PERMISSIONS,
            ));

            $permissionIds = DB::table('permissions')->pluck('id', 'name');

            foreach (self::STARTERS as $key => $starter) {
                $roleId = DB::table('roles')->insertGetId([
                    'name' => $starter['name'], 'key' => $key, 'is_global' => false, 'organization_id' => null, 'created_by' => null,
                    'created_at' => $now, 'updated_at' => $now,
                ]);

                DB::table('role_has_permissions')->insert(array_map(
                    fn (string $name) => ['role_id' => $roleId, 'permission_id' => $permissionIds[$name], 'created_at' => $now, 'updated_at' => $now],
                    $starter['permissions'],
                ));
            }
        });
    }

    public function down(): void
    {
        // Their grants go with them through the foreign keys.
        DB::table('roles')->whereIn('key', array_keys(self::STARTERS))->delete();
        DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->delete();
    }

    /**
     * Such an installation was built by the forty migrations these files replaced on 2026-10-01 (one file per table
     * since, each skipping a table that is already there). The rows naming the old ones are bookkeeping for files that
     * are gone, so they go too, and its `migrations` table reads as a fresh install's does. This is the last of the
     * files to run, which is why it happens here.
     */
    private function forgetTheMigrationsTheseReplaced(): void
    {
        $present = array_map(
            fn (string $file) => basename($file, '.php'),
            glob(database_path('migrations/*.php')) ?: [],
        );

        if ($present !== []) {
            DB::table('migrations')->whereNotIn('migration', $present)->delete();
        }
    }
};
