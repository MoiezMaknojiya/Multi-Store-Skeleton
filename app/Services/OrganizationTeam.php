<?php

namespace App\Services;

use App\Models\Role;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The rules of an organization's team (docs/ORGANIZATION-SPEC.md §5, B and C).
 *
 * Power inside an organization comes from one place — the member's role there — and every rule about
 * who may hand out or take away that power is answered here, so the Members page, the
 * invitations and Settings → Organizations can never disagree about it.
 */
class OrganizationTeam
{
    /** The role a person holds in an organization; null when they are not a member of it. */
    public function roleOf(User $user, Organization $organization): ?Role
    {
        $roleId = DB::table('organization_user')
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->value('role_id');

        return $roleId ? Role::find($roleId) : null;
    }

    public function isMember(User $user, Organization $organization): bool
    {
        return DB::table('organization_user')->where('organization_id', $organization->id)->where('user_id', $user->id)->exists();
    }

    /**
     * The permission names a person holds in an organization — none when they are not a member.
     *
     * @return Collection<int, string>
     */
    public function permissionsOf(User $user, Organization $organization): Collection
    {
        return $this->roleOf($user, $organization)?->permissions()->pluck('name') ?? collect();
    }

    public function isOwner(User $user, Organization $organization): bool
    {
        return $this->roleOf($user, $organization)?->isOwner() === true;
    }

    public function ownerCount(Organization $organization): int
    {
        return DB::table('organization_user')
            ->join('roles', 'roles.id', '=', 'organization_user.role_id')
            ->where('organization_user.organization_id', $organization->id)
            ->where('roles.key', Role::OWNER)
            ->count();
    }

    /**
     * Rule 7 — may the actor give this role to somebody here? The role must belong to the organization (an organization
     * role, or this organization's custom role), and nobody hands out access they do not hold themselves. Which role
     * the actor holds does not matter — the Owner role is given like any other (owner's rule, 2026-09-17).
     */
    public function mayAssign(User $actor, Organization $organization, Role $role): bool
    {
        if (! Role::availableInOrganization($organization->id)->whereKey($role->id)->exists()) {
            return false;
        }

        return $this->roleIsWithinReach($this->permissionsOf($actor, $organization), $role);
    }

    /**
     * Rule 8 — may the actor change or remove this member? Never themselves, and only when the actor holds
     * every permission the member's role holds — an Owner included.
     */
    public function mayManage(User $actor, Organization $organization, User $member): bool
    {
        if ($actor->is($member)) {
            return false;
        }

        $role = $this->roleOf($member, $organization);

        return $role !== null && $this->roleIsWithinReach($this->permissionsOf($actor, $organization), $role);
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
     * Every role a member of this organization can hold, with permissions loaded, in picker order: the Owner
     * role, the other organization roles, then this organization's own custom roles — each by name.
     *
     * @return Collection<int, Role>
     */
    public function rolesOf(Organization $organization): Collection
    {
        return Role::availableInOrganization($organization->id)
            ->with('permissions:id,name,label')
            ->get()
            ->sortBy(fn (Role $role) => [$role->isOwner() ? 0 : ($role->isOrganizationRole() ? 1 : 2), strtolower($role->name)])
            ->values();
    }

    /**
     * Run a change to an organization's team with the organization row locked (rule 9).
     *
     * Two co-owners demoting, removing or leaving at the same moment would otherwise both pass
     * the "at least one Owner" test on what they read before either wrote, and the organization would
     * be left with none. The facts a change depends on must therefore be read INSIDE $change.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $change
     * @return TResult
     */
    public function changeTeam(Organization $organization, Closure $change): mixed
    {
        return DB::transaction(function () use ($organization, $change) {
            Organization::whereKey($organization->id)->lockForUpdate()->first(['id']);

            return $change();
        });
    }

    /** Lock every organization this person owns — call inside a transaction, before deciding whether they may go. */
    public function lockOrganizationsOwnedBy(User $user): void
    {
        $organizationIds = DB::table('organization_user')
            ->where('user_id', $user->id)
            ->where('role_id', Role::where('key', Role::OWNER)->value('id'))
            ->pluck('organization_id');

        Organization::whereIn('id', $organizationIds)->orderBy('id')->lockForUpdate()->get(['id']);
    }

    /** Rule 10 — anyone may leave an organization except its last Owner. */
    public function mayLeave(User $user, Organization $organization): bool
    {
        return ! ($this->isOwner($user, $organization) && $this->ownerCount($organization) === 1);
    }

    /** Take a person out of an organization at their own request. False, and nothing changed, for its last Owner. */
    public function leave(User $user, Organization $organization): bool
    {
        return $this->changeTeam($organization, function () use ($user, $organization) {
            if (! $this->mayLeave($user, $organization)) {
                return false;
            }

            DB::table('organization_user')->where('organization_id', $organization->id)->where('user_id', $user->id)->delete();

            return true;
        });
    }

    /**
     * The organizations a person belongs to, by name, each with the role they hold there and whether they are its only
     * Owner — who cannot leave (rule 10). "Your organizations" lists them, on Settings → Organizations or on the profile.
     *
     * @return Collection<int, array{organization: Organization, role: ?Role, is_sole_owner: bool}>
     */
    public function membershipsOf(User $user): Collection
    {
        $organizations = $user->organizations()->orderBy('name')->get();
        $roles = Role::whereIn('id', $organizations->pluck('pivot.role_id'))->get()->keyBy('id');
        $soleOwnerOf = $this->organizationsSolelyOwnedBy($user)->pluck('id');

        return $organizations->map(fn (Organization $organization) => [
            'organization' => $organization,
            'role' => $roles->get($organization->pivot->role_id),
            'is_sole_owner' => $soleOwnerOf->contains($organization->id),
        ]);
    }

    /**
     * The organizations in which this person is the only Owner — the ones that would be left without
     * an owner if they went (rules 9 and 21).
     *
     * @return Collection<int, Organization>
     */
    public function organizationsSolelyOwnedBy(User $user): Collection
    {
        $ownerRoleId = Role::where('key', Role::OWNER)->value('id');

        $owned = DB::table('organization_user')
            ->where('user_id', $user->id)
            ->where('role_id', $ownerRoleId)
            ->pluck('organization_id');

        $soleIds = DB::table('organization_user')
            ->whereIn('organization_id', $owned)
            ->where('role_id', $ownerRoleId)
            ->groupBy('organization_id')
            ->havingRaw('count(*) = 1')
            ->pluck('organization_id');

        return Organization::whereIn('id', $soleIds)->orderBy('name')->get();
    }
}
