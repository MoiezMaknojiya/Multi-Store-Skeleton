{{-- "Check your inbox" (EmailVerificationController::notice): the one page an account that has not confirmed its
     email may open, beside its profile (owner's rule, 2026-09-29). It says where the link went, how to get another,
     how to fix a wrong address, and when an unconfirmed account is removed. --}}
<x-guest-layout title="Check your inbox">
    <div dusk="verify-email-page">
        @if (session('impersonating_original_id'))
            {{-- "Log in as" an account that has not confirmed: the way back is here too, as on every other page. --}}
            <div class="mb-6 overflow-hidden rounded-md" dusk="verify-email-impersonating"><x-impersonation-banner /></div>
        @endif

        <h1 class="auth-title">Check your inbox</h1>

        <p class="mt-3 text-sm text-gray-600">
            We sent a link to <strong class="text-gray-900 break-all" dusk="verify-email-address">{{ $email }}</strong>.
            Open it to confirm your email, and your account is ready: screens, pictures, videos and ads.
        </p>

        @if ($canConfirmForThem)
            {{-- Only a super admin viewing as this account sees it (EmailVerificationController::confirmForThem, which
                 checks again); the account itself never does. --}}
            <form method="POST" action="{{ route('verification.confirm-for-them') }}" class="alert-info mt-4" dusk="verify-email-confirm-box">
                @csrf
                <p>As a super admin, you can confirm this email yourself.</p>
                <button type="submit" class="btn-primary-auth mt-3" dusk="verify-email-confirm-for-them">Confirm Email and Continue</button>
            </form>
        @endif

        @if (session('status') === 'verification-link-sent')
            <p class="alert-success mt-4" role="status" dusk="verify-email-status">A new link is on its way to {{ $email }}.</p>
        @endif

        @if (session('error'))
            <p class="alert-error mt-4" role="alert" dusk="verify-email-error">{{ session('error') }}</p>
        @endif

        {{-- Before "send it again": a link on its way needs a minute, and asking again spends the few an hour allows. --}}
        <p class="mt-6 text-sm text-gray-600">Nothing yet? It can take a minute or two — look in spam or junk too.</p>

        <form method="POST" action="{{ route('verification.send') }}" class="mt-3">
            @csrf
            <button type="submit" class="btn-primary-auth" dusk="verify-email-resend">Send the Link Again</button>
        </form>

        <p class="mt-6 text-sm text-gray-600">
            Wrong address?
            <a href="{{ route('profile.edit') }}" class="text-blue-600 hover:underline" dusk="verify-email-change">Change it in your profile</a>,
            and a new link goes to the right one.
        </p>

        @if ($removedOn)
            <p class="mt-3 text-xs text-gray-500" dusk="verify-email-removal">
                An account that is not confirmed is removed, with its organization, after {{ $removedOn->toFormattedDayDateString() }}.
            </p>
        @endif

        <form method="POST" action="{{ route('logout') }}" class="mt-6">
            @csrf
            <button type="submit" class="text-sm text-gray-600 underline hover:text-gray-900"
                    dusk="verify-email-logout">Sign Out</button>
        </form>
    </div>
</x-guest-layout>
