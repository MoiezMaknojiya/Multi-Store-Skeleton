<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationTeam;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * The other end of an invitation: the link in the email (docs/ORGANIZATION-SPEC.md rule 16), and the dashboard's
 * "Invitations for you" card for an account whose inbox is confirmed already (acceptHere, declineHere).
 *
 * Possessing the link is what proves the inbox, so an account created here is marked verified.
 * An account that already exists always signs in with its own password first — the link alone
 * never logs anybody in.
 */
class InvitationResponseController extends Controller
{
    /** What join() found under its locks, beside the tier rule's own words: the link used a moment ago, or a member already. */
    private const USED = 'used';

    private const ALREADY_IN = 'already-in';

    /** What join() found under its first lock: the invitation's organization deleted while the answer waited. */
    private const GONE = 'gone';

    public function __construct(private OrganizationTeam $team) {}

    public function show(string $token): View
    {
        $invitation = $this->openInvitation($token);

        if ($invitation === null) {
            return view('invitations.invalid');
        }

        $user = auth()->user();

        if ($user !== null) {
            $state = $invitation->isFor($user) ? 'accept' : 'mismatch';
        } elseif ($this->accountFor($invitation) !== null) {
            // Signing in brings them straight back here to accept.
            session()->put('url.intended', route('invitations.show', $token));
            $state = 'login';
        } else {
            $state = 'register';
        }

        return view('invitations.show', [
            'invitation' => $invitation,
            'token' => $token,
            'state' => $state,
            'place' => $this->placeName($invitation),
        ]);
    }

    public function accept(string $token): RedirectResponse
    {
        $invitation = $this->openInvitation($token);

        if ($invitation === null) {
            return redirect()->route('invitations.show', $token);
        }

        $user = auth()->user();
        abort_unless($invitation->isFor($user), 403, 'This invitation is for a different email address.');

        // The link was opened from this account's own inbox: that confirms its address, as the signup's link would.
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            ActivityLog::record('account.verified', $user, "Confirmed their email {$user->email} by an invitation's link");
        }

