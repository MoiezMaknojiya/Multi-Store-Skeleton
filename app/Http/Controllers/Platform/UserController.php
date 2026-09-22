<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Controllers\Concerns\HandlesCrudData;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\StoreTeam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Accounts (docs/STORE-ORGANIZATION-SPEC.md rules 13, 21, 24) — the platform's alone (owner's rule, 2026-09-17:
 * a store's people are its Members page). Nobody's details are edited here — people manage their own. The
 * platform looks at an account, logs in as it, puts it in a store with a role, changes or takes away that role
 * (super admins — Stores), takes away a platform role, or deletes the account.
 */
class UserController extends Controller
{
    use ConfirmsPassword, HandlesCrudData;

    public function __construct(private StoreTeam $team) {}

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

        $query = User::query()->orderBy('first_name')->orderBy('last_name');

        // The platform team is the super admins' business; support sees customers only.
        if (! $viewer->isSuperAdmin()) {
            $query->whereNotIn('id', DB::table('store_user')->where('store_id', 0)->select('user_id'));
        }

        return $this->paginatedResponse(
            $request, $query, ['first_name', 'last_name', 'email'], 'users',
            ['id', 'first_name', 'last_name', 'email', 'created_at'],
            fn (Collection $users) => $this->attachAccess($users, $viewer)
        );
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

        $ownerless = $this->team->storesSolelyOwnedBy($user)->pluck('name');
        $withdrawn = $user->invitationsToEmail()->count();
        $name = $user->name;
        $email = $user->email;

        $user->delete();

        ActivityLog::record('user.deleted', null, "Deleted the account of {$name} ({$email})"
            .($withdrawn > 0 ? " and the {$withdrawn} pending ".str('invitation')->plural($withdrawn).' to that email' : '')
            .($ownerless->isNotEmpty() ? ' — left without an Owner: '.$ownerless->join(', ') : ''));

