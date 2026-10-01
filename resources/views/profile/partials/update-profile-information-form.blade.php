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

        <x-auth.form-field name="email" label="Email" type="email" :required="true" :value="$user->email" maxlength="255" autocomplete="username" />

        {{-- "Your profile is saved." arrives as a toast (components/toasts.blade.php). --}}
        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Save Changes') }}</x-primary-button>
        </div>
    </form>

    {{-- The email, confirmed by a link to it (owner's rule, 2026-09-29): a changed address waits here until its link
         is opened, and the account keeps the one it has meanwhile. --}}
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
        <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
             dusk="profile-email-unconfirmed">
            <p>
                Your email is not confirmed yet. Open the link we sent to
                <strong class="break-all">{{ $user->email }}</strong> to use the panel.
            </p>
            <form method="POST" action="{{ route('verification.send') }}" class="mt-3">
                @csrf
                <button type="submit" class="btn-secondary" dusk="profile-email-unconfirmed-resend">Send the Link Again</button>
            </form>
        </div>
    @endif

    @if ($user->pending_email)
        <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
             dusk="profile-email-pending">
            <p>
                We sent a link to <strong class="break-all">{{ $user->pending_email }}</strong>. Your email changes once
                you open it; until then it stays {{ $user->email }}.
            </p>
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