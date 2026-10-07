<section x-data="passwordForm()">
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('Update Password') }}
        </h2>
    </header>

    <form method="post" action="{{ route('password.update') }}" class="mt-6 space-y-6" novalidate
        @submit="validateBeforeSubmit($event)">
        @csrf
        @method('put')

        <x-auth.form-field name="current_password" label="Current Password" type="password" :required="true"
            bag="updatePassword" id="update_password_current_password" autocomplete="current-password" />

        {{-- The new password and its confirmation: one show/hide for both, on the confirmation (owner, 2026-10-06).
             The current password keeps its own. --}}
        <div class="space-y-6" x-data="passwordToggle()">
            <x-auth.form-field name="password" label="New Password" type="password" :required="true" shared :eye="false"
                bag="updatePassword" id="update_password_password" autocomplete="new-password" hint="At least 8 characters." />

            <x-auth.form-field name="password_confirmation" label="Confirm Password" type="password" :required="true" shared
                bag="updatePassword" id="update_password_password_confirmation" autocomplete="new-password" />
        </div>

        {{-- "Your password is changed." arrives as a toast (components/toasts.blade.php). --}}
        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Change Password') }}</x-primary-button>
        </div>
    </form>
</section>