<x-guest-layout title="Forgot password">
    <h1 class="auth-title mb-1">Forgot your password?</h1>
    <p class="mb-6 text-sm text-gray-600">
        No problem. Type your email address and we will email you a link to choose a new one.
    </p>

    <x-auth.session-status class="mb-4" :status="session('status')" />

    {{-- A plain POST form, so it is on the auth track like the sign-in page: x-auth.form-field paints the
         red border and the message under the field, and old() comes back only as one plain value. --}}
    <form method="POST" action="{{ route('password.email') }}" class="space-y-5" dusk="forgot-password-form" novalidate
        x-data="forgotPasswordForm()" @submit="handleSubmit($event)">
        @csrf

        <x-auth.form-field name="email" label="Email" type="email" placeholder="Enter your email"
            maxlength="255" autofocus autocomplete="username" :required="true" />

        <button type="submit" class="btn-primary-auth" dusk="forgot-password-submit">
            {{ __('Email Password Reset Link') }}
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-gray-600">
        <a href="{{ route('login') }}" class="font-medium text-blue-600 hover:underline" dusk="back-to-sign-in">Back to sign in</a>
    </p>
</x-guest-layout>
