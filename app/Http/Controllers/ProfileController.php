<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Requests\ProfileUpdateRequest;
use App\Models\ActivityLog;
use App\Models\Store;
use App\Models\User;
use App\Services\StoreTeam;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProfileController extends Controller
{
    use ConfirmsPassword;

    /**
     * Display the user's profile form, with the stores they belong to (shown here while Settings has no
     * Stores tab for them).
     */
    public function edit(Request $request, StoreTeam $team): View
    {
        $user = $request->user();

        return view('profile.edit', [
            'user' => $user,
            'memberships' => $team->membershipsOf($user),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->first_name = $request->first_name;
        $user->last_name = $request->last_name;
        $user->phone = $request->phone;
        $user->email = $request->email;

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $changed = $user->isDirty();
        $user->save();

        if ($changed) {
            ActivityLog::record('profile.updated', $user, 'Updated their own profile');
        }

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Leave one of your stores (docs/STORE-ORGANIZATION-SPEC.md rule 10) — open to every member, whatever
     * their role, from "Your stores" on Settings → Stores or on the profile. It returns to the page it was
     * sent from, unless the store left was the one being worked in: its Stores tab is gone with it, so the
     * profile lists what remains.
     */
    public function leaveStore(Request $request, Store $store, StoreTeam $team): RedirectResponse
    {
        $user = $request->user();
        abort_unless($team->isMember($user, $store), 404);

        if (! $team->leave($user, $store)) {
            return Redirect::back()->withErrors([
                'store' => "You are the only Owner of {$store->name}. Make someone else an Owner before you leave.",
            ], 'storeMembership');
        }

        $leftTheStoreWorkedIn = (int) session('current_store_id') === $store->id;

        if ($leftTheStoreWorkedIn) {
            session()->forget('current_store_id');
        }

        ActivityLog::record('member.left', $store, "Left {$store->name}");

        return ($leftTheStoreWorkedIn ? Redirect::route('profile.edit') : Redirect::back())
            ->with('status', "You left {$store->name}.");
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request, StoreTeam $team): RedirectResponse
    {
        // A super admin anchors the platform. Super admins cannot delete their own account —
        // the UI hides the control and the backend enforces it for any crafted request.
        abort_if($request->user()->isSuperAdmin(), 403, 'Super Admins cannot delete their own account.');

        $user = $request->user();

        // A store always keeps an Owner (docs/STORE-ORGANIZATION-SPEC.md rule 21): hand each one on first.
        // Asked before the password, and again under the stores' lock, where a co-owner leaving at the
        // same moment is seen.
        $this->refuseWhileSoleOwner($team, $user);

        $this->confirmPassword($request, 'userDeletion');

        DB::transaction(function () use ($team, $user) {
            $team->lockStoresOwnedBy($user);
            $this->refuseWhileSoleOwner($team, $user);

            // Logged while still authenticated so the actor snapshot is correct.
            $withdrawn = $user->invitationsToEmail()->count();
            ActivityLog::record('account.deleted', null, "Deleted their own account ({$user->email})"
                .($withdrawn > 0 ? " and the {$withdrawn} pending ".str('invitation')->plural($withdrawn).' to that email' : ''));

            // Signed out BEFORE the row goes: logging out writes a fresh remember token onto the user,
            // and saving a deleted model would insert it again.
            Auth::logout();

            // The account, its memberships and what points at it (User::booted) — nothing else. What the
            // person made belongs to the stores they made it in and stays there (created_by becomes empty).
            $user->delete();
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    /** The last Owner of a store cannot go (rule 21) — the message names the stores to hand on first. */
    private function refuseWhileSoleOwner(StoreTeam $team, User $user): void
    {
        $soleOwned = $team->storesSolelyOwnedBy($user);

        if ($soleOwned->isNotEmpty()) {
            throw ValidationException::withMessages([
                'password' => 'You are the only Owner of '.$soleOwned->pluck('name')->join(', ', ' and ')
                    .'. Make someone else an Owner of '.($soleOwned->count() === 1 ? 'it' : 'them').', or delete '.($soleOwned->count() === 1 ? 'that store' : 'those stores').', first.',
            ])->errorBag('userDeletion');
        }
    }
}