        return response()->json([
            'message' => "{$name}'s account was deleted.",
            'ownerless_stores' => $ownerless->values(),
        ]);
    }

    /** Take a platform role away (rule 24). The account stays, with no access at all. */
    public function removePlatformRole(Request $request, User $user): JsonResponse
    {
        $actor = auth()->user();
        $roleId = DB::table('store_user')->where('user_id', $user->id)->where('store_id', 0)->value('role_id') ?? abort(404);

        if ($actor->is($user)) {
            return response()->json(['message' => 'You cannot remove your own platform role.'], 422);
        }

        abort_if($user->isPrimarySuperAdmin(), 403, 'The primary super admin keeps their role.');
        abort_if($user->isSuperAdmin() && ! $actor->isPrimarySuperAdmin(), 403, 'Only the primary super admin can remove Super-Admin from someone.');

        $this->confirmPassword($request);

        DB::table('store_user')->where('user_id', $user->id)->where('store_id', 0)->delete();

        $roleName = Role::find($roleId)?->name;
        ActivityLog::record('platform.role_removed', $user, "Removed the platform role {$roleName} from {$user->name} ({$user->email})");

        return response()->json(['message' => "{$user->name} is no longer on the platform team."]);
    }

    /**
     * A person's place in the stores, for the platform's Manage stores form: the stores they belong to with
     * their role in each, the stores they could be added to, and the roles on offer — the store roles every
     * store has, and each store's own custom roles.
     */
    public function storeAccess(User $user): JsonResponse
    {
        $memberships = DB::table('store_user')
            ->join('stores', 'stores.id', '=', 'store_user.store_id')
            ->where('store_user.user_id', $user->id)
            ->orderBy('stores.name')
            ->get(['stores.id', 'stores.name', 'store_user.role_id']);

        $rolePayload = fn (Role $role) => [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description(),
        ];

        return response()->json([
            'memberships' => $memberships->map(fn (object $row) => [
                'store_id' => (int) $row->id,
                'store_name' => $row->name,
                'role_id' => (int) $row->role_id,
            ])->values(),
            'stores' => Store::whereNotIn('id', $memberships->pluck('id'))->orderBy('name')->get(['id', 'name', 'city']),
            'store_roles' => Role::storeRoles()->with('permissions:id,name,label')->get()
                ->sortBy(fn (Role $role) => [$role->isOwner() ? 0 : 1, strtolower($role->name)])
                ->map($rolePayload)->values(),
            // Keyed by store id — an object in JSON even when no store has a custom role yet.
            'custom_roles' => (object) Role::where('is_global', false)->whereNotNull('store_id')->with('permissions:id,name,label')->orderBy('name')->get()
                ->groupBy('store_id')
                ->map(fn (Collection $roles) => $roles->map($rolePayload)->values())
                ->all(),
        ]);
    }

    /**
     * Add a person to a store with a role, from the platform (owner's rule, 2026-09-17: the super admin
     * assigns anybody to any store, as before). Straight in — no invitation. The role must be one that store
     * has; a platform account never joins a store (the tiers stay apart), and a member is changed, not added.
     */
    public function assignToStore(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'store_id' => ['required', 'integer'],
            'role_id' => ['required', 'integer'],
        ], [
            'store_id.required' => 'Choose a store.',
            'role_id.required' => 'Choose a role.',
        ]);

        if ($user->globalRole() !== null) {
            throw ValidationException::withMessages(['store_id' => "{$user->name} is on the platform team, which works above the stores. Remove their platform role first."]);
        }

        $store = Store::find($validated['store_id'])
            ?? throw ValidationException::withMessages(['store_id' => 'Choose a store that exists.']);

        $role = Role::availableInStore($store->id)->find($validated['role_id'])
            ?? throw ValidationException::withMessages(['role_id' => 'Choose a role that exists in this store.']);

        $this->team->changeTeam($store, function () use ($user, $store, $role) {
            if ($this->team->isMember($user, $store)) {
                throw ValidationException::withMessages(['store_id' => "{$user->name} is already in {$store->name}. Change their role there instead."]);
            }

            $user->stores()->attach($store->id, ['role_id' => $role->id]);
        });

        ActivityLog::record('member.assigned', $user,
            "Added {$user->name} ({$user->email}) to {$store->name} as {$role->name}, from the platform",
            storeId: $store->id);

        return response()->json(['message' => "{$user->name} is now {$role->name} in {$store->name}."], 201);
    }

    /**
     * Take a person out of a store, from the platform — their membership only, the way Remove member does
     * (rule 20): what they made stays with the store. The store keeps at least one Owner (rule 9).
     */
    public function removeFromStore(Request $request, User $user, Store $store): JsonResponse
    {
        $this->team->roleOf($user, $store) ?? abort(404);

        $this->refuseToLeaveOwnerless($user, $store);
        $this->confirmPassword($request);

        // Decided again under the store's lock, so a co-owner leaving at this moment is seen.
        $this->team->changeTeam($store, function () use ($user, $store) {
            $this->team->roleOf($user, $store) ?? abort(404);
            $this->refuseToLeaveOwnerless($user, $store);

            DB::table('store_user')->where('store_id', $store->id)->where('user_id', $user->id)->delete();
        });

        ActivityLog::record('member.removed', $user,
            "Removed {$user->name} ({$user->email}) from {$store->name}, from the platform",
            storeId: $store->id);

        return response()->json(['message' => "{$user->name} was removed from {$store->name}."]);
    }

    /**
     * Give a member of a store another role there, from the platform (owner's rule, 2026-09-16: the super
     * admin gives any one person the access they need). The role must be one this store has — a store role,
     * or its own custom role — and the store keeps at least one Owner (rule 9, decided under the store's lock).
     */
    public function changeStoreRole(Request $request, User $user, Store $store): JsonResponse
    {
        $this->team->roleOf($user, $store) ?? abort(404);

        $validated = $request->validate(['role_id' => ['required', 'integer']], ['role_id.required' => 'Choose a role.']);

        $role = Role::availableInStore($store->id)->find($validated['role_id'])
            ?? throw ValidationException::withMessages(['role_id' => 'Choose a role that exists in this store.']);

        $current = $this->team->changeTeam($store, function () use ($user, $store, $role) {
            $current = $this->team->roleOf($user, $store) ?? abort(404);

            if ($current->isOwner() && ! $role->isOwner() && $this->team->ownerCount($store) === 1) {
                throw ValidationException::withMessages([
                    'role_id' => "{$user->name} is the only Owner of {$store->name}. Make someone else an Owner first.",
                ]);
            }

            if ($role->id !== $current->id) {
                DB::table('store_user')
                    ->where('store_id', $store->id)
                    ->where('user_id', $user->id)
                    ->update(['role_id' => $role->id, 'updated_at' => now()]);
            }

            return $current;
        });

        if ($role->id !== $current->id) {
            ActivityLog::record('member.role_changed', $user,
                "Changed the role of {$user->name} in {$store->name} from {$current->name} to {$role->name}, from the platform",
                storeId: $store->id);
        }

        return response()->json(['message' => "{$user->name} is now {$role->name} in {$store->name}."]);
    }

    /** A store's last Owner stays: the store is never left without one (rule 9). */
    private function refuseToLeaveOwnerless(User $user, Store $store): void
    {
        // The same question as leaving: the answer is "no" for a store's only Owner and "yes" for everybody else.
        if (! $this->team->mayLeave($user, $store)) {
            abort(response()->json([
                'message' => "{$user->name} is the only Owner of {$store->name}. Make someone else an Owner first.",
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
        $rows = DB::table('store_user')
            ->join('roles', 'roles.id', '=', 'store_user.role_id')
            ->leftJoin('stores', 'stores.id', '=', 'store_user.store_id')
            ->whereIn('store_user.user_id', $users->pluck('id'))
            ->orderBy('stores.name')
            ->get(['store_user.user_id', 'store_user.store_id', 'stores.name as store_name', 'roles.name as role_name', 'roles.key as role_key'])
            ->groupBy('user_id');

        $ownedStoreIds = $rows->flatten(1)->where('role_key', Role::OWNER)->pluck('store_id')->unique();
        $ownerCounts = DB::table('store_user')
            ->join('roles', 'roles.id', '=', 'store_user.role_id')
            ->whereIn('store_user.store_id', $ownedStoreIds)
            ->where('roles.key', Role::OWNER)
            ->groupBy('store_user.store_id')
            ->selectRaw('store_user.store_id, count(*) as owners')
            ->pluck('owners', 'store_id');

        $primaryId = User::primarySuperAdminId();
        $viewerIsPrimary = $viewer->id === $primaryId;
        $viewerIsSuperAdmin = $viewer->isSuperAdmin();
        $viewerMayDelete = $viewer->can('user-destroy');

        $users->each(function (User $user) use ($rows, $ownerCounts, $viewer, $primaryId, $viewerIsPrimary, $viewerIsSuperAdmin, $viewerMayDelete) {
            $mine = $rows->get($user->id, collect());
            $platform = $mine->firstWhere('store_id', 0);
            $stores = $mine->filter(fn (object $row) => (int) $row->store_id > 0 && $row->store_name !== null);

            $isYou = $user->id === $viewer->id;
            $isPrimary = $user->id === $primaryId;
            $isSuperAdmin = $platform?->role_name === Role::SUPER_ADMIN;

            $user->append('name');
            $user->is_you = $isYou;
            $user->is_primary = $isPrimary;
            $user->platform_role = $platform?->role_name;
            $user->memberships = $stores->map(fn (object $row) => [
                'store_id' => (int) $row->store_id,
                'store_name' => $row->store_name,
                'role_name' => $row->role_name,
                'role_key' => $row->role_key,
            ])->values();
            $user->sole_owner_of = $stores
                ->filter(fn (object $row) => $row->role_key === Role::OWNER && (int) ($ownerCounts[$row->store_id] ?? 0) === 1)
                ->pluck('store_name')
                ->values();
            $user->can = [
                'impersonate' => $viewerIsSuperAdmin && ! $isYou && ! $isSuperAdmin,
                'manage_stores' => $viewerIsSuperAdmin && $platform === null,
                'remove_platform_role' => $viewerIsSuperAdmin && $platform !== null && ! $isYou && ! $isPrimary
                    && (! $isSuperAdmin || $viewerIsPrimary),
                'delete' => $viewerMayDelete && ! $isYou && ! $isPrimary
                    && ($platform === null || $viewerIsSuperAdmin)
                    && (! $isSuperAdmin || $viewerIsPrimary),
            ];
        });
    }
}
