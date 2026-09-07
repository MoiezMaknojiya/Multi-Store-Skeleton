<x-guest-layout>
    <h2 class="text-2xl font-bold text-gray-800 mb-1">Create your account</h2>
    <p class="text-sm text-gray-500 mb-8">
        Already have an account?
        <a href="{{ route('login') }}" class="text-blue-600 hover:underline font-medium">Sign in</a>
    </p>

    @unless ($signupOpen)
        <div class="mb-6 rounded-md bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
            Registration is not available right now. Please contact the administrator.
        </div>
    @endunless

    <form method="POST" action="{{ route('register') }}" class="space-y-5" dusk="register-form"
        x-data="registerForm()" @submit="handleSubmit($event)">
        @csrf

        {{-- Section 1: the owner --}}
        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400">Your Details</h3>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <x-auth.form-field name="first_name" label="First Name" placeholder="First name" :required="true" />
            <x-auth.form-field name="last_name" label="Last Name" placeholder="Last name" :required="true" />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <x-auth.form-field name="phone" label="Phone (10 digits)" type="tel" placeholder="1234567890" :required="true" />
            <x-auth.form-field name="email" label="Email" type="email" placeholder="Enter your email" autocomplete="username" :required="true" />
        </div>

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

        {{-- Section 2: their store --}}
        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400 pt-2 border-t border-gray-100">Your Store</h3>

        <x-auth.form-field name="store_name" label="Store Name" placeholder="e.g. Fresh Mart" :required="true" />

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <x-auth.form-field name="street" label="Street" placeholder="Street address" :required="true" />
            <x-auth.form-field name="suite" label="Suite/Unit (optional)" placeholder="Suite" />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <x-auth.form-field name="city" label="City" placeholder="City" :required="true" />
            <x-auth.form-field name="state" label="State" :required="true">
                <select id="state" name="state" class="form-input-auth @error('state') border-red-500 @enderror">
                    <option value="">Select State</option>
                    @foreach ($states as $code => $name)
                        <option value="{{ $code }}" @selected(old('state') === $code)>{{ $name }} ({{ $code }})</option>
                    @endforeach
                </select>
            </x-auth.form-field>
            <x-auth.form-field name="zip_code" label="Zip Code" placeholder="Zip code" :required="true" />
        </div>

        <button type="submit" class="btn-primary-auth" @disabled(! $signupOpen)>
            Create Account
        </button>
    </form>
</x-guest-layout>
