<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    /**
     * Organization permissions: an organization's own work. On an organization role or a custom role they are read
     * against the member's role in the organization they are working in; on a platform role they reach every
     * organization (support holding media-view reads every organization's library).
     */
    public const ORGANIZATION = [
        'organization-update',
        'member-view', 'member-invite', 'member-update', 'member-remove',
        'role-view', 'role-store', 'role-update', 'role-destroy',
        'screen-view', 'screen-store', 'screen-update', 'screen-destroy', 'screen-playlist',
        'media-view', 'media-store', 'media-update', 'media-destroy',
        'daypart-view', 'daypart-store', 'daypart-update', 'daypart-destroy',
        'ad-view', 'ad-store', 'ad-update', 'ad-destroy',
    ];

    /**
     * Platform permissions: accounts, organizations, channels and the activity log. On a platform role they
     * reach every organization. An organization's role may carry the ones in ORGANIZATION_SCOPED too, and there they reach
     * that one organization and nothing past it. Accounts are the platform's alone (owner's rule, 2026-09-17:
     * an organization's people are its Members page).
     */
    public const PLATFORM = [
        'user-view', 'user-destroy',
        'organization-view', 'organization-store', 'organization-destroy',
        'channel-view', 'channel-store', 'channel-update', 'channel-destroy',
        'activity-view', 'activity-destroy',
    ];

    /**
     * The platform permissions that also work inside an organization, for that organization alone (owner's rule,
     * 2026-09-16: the super admin decides who holds what, and an organization's people work within their organization):
     *
     *  - organization-view, organization-store, organization-destroy — the Organizations tab of Settings for the organization they work in
     *    (owner's rule, 2026-09-17: turning View Organizations off hides it), a new organization they open there and own,
     *    and deleting that organization;
     *  - channel-* — the organization's own channels, for its own screens;
     *  - activity-view — this organization's history.
     *
     * Not among them: user-view and user-destroy (accounts are the platform's; an organization's people are its Members
     * page), and activity-destroy (yearly maintenance drops a whole year of every organization's history). Nor is there any
     * permission to change or delete what the platform shares with every organization's Ad Builder — its ads and its files:
     * an organization sees them, uses them and copies the ads, and only above the organizations are they changed or deleted (owner,
     * 2026-10-01: "srif delete nahi kar sakta ha ... permission hata do").
     */
    public const ORGANIZATION_SCOPED = [
        'organization-view', 'organization-store', 'organization-destroy',
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
        'ad-view' => 'View Ads',
        'ad-store' => 'Create Ads',
        'ad-update' => 'Update Ads',
        'ad-destroy' => 'Delete Ads',
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
    ];

    protected $fillable = ['name', 'label'];

    protected $appends = ['display_name'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_has_permissions')
            ->withTimestamps();
    }

    /** Whether an organization's role may carry the named permission. One made on the Permissions page may not. */
    public static function belongsToOrganizations(string $name): bool
    {
        return in_array($name, self::ORGANIZATION, true) || in_array($name, self::ORGANIZATION_SCOPED, true);
    }

    /** Human-readable label, falling back to the raw permission name when unset. */
    public function getDisplayNameAttribute(): string
    {
        return $this->label ?: $this->name;
    }
}
