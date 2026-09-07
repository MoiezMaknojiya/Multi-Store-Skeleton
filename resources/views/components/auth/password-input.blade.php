{{-- Password input with show/hide toggle.
     The input starts with type="password" as a static fallback so the password
     is hidden even before Alpine binds. Once Alpine binds, :type takes over
     and toggles between 'password' and 'text' based on the `show` flag. --}}
@props(['name' => 'password', 'placeholder' => 'Enter your password', 'autocomplete' => 'current-password', 'alpine' => 'passwordToggle()'])

<div class="relative" x-data="{{ $alpine }}">
    <input id="{{ $name }}" type="password" :type="show ? 'text' : 'password'" name="{{ $name }}"
        autocomplete="{{ $autocomplete }}"
        placeholder="{{ $placeholder }}"
        class="form-input-auth pr-11 @error($name) border-red-500 @enderror">

    <button type="button" @click="toggle ? toggle() : (show = !show)"
        class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 hover:text-gray-600">
        {{-- Eye open icon (password hidden) --}}
        <svg x-show="!show" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M15 12a3 3 0 11-6 0 3 3 0 016 0zM2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
        </svg>
        {{-- Eye closed icon (password visible) --}}
        <svg x-show="show" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="display:none">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
        </svg>
    </button>
</div>