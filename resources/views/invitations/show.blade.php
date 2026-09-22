{{-- The page an invitation link opens. Four states (docs/STORE-ORGANIZATION-SPEC.md rule 16):
     register (no account yet), login (an account exists), accept (signed in as the invitee),
     mismatch (signed in as somebody else). $place — the store, or the platform team — comes from
     InvitationResponseController::placeName, the same words its log lines and welcome use. --}}
<x-guest-layout>
    <div dusk="invitation-page" data-state="{{ $state }}">
        <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
            </svg>
            Invitation
        </span>

        <h2 class="mt-4 text-2xl font-bold text-gray-800">Join {{ $place }}</h2>

        <div class="mt-5 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3.5">
            <p class="text-sm text-gray-700">
                @if ($invitation->inviter)
                    <span class="font-semibold">{{ $invitation->inviter->name }}</span> invited
                @else
                    You were invited
                @endif
                <span class="font-semibold">{{ $invitation->email }}</span> to join as
                <span class="font-semibold" dusk="invitation-role">{{ $invitation->role->name }}</span>.
            </p>
            <p class="mt-1 text-xs text-gray-500">{{ $invitation->role->description() }}</p>
            <p class="mt-2 text-xs text-gray-400">Expires {{ $invitation->expires_at->toFormattedDayDateString() }}.</p>
        </div>

        @if (session('error'))
            <div class="mt-5 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
                {{ session('error') }}
            </div>
        @endif

        @switch($state)
            @case('register')
                <p class="mt-6 text-sm text-gray-500">Create your account to accept. You'll sign in with this email and the password you choose.</p>

                <form method="POST" action="{{ route('invitations.register', $token) }}" class="mt-5 space-y-5" dusk="invitation-register-form"
                    x-data="invitationRegisterForm()" @submit="handleSubmit($event)">
                    @csrf

                    <x-auth.form-field name="email_display" label="Email" id="invitation_email">
                        <input id="invitation_email" type="email" value="{{ $invitation->email }}" class="form-input-auth bg-gray-50 text-gray-500" disabled>
                    </x-auth.form-field>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-auth.form-field name="first_name" label="First Name" placeholder="First name" autocomplete="given-name" :required="true" dusk="invitation-first-name" />
                        <x-auth.form-field name="last_name" label="Last Name" placeholder="Last name" autocomplete="family-name" :required="true" dusk="invitation-last-name" />
                    </div>

                    <x-auth.form-field name="phone" label="Phone (10 digits)" type="tel" placeholder="1234567890" autocomplete="tel" :required="true" dusk="invitation-phone" />

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-auth.form-field name="password" label="Password" :required="true">
                            <x-slot name="input">
                                <x-auth.password-input name="password" placeholder="Create a password" autocomplete="new-password" />
                            </x-slot>
                        </x-auth.form-field>
                        <x-auth.form-field name="password_confirmation" label="Confirm Password" :required="true">
                            <x-slot name="input">
                                <x-auth.password-input name="password_confirmation" placeholder="Confirm your password" autocomplete="new-password" />
                            </x-slot>
                        </x-auth.form-field>
                    </div>

                    <button type="submit" class="btn-primary-auth" dusk="invitation-register">Create account and join</button>
                </form>
                @break

            @case('login')
                <p class="mt-6 text-sm text-gray-600">
                    An account already exists for <span class="font-medium">{{ $invitation->email }}</span>.
                    Sign in with it and you'll come straight back here to accept.
                </p>
                <a href="{{ route('login') }}" class="btn-primary-auth mt-5" dusk="invitation-login">Sign in to accept</a>
                @break

            @case('accept')
                <p class="mt-6 text-sm text-gray-600">You're signed in as <span class="font-medium">{{ auth()->user()->email }}</span>.</p>
                <form method="POST" action="{{ route('invitations.accept', $token) }}" class="mt-5">
                    @csrf
                    <button type="submit" class="btn-primary-auth" dusk="invitation-accept">Accept invitation</button>
                </form>
                @break

            @case('mismatch')
                <div class="mt-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800" dusk="invitation-mismatch">
                    You're signed in as <span class="font-semibold">{{ auth()->user()->email }}</span>, but this invitation is for
                    <span class="font-semibold">{{ $invitation->email }}</span>. Sign out, then open the link again.
                </div>
                <form method="POST" action="{{ route('logout') }}" class="mt-5">
                    @csrf
                    <button type="submit" class="btn-primary-auth">Sign out</button>
                </form>
                @break
        @endswitch

        @if ($state !== 'mismatch')
            <form method="POST" action="{{ route('invitations.decline', $token) }}" class="mt-4 text-center">
                @csrf
                <button type="submit" class="text-sm text-gray-500 hover:text-gray-700 hover:underline" dusk="invitation-decline">
                    Decline invitation
                </button>
            </form>
        @endif
    </div>
</x-guest-layout>
