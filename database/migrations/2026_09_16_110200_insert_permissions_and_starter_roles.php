<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The permission catalogue and the store roles an installation begins with (docs/STORE-ORGANIZATION-SPEC.md §3–§4).
 *
 * Self-contained on purpose: the lists below are this migration's own copy, never the models' constants, so a later
 * change to Permission::LABELS or Role::STARTERS cannot change what this did. A permission added later ships a
 * migration of its own. The Super-Admin role and account come from DatabaseSeeder.
 *
 * The starter roles are only a starting point: the super admin renames them, changes what they allow and deletes
 * all but the Owner role (`key = owner`), and nothing ever touches an existing role again.
 */
return new class extends Migration
{
    /** Every permission the application ships with, with its label. */
    private const PERMISSIONS = [
        'store-update' => 'Update Store Details',
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
        'store-view' => 'View Stores',
        'store-store' => 'Create Stores',
        'store-destroy' => 'Delete Stores',
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
    ];

    /** A store's own work: what Owner and Admin start with in full. */
    private const STORE_PERMISSIONS = [
        'store-update',
        'member-view', 'member-invite', 'member-update', 'member-remove',
        'role-view', 'role-store', 'role-update', 'role-destroy',
        'screen-view', 'screen-store', 'screen-update', 'screen-destroy', 'screen-playlist',
        'media-view', 'media-store', 'media-update', 'media-destroy',
        'daypart-view', 'daypart-store', 'daypart-update', 'daypart-destroy',
    ];

    /** The starter store roles by key, with the permissions each starts with. Only the Owner's key means anything. */
    private const STARTERS = [
        'owner' => ['name' => 'Owner', 'permissions' => [...self::STORE_PERMISSIONS, 'store-view', 'store-destroy']],
        'admin' => ['name' => 'Admin', 'permissions' => [...self::STORE_PERMISSIONS, 'store-view']],
        'staff' => ['name' => 'Staff', 'permissions' => [
            'screen-view', 'screen-playlist', 'media-view', 'media-store', 'media-update', 'media-destroy', 'daypart-view',
        ]],
        'viewer' => ['name' => 'Viewer', 'permissions' => ['screen-view', 'media-view', 'daypart-view']],
    ];

    public function up(): void
    {
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
                    'name' => $starter['name'], 'key' => $key, 'is_global' => false, 'store_id' => null, 'created_by' => null,
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
};
