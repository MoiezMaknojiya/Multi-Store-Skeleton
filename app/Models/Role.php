<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Four kinds of role (docs/STORE-ORGANIZATION-SPEC.md §2 — owner's rules, 2026-09-17):
 *
 *  - Super-Admin — the platform's top role, by its exact name; never edited.
 *  - Store role  — not global, no store: made by the super admin and offered in EVERY store. One of them
 *    is the Owner role (key `owner`): whoever holds it owns the store. Its name and permissions change
 *    like any other; it is never deleted.
 *  - Custom role — not global, store_id set: made inside one store by its members, and belongs to it.
 *  - Platform role — global: the platform team's roles (store_id = 0 memberships), made by the super admin.
 *
 * Nothing is built in beyond Super-Admin and the Owner marker: every other store role — the starters an
 * installation begins with included — is named, changed and deleted like one the super admin made.
 */
class Role extends Model
{
    /** The Owner role's key: the one mark a rule reads (ownership, signup, "at least one Owner"). */
    public const OWNER = 'owner';

    /** Starter store roles' keys — they identify the roles a new installation begins with, and nothing more. */
    public const ADMIN = 'admin';

    public const STAFF = 'staff';

    public const VIEWER = 'viewer';

    /**
     * The name of the platform's top role. It is compared in PHP, never in SQL: MySQL's accent-
     * and case-insensitive collation (utf8mb4_0900_ai_ci) treats "Súper-Admin" as this very
     * name, so a WHERE on it would let a look-alike role pass for the real one.
     */
    public const SUPER_ADMIN = 'Super-Admin';

    /**
     * The store roles a new installation begins with, and the permissions each starts with. After that
     * they are ordinary store roles: the super admin renames, changes and (all but the Owner role) deletes
     * them, so the database — not this list — says what a role allows. The migrations keep their own copy of
     * it (they never read the models), and the seeder reads only the Owner entry, to put that role back when
     * it is missing.
     *
     * A null permission list (Admin) means every store permission, and the Stores tab of Settings. Deleting the
     * store starts with the Owner role alone.
     */
    public const STARTERS = [
        self::OWNER => [
            'name' => 'Owner',
            'permissions' => [...Permission::STORE, 'store-view', 'store-destroy'],
        ],
        self::ADMIN => [
            'name' => 'Admin',
            'permissions' => null,
        ],
        self::STAFF => [
            'name' => 'Staff',
            'permissions' => ['screen-view', 'screen-playlist', 'media-view', 'media-store', 'media-update', 'media-destroy', 'daypart-view'],
        ],
        self::VIEWER => [
            'name' => 'Viewer',
            'permissions' => ['screen-view', 'media-view', 'daypart-view'],
        ],
    ];

    protected $fillable = ['name', 'key', 'created_by', 'is_global', 'store_id'];

    protected function casts(): array
    {
        return [
            'is_global' => 'boolean',
        ];
    }

    /**
     * The permission names a starter role begins with.
     *
     * @return array<int, string>
     */
    public static function starterPermissions(string $key): array
    {
        return self::STARTERS[$key]['permissions'] ?? [...Permission::STORE, 'store-view'];
    }

    /** A starter store role, by its key — while the installation still has it. */
    public static function starter(string $key): self
    {
        return self::where('key', $key)->firstOrFail();
    }

    /** The Owner role: the store role whose holders own their store. */
    public static function owner(): self
    {
        return self::starter(self::OWNER);
    }

    /** Whether this is the Owner role. */
    public function isOwner(): bool
    {
        return $this->key === self::OWNER;
    }

    public function isSuperAdmin(): bool
    {
        return $this->name === self::SUPER_ADMIN;
    }

    /** The id of the one genuine Super-Admin role — matched on the exact name (see SUPER_ADMIN). */
    public static function superAdminId(): ?int
    {
        return self::where('name', self::SUPER_ADMIN)
            ->orderBy('id')
            ->get(['id', 'name'])
            ->first(fn (self $role) => $role->isSuperAdmin())
            ?->id;
    }

    /** A platform role, held on the store_id = 0 row. Super-Admin is one whatever its flag says. */
    public function isGlobal(): bool
    {
        return $this->is_global || $this->isSuperAdmin();
    }

    /** A store role: made by the super admin and offered in every store (the Owner role is one). */
    public function isStoreRole(): bool
    {
        return ! $this->isGlobal() && $this->store_id === null;
    }

    /** A custom role: made inside one store, and that store's alone. */
    public function isCustomRole(): bool
    {
        return ! $this->isGlobal() && $this->store_id !== null;
    }

    /**
     * What a picker, the roles list or an invitation shows under the role's name: what the role allows,
     * read from its permissions — so it is true whatever the role is called or has been changed to.
     */
    public function description(): string
    {
        if ($this->isSuperAdmin()) {
            return 'Full control of the platform: every store, every account and the permission catalogue.';
        }

        $labels = $this->permissions->sortBy('id')->map(fn (Permission $permission) => $permission->display_name)->values();

        $allows = match (true) {
            $labels->isEmpty() => 'Allows nothing yet.',
            $labels->count() <= 3 => 'Allows '.$labels->join(', ', ' and ').'.',
            default => 'Allows '.$labels->take(3)->join(', ').' and '.($labels->count() - 3).' more.',
        };

        return match (true) {
            $this->isOwner() => "Owns the store. {$allows}",
            $this->isGlobal() => "Platform team. {$allows}",
            default => $allows,
        };
    }

    /** The roles a member of the given store can hold: every store role, and that store's own custom roles. */
    public function scopeAvailableInStore(Builder $query, int $storeId): Builder
    {
        return $query->where('is_global', false)
            ->when(self::superAdminId(), fn (Builder $q, int $superAdminId) => $q->whereKeyNot($superAdminId))
            ->where(fn (Builder $q) => $q->whereNull('store_id')->orWhere('store_id', $storeId));
    }

    /** The store roles: offered in every store, the Owner role among them. */
    public function scopeStoreRoles(Builder $query): Builder
    {
        return $query->where('is_global', false)
            ->whereNull('store_id')
            ->when(self::superAdminId(), fn (Builder $q, int $superAdminId) => $q->whereKeyNot($superAdminId));
    }

    /** The platform team's roles: Super-Admin and every global role. */
    public function scopePlatform(Builder $query): Builder
    {
        $superAdminId = self::superAdminId();

        return $query->where(fn (Builder $q) => $q->where('is_global', true)
            ->when($superAdminId, fn (Builder $q) => $q->orWhere('id', $superAdminId)));
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_has_permissions')->withTimestamps();
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** The open invitations that would give this role. */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /** Everyone holding this role, in any store or on the platform (store_id = 0). */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'store_user')->withPivot('store_id')->withTimestamps();
    }
}