        return match ($outcome = $this->join($invitation, $user)) {
            null => redirect()->route('dashboard')->with('status', $this->welcome($invitation)),
            // Used a moment ago — a double submit, or another tab. The page says what is left.
            self::USED => redirect()->route('invitations.show', $token),
            self::ALREADY_IN => $this->alreadyAMember($invitation, $user),
            // The invitation went with its organization, so its own page could only say "no longer valid": the dashboard
            // (the email is confirmed above) says why.
            self::GONE => redirect()->route('dashboard')->with('invitation-problem', $this->organizationGone($invitation)),
            default => redirect()->route('invitations.show', $token)->with('error', $outcome),
        };
    }

    public function register(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->openInvitation($token);

        // Gone, or somebody already holds this email: the page explains what to do instead.
        if ($invitation === null || $this->accountFor($invitation) !== null) {
            return redirect()->route('invitations.show', $token);
        }

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'numeric', 'digits:10'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        // One transaction under the organization's lock and then the invitation's: a double submit or a second tab can
        // neither make a second account for this email nor use the link twice, and an organization being deleted is waited
        // for. Deleted meanwhile, it took the invitation with it: nobody is made, and the link's page says it is no longer valid.
        $user = DB::transaction(function () use ($validated, $invitation): ?User {
            if (! $this->lockItsOrganization($invitation) || ! $this->lockOpen($invitation) || $this->accountFor($invitation) !== null) {
                return null;
            }

            $user = User::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'phone' => $validated['phone'],
                'email' => $invitation->email,
                'password' => Hash::make($validated['password']),
            ]);

            // Opening the link proved the inbox.
            $user->forceFill(['email_verified_at' => now()])->save();

            $this->addMembership($invitation, $user);

            return $user;
        });

        if ($user === null) {
            return redirect()->route('invitations.show', $token);
        }

        Auth::login($user);
        $request->session()->regenerate();

        ActivityLog::record('user.registered', $user, "Created an account from an invitation: {$user->name} ({$user->email})", $user);

        $this->afterJoining($invitation, $user);

        return redirect()->route('dashboard')->with('status', $this->welcome($invitation));
    }

    public function decline(string $token): RedirectResponse
    {
        // Its organization read now, while the invitation is there: the log line names it.
        $invitation = Invitation::findByToken($token)?->load('organization');

        if ($invitation !== null) {
            abort_if(auth()->check() && ! $invitation->isFor(auth()->user()), 403, 'This invitation is for a different email address.');

            // A guest holding the emailed link speaks for that inbox, like a password-reset link:
            // name the account behind it when there is one, rather than "System".
            if ($this->deleteDeclined($invitation)) {
                ActivityLog::record('invitation.declined', $invitation->organization,
                    "{$invitation->email} declined the invitation to ".$this->placeName($invitation),
                    auth()->user() ?? $this->accountFor($invitation));
            }
        }

        return redirect()->route(auth()->check() ? 'dashboard' : 'login')->with('status', 'Invitation declined.');
    }

    /**
     * An organization's invitation accepted on the dashboard (owner, 2026-10-07 — "haan bana do") by the account it was
     * sent to. Every page of the panel stands behind `verified`, so that account has proved this inbox as the emailed
     * link would. The platform team's invitations, and anybody else's, are not found here (404).
     */
    public function acceptHere(int $invitation): RedirectResponse
    {
        $user = auth()->user();
        $invitation = $this->sentHere($invitation, $user);

        // A page left open past the week: said on the dashboard, nothing joined.
        if ($invitation->isExpired()) {
            return redirect()->route('dashboard')->with('invitation-problem',
                "The invitation to {$invitation->organization->name} has expired. Ask them to send it again.");
        }

        return match ($outcome = $this->join($invitation, $user)) {
            null => redirect()->route('dashboard')->with('status', $this->welcome($invitation)),
            // A double submit used it a moment ago: the dashboard shows where things stand.
            self::USED => redirect()->route('dashboard'),
            self::ALREADY_IN => $this->alreadyAMember($invitation, $user),
            self::GONE => redirect()->route('dashboard')->with('invitation-problem', $this->organizationGone($invitation)),
            default => redirect()->route('dashboard')->with('invitation-problem', $outcome),
        };
    }

    /** Turned down on the dashboard: the invitation goes and the log says so — nobody is emailed (owner, 2026-10-07). */
    public function declineHere(int $invitation): RedirectResponse
    {
        $user = auth()->user();
        $invitation = $this->sentHere($invitation, $user);

        if ($this->deleteDeclined($invitation)) {
            ActivityLog::record('invitation.declined', $invitation->organization,
                "{$user->email} declined the invitation to {$invitation->organization->name} on their dashboard", $user);
        }

        return redirect()->route('dashboard')->with('status', 'Invitation declined.');
    }

    /**
     * Delete a declined invitation — its organization read beforehand. False when nothing was there to delete: declined
     * in another tab, revoked, or taken with its organization (Organization::purgeContents, which this delete waits for on
     * MySQL). Nothing was declined then, so nothing is logged and the organization is never asked for its name; and since
     * an invitation goes with its organization (its foreign key cascades), one deleted here had its organization loaded.
     * The answer is the same either way, as for a link declined twice: the invitation is no longer open.
     */
    private function deleteDeclined(Invitation $invitation): bool
    {
        return Invitation::whereKey($invitation->id)->delete() > 0;
    }

    /**
     * One of the organizations' invitations to this person's own address, with its organization and role still there —
     * or 404: anybody else's invitation, and the platform team's, are never found here.
     */
    private function sentHere(int $id, User $user): Invitation
    {
        return Invitation::sentTo($user)->whereHas('organization')->whereHas('role')
            ->with(['organization', 'role', 'inviter'])->findOrFail($id);
    }

    /** An invitation to an organization the person is in already: used up, and that organization opened. */
    private function alreadyAMember(Invitation $invitation, User $user): RedirectResponse
    {
        $invitation->delete();
        session(['current_organization_id' => $invitation->organization_id]);

        ActivityLog::record('invitation.accepted', $invitation->organization,
            "{$user->name} ({$user->email}) used an invitation to {$invitation->organization->name}, where they were already a member", $user);

        return redirect()->route('dashboard')->with('status', "You are already a member of {$invitation->organization->name}.");
    }

    /** The invitation behind a link while it can still be used; null when it is gone or expired. */
    private function openInvitation(string $token): ?Invitation
    {
        $invitation = Invitation::findByToken($token)?->load(['organization', 'role', 'inviter']);

        if ($invitation === null || $invitation->isExpired() || $invitation->role === null) {
            return null;
        }

        // An organization invitation whose organization was deleted is as dead as an expired one.
        if (! $invitation->isForPlatform() && $invitation->organization === null) {
            return null;
        }

        return $invitation;
    }

    private function accountFor(Invitation $invitation): ?User
    {
        return User::whereRaw('lower(email) = ?', [$invitation->email])->first();
    }

    /**
     * Platform and organization tiers never mix (rule 3) — read from the database as it is now, never from what the person's
     * model remembered earlier in the request (User::globalRole() is kept for the request): join() asks it under the lock.
     */
    private function whyCannotJoin(Invitation $invitation, User $user): ?string
    {
        $onThePlatform = $user->isOnThePlatformTeamNow();

        if ($invitation->isForPlatform()) {
            if ($onThePlatform) {
                return 'You are already on the platform team.';
            }

            // organizations() joins real organizations, so the platform row (organization_id = 0) is never among them.
            return $user->organizations()->exists()
                ? 'Your account is a member of one or more organizations. An organization account cannot join the platform team.'
                : null;
        }

        return $onThePlatform
            ? 'Platform accounts cannot join an organization.'
            : null;
    }

    /**
     * Use the invitation for this person, deciding under the lock of its organization's row, then their own account row,
     * then the invitation's: a second tab accepting another invitation at the same moment — of the other tier, or to the
     * same organization — waits for this one, and then sees the membership it added. The role comes last, read by the
     * membership's foreign key as it is written: the order every change takes them (OrganizationTeam::changeTeam names the
     * person before the role it gives), so nothing holding the role waits here for the person's row. Never the role before
     * the invitation: RoleController::destroy takes the invitations to a role, then the role. Null once they joined; GONE when its
     * organization was deleted while this waited (lockItsOrganization); USED when the link was used a moment ago; ALREADY_IN
     * when they are in its organization already; otherwise the words of the tier rule. Nothing changes but on null. The
     * account itself, deleted from the platform's Users page meanwhile, is 404 — never a membership of nobody, the foreign key's 500.
     */
    private function join(Invitation $invitation, User $user): ?string
    {
        $outcome = DB::transaction(function () use ($invitation, $user): ?string {
            if (! $this->lockItsOrganization($invitation)) {
                return self::GONE;
            }

            User::whereKey($user->id)->lockForUpdate()->firstOrFail(['id']);

            if (! $this->lockOpen($invitation)) {
                return self::USED;
            }

            if (! $invitation->isForPlatform() && $this->team->isMember($user, $invitation->organization)) {
                return self::ALREADY_IN;
            }

            if ($reason = $this->whyCannotJoin($invitation, $user)) {
                return $reason;
            }

            $this->addMembership($invitation, $user);

            return null;
        });

        if ($outcome === null) {
            $this->afterJoining($invitation, $user);
        }

        return $outcome;
    }

    /**
     * Take the invitation's organization row under a lock — call inside a transaction, as its first statement. It is the
     * first lock every change to a team takes (OrganizationTeam::changeTeam, Platform\UserController::assignToOrganization,
     * OrganizationTeam::whileTheirOrganizationsAreLocked), and the first Organization::purgeContents takes before it deletes
     * the memberships and then the invitations: an accept holding the person's row and the invitation's, then writing its
     * membership, met that delete the other way round on MySQL — a deadlock, a 500. A lock takes no snapshot, so the reads
     * after it see what committed while it waited. False when the organization was deleted meanwhile — it took the
     * invitation with it, and the caller joins nobody. A platform invitation has no organization row.
     */
    private function lockItsOrganization(Invitation $invitation): bool
    {
        return $invitation->isForPlatform()
            || Organization::whereKey($invitation->organization_id)->lockForUpdate()->first(['id']) !== null;
    }

    /**
     * Why an invitation answered as its organization was deleted did nothing — said on the dashboard. The name is the one
     * both doors loaded with the invitation (openInvitation, sentHere), before the organization went.
     */
    private function organizationGone(Invitation $invitation): string
    {
        return "{$invitation->organization->name} has been deleted, so this invitation can no longer be used.";
    }

    /** Take the invitation's row under a lock — call inside a transaction. False when it is gone. */
    private function lockOpen(Invitation $invitation): bool
    {
        return Invitation::whereKey($invitation->id)->lockForUpdate()->first(['id']) !== null;
    }

    /** The membership the invitation promised, and the invitation used up. */
    private function addMembership(Invitation $invitation, User $user): void
    {
        DB::table('organization_user')->insert([
            'user_id' => $user->id,
            'organization_id' => $invitation->organization_id ?? 0,
            'role_id' => $invitation->role_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invitation->delete();
    }

    private function afterJoining(Invitation $invitation, User $user): void
    {
        if ($invitation->isForPlatform()) {
            session()->forget('current_organization_id');
        } else {
            session(['current_organization_id' => $invitation->organization_id]);
        }

        ActivityLog::record('invitation.accepted', $invitation->organization ?? $user,
            "{$user->name} ({$user->email}) joined ".$this->placeName($invitation)." as {$invitation->role->name}", $user);
    }

    private function welcome(Invitation $invitation): string
    {
        return 'Welcome to '.$this->placeName($invitation).'!';
    }

    /** Where the invitation leads — the organization, or the platform team. The page's heading says it too. */
    private function placeName(Invitation $invitation): string
    {
        return $invitation->isForPlatform() ? 'the '.config('app.name').' team' : $invitation->organization->name;
    }
}
