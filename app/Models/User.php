<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

/**
 * An account: a login and nothing more (docs/STORE-ORGANIZATION-SPEC.md rule 1). Nobody owns it,
 * and it gives no power by itself — power comes from memberships (`store_user`): a role in a
 * store, or a platform role on the store_id = 0 row.
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'first_name', 'last_name', 'phone', 'email', 'password',
    ];

    /**
     * A deleted account leaves nothing pointing at it (owner's rules, 2026-09-17): its memberships go through their
     * foreign key, what it made stays with its stores (`created_by` empties the same way) — and here its sign-ins on
     * every device, its password-reset link and every invitation waiting for its email go too, and the activity
     * log forgets whose id it was: on MySQL that table is partitioned and cannot carry a foreign key, so nothing
     * else would empty `actor_id` (the entry keeps the person's name in `actor_name`).
     */
    protected static function booted(): void
    {
        static::deleted(function (User $user) {
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }

            DB::table(config('auth.passwords.users.table', 'password_reset_tokens'))->where('email', $user->email)->delete();
            $user->invitationsToEmail()->delete();
            DB::table('activity_logs')->where('actor_id', $user->id)->update(['actor_id' => null]);
        });
    }

    /** The invitations addressed to this account's email — to any store or to the platform team, expired ones included. */
    public function invitationsToEmail(): Builder
    {
        return Invitation::where('email', Invitation::normalizeEmail($this->email));
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
    public function refresh(): static
    {
        $this->isSuperAdminMemo = null;
        $this->globalRoleResolved = false;
        $this->globalRoleMemo = null;
        $this->permissionNamesMemo = [];

        return parent::refresh();
    }

    /** Super-Admin is a platform role, so it is held on the store_id = 0 row (the tiers are exclusive). */
    public function isSuperAdmin(): bool
    {
        return $this->isSuperAdminMemo ??= ($this->globalRole()?->isSuperAdmin() ?? false);
    }

    /**
     * The primary super admin: the first person ever given the Super-Admin role. Nobody else
     * may delete them or take their role, and only they may do either to another super admin
     * (docs/STORE-ORGANIZATION-SPEC.md rule 24).
     */
    public static function primarySuperAdminId(): ?int
    {
        $roleId = Role::superAdminId();

        $userId = $roleId === null ? null : DB::table('store_user')
            ->where('role_id', $roleId)
            ->orderBy('id')
            ->value('user_id');

        return $userId === null ? null : (int) $userId;
    }

    public function isPrimarySuperAdmin(): bool
    {
        return $this->id === self::primarySuperAdminId();
    }

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** The stores this person is a member of (the platform row, store_id = 0, has no store and is not among them). */
    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'store_user')
            ->withPivot('role_id')
            ->withTimestamps();
    }

    /**
     * The role this person holds in the currently selected store.
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
     * Whether Settings has a Stores tab for this person (owner's rule, 2026-09-17): a store member working in
     * a store whose role there holds View Stores. Without it "Your stores" stays on the profile, so anybody
     * can still leave a store.
     */
    public function hasStoresTab(): bool
    {
        return $this->globalRole() === null && (bool) session('current_store_id') && $this->can('store-view');
    }

    /**
     * The platform role, held on the store_id = 0 row, if any: Super-Admin, or any role the
     * super admin made for the platform team.
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
     * Whether this person holds a permission where they stand: their platform role when they have one
     * (the tiers are exclusive, so it always wins — a store left in the session changes nothing),
     * otherwise their role in the selected store.
     */
    public function hasPermissionInCurrentStore(string $permissionName): bool
    {
        return in_array($permissionName, $this->contextPermissionNames(), true);
    }

    /**
     * Every permission name the user holds in the current context (their platform
     * role, or else the session's store role). Loaded ONCE per request per context —
     * a page render fires a dozen @can checks and they all share this list.
     *
     * @return array<int, string>
     */
    public function contextPermissionNames(): array
    {
        // The platform team never works inside a store (the tiers are exclusive), so a store left
        // in a platform account's session can never swap its platform role for no role at all.
        $platformRole = $this->globalRole();
        $key = $platformRole !== null ? 'platform' : 'store:'.(int) session('current_store_id');

        if (! array_key_exists($key, $this->permissionNamesMemo)) {
            $role = $platformRole ?? (session('current_store_id') ? $this->currentRole() : null);

            $this->permissionNamesMemo[$key] = $role
                ? $role->permissions()->pluck('name')->all()
                : [];
        }

        return $this->permissionNamesMemo[$key];
    }
}
