<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'first_name', 'last_name', 'phone', 'email', 'password', 'created_by',
    ];

    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        $query->where('id', '!=', $viewer->id);

        // People are NOT store-scoped, and that is deliberate (owner's decision):
        // whoever you created stays yours in every store you work in, so you can
        // staff another of your own stores without going to an admin. The ROLE is
        // the part that never travels — see Role::visibleTo.
        if (! $viewer->isSuperAdmin()) {
            return $query->where('created_by', $viewer->id);
        }

        // A super admin sees every regular user, but other super admins stay hidden
        // unless the viewer is the one who created them — so a promoted super admin
        // cannot see (or act on) the admin who promoted them.
        $superAdminIds = DB::table('store_user')
            ->join('roles', 'store_user.role_id', '=', 'roles.id')
            ->where('roles.name', 'Super-Admin')
            ->select('store_user.user_id');

        return $query->where(fn (Builder $q) => $q
            ->whereNotIn('id', $superAdminIds)
            ->orWhere('created_by', $viewer->id));
    }

    public function getNameAttribute(): string
    {
        return trim($this->{'first_name'}.' '.$this->{'last_name'});
    }

    /* ── Per-request memo caches ──────────────────────────────────────────
     * A model instance lives for one request, and these lookups get hit by
     * every @can check, scope, and blade config — without memoization a single
     * page render repeats the same queries dozens of times. */
    private ?bool $isSuperAdminMemo = null;

    private bool $globalRoleResolved = false;

    private ?Role $globalRoleMemo = null;

    /** @var array<string, array<int, string>> permission names keyed by store context */
    private array $permissionNamesMemo = [];

    /** refresh() also flushes the permission memos, so a refreshed instance
     *  re-reads its roles/permissions from the database. */
    public function refresh()
    {
        $this->isSuperAdminMemo = null;
        $this->globalRoleResolved = false;
        $this->globalRoleMemo = null;
        $this->permissionNamesMemo = [];

        return parent::refresh();
    }

    public function isSuperAdmin(): bool
    {
        // Check if any store assignment has a role named "Super-Admin"
        return $this->isSuperAdminMemo ??= DB::table('store_user')
            ->join('roles', 'store_user.role_id', '=', 'roles.id')
            ->where('store_user.user_id', $this->id)
            ->where('roles.name', 'Super-Admin')
            ->exists();
    }

    /**
     * The id of the (first) user holding the Super-Admin role — used to attribute
     * self-registered owners so they appear in the admin's user listing.
     */
    public static function firstSuperAdminId(): ?int
    {
        return DB::table('store_user')
            ->join('roles', 'store_user.role_id', '=', 'roles.id')
            ->where('roles.name', 'Super-Admin')
            ->orderBy('store_user.id')
            ->value('store_user.user_id');
    }

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // Relationships
    public function stores()
    {
        return $this->belongsToMany(Store::class, 'store_user')
            ->withPivot('role_id', 'created_at')
            ->withTimestamps();
    }

    /**
     * Get the user's role in the currently selected store.
     */
    public function currentRole(): ?Role
    {
        $storeId = session('current_store_id');
        if (! $storeId) {
            return null;
        }

        $pivot = $this->stores()->wherePivot('store_id', $storeId)->first();
        if (! $pivot || ! $pivot->pivot->role_id) {
            return null;
        }

        return Role::find($pivot->pivot->role_id);
    }

    /**
     * The role held on the global sentinel row (store_id = 0), if any:
     * Super-Admin, or any custom role marked is_global.
     */
    public function globalRole(): ?Role
    {
        if (! $this->globalRoleResolved) {
            $roleId = DB::table('store_user')
                ->where('user_id', $this->id)
                ->where('store_id', 0)
                ->value('role_id');

            $this->globalRoleMemo = $roleId ? Role::find($roleId) : null;
            $this->globalRoleResolved = true;
        }

        return $this->globalRoleMemo;
    }

    /**
     * Check if the user has a specific permission in the current store context.
     * With no store selected, the user's global role (the store_id = 0 sentinel
     * row) applies — Super-Admin, or any custom global role.
     */
    public function hasPermissionInCurrentStore(string $permissionName): bool
    {
        return in_array($permissionName, $this->contextPermissionNames(), true);
    }

    /**
     * Every permission name the user holds in the current context (the session's
     * store role, or their global role). Loaded ONCE per request per context —
     * a page render fires a dozen @can checks and they all share this list.
     *
     * @return array<int, string>
     */
    public function contextPermissionNames(): array
    {
        $key = (string) (session('current_store_id') ?? 'global');

        if (! array_key_exists($key, $this->permissionNamesMemo)) {
            $role = session('current_store_id') ? $this->currentRole() : $this->globalRole();

            $this->permissionNamesMemo[$key] = $role
                ? $role->permissions()->pluck('name')->all()
                : [];
        }

        return $this->permissionNamesMemo[$key];
    }

    /**
     * The owner's rule: deleting a user deletes EVERYTHING they created,
     * recursively — their users (the whole subtree), the roles they created
     * (unless still assigned to a surviving user), and the stores they created.
     * Hard delete for users, by explicit decision. Returns counts for the log.
     *
     * @return array{users: int, roles: int, stores: int}
     */
    public function deleteCascade(): array
    {
        // Collect the whole created_by subtree, level by level.
        $subtreeIds = [];
        $queue = [$this->id];
        while ($queue !== []) {
            $queue = User::whereIn('created_by', $queue)->pluck('id')->all();
            $subtreeIds = array_merge($subtreeIds, $queue);
        }
        $allIds = array_merge($subtreeIds, [$this->id]);

        $createdRoleIds = Role::whereIn('created_by', $allIds)->pluck('id');
        $createdStoreIds = Store::whereIn('created_by', $allIds)->pluck('id');

        // Users first: their store_user rows cascade away at the DB level.
        User::whereIn('id', $allIds)->delete();

        // Their roles — but a role still assigned to a SURVIVING user stays alive
        // (its created_by just went null via the FK); deleting it would strip them.
        $deletableRoleIds = Role::whereIn('id', $createdRoleIds)->whereDoesntHave('users')->pluck('id');
        DB::table('role_has_permissions')->whereIn('role_id', $deletableRoleIds)->delete();
        Role::whereIn('id', $deletableRoleIds)->delete();

        // Their stores: soft-deleted (matching store behavior) with users detached.
        $stores = Store::whereIn('id', $createdStoreIds)->get();
        foreach ($stores as $store) {
            $store->users()->detach();
            $store->delete();
        }

        return [
            'users' => count($subtreeIds),
            'roles' => $deletableRoleIds->count(),
            'stores' => $stores->count(),
        ];
    }

    public function assignablePermissions(): Builder
    {
        if ($this->isSuperAdmin()) {
            return Permission::query();
        }

        $role = session('current_store_id') ? $this->currentRole() : $this->globalRole();
        if (! $role) {
            return Permission::query()->whereRaw('0 = 1');
        }

        return Permission::query()->whereIn(
            'id',
            $role->permissions()->select('permissions.id')
        );
    }
}
