<x-guest-layout title="Create your account">
    <h1 class="auth-title mb-1">Create your account</h1>
    <p class="text-sm text-gray-500 mb-8">
        Already have an account?
        <a href="{{ route('login') }}" class="text-blue-600 hover:underline font-medium">Sign in</a>
    </p>

    @unless ($signupOpen)
        <div class="alert-warning mb-6" role="status">
            Registration is not available right now. Please contact the administrator.
        </div>
    @endunless

    <form method="POST" action="{{ route('register') }}" class="space-y-5" dusk="register-form" novalidate
        x-data="registerForm()" @submit="handleSubmit($event)">
        @csrf
        <x-auth.robot-trap />

        @error('form')
            <div class="alert-error" role="alert" dusk="register-form-error">{{ $message }}</div>
        @enderror

        {{-- Section 1: the owner --}}
        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Your Details</h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <x-auth.form-field name="first_name" label="First Name" placeholder="First name" maxlength="255" autocomplete="given-name" :required="true" />
            <x-auth.form-field name="last_name" label="Last Name" placeholder="Last name" maxlength="255" autocomplete="family-name" :required="true" />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <x-auth.form-field name="phone" label="Phone (10 digits)" type="tel" placeholder="1234567890" data-digits="10" inputmode="numeric" autocomplete="tel-national" :required="true" />
            <x-auth.form-field name="email" label="Email" type="email" placeholder="Enter your email" maxlength="255" autocomplete="username" :required="true" />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <x-auth.form-field name="password" label="Password" type="password" :required="true"
            placeholder="Create a password" autocomplete="new-password" hint="At least 8 characters." />
            <x-auth.form-field name="password_confirmation" label="Confirm Password" type="password" :required="true"
            placeholder="Confirm your password" autocomplete="new-password" />
        </div>

        {{-- Section 2: their store --}}
        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500 pt-2 border-t border-gray-100">Your Store</h2>

        <x-auth.form-field name="store_name" label="Store Name" placeholder="e.g. Fresh Mart" maxlength="255" autocomplete="organization" :required="true" />

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <x-auth.form-field name="street" label="Street" placeholder="Street address" maxlength="255" autocomplete="address-line1" :required="true" />
            <x-auth.form-field name="suite" label="Suite/Unit (optional)" placeholder="Suite" maxlength="100" autocomplete="address-line2" />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <x-auth.form-field name="city" label="City" placeholder="City" maxlength="100" autocomplete="address-level2" :required="true" />
            <x-auth.form-field name="state" label="State" :required="true">
                <select id="state" name="state" autocomplete="address-level1" aria-required="true"
                    @error('state') aria-invalid="true" aria-describedby="state-error" @enderror
                    class="form-input-auth @error('state') !border-red-500 @enderror">
                    <option value="">Select State</option>
                    @foreach ($states as $code => $name)
                        <option value="{{ $code }}" @selected(old('state') === $code)>{{ $name }} ({{ $code }})</option>
                    @endforeach
                </select>
            </x-auth.form-field>
            <x-auth.form-field name="zip_code" label="Zip Code" placeholder="Zip code" data-digits="10" inputmode="numeric" autocomplete="postal-code" :required="true" />
        </div>

        <button type="submit" class="btn-primary-auth" @disabled(! $signupOpen)>
            Create Account
        </button>
    </form>
</x-guest-layout>
