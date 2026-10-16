<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('Profile Information') }}
        </h2>
    </header>

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6" novalidate
        x-data="profileInfo()" @submit="validateBeforeSubmit($event)">
        @csrf
        @method('patch')

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
            <x-auth.form-field name="first_name" label="First Name" :required="true" :value="$user->first_name" maxlength="255" autocomplete="given-name" />

            <x-auth.form-field name="last_name" label="Last Name" :required="true" :value="$user->last_name" maxlength="255" autocomplete="family-name" />
        </div>

        {{-- Digits only, ten at most, like every phone field (resources/js/core/digits-only.js). --}}
        <x-auth.form-field name="phone" label="Phone (10 digits)" type="tel" :required="true" :value="$user->phone"
            placeholder="1234567890" data-digits="10" inputmode="numeric" autocomplete="tel-national" />

        {{-- Read-only for a super admin viewing as an account not confirmed yet (ProfileController::emailIsTheirsAlone). --}}
        <x-auth.form-field name="email" label="Email" type="email" :required="true" :value="$user->email" maxlength="255" autocomplete="username"
            :readonly="$emailIsTheirsAlone" :hint="$emailIsTheirsAlone ? 'Only '.$user->name.' can correct this email, signed in themselves.' : null" />

        {{-- "Your profile is saved." arrives as a toast (components/toasts.blade.php). --}}
        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Save Changes') }}</x-primary-button>
        </div>
    </form>

    {{-- The email, confirmed by a link to it (owner's rule, 2026-09-29): a changed address waits here, and the account
         keeps the one it has meanwhile — approved first from that address, then confirmed from the new one (owner,
         2026-10-15), so an open session alone cannot move the account to another inbox. --}}
    @if (session('error'))
        <p class="alert-error mt-4" role="alert" dusk="profile-email-error">{{ session('error') }}</p>
    @endif

    @if (session('status') === 'email-changed')
        <p class="alert-success mt-4" role="status" dusk="profile-email-changed">Your email is now {{ $user->email }}.</p>
    @elseif (session('status') === 'email-change-cancelled')
        <p class="alert-success mt-4" role="status" dusk="profile-email-kept">Your email stays {{ $user->email }}.</p>
    @elseif (session('status') === 'verification-link-sent')
        <p class="alert-success mt-4" role="status" dusk="profile-verification-sent">A new link is on its way to {{ $user->email }}. Open it to confirm your email.</p>
    @elseif (! $user->hasVerifiedEmail())
        {{-- Not confirmed yet: only this page and "Check your inbox" open until it is. --}}
        <div class="alert-warning mt-4" dusk="profile-email-unconfirmed">
            <p>
                Your email is not confirmed yet. Open the link in the email to
                <strong class="break-all">{{ $user->email }}</strong> to use the panel.
            </p>
            <form method="POST" action="{{ route('verification.send') }}" class="mt-3">
                @csrf
                <button type="submit" class="btn-secondary" dusk="profile-email-unconfirmed-resend">Send the Link Again</button>
            </form>
        </div>
    @endif

    @if ($user->pending_email)
        {{-- Which of the two steps it stands at, said as a step: the approval from the address it has, then the new one's link.
             Neither says its email went out — a change waiting from before the approval step had none, and a mail server may
             have refused one — so each says what to do when it has not come. --}}
        <div class="alert-warning mt-4" dusk="profile-email-pending">
            @if ($user->emailChangeApproved())
                <p dusk="profile-email-step-confirm">
                    <span class="font-medium">Step 2 of 2:</span> approved. Open the link in the email to
                    <strong class="break-all">{{ $user->pending_email }}</strong> to finish.
                    Until then your email stays <span class="break-all">{{ $user->email }}</span>.
                    No email? Press Send the Link Again.
                </p>
            @else
                <p dusk="profile-email-step-approve">
                    <span class="font-medium">Step 1 of 2:</span> approve the change to
                    <strong class="break-all">{{ $user->pending_email }}</strong> with the link in the email to
                    <strong class="break-all">{{ $user->email }}</strong>, which stays your email until the change is done.
                    No email? Press Send the Link Again.
                </p>
            @endif
            @if (session('status') === 'email-link-resent')
                <p class="mt-2 font-medium" dusk="profile-email-resent">A new link is on its way.</p>
            @endif
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <form method="POST" action="{{ route('profile.email.resend') }}">
                    @csrf
                    <button type="submit" class="btn-secondary" dusk="profile-email-resend">Send the Link Again</button>
                </form>
                <form method="POST" action="{{ route('profile.email.cancel') }}">
                    @csrf
                    @method('delete')
                    <button type="submit" class="btn-secondary" dusk="profile-email-cancel">Keep My Current Email</button>
                </form>
            </div>
        </div>
    @endif
</section>