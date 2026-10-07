<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Platform\ImpersonateController;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * An account's email, confirmed (owner's rule, 2026-09-29). A customer who signs up can do nothing that uses the
 * server's space until they open the link we email them — the `verified` middleware sends them here meanwhile —
 * and an account never confirmed is removed after User::UNVERIFIED_DAYS days (PruneUnverifiedAccounts). A changed
 * address waits in users.pending_email until the link sent to IT is opened; the old one stands until then.
 *
 * Every link names the account and the address it proves (by its hash), is signed, lasts a while, and opens
 * nothing by itself: the account has to be signed in, as for an invitation's link. A link that is not right says
 * why on the page it lands on, rather than a bare 403.
 */
class EmailVerificationController extends Controller
{
    /** "Check your inbox" — the one page an unconfirmed account may open, beside its profile. */
    public function notice(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('dashboard');
        }

        return view('auth.verify-email', [
            'email' => $user->email,
            'removedOn' => $user->created_at?->copy()->addDays(User::UNVERIFIED_DAYS),
            'canConfirmForThem' => ImpersonateController::superAdminViewingAs($user) !== null,
        ]);
    }

    /**
     * A super admin viewing as an account that has not confirmed (owner, 2026-10-06: "super admin jab 'Login As' per
     * click karne toh usko 'Check your inbox' per le jaye aur waha ek button ho jo super admin ko hi show ho jis per
     * click karne se email verify ho jaye aur agay chale jaye") confirms its email for it, and goes on into the panel:
     * a customer the platform knows, whose link went astray. Only the super admin who started the "Log in as" may —
     * checked again here, whatever the page showed — and the log names them.
     */
    public function confirmForThem(Request $request): RedirectResponse
    {
        $user = $request->user();
        $admin = ImpersonateController::superAdminViewingAs($user);

        abort_if($admin === null, 403, 'Only a super admin viewing as this account can confirm its email.');

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();

            ActivityLog::record('account.verified', $user, "Confirmed the email {$user->email} of {$user->name} for them, by Log In As", $admin);
        }

        return redirect()->route('dashboard')->with('status', "{$user->email} is confirmed.");
    }

    /** The link in the signup email: it confirms the address the account holds now. */
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('dashboard')->with('status', 'Your email is already confirmed.');
        }

        if ($problem = $this->whyNot($request, $user, $id, $hash, $user->email)) {
            return redirect()->route('verification.notice')->with('error', $problem);
        }

        $user->markEmailAsVerified();

        ActivityLog::record('account.verified', $user, "Confirmed their email {$user->email}");

        return redirect()->route('dashboard')->with('status', 'Your email is confirmed. Welcome!');
    }

    /** Another link, for an account still waiting; its mail server's refusal is said, never thrown. */
    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('dashboard');
        }

        // Sent from "Check your inbox" or from the profile: each page says it in its own words — and why not, when
        // it could not go out (User::sendALink).
        $problem = $user->sendVerificationLink();

        return $problem === null
            ? back()->with('status', 'verification-link-sent')
            : back()->with('error', $problem);
    }

    /** The link sent to a changed address: the account takes it now, and it counts as confirmed. */
    public function confirmNewEmail(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = $request->user();

        if ($user->pending_email === null) {
            return redirect()->route('profile.edit')->with('error', 'There is no change of email waiting to be confirmed.');
        }

        if ($problem = $this->whyNot($request, $user, $id, $hash, $user->pending_email)) {
            return redirect()->route('profile.edit')->with('error', $problem);
        }

        $old = $user->email;
        $new = $user->pending_email;

        try {
            $taken = DB::transaction(function () use ($user, $new): bool {
                // Another account may have taken the address since it was asked for (it was free then).
                if (User::where('email', $new)->whereKeyNot($user->id)->lockForUpdate()->exists()) {
                    return true;
                }

                $user->forceFill(['email' => $new, 'pending_email' => null, 'email_verified_at' => now()])->save();

                return false;
            });
        } catch (QueryException) {
            $taken = true; // the unique index, at the same moment
        }

        if ($taken) {
            $user->forceFill(['pending_email' => null])->save();

            return redirect()->route('profile.edit')->with('error', "{$new} is used by another account now, so your email stays {$old}.");
        }

        ActivityLog::record('account.email_changed', $user, "Changed their email from {$old} to {$new}");

        return redirect()->route('profile.edit')->with('status', 'email-changed');
    }

    /** Why this link cannot confirm $email for $user — or null when it can. */
    private function whyNot(Request $request, User $user, string $id, string $hash, string $email): ?string
    {
        if (! $request->hasValidSignature()) {
            return 'That link has expired or is not complete. Send a new one below.';
        }

        if ((string) $user->getKey() !== $id) {
            return 'That link is for another account. Sign out and sign in with the account it was sent to.';
        }

        if (! hash_equals(sha1($email), $hash)) {
            return 'That link was sent to an address this account is not using now. Send a new one below.';
        }

        return null;
    }
}
