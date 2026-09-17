<?php

namespace App\Services;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The rules of a store's team (docs/STORE-ORGANIZATION-SPEC.md §5, B and C).
 *
 * Power inside a store comes from one place — the member's role there — and every rule about
 * who may hand out or take away that power is answered here, so the Members page, the
 * invitations and Settings → Stores can never disagree about it.
 */
class StoreTeam
{
    /** The role a person holds in a store; null when they are not a member of it. */
    public function roleOf(User $user, Store $store): ?Role
    {
        $roleId = DB::table('store_user')
            ->where('store_id', $store->id)
            ->where('user_id', $user->id)
            ->value('role_id');

        return $roleId ? Role::find($roleId) : null;
    }

    public function isMember(User $user, Store $store): bool
    {
        return DB::table('store_user')->where('store_id', $store->id)->where('user_id', $user->id)->exists();
    }

    /**
     * The permission names a person holds in a store — none when they are not a member.
     *
     * @return Collection<int, string>
     */
    public function permissionsOf(User $user, Store $store): Collection
    {
        return $this->roleOf($user, $store)?->permissions()->pluck('name') ?? collect();
    }

    public function isOwner(User $user, Store $store): bool
    {
        return $this->roleOf($user, $store)?->isOwner() === true;
    }

    public function ownerCount(Store $store): int
    {
        return DB::table('store_user')
            ->join('roles', 'roles.id', '=', 'store_user.role_id')
            ->where('store_user.store_id', $store->id)
            ->where('roles.key', Role::OWNER)
            ->count();
    }

    /**
     * Rule 7 — may the actor give this role to somebody here? The role must belong to the store (a store
     * role, or this store's custom role), and nobody hands out access they do not hold themselves. Which role
     * the actor holds does not matter — the Owner role is given like any other (owner's rule, 2026-09-17).
     */
    public function mayAssign(User $actor, Store $store, Role $role): bool
    {
        if (! Role::availableInStore($store->id)->whereKey($role->id)->exists()) {
            return false;
        }

        return $this->roleIsWithinReach($this->permissionsOf($actor, $store), $role);
    }

    /**
     * Rule 8 — may the actor change or remove this member? Never themselves, and only when the actor holds
     * every permission the member's role holds — an Owner included.
     */
    public function mayManage(User $actor, Store $store, User $member): bool
    {
        if ($actor->is($member)) {
            return false;
        }

        $role = $this->roleOf($member, $store);

        return $role !== null && $this->roleIsWithinReach($this->permissionsOf($actor, $store), $role);
    }

    /**
     * The test shared by rules 7 and 8, for callers that already hold the actor's permissions: every
     * permission of the role is one the actor holds. The members list asks it once per row without a query
     * per row (eager-load permissions).
     *
     * @param  Collection<int, string>  $actorPermissions
     */
    public function roleIsWithinReach(Collection $actorPermissions, Role $role): bool
    {
        return $role->permissions->pluck('name')->diff($actorPermissions)->isEmpty();
    }

    /**
     * Every role a member of this store can hold, with permissions loaded, in picker order: the Owner
     * role, the other store roles, then this store's own custom roles — each by name.
     *
     * @return Collection<int, Role>
     */
    public function rolesOf(Store $store): Collection
    {
        return Role::availableInStore($store->id)
            ->with('permissions:id,name,label')
            ->get()
            ->sortBy(fn (Role $role) => [$role->isOwner() ? 0 : ($role->isStoreRole() ? 1 : 2), strtolower($role->name)])
            ->values();
    }

    /**
     * Run a change to a store's team with the store row locked (rule 9).
     *
     * Two co-owners demoting, removing or leaving at the same moment would otherwise both pass
     * the "at least one Owner" test on what they read before either wrote, and the store would
     * be left with none. The facts a change depends on must therefore be read INSIDE $change.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $change
     * @return TResult
     */
    public function changeTeam(Store $store, Closure $change): mixed
    {
        return DB::transaction(function () use ($store, $change) {
            Store::whereKey($store->id)->lockForUpdate()->first(['id']);

            return $change();
        });
    }

    /** Lock every store this person owns — call inside a transaction, before deciding whether they may go. */
    public function lockStoresOwnedBy(User $user): void
    {
        $storeIds = DB::table('store_user')
            ->where('user_id', $user->id)
            ->where('role_id', Role::where('key', Role::OWNER)->value('id'))
            ->pluck('store_id');

        Store::whereIn('id', $storeIds)->orderBy('id')->lockForUpdate()->get(['id']);
    }

    /** Rule 10 — anyone may leave a store except its last Owner. */
    public function mayLeave(User $user, Store $store): bool
    {
        return ! ($this->isOwner($user, $store) && $this->ownerCount($store) === 1);
    }

    /** Take a person out of a store at their own request. False, and nothing changed, for its last Owner. */
    public function leave(User $user, Store $store): bool
    {
        return $this->changeTeam($store, function () use ($user, $store) {
            if (! $this->mayLeave($user, $store)) {
                return false;
            }

            DB::table('store_user')->where('store_id', $store->id)->where('user_id', $user->id)->delete();

            return true;
        });
    }

    /**
     * The stores a person belongs to, by name, each with the role they hold there and whether they are its only
     * Owner — who cannot leave (rule 10). "Your stores" lists them, on Settings → Stores or on the profile.
     *
     * @return Collection<int, array{store: Store, role: ?Role, is_sole_owner: bool}>
     */
    public function membershipsOf(User $user): Collection
    {
        $stores = $user->stores()->orderBy('name')->get();
        $roles = Role::whereIn('id', $stores->pluck('pivot.role_id'))->get()->keyBy('id');
        $soleOwnerOf = $this->storesSolelyOwnedBy($user)->pluck('id');

        return $stores->map(fn (Store $store) => [
            'store' => $store,
            'role' => $roles->get($store->pivot->role_id),
            'is_sole_owner' => $soleOwnerOf->contains($store->id),
        ]);
    }

    /**
     * The stores in which this person is the only Owner — the ones that would be left without
     * an owner if they went (rules 9 and 21).
     *
     * @return Collection<int, Store>
     */
    public function storesSolelyOwnedBy(User $user): Collection
    {
        $ownerRoleId = Role::where('key', Role::OWNER)->value('id');

        $owned = DB::table('store_user')
            ->where('user_id', $user->id)
            ->where('role_id', $ownerRoleId)
            ->pluck('store_id');

        $soleIds = DB::table('store_user')
            ->whereIn('store_id', $owned)
            ->where('role_id', $ownerRoleId)
            ->groupBy('store_id')
            ->havingRaw('count(*) = 1')
            ->pluck('store_id');

        return Store::whereIn('id', $soleIds)->orderBy('name')->get();
    }
}
