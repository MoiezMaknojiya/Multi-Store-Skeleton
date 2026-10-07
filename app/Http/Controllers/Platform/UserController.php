<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Controllers\Concerns\HandlesCrudData;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationTeam;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Accounts (docs/ORGANIZATION-SPEC.md rules 13, 21, 24) — the platform's alone (owner's rule, 2026-09-17:
 * an organization's people are its Members page). Nobody's details are edited here — people manage their own. The
 * platform looks at an account, logs in as it, puts it in an organization with a role, changes or takes away that role
 * (super admins — Organizations), takes away a platform role, or deletes the account.
 */
class UserController extends Controller
{
    use ConfirmsPassword, HandlesCrudData;

    public function __construct(private OrganizationTeam $team) {}

    public function index(Request $request): View
    {
        $viewer = $request->user();

        return view('users.index', [
            // Super-Admin is offered only to the primary super admin, the one who can also take it away.
            'platformRoles' => $viewer->isSuperAdmin()
                ? Role::platform()->orderBy('name')->get(['id', 'name'])
                    ->reject(fn (Role $role) => $role->isSuperAdmin() && ! $viewer->isPrimarySuperAdmin())
                    ->values()
                : collect(),
        ]);
    }

    /** Accounts with what each one can reach, and what the viewer may do to it. */
    public function data(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $platformTeam = DB::table('organization_user')->where('organization_id', 0)->select('user_id');

        $query = $this->sortedBy(User::query(), $request);

        // The platform team is the super admins' business; support sees customers only.
        if (! $viewer->isSuperAdmin()) {
            $query->whereNotIn('id', $platformTeam);
        } else {
            // The super admin's tabs (owner, 2026-10-07): the platform team, or everybody outside it — anything else is all.
            match (self::plainValue($request->input('group'))) {
                'platform' => $query->whereIn('id', $platformTeam),
                'organization' => $query->whereNotIn('id', $platformTeam),
                default => null,
            };
        }

        $response = $this->paginatedResponse(
            $request, $query, ['first_name', 'last_name', 'email'], 'users',
            ['id', 'first_name', 'last_name', 'email', 'created_at'],
            fn (Collection $users) => $this->attachAccess($users, $viewer),
            // A full name as it is shown ("Ali Khan"): its first word in the first name and the rest in the last.
            function (Builder $name, string $search) {
                $words = preg_split('/\s+/', trim($search), 2);

                if (count($words) === 2) {
                    $name->orWhere(fn (Builder $both) => $both
                        ->where('first_name', 'like', "%{$words[0]}%")
                        ->where('last_name', 'like', "%{$words[1]}%"));
                }
            }
        );

        // Each tab says how many it holds, whatever is searched: the super admin's alone, who sees both sides.
        if ($viewer->isSuperAdmin()) {
            $response->setData([...$response->getData(true), 'counts' => [
                'all' => User::count(),
                'platform' => User::whereIn('id', $platformTeam)->count(),
                'organization' => User::whereNotIn('id', $platformTeam)->count(),
            ]]);
        }

        return $response;
    }

    /**
     * The accounts in the order their headings ask for (owner, 2026-10-07: "sorting bhi"): `sort` name or joined,
     * `direction` asc or desc — anything else, or nothing, A to Z by name as the list always was, capitals or not. The
     * id keeps an order steady between pages.
     */
    private function sortedBy(Builder $query, Request $request): Builder
    {
        $sort = self::plainValue($request->input('sort'));
        $asked = self::plainValue($request->input('direction'));

        if ($sort === 'joined') {
            $direction = $asked === 'asc' ? 'asc' : 'desc';

            return $query->orderBy('created_at', $direction)->orderBy('id', $direction);
        }

        // By name, whatever the case: Z to A only when asked for by name.
        $direction = $sort === 'name' && $asked === 'desc' ? 'desc' : 'asc';

        return $query->orderByRaw('lower(first_name) '.$direction)->orderByRaw('lower(last_name) '.$direction)->orderBy('id', $direction);
    }

    /** Delete an account: the account, its memberships and what points at it — nothing else (rule 21). */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        $this->ensureReachable($actor, $user);

