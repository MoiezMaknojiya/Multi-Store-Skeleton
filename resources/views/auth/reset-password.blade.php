<x-guest-layout title="Choose a new password">
    {{-- The page the emailed link opens (NewPasswordController::create). A plain POST form on the auth track,
         like sign-in and sign-up: x-auth.form-field for the fields, the eye toggle for the passwords. $email
         arrives as a plain string (?email[]=x reads as none), so the link cannot break the page. --}}
    <h1 class="auth-title mb-6">Choose a new password</h1>
    <form method="POST" action="{{ route('password.store') }}" class="space-y-5" dusk="reset-password-form" novalidate
        x-data="resetPasswordForm()" @submit="handleSubmit($event)">
        @csrf

        {{-- The token from the link travels with the form. --}}
        <input type="hidden" name="token" value="{{ $token }}">

        {{-- The email arrives filled in from the link, so the keyboard starts at the new password. --}}
        <x-auth.form-field name="email" label="Email" type="email" :value="$email"
            maxlength="255" autocomplete="username" :required="true" />

        {{-- One show/hide for both, on the confirmation (owner, 2026-10-06). --}}
        <div class="space-y-5" x-data="passwordToggle()">
            <x-auth.form-field name="password" label="Password" type="password" :required="true" autofocus shared :eye="false"
                placeholder="Choose a new password" autocomplete="new-password" hint="At least 8 characters." />

            <x-auth.form-field name="password_confirmation" label="Confirm Password" type="password" :required="true" shared
                placeholder="Confirm your new password" autocomplete="new-password" />
        </div>

        <button type="submit" class="btn-primary-auth" dusk="reset-password-submit">
            {{ __('Reset Password') }}
        </button>
    </form>

    {{-- A link that expired or was used says so under the email; the way on is here, whatever happened. --}}
    <p class="mt-6 text-center text-sm text-gray-600">
        Link not working?
        <a href="{{ route('password.request') }}" class="font-medium text-blue-600 hover:underline" dusk="ask-for-new-link">Ask for a new one</a>
        · <a href="{{ route('login') }}" class="font-medium text-blue-600 hover:underline">Back to sign in</a>
    </p>
</x-guest-layout>
