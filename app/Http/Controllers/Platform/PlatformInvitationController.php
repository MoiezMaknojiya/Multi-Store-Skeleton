<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inviting people onto the platform team (docs/ORGANIZATION-SPEC.md rule 19) — super
 * admins only, on the routes. Accepting creates the organization_id = 0 membership; see
 * InvitationResponseController.
 */
class PlatformInvitationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'invitations' => Invitation::forPlatform()
                ->with(['role:id,name', 'inviter:id,first_name,last_name'])
                ->latest()
                ->get()
                ->map(fn (Invitation $invitation) => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role?->name,
                    'invited_by' => $invitation->inviter?->name,
                    'expires_at' => $invitation->expires_at->toIso8601String(),
                    'is_expired' => $invitation->isExpired(),
                ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role_id' => ['required', 'integer'],
        ]);

        $email = Invitation::normalizeEmail($validated['email']);
        $role = Role::platform()->find($validated['role_id']);

        if ($role === null) {
            throw ValidationException::withMessages(['role_id' => 'Choose a platform role.']);
        }

        // Making a super admin is kept with whoever can undo it: only the primary super admin
        // takes Super-Admin away (UserController::removePlatformRole), so only they hand it out.
        if ($role->isSuperAdmin() && ! auth()->user()->isPrimarySuperAdmin()) {
            throw ValidationException::withMessages(['role_id' => 'Only the primary super admin can invite a super admin.']);
        }

        $account = User::whereRaw('lower(email) = ?', [$email])->first();

        if ($account?->globalRole() !== null) {
            throw ValidationException::withMessages(['email' => "{$email} is already on the platform team."]);
        }

        // User::organizations() joins real organizations, so the platform row (organization_id = 0) is never among them.
        if ($account !== null && $account->organizations()->exists()) {
            throw ValidationException::withMessages(['email' => 'This email belongs to an organization account, which cannot join the platform team.']);
        }

        if (Invitation::forPlatform()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'An invitation is already waiting for this email — resend it instead.']);
        }

        // Under the role's lock: deleted since it was found, it is refused in words, never a 500 from the foreign key, and a
        // delete of the role waits for this invitation, and revokes it. The inviter's row first, whom the invitation names: the
        // order every change takes a person and a role (OrganizationTeam::changeTeam) — an account's deletion holds its row
        // while it clears the roles it made — and an inviter deleted a moment ago is 404, never the foreign key's 500.
        [$invitation, $token] = DB::transaction(function () use ($email, $role) {
            User::whereKey(auth()->id())->sharedLock()->firstOrFail(['id']);

            Role::whereKey($role->id)->sharedLock()->first(['id'])
                ?? throw ValidationException::withMessages(['role_id' => 'Choose a platform role.']);

            return Invitation::open(null, $email, $role, auth()->user());
        });
        $sent = $invitation->sendLink($token);

        ActivityLog::record('platform.invited', null, "Invited {$email} to the platform team as {$role->name}");

        return response()->json([
            'message' => $sent
                ? "Invitation sent to {$email}."
                : "The invitation for {$email} was created, but the email could not be sent. Use Resend to try again.",
            'email_sent' => $sent,
        ], 201);
    }

    public function resend(Invitation $invitation): JsonResponse
    {
        abort_unless($invitation->isForPlatform(), 404);

        $token = $invitation->renew();
        $sent = $invitation->sendLink($token);

        ActivityLog::record('invitation.resent', null, "Resent the platform invitation for {$invitation->email}");

        return response()->json([
            'message' => $sent
                ? "Invitation sent again to {$invitation->email}."
                : "A new link for {$invitation->email} was made, but the email could not be sent. Try again in a moment.",
            'email_sent' => $sent,
        ]);
    }

    public function destroy(Invitation $invitation): JsonResponse
    {
        abort_unless($invitation->isForPlatform(), 404);

        $invitation->delete();

        ActivityLog::record('invitation.revoked', null, "Revoked the platform invitation for {$invitation->email}");

        return response()->json(['message' => "Invitation for {$invitation->email} revoked."]);
    }
}
