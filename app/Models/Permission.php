<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    /**
     * Store permissions: a store's own work. On a store role or a custom role they are read
     * against the member's role in the store they are working in; on a platform role they reach every
     * store (support holding media-view reads every store's library).
     */
    public const STORE = [
        'store-update',
        'member-view', 'member-invite', 'member-update', 'member-remove',
        'role-view', 'role-store', 'role-update', 'role-destroy',
        'screen-view', 'screen-store', 'screen-update', 'screen-destroy', 'screen-playlist',
        'media-view', 'media-store', 'media-update', 'media-destroy',
        'daypart-view', 'daypart-store', 'daypart-update', 'daypart-destroy',
    ];

    /**
     * Platform permissions: accounts, stores, channels and the activity log. On a platform role they
     * reach every store. A store's role may carry the ones in STORE_SCOPED too, and there they reach
     * that one store and nothing past it. Accounts are the platform's alone (owner's rule, 2026-09-17:
     * a store's people are its Members page).
     */
    public const PLATFORM = [
        'user-view', 'user-destroy',
        'store-view', 'store-store', 'store-destroy',
        'channel-view', 'channel-store', 'channel-update', 'channel-destroy',
        'activity-view', 'activity-destroy',
    ];

    /**
     * The platform permissions that also work inside a store, for that store alone (owner's rule,
     * 2026-09-16: the super admin decides who holds what, and a store's people work within their store):
     *
     *  - store-view, store-store, store-destroy — the Stores tab of Settings for the store they work in
     *    (owner's rule, 2026-09-17: turning View Stores off hides it), a new store they open there and own,
     *    and deleting that store;
     *  - channel-* — the store's own channels, for its own screens;
     *  - activity-view — this store's history.
     *
     * Not among them: user-view and user-destroy (accounts are the platform's; a store's people are its Members
     * page), and activity-destroy (yearly maintenance drops a whole year of every store's history).
     */
    public const STORE_SCOPED = [
        'store-view', 'store-store', 'store-destroy',
        'channel-view', 'channel-store', 'channel-update', 'channel-destroy',
        'activity-view',
    ];

    /**
     * The permission catalogue itself stays with the Super-Admin role alone (owner's rule):
     * every route names its permission in code, so renaming a row either breaks a feature for
     * everybody or turns a harmless permission into a powerful one.
     */
    public const SUPER_ADMIN_ONLY = [
        'permission-view', 'permission-store', 'permission-update', 'permission-destroy',
    ];

    /** The label of every permission the application ships with. */
    public const LABELS = [
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

    protected $fillable = ['name', 'label'];

    protected $appends = ['display_name'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_has_permissions')
            ->withTimestamps();
    }

    /** Whether a store's role may carry the named permission. One made on the Permissions page may not. */
    public static function belongsToStores(string $name): bool
    {
        return in_array($name, self::STORE, true) || in_array($name, self::STORE_SCOPED, true);
    }

    /** Human-readable label, falling back to the raw permission name when unset. */
    public function getDisplayNameAttribute(): string
    {
        return $this->label ?: $this->name;
    }
}