        if ($actor->is($user)) {
            return response()->json(['message' => 'You cannot delete your own account here.'], 422);
        }

        abort_if($user->isPrimarySuperAdmin(), 403, 'The primary super admin cannot be deleted.');
        abort_if($user->isSuperAdmin() && ! $actor->isPrimarySuperAdmin(), 403, 'Only the primary super admin can delete a super admin.');

        $this->confirmPassword($request);

        $ownerless = $this->team->organizationsSolelyOwnedBy($user)->pluck('name');
        $withdrawn = $user->invitationsToEmail()->count();
        $name = $user->name;
        $email = $user->email;

        $user->delete();

        ActivityLog::record('user.deleted', null, "Deleted the account of {$name} ({$email})"
            .($withdrawn > 0 ? " and the {$withdrawn} pending ".str('invitation')->plural($withdrawn).' to that email' : '')
            .($ownerless->isNotEmpty() ? ' — left without an Owner: '.$ownerless->join(', ') : ''));

        return response()->json([
            'message' => "{$name}'s account was deleted.",
            'ownerless_organizations' => $ownerless->values(),
        ]);
    }

    /** Take a platform role away (rule 24). The account stays, with no access at all. */
    public function removePlatformRole(Request $request, User $user): JsonResponse
    {
        $actor = auth()->user();
        $roleId = DB::table('organization_user')->where('user_id', $user->id)->where('organization_id', 0)->value('role_id') ?? abort(404);

        if ($actor->is($user)) {
            return response()->json(['message' => 'You cannot remove your own platform role.'], 422);
        }

        abort_if($user->isPrimarySuperAdmin(), 403, 'The primary super admin keeps their role.');
        abort_if($user->isSuperAdmin() && ! $actor->isPrimarySuperAdmin(), 403, 'Only the primary super admin can remove Super-Admin from someone.');

        $this->confirmPassword($request);

        DB::table('organization_user')->where('user_id', $user->id)->where('organization_id', 0)->delete();

        $roleName = Role::find($roleId)?->name;
        ActivityLog::record('platform.role_removed', $user, "Removed the platform role {$roleName} from {$user->name} ({$user->email})");

        return response()->json(['message' => "{$user->name} is no longer on the platform team."]);
    }

    /**
     * A person's place in the organizations, for the platform's Manage organizations form: the organizations they belong to with
     * their role in each, the organizations they could be added to, and the roles on offer — the organization roles every
     * organization has, and each organization's own custom roles.
     */
    public function organizationAccess(User $user): JsonResponse
    {
        $memberships = DB::table('organization_user')
            ->join('organizations', 'organizations.id', '=', 'organization_user.organization_id')
            ->where('organization_user.user_id', $user->id)
            ->orderBy('organizations.name')
            ->get(['organizations.id', 'organizations.name', 'organization_user.role_id']);

        $rolePayload = fn (Role $role) => [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description(),
        ];

        return response()->json([
            'memberships' => $memberships->map(fn (object $row) => [
                'organization_id' => (int) $row->id,
                'organization_name' => $row->name,
                'role_id' => (int) $row->role_id,
            ])->values(),
            'organizations' => Organization::whereNotIn('id', $memberships->pluck('id'))->orderBy('name')->get(['id', 'name', 'city']),
            'organization_roles' => Role::organizationRoles()->with('permissions:id,name,label')->get()
                ->sortBy(fn (Role $role) => [$role->isOwner() ? 0 : 1, strtolower($role->name)])
                ->map($rolePayload)->values(),
            // Keyed by organization id — an object in JSON even when no organization has a custom role yet.
            'custom_roles' => (object) Role::where('is_global', false)->whereNotNull('organization_id')->with('permissions:id,name,label')->orderBy('name')->get()
                ->groupBy('organization_id')
                ->map(fn (Collection $roles) => $roles->map($rolePayload)->values())
                ->all(),
        ]);
    }

    /**
     * Add a person to an organization with a role, from the platform (owner's rule, 2026-09-17: the super admin
     * assigns anybody to any organization, as before). Straight in — no invitation. The role must be one that organization
     * has; a platform account never joins an organization (the tiers stay apart), and a member is changed, not added.
     */
    public function assignToOrganization(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'integer'],
            'role_id' => ['required', 'integer'],
        ], [
            'organization_id.required' => 'Choose an organization.',
            'role_id.required' => 'Choose a role.',
        ]);

        if ($user->globalRole() !== null) {
            throw ValidationException::withMessages(['organization_id' => "{$user->name} is on the platform team, which works above the organizations. Remove their platform role first."]);
        }

        $organization = Organization::find($validated['organization_id'])
            ?? throw ValidationException::withMessages(['organization_id' => 'Choose an organization that exists.']);

        $role = Role::availableInOrganization($organization->id)->find($validated['role_id'])
            ?? throw ValidationException::withMessages(['role_id' => 'Choose a role that exists in this organization.']);

        $this->team->changeTeam($organization, function () use ($user, $organization, $role) {
            if ($this->team->isMember($user, $organization)) {
                throw ValidationException::withMessages(['organization_id' => "{$user->name} is already in {$organization->name}. Change their role there instead."]);
            }

            $user->organizations()->attach($organization->id, ['role_id' => $role->id]);
        });

        ActivityLog::record('member.assigned', $user,
            "Added {$user->name} ({$user->email}) to {$organization->name} as {$role->name}, from the platform",
            organizationId: $organization->id);

        return response()->json(['message' => "{$user->name} is now {$role->name} in {$organization->name}."], 201);
    }

    /**
     * Take a person out of an organization, from the platform — their membership only, the way Remove member does
     * (rule 20): what they made stays with the organization. The organization keeps at least one Owner (rule 9).
     */
    public function removeFromOrganization(Request $request, User $user, Organization $organization): JsonResponse
    {
        $this->team->roleOf($user, $organization) ?? abort(404);

        $this->refuseToLeaveOwnerless($user, $organization);
        $this->confirmPassword($request);

        // Decided again under the organization's lock, so a co-owner leaving at this moment is seen.
        $this->team->changeTeam($organization, function () use ($user, $organization) {
            $this->team->roleOf($user, $organization) ?? abort(404);
            $this->refuseToLeaveOwnerless($user, $organization);

            DB::table('organization_user')->where('organization_id', $organization->id)->where('user_id', $user->id)->delete();
        });

        ActivityLog::record('member.removed', $user,
            "Removed {$user->name} ({$user->email}) from {$organization->name}, from the platform",
            organizationId: $organization->id);

        return response()->json(['message' => "{$user->name} was removed from {$organization->name}."]);
    }

    /**
     * Give a member of an organization another role there, from the platform (owner's rule, 2026-09-16: the super
     * admin gives any one person the access they need). The role must be one this organization has — an organization role,
     * or its own custom role — and the organization keeps at least one Owner (rule 9, decided under the organization's lock).
     */
    public function changeOrganizationRole(Request $request, User $user, Organization $organization): JsonResponse
    {
        $this->team->roleOf($user, $organization) ?? abort(404);

        $validated = $request->validate(['role_id' => ['required', 'integer']], ['role_id.required' => 'Choose a role.']);

        $role = Role::availableInOrganization($organization->id)->find($validated['role_id'])
            ?? throw ValidationException::withMessages(['role_id' => 'Choose a role that exists in this organization.']);

        $current = $this->team->changeTeam($organization, function () use ($user, $organization, $role) {
            $current = $this->team->roleOf($user, $organization) ?? abort(404);

            if ($current->isOwner() && ! $role->isOwner() && $this->team->ownerCount($organization) === 1) {
                throw ValidationException::withMessages([
                    'role_id' => "{$user->name} is the only Owner of {$organization->name}. Make someone else an Owner first.",
                ]);
            }

            if ($role->id !== $current->id) {
                DB::table('organization_user')
                    ->where('organization_id', $organization->id)
                    ->where('user_id', $user->id)
                    ->update(['role_id' => $role->id, 'updated_at' => now()]);
            }

            return $current;
        });

        if ($role->id !== $current->id) {
            ActivityLog::record('member.role_changed', $user,
                "Changed the role of {$user->name} in {$organization->name} from {$current->name} to {$role->name}, from the platform",
                organizationId: $organization->id);
        }

        return response()->json(['message' => "{$user->name} is now {$role->name} in {$organization->name}."]);
    }

    /** An organization's last Owner stays: the organization is never left without one (rule 9). */
    private function refuseToLeaveOwnerless(User $user, Organization $organization): void
    {
        // The same question as leaving: the answer is "no" for an organization's only Owner and "yes" for everybody else.
        if (! $this->team->mayLeave($user, $organization)) {
            abort(response()->json([
                'message' => "{$user->name} is the only Owner of {$organization->name}. Make someone else an Owner first.",
            ], 422));
        }
    }

    /** Support never reaches the platform team; only super admins do (rule 13). */
    private function ensureReachable(User $actor, User $user): void
    {
        abort_if(! $actor->isSuperAdmin() && $user->globalRole() !== null, 404);
    }

    /** One query for the page's memberships and one for the owner counts behind "sole owner". */
    private function attachAccess(Collection $users, User $viewer): void
    {
        $rows = DB::table('organization_user')
            ->join('roles', 'roles.id', '=', 'organization_user.role_id')
            ->leftJoin('organizations', 'organizations.id', '=', 'organization_user.organization_id')
            ->whereIn('organization_user.user_id', $users->pluck('id'))
            ->orderBy('organizations.name')
            ->get(['organization_user.user_id', 'organization_user.organization_id', 'organizations.name as organization_name', 'roles.name as role_name', 'roles.key as role_key'])
            ->groupBy('user_id');

        $ownedOrganizationIds = $rows->flatten(1)->where('role_key', Role::OWNER)->pluck('organization_id')->unique();
        $ownerCounts = DB::table('organization_user')
            ->join('roles', 'roles.id', '=', 'organization_user.role_id')
            ->whereIn('organization_user.organization_id', $ownedOrganizationIds)
            ->where('roles.key', Role::OWNER)
            ->groupBy('organization_user.organization_id')
            ->selectRaw('organization_user.organization_id, count(*) as owners')
            ->pluck('owners', 'organization_id');

        $primaryId = User::primarySuperAdminId();
        $viewerIsPrimary = $viewer->id === $primaryId;
        $viewerIsSuperAdmin = $viewer->isSuperAdmin();
        $viewerMayDelete = $viewer->can('user-destroy');

        $users->each(function (User $user) use ($rows, $ownerCounts, $viewer, $primaryId, $viewerIsPrimary, $viewerIsSuperAdmin, $viewerMayDelete) {
            $mine = $rows->get($user->id, collect());
            $platform = $mine->firstWhere('organization_id', 0);
            $organizations = $mine->filter(fn (object $row) => (int) $row->organization_id > 0 && $row->organization_name !== null);

            $isYou = $user->id === $viewer->id;
            $isPrimary = $user->id === $primaryId;
            $isSuperAdmin = $platform?->role_name === Role::SUPER_ADMIN;

            $user->append('name');
            $user->is_you = $isYou;
            $user->is_primary = $isPrimary;
            $user->platform_role = $platform?->role_name;
            $user->memberships = $organizations->map(fn (object $row) => [
                'organization_id' => (int) $row->organization_id,
                'organization_name' => $row->organization_name,
                'role_name' => $row->role_name,
                'role_key' => $row->role_key,
            ])->values();
            $user->sole_owner_of = $organizations
                ->filter(fn (object $row) => $row->role_key === Role::OWNER && (int) ($ownerCounts[$row->organization_id] ?? 0) === 1)
                ->pluck('organization_name')
                ->values();
            $user->can = [
                'impersonate' => $viewerIsSuperAdmin && ! $isYou && ! $isSuperAdmin,
                'manage_organizations' => $viewerIsSuperAdmin && $platform === null,
                'remove_platform_role' => $viewerIsSuperAdmin && $platform !== null && ! $isYou && ! $isPrimary
                    && (! $isSuperAdmin || $viewerIsPrimary),
                'delete' => $viewerMayDelete && ! $isYou && ! $isPrimary
                    && ($platform === null || $viewerIsSuperAdmin)
                    && (! $isSuperAdmin || $viewerIsPrimary),
            ];
        });
    }
}
