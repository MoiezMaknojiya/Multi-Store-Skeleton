{{-- The page an invitation link opens. Four states (docs/ORGANIZATION-SPEC.md rule 16):
     register (no account yet), login (an account exists), accept (signed in as the invitee),
     mismatch (signed in as somebody else). $place — the organization, or the platform team — comes from
     InvitationResponseController::placeName, the same words its log lines and welcome use. --}}
<x-guest-layout :title="'Join '.$place">
    <div dusk="invitation-page" data-state="{{ $state }}">
        <span class="badge-info">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
            </svg>
            Invitation
        </span>

        <h1 class="auth-title mt-4">Join {{ $place }}</h1>

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
            <p class="mt-2 text-xs text-gray-500">Expires {{ $invitation->expires_at->toFormattedDayDateString() }}.</p>
        </div>

        @if (session('error'))
            <div class="alert-error mt-5" role="alert">
                {{ session('error') }}
            </div>
        @endif

        @switch($state)
            @case('register')
                <p class="mt-6 text-sm text-gray-500">Create your account to accept. You'll sign in with this email and the password you choose.</p>

                <form method="POST" action="{{ route('invitations.register', $token) }}" class="mt-5 space-y-5" dusk="invitation-register-form" novalidate
                    x-data="invitationRegisterForm()" @submit="handleSubmit($event)">
                    @csrf

                    {{-- Read-only, not disabled: a password manager files the new password under this address (it reads
                         only enabled fields), not under the phone number typed below it. It is never posted — no name. --}}
                    <x-auth.form-field name="email_display" label="Email" id="invitation_email">
                        <input id="invitation_email" type="email" value="{{ $invitation->email }}" class="form-input-auth bg-gray-50 text-gray-600"
                            readonly autocomplete="username" maxlength="255">
                    </x-auth.form-field>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-auth.form-field name="first_name" label="First Name" placeholder="First name" maxlength="255" autocomplete="given-name" :required="true" dusk="invitation-first-name" />
                        <x-auth.form-field name="last_name" label="Last Name" placeholder="Last name" maxlength="255" autocomplete="family-name" :required="true" dusk="invitation-last-name" />
                    </div>

                    <x-auth.form-field name="phone" label="Phone (10 digits)" type="tel" placeholder="1234567890" data-digits="10" inputmode="numeric" autocomplete="tel-national" :required="true" dusk="invitation-phone" />

                    {{-- One show/hide for both, on the confirmation (owner, 2026-10-06). --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4" x-data="passwordToggle()">
                        <x-auth.form-field name="password" label="Password" type="password" :required="true" shared :eye="false"
            placeholder="Create a password" autocomplete="new-password" hint="At least 8 characters." />
                        <x-auth.form-field name="password_confirmation" label="Confirm Password" type="password" :required="true" shared
            placeholder="Confirm your password" autocomplete="new-password" />
                    </div>

                    <button type="submit" class="btn-primary-auth" dusk="invitation-register">Create Account and Join</button>
                </form>
                @break

            @case('login')
                <p class="mt-6 text-sm text-gray-600">
                    An account already exists for <span class="font-medium">{{ $invitation->email }}</span>.
                    Sign in with it and you'll come straight back here to accept.
                </p>
                <a href="{{ route('login') }}" class="btn-primary-auth mt-5" dusk="invitation-login">Sign In to Accept</a>
                @break

            @case('accept')
                <p class="mt-6 text-sm text-gray-600">You're signed in as <span class="font-medium">{{ auth()->user()->email }}</span>.</p>
                <form method="POST" action="{{ route('invitations.accept', $token) }}" class="mt-5">
                    @csrf
                    <button type="submit" class="btn-primary-auth" dusk="invitation-accept">Accept Invitation</button>
                </form>
                @break

            @case('mismatch')
                <div class="alert-warning mt-6" dusk="invitation-mismatch">
                    You're signed in as <span class="font-semibold">{{ auth()->user()->email }}</span>, but this invitation is for
                    <span class="font-semibold">{{ $invitation->email }}</span>. Sign out, then open the link again.
                </div>
                <form method="POST" action="{{ route('logout') }}" class="mt-5">
                    @csrf
                    <button type="submit" class="btn-primary-auth">Sign Out</button>
                </form>
                @break
        @endswitch

        {{-- Declining deletes the invitation, so it asks first: one stray tap under the main button must not end it. --}}
        @if ($state !== 'mismatch')
            <div class="mt-4 text-center" x-data="{ sure: false }">
                <button type="button" x-show="! sure" @click="sure = true" dusk="invitation-decline"
                    class="rounded-md px-3 py-2 text-sm text-gray-600 hover:text-gray-800 hover:underline">
                    Decline Invitation
                </button>
                <form x-show="sure" x-cloak method="POST" action="{{ route('invitations.decline', $token) }}" class="alert-warning text-left">
                    @csrf
                    <p>Decline? This link stops working, and you would need a new invitation to join.</p>
                    <div class="mt-3 flex flex-wrap justify-end gap-2">
                        <button type="button" class="btn-secondary" @click="sure = false" dusk="invitation-decline-keep">Keep It</button>
                        <button type="submit" class="btn-danger" dusk="invitation-decline-confirm">Decline</button>
                    </div>
                </form>
            </div>
        @endif
    </div>
</x-guest-layout>
