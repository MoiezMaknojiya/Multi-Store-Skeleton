<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Controllers\Concerns\ResolvesCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\StoreTeam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * A store's team, seen and managed from inside the store (docs/STORE-ORGANIZATION-SPEC.md §5).
 *
 * Everyone in the store is listed, whoever brought them in — membership is the only source of
 * power, so there is nothing personal to hide. What the viewer may DO to each row is decided by
 * StoreTeam and sent with the row, so the page never offers a button the server would refuse.
 */
class MemberController extends Controller
{
    use ConfirmsPassword, ResolvesCurrentStore;

    public function __construct(private StoreTeam $team) {}

    public function index(): View
    {
        return view('members.index', ['store' => $this->currentStore()]);
    }

    /** The current store's members and open invitations, with the viewer's powers over each. */
    public function data(): JsonResponse
    {
        $store = $this->currentStore();
        $actor = auth()->user();

        $actorPermissions = $this->team->permissionsOf($actor, $store);
        $roles = $this->team->rolesOf($store)->keyBy('id');
        $withinReach = fn (?Role $role) => $role !== null && $this->team->roleIsWithinReach($actorPermissions, $role);
        $ownerCount = $this->team->ownerCount($store);

        $members = DB::table('store_user')
            ->join('users', 'users.id', '=', 'store_user.user_id')
            ->where('store_user.store_id', $store->id)
            ->orderBy('users.first_name')
            ->orderBy('users.last_name')
            ->get(['users.id', 'users.first_name', 'users.last_name', 'users.email', 'store_user.role_id', 'store_user.created_at']);

        $invitations = Invitation::forStore($store)
            ->with('inviter:id,first_name,last_name')
            ->latest()
            ->get();

        return response()->json([
            'members' => $members->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => trim($row->first_name.' '.$row->last_name),
                'email' => $row->email,
                'role' => $this->rolePayload($roles->get($row->role_id)),
                'joined_at' => $row->created_at ? Carbon::parse($row->created_at)->toIso8601String() : null,
                'is_you' => (int) $row->id === $actor->id,
                // The store's only Owner is never changed or removed here (rule 9), whoever looks.
                'can_manage' => (int) $row->id !== $actor->id && $withinReach($roles->get($row->role_id))
                    && ! ($roles->get($row->role_id)?->isOwner() && $ownerCount === 1),
            ])->values(),
            'invitations' => $invitations->map(fn (Invitation $invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $this->rolePayload($roles->get($invitation->role_id)),
                'invited_by' => $invitation->inviter?->name,
                'expires_at' => $invitation->expires_at->toIso8601String(),
                'is_expired' => $invitation->isExpired(),
                'can_manage' => $withinReach($roles->get($invitation->role_id)),
            ])->values(),
            'assignable_roles' => $roles->filter($withinReach)->map(fn (Role $role) => $this->rolePayload($role))->values(),
            'owner_count' => $ownerCount,
        ]);
    }

    /** Give a member a different role (rules 7–9). */
    public function update(Request $request, User $user): JsonResponse
    {
        $store = $this->currentStore();
        $actor = auth()->user();
        $this->team->roleOf($user, $store) ?? abort(404);

        $validated = $request->validate(['role_id' => ['required', 'integer']]);

        $role = Role::availableInStore($store->id)->find($validated['role_id']);
        if ($role === null) {
            throw ValidationException::withMessages(['role_id' => 'Choose a role that exists in this store.']);
        }

        abort_if($actor->is($user), 403, 'You cannot change your own role.');

        // Decided under the store's lock, so a co-owner changing roles at this very moment is seen.
        $current = $this->team->changeTeam($store, function () use ($store, $actor, $user, $role) {
            $current = $this->team->roleOf($user, $store) ?? abort(404);

            abort_unless($this->team->mayManage($actor, $store, $user), 403, 'You cannot change the role of someone who has access you do not have.');

            if (! $this->team->mayAssign($actor, $store, $role)) {
                throw ValidationException::withMessages(['role_id' => 'You can only give a role whose access you have yourself.']);
            }

            // Reach is permissions alone now (owner's rule, 2026-09-17), so an Owner may be reached by somebody who is
            // not one: the store's last Owner is kept here, read under the lock.
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
                "Changed the role of {$user->name} in {$store->name} from {$current->name} to {$role->name}", storeId: $store->id);
        }

        return response()->json(['message' => "{$user->name} is now {$role->name}."]);
    }

    /** Take a member out of the store. Their account and everything they made here stay. */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $store = $this->currentStore();
        $actor = auth()->user();
        $role = $this->team->roleOf($user, $store) ?? abort(404);

        if ($actor->is($user)) {
            return response()->json(['message' => 'To leave this store, use Leave store.'], 422);
        }

        abort_unless($this->team->mayManage($actor, $store, $user), 403, 'You cannot remove someone who has access you do not have.');
        $this->refuseTheLastOwner($user, $store, $role);

        $this->confirmPassword($request);

        $this->team->changeTeam($store, function () use ($store, $actor, $user) {
            $role = $this->team->roleOf($user, $store) ?? abort(404);

            abort_unless($this->team->mayManage($actor, $store, $user), 403, 'You cannot remove someone who has access you do not have.');
            $this->refuseTheLastOwner($user, $store, $role);

            DB::table('store_user')->where('store_id', $store->id)->where('user_id', $user->id)->delete();
        });

        ActivityLog::record('member.removed', $user, "Removed {$user->name} ({$user->email}) from {$store->name}", storeId: $store->id);

        return response()->json(['message' => "{$user->name} was removed from {$store->name}."]);
    }

    /** Leave the current store — anyone but its last Owner (rule 10). */
    public function leave(): JsonResponse
    {
        $store = $this->currentStore();

        if (! $this->team->leave(auth()->user(), $store)) {
            return response()->json([
                'message' => "You are the only Owner of {$store->name}. Make someone else an Owner before you leave.",
            ], 422);
        }

        session()->forget('current_store_id');

        ActivityLog::record('member.left', $store, "Left {$store->name}");

        return response()->json(['message' => "You left {$store->name}.", 'redirect' => route('dashboard')]);
    }

    /**
     * A store's last Owner stays (rule 9). Holding everything the Owner role allows is enough to reach an Owner, so this
     * is asked on its own — before the password, and again under the store's lock.
     */
    private function refuseTheLastOwner(User $user, Store $store, Role $role): void
    {
        if ($role->isOwner() && $this->team->ownerCount($store) === 1) {
            abort(response()->json([
                'message' => "{$user->name} is the only Owner of {$store->name}. Make someone else an Owner first.",
            ], 422));
        }
    }

    /** @return array{id: int, name: string, key: string|null, description: string}|null */
    private function rolePayload(?Role $role): ?array
    {
        return $role === null ? null : [
            'id' => $role->id,
            'name' => $role->name,
            'key' => $role->key,
            'description' => $role->description(),
        ];
    }
}
