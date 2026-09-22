<x-guest-layout>
    {{-- The page the emailed link opens (NewPasswordController::create). A plain POST form on the auth track,
         like sign-in and sign-up: x-auth.form-field for the fields, the eye toggle for the passwords. $email
         arrives as a plain string (?email[]=x reads as none), so the link cannot break the page. --}}
    <form method="POST" action="{{ route('password.store') }}" class="space-y-5" dusk="reset-password-form"
        x-data="resetPasswordForm()" @submit="handleSubmit($event)">
        @csrf

        {{-- The token from the link travels with the form. --}}
        <input type="hidden" name="token" value="{{ $token }}">

        <x-auth.form-field name="email" label="Email" type="email" :value="$email"
            autofocus autocomplete="username" :required="true" />

        <x-auth.form-field name="password" label="Password" :required="true">
            <x-slot name="input">
                <x-auth.password-input name="password" placeholder="Choose a new password" autocomplete="new-password" />
            </x-slot>
        </x-auth.form-field>

        <x-auth.form-field name="password_confirmation" label="Confirm Password" :required="true">
            <x-slot name="input">
                <x-auth.password-input name="password_confirmation" placeholder="Confirm your new password" autocomplete="new-password" />
            </x-slot>
        </x-auth.form-field>

        <button type="submit" class="btn-primary-auth" dusk="reset-password-submit">
            {{ __('Reset Password') }}
        </button>
    </form>
</x-guest-layout>
