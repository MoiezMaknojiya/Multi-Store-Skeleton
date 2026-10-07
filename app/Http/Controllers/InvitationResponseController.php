<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Invitation;
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

        if (! $invitation->isForPlatform() && $this->team->isMember($user, $invitation->organization)) {
            return $this->alreadyAMember($invitation, $user);
        }

        if ($reason = $this->whyCannotJoin($invitation, $user)) {
            return redirect()->route('invitations.show', $token)->with('error', $reason);
        }

        if (! $this->join($invitation, $user)) {
            // Used a moment ago — a double submit, or another tab. The page says what is left.
            return redirect()->route('invitations.show', $token);
        }

        return redirect()->route('dashboard')->with('status', $this->welcome($invitation));
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

        // One transaction under the invitation's lock: a double submit or a second tab can neither
        // make a second account for this email nor use the link twice.
        $user = DB::transaction(function () use ($validated, $invitation): ?User {
            if (! $this->lockOpen($invitation) || $this->accountFor($invitation) !== null) {
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
        $invitation = Invitation::findByToken($token);

        if ($invitation !== null) {
            abort_if(auth()->check() && ! $invitation->isFor(auth()->user()), 403, 'This invitation is for a different email address.');

            $invitation->delete();

            // A guest holding the emailed link speaks for that inbox, like a password-reset link:
            // name the account behind it when there is one, rather than "System".
            ActivityLog::record('invitation.declined', $invitation->organization,
                "{$invitation->email} declined the invitation to ".$this->placeName($invitation),
                auth()->user() ?? $this->accountFor($invitation));
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

        if ($this->team->isMember($user, $invitation->organization)) {
            return $this->alreadyAMember($invitation, $user);
        }

        if ($reason = $this->whyCannotJoin($invitation, $user)) {
            return redirect()->route('dashboard')->with('invitation-problem', $reason);
        }

        // False only when a double submit used it a moment ago: the dashboard shows where things stand.
        return $this->join($invitation, $user)
            ? redirect()->route('dashboard')->with('status', $this->welcome($invitation))
            : redirect()->route('dashboard');
    }

    /** Turned down on the dashboard: the invitation goes and the log says so — nobody is emailed (owner, 2026-10-07). */
    public function declineHere(int $invitation): RedirectResponse
    {
        $user = auth()->user();
        $invitation = $this->sentHere($invitation, $user);
        $invitation->delete();

        ActivityLog::record('invitation.declined', $invitation->organization,
            "{$user->email} declined the invitation to {$invitation->organization->name} on their dashboard", $user);

        return redirect()->route('dashboard')->with('status', 'Invitation declined.');
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

    /** Platform and organization tiers never mix (rule 3). */
    private function whyCannotJoin(Invitation $invitation, User $user): ?string
    {
        if ($invitation->isForPlatform()) {
            if ($user->globalRole() !== null) {
                return 'You are already on the platform team.';
            }

            // organizations() joins real organizations, so the platform row (organization_id = 0) is never among them.
            return $user->organizations()->exists()
                ? 'Your account is a member of one or more organizations. An organization account cannot join the platform team.'
                : null;
        }

        return $user->globalRole() !== null
            ? 'Platform accounts cannot join an organization.'
            : null;
    }

    /** Use the invitation for this person. False, and nothing changed, when the link was used already. */
    private function join(Invitation $invitation, User $user): bool
    {
        $joined = DB::transaction(function () use ($invitation, $user): bool {
            if (! $this->lockOpen($invitation)) {
                return false;
            }

            $this->addMembership($invitation, $user);

            return true;
        });

        if ($joined) {
            $this->afterJoining($invitation, $user);
        }

        return $joined;
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
