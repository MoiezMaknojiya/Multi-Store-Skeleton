{{-- An invitation link that no longer works: expired, already used, revoked, or never real.
     The page says the same thing in every case, so a guessed link learns nothing. --}}
<x-guest-layout>
    <div class="text-center" dusk="invitation-invalid">
        <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-amber-50">
            <svg class="h-7 w-7 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
            </svg>
        </div>

        <h2 class="mt-5 text-2xl font-bold text-gray-800">This invitation is no longer valid</h2>
        <p class="mt-2 text-sm text-gray-500">
            It may have expired, already been used, or been cancelled.
            Ask the person who invited you to send a new one.
        </p>

        <a href="{{ auth()->check() ? route('dashboard') : route('login') }}" class="btn-primary-auth mt-8">
            {{ auth()->check() ? 'Go to dashboard' : 'Go to sign in' }}
        </a>
    </div>
</x-guest-layout>
