<x-guest-layout>
    <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
        {{ __('Forgot your password? No problem. Just let us know your email address and we will email you a password reset link that will allow you to choose a new one.') }}
    </div>

    <x-auth.session-status class="mb-4" :status="session('status')" />

    {{-- A plain POST form, so it is on the auth track like the sign-in page: x-auth.form-field paints the
         red border and the message under the field, and old() comes back only as one plain value. --}}
    <form method="POST" action="{{ route('password.email') }}" class="space-y-5" dusk="forgot-password-form"
        x-data="forgotPasswordForm()" @submit="handleSubmit($event)">
        @csrf

        <x-auth.form-field name="email" label="Email" type="email" placeholder="Enter your email"
            autofocus autocomplete="username" :required="true" />

        <button type="submit" class="btn-primary-auth" dusk="forgot-password-submit">
            {{ __('Email Password Reset Link') }}
        </button>
    </form>
</x-guest-layout>
