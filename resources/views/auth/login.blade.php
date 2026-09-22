<x-guest-layout>
    <h2 class="text-2xl font-bold text-gray-800 mb-1">Sign In</h2>
    <p class="text-sm text-gray-500 mb-8">
        Don't have an account?
        <a href="{{ route('register') }}" class="text-blue-600 hover:underline font-medium">Create one</a>
    </p>

    <x-auth.session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="space-y-5"
        x-data="loginForm()" @submit="handleSubmit($event)">
        @csrf

        <x-auth.form-field name="email" label="Email" type="email"
            placeholder="Enter your email" autofocus autocomplete="username" :required="true" />

        {{-- Password with toggle --}}
        <x-auth.form-field name="password" label="Password" :required="true">
            <x-slot name="input">
                <x-auth.password-input name="password" placeholder="Enter your password" autocomplete="current-password" />
            </x-slot>
        </x-auth.form-field>

        {{-- Remember Me + Forgot Password --}}
        <div class="flex items-center justify-between">
            <label class="flex items-center gap-2 cursor-pointer">
                <input id="remember_me" type="checkbox" name="remember"
                       class="form-checkbox">
                <span class="text-sm text-gray-600">Remember me</span>
            </label>
            <a href="{{ route('password.request') }}" class="text-sm text-blue-600 hover:underline">
                Forgot password?
            </a>
        </div>

        <button type="submit" class="btn-primary-auth" dusk="login-submit">
            Sign In
        </button>
    </form>
</x-guest-layout>