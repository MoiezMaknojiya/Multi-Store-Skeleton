<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * The rules of an organization's team (docs/ORGANIZATION-SPEC.md §5, B and C).
 *
 * Power inside an organization comes from one place — the member's role there — and every rule about
 * who may hand out or take away that power is answered here, so the Members page, the
 * invitations and Settings → Organizations can never disagree about it.
 */
class OrganizationTeam
{
    /** How often an account's deletion looks again for an organization it was put in while it waited. */
    private const LOOKS = 3;

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
     * be left with none. The facts a change depends on must therefore be read INSIDE $change — and the
     * lock is the transaction's first read: MySQL's REPEATABLE READ answers every plain read of a
     * transaction from the snapshot its first read took, so a read before the wait would hand $change
     * what was there before the other change committed.
     *
     * An organization deleted while the request was on its way has no team to change: 404, never a
     * membership of nothing (organization_user has no foreign key on the organization — 0 is the platform).
     *
     * A change that writes a person's id into a membership or an invitation names that person ($naming: the member it
     * puts in, the inviter an invitation records). Their row is read under a shared lock next, BEFORE the role, which is
     * the order every change takes them — an organization, a person, an invitation, a role: an invitation's accept holds
     * the person's row while its membership's foreign key waits for the role (InvitationResponseController::join), and an
     * account's deletion holds its row while it clears the roles it made and the invitations it sent. Held the other way
     * round — the role while the person's row was waited for, which a membership's or an invitation's foreign key reads —
     * the two could wait for each other (or for a save of that role on the Roles page, queued behind the one holding it),
     * and MySQL would end one with a deadlock, a 500. An account deleted meanwhile is 404, never a 500 from the foreign key.
     *
     * A change that gives a role passes it ($giving). The role is read again under a shared lock, after the
     * organization row and the person named, so a role deleted meanwhile is refused in words rather
     * than given — RoleController::destroy takes the role FOR UPDATE and counts its holders under that lock,
     * so it waits for a change that holds the role, and sees the person it was given to.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $change
     * @return TResult
     */
    public function changeTeam(Organization $organization, Closure $change, ?Role $giving = null, ?User $naming = null): mixed
    {
        return DB::transaction(function () use ($organization, $change, $giving, $naming) {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail(['id']);

            if ($naming !== null) {
                User::whereKey($naming->id)->sharedLock()->firstOrFail(['id']);
            }

            if ($giving !== null && Role::whereKey($giving->id)->sharedLock()->first(['id']) === null) {
                throw ValidationException::withMessages(['role_id' => 'Choose a role that exists in this organization.']);
            }

            return $change();
        });
    }

    /**
     * Run $then — a person's account deleted, and what decides whether it may be (rule 21) — in a transaction holding
     * every organization they are in, and then their own row: of two co-owners going at the same moment, the second
     * waits for the first and then sees them gone.
     *
     * Every organization they are in, not only those they own: made an Owner while this waited — by a co-owner who
     * then steps down on the strength of it — they would go unseen, and the organization be left with none. A change to
     * the team of an organization held here waits at its row (changeTeam); their own row, locked next, keeps anybody
     * from putting them in an organization not held (a membership's foreign key reads that row) until this is over.
     * That is the order every other change takes them — an organization's row, then a person's (changeTeam's $naming,
     * an invitation's accept, accounts:prune-unverified) — and no membership is locked: one locked before
     * its organization's row, the other way round from changeTeam, could meet a change there and deadlock.
     *
     * Which organizations they are in is looked up BEFORE the transaction, so that the locks, taken by id, are its first
     * statements. MySQL's REPEATABLE READ answers every plain read of a transaction from the snapshot its first plain
     * read took, and a lock takes none: the plain reads inside $then come after the wait and see what committed during it.
     *
     * Put in another organization while it waited for the locks, the person is in one this transaction does not hold:
     * it stops and starts again with that one locked too, up to LOOKS times. The last look goes on with what it holds,
     * $then still reading the newcomer as it is after the wait. An account deleted meanwhile — from the platform's
     * Users page — is 404, and $then never runs on a row that is gone.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $then
     * @return TResult
     */
    public function whileTheirOrganizationsAreLocked(User $user, Closure $then): mixed
    {
        $lookAgain = new stdClass;
        $look = 0;

        do {
            $look++;
            $locked = $this->idsOfOrganizationsOf($user);

            $outcome = DB::transaction(function () use ($user, $then, $locked, $look, $lookAgain) {
                if ($locked->isNotEmpty()) {
                    Organization::whereKey($locked)->orderBy('id')->lockForUpdate()->pluck('id');
                }

                User::whereKey($user->id)->lockForUpdate()->firstOrFail(['id']);

                if ($look < self::LOOKS && $this->idsOfOrganizationsOf($user)->diff($locked)->isNotEmpty()) {
                    return $lookAgain;
                }

                return $then();
            });
        } while ($outcome === $lookAgain);

        return $outcome;
    }

    /**
     * The organizations this person is in, whatever their role there, by id — never the platform's 0, which is no row.
     *
     * @return Collection<int, int>
     */
    private function idsOfOrganizationsOf(User $user): Collection
    {
        return DB::table('organization_user')
            ->where('user_id', $user->id)
            ->where('organization_id', '>', 0)
            ->orderBy('organization_id')
            ->pluck('organization_id');
    }

    /**
     * The organizations this person holds the Owner role in, by id.
     *
     * @return Collection<int, int>
     */
    private function idsOfOrganizationsOwnedBy(User $user): Collection
    {
        return DB::table('organization_user')
            ->where('user_id', $user->id)
            ->whereIn('role_id', Role::query()->select('id')->where('key', Role::OWNER))
            ->orderBy('organization_id')
            ->pluck('organization_id');
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
     * Plain reads, which lock nothing: the Profile and Settings pages list them on every visit. Inside an account's
     * deletion they come after whileTheirOrganizationsAreLocked's lock, so they see what committed while it waited.
     *
     * @return Collection<int, Organization>
     */
    public function organizationsSolelyOwnedBy(User $user): Collection
    {
        $soleIds = DB::table('organization_user')
            ->whereIn('organization_id', $this->idsOfOrganizationsOwnedBy($user))
            ->whereIn('role_id', Role::query()->select('id')->where('key', Role::OWNER))
            ->groupBy('organization_id')
            ->havingRaw('count(*) = 1')
            ->pluck('organization_id');

        return Organization::whereIn('id', $soleIds)->orderBy('name')->get();
    }
}
