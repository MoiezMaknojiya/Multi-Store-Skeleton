<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Invitation;
use App\Models\User;
use App\Services\StoreTeam;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * The other end of an invitation: the link in the email (docs/STORE-ORGANIZATION-SPEC.md rule 16).
 *
 * Possessing the link is what proves the inbox, so an account created here is marked verified.
 * An account that already exists always signs in with its own password first — the link alone
 * never logs anybody in.
 */
class InvitationResponseController extends Controller
{
    public function __construct(private StoreTeam $team) {}

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

        return view('invitations.show', compact('invitation', 'token', 'state'));
    }

    public function accept(string $token): RedirectResponse
    {
        $invitation = $this->openInvitation($token);

        if ($invitation === null) {
            return redirect()->route('invitations.show', $token);
        }

        $user = auth()->user();
        abort_unless($invitation->isFor($user), 403, 'This invitation is for a different email address.');

        if (! $invitation->isForPlatform() && $this->team->isMember($user, $invitation->store)) {
            $invitation->delete();
            session(['current_store_id' => $invitation->store_id]);

            ActivityLog::record('invitation.accepted', $invitation->store,
                "{$user->name} ({$user->email}) used an invitation to {$invitation->store->name}, where they were already a member", $user);

            return redirect()->route('dashboard')->with('status', "You are already a member of {$invitation->store->name}.");
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
        $user = DB::transaction(function () use ($validated, $invitation) {
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
            ActivityLog::record('invitation.declined', $invitation->store,
                "{$invitation->email} declined the invitation to ".$this->placeName($invitation),
                auth()->user() ?? $this->accountFor($invitation));
        }

        return redirect()->route(auth()->check() ? 'dashboard' : 'login')->with('status', 'Invitation declined.');
    }

    /** The invitation behind a link while it can still be used; null when it is gone or expired. */
    private function openInvitation(string $token): ?Invitation
    {
        $invitation = Invitation::with(['store', 'role', 'inviter'])->where('token_hash', Invitation::hashToken($token))->first();

        if ($invitation === null || $invitation->isExpired() || $invitation->role === null) {
            return null;
        }

        // A store invitation whose store was deleted is as dead as an expired one.
        if (! $invitation->isForPlatform() && $invitation->store === null) {
            return null;
        }

        return $invitation;
    }

    private function accountFor(Invitation $invitation): ?User
    {
        return User::whereRaw('lower(email) = ?', [$invitation->email])->first();
    }

    /** Platform and store tiers never mix (rule 3). */
    private function whyCannotJoin(Invitation $invitation, User $user): ?string
    {
        if ($invitation->isForPlatform()) {
            if ($user->globalRole() !== null) {
                return 'You are already on the platform team.';
            }

            return DB::table('store_user')->where('user_id', $user->id)->where('store_id', '>', 0)->exists()
                ? 'Your account is a member of one or more stores. A store account cannot join the platform team.'
                : null;
        }

        return $user->globalRole() !== null
            ? 'Platform accounts cannot join a store.'
            : null;
    }

    /** Use the invitation for this person. False, and nothing changed, when the link was used already. */
    private function join(Invitation $invitation, User $user): bool
    {
        $joined = DB::transaction(function () use ($invitation, $user) {
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
        DB::table('store_user')->insert([
            'user_id' => $user->id,
            'store_id' => $invitation->store_id ?? 0,
            'role_id' => $invitation->role_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invitation->delete();
    }

    private function afterJoining(Invitation $invitation, User $user): void
    {
        if ($invitation->isForPlatform()) {
            session()->forget('current_store_id');
        } else {
            session(['current_store_id' => $invitation->store_id]);
        }

        ActivityLog::record('invitation.accepted', $invitation->store ?? $user,
            "{$user->name} ({$user->email}) joined ".$this->placeName($invitation)." as {$invitation->role->name}", $user);
    }

    private function welcome(Invitation $invitation): string
    {
        return 'Welcome to '.$this->placeName($invitation).'!';
    }

    private function placeName(Invitation $invitation): string
    {
        return $invitation->isForPlatform() ? 'the '.config('app.name').' team' : $invitation->store->name;
    }
}
