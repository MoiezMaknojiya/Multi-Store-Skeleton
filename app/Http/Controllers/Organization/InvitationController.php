<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationTeam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Inviting people into the current organization (rule 15). Nobody is created here: the person
 * accepts from the email link and sets their own password (InvitationResponseController).
 */
class InvitationController extends Controller
{
    use ResolvesCurrentOrganization;

    public function __construct(private OrganizationTeam $team) {}

    public function store(Request $request): JsonResponse
    {
        $organization = $this->currentOrganization();
        $actor = auth()->user();

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role_id' => ['required', 'integer'],
        ]);

        $email = Invitation::normalizeEmail($validated['email']);
        $role = $this->assignableRole($actor, $organization, (int) $validated['role_id']);

        $account = User::whereRaw('lower(email) = ?', [$email])->first();

        if ($account?->globalRole()) {
            throw ValidationException::withMessages(['email' => 'This email belongs to a platform account and cannot join an organization.']);
        }

        if ($account !== null && $this->team->isMember($account, $organization)) {
            throw ValidationException::withMessages(['email' => "{$email} is already a member of this organization."]);
        }

        if (Invitation::forOrganization($organization)->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'An invitation is already waiting for this email — resend it instead.']);
        }

        [$invitation, $token] = Invitation::open($organization, $email, $role, $actor);
        $sent = $invitation->sendLink($token);

        ActivityLog::record('member.invited', $organization, "Invited {$email} to {$organization->name} as {$role->name}");

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
        $organization = $this->currentOrganization();
        $this->ensureManageable($invitation, $organization);

        $token = $invitation->renew();
        $sent = $invitation->fresh()->sendLink($token);

        ActivityLog::record('invitation.resent', $organization, "Resent the invitation for {$invitation->email} to {$organization->name}");

        return response()->json([
            'message' => $sent
                ? "Invitation sent again to {$invitation->email}."
                : "A new link for {$invitation->email} was made, but the email could not be sent. Try again in a moment.",
            'email_sent' => $sent,
        ]);
    }

    public function destroy(Invitation $invitation): JsonResponse
    {
        $organization = $this->currentOrganization();
        $this->ensureManageable($invitation, $organization);

        $invitation->delete();

        ActivityLog::record('invitation.revoked', $organization, "Revoked the invitation for {$invitation->email} to {$organization->name}");

        return response()->json(['message' => "Invitation for {$invitation->email} revoked."]);
    }

    /** A role of this organization the actor may give (rule 7) — or a validation error saying why not. */
    private function assignableRole(User $actor, Organization $organization, int $roleId): Role
    {
        $role = Role::availableInOrganization($organization->id)->find($roleId);

        if ($role === null) {
            throw ValidationException::withMessages(['role_id' => 'Choose a role that exists in this organization.']);
        }

        if (! $this->team->mayAssign($actor, $organization, $role)) {
            throw ValidationException::withMessages(['role_id' => 'You can only invite people to a role whose access you have yourself.']);
        }

        return $role;
    }

    /** Only this organization's invitations, and only for roles the actor could give themselves. */
    private function ensureManageable(Invitation $invitation, Organization $organization): void
    {
        abort_unless($invitation->organization_id === $organization->id, 404);

        abort_unless($this->team->mayAssign(auth()->user(), $organization, $invitation->role), 403,
            'You cannot manage an invitation to a role whose access you do not have.');
    }
}
