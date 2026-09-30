{{-- "Check your inbox" (EmailVerificationController::notice): the one page an account that has not confirmed its
     email may open, beside its profile (owner's rule, 2026-09-29). It says where the link went, how to get another,
     how to fix a wrong address, and when an unconfirmed account is removed. --}}
<x-guest-layout title="Check your inbox">
    <div dusk="verify-email-page">
        @if (session('impersonating_original_id'))
            {{-- "Log in as" an account that has not confirmed: the way back is here too, as on every other page. --}}
            <div class="mb-6 overflow-hidden rounded-md" dusk="verify-email-impersonating"><x-impersonation-banner /></div>
        @endif

        <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Check your inbox</h1>

        <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">
            We sent a link to <strong class="text-gray-900 dark:text-gray-100 break-all" dusk="verify-email-address">{{ $email }}</strong>.
            Open it to confirm your email, and your account is ready: screens, pictures, videos and ads.
        </p>

        @if (session('status') === 'verification-link-sent')
            <p class="mt-4 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950/40 dark:text-green-200"
               dusk="verify-email-status">A new link is on its way to {{ $email }}.</p>
        @endif

        @if (session('error'))
            <p class="mt-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200"
               dusk="verify-email-error">{{ session('error') }}</p>
        @endif

        <form method="POST" action="{{ route('verification.send') }}" class="mt-6">
            @csrf
            <button type="submit" class="btn-primary-auth" dusk="verify-email-resend">Send the link again</button>
        </form>

        <p class="mt-6 text-sm text-gray-600 dark:text-gray-400">
            Wrong address?
            <a href="{{ route('profile.edit') }}" class="text-blue-600 hover:underline dark:text-blue-400" dusk="verify-email-change">Change it in your profile</a>,
            and a new link goes to the right one.
        </p>

        @if ($removedOn)
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400" dusk="verify-email-removal">
                An account that is not confirmed is removed, with its store, after {{ $removedOn->toFormattedDayDateString() }}.
            </p>
        @endif

        <form method="POST" action="{{ route('logout') }}" class="mt-6">
            @csrf
            <button type="submit" class="text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                    dusk="verify-email-logout">Sign out</button>
        </form>
    </div>
</x-guest-layout>
