<?php

namespace App\Http\Controllers\Store;

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
use Illuminate\Validation\ValidationException;

/**
 * Inviting people into the current store (rule 15). Nobody is created here: the person
 * accepts from the email link and sets their own password (InvitationResponseController).
 */
class InvitationController extends Controller
{
    use ResolvesCurrentStore;

    public function __construct(private StoreTeam $team) {}

    public function store(Request $request): JsonResponse
    {
        $store = $this->currentStore();
        $actor = auth()->user();

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role_id' => ['required', 'integer'],
        ]);

        $email = Invitation::normalizeEmail($validated['email']);
        $role = $this->assignableRole($actor, $store, (int) $validated['role_id']);

        $account = User::whereRaw('lower(email) = ?', [$email])->first();

        if ($account?->globalRole()) {
            throw ValidationException::withMessages(['email' => 'This email belongs to a platform account and cannot join a store.']);
        }

        if ($account !== null && $this->team->isMember($account, $store)) {
            throw ValidationException::withMessages(['email' => "{$email} is already a member of this store."]);
        }

        if (Invitation::forStore($store)->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'An invitation is already waiting for this email — resend it instead.']);
        }

        [$invitation, $token] = Invitation::open($store, $email, $role, $actor);
        $sent = $invitation->sendLink($token);

        ActivityLog::record('member.invited', $store, "Invited {$email} to {$store->name} as {$role->name}");

        return response()->json([
            'message' => $sent
                ? "Invitation sent to {$email}."
                : "The invitation for {$email} was created, but the email could not be sent. Use Resend to try again.",
            'email_sent' => $sent,
        ], 201);
    }

    /** A new link and a new week for an open invitation; the old link stops working. */
    public function resend(Invitation $invitation): JsonResponse
    {
        $store = $this->currentStore();
        $this->ensureManageable($invitation, $store);

        $token = $invitation->renew();
        $sent = $invitation->fresh()->sendLink($token);

        ActivityLog::record('invitation.resent', $store, "Resent the invitation for {$invitation->email} to {$store->name}");

        return response()->json([
            'message' => $sent
                ? "Invitation sent again to {$invitation->email}."
                : "A new link for {$invitation->email} was made, but the email could not be sent. Try again in a moment.",
            'email_sent' => $sent,
        ]);
    }

    public function destroy(Invitation $invitation): JsonResponse
    {
        $store = $this->currentStore();
        $this->ensureManageable($invitation, $store);

        $invitation->delete();

        ActivityLog::record('invitation.revoked', $store, "Revoked the invitation for {$invitation->email} to {$store->name}");

        return response()->json(['message' => "Invitation for {$invitation->email} revoked."]);
    }

    /** A role of this store the actor may give (rule 7) — or a validation error saying why not. */
    private function assignableRole(User $actor, Store $store, int $roleId): Role
    {
        $role = Role::availableInStore($store->id)->find($roleId);

        if ($role === null) {
            throw ValidationException::withMessages(['role_id' => 'Choose a role that exists in this store.']);
        }

        if (! $this->team->mayAssign($actor, $store, $role)) {
            throw ValidationException::withMessages(['role_id' => 'You can only invite people to a role whose access you have yourself.']);
        }

        return $role;
    }

    /** Only this store's invitations, and only for roles the actor could give themselves. */
    private function ensureManageable(Invitation $invitation, Store $store): void
    {
        abort_unless($invitation->store_id === $store->id, 404);

        abort_unless($this->team->mayAssign(auth()->user(), $store, $invitation->role), 403,
            'You cannot manage an invitation to a role whose access you do not have.');
    }
}
