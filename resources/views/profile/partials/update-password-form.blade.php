<section x-data="passwordForm({{ Js::from(session('status')) }})">
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('Update Password') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Ensure your account is using a long, random password to stay secure.') }}
        </p>
    </header>

    <form method="post" action="{{ route('password.update') }}" class="mt-6 space-y-6"
        @submit="validateBeforeSubmit($event)">
        @csrf
        @method('put')

        <x-auth.form-field name="current_password" label="Current Password" type="password" :required="true"
            bag="updatePassword" id="update_password_current_password" autocomplete="current-password" />

        <x-auth.form-field name="password" label="New Password" type="password" :required="true"
            bag="updatePassword" id="update_password_password" autocomplete="new-password" />

        <x-auth.form-field name="password_confirmation" label="Confirm Password" type="password" :required="true"
            bag="updatePassword" id="update_password_password_confirmation" autocomplete="new-password" />

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Update') }}</x-primary-button>

            <p
                x-show="showSuccess"
                class="text-sm text-gray-600 dark:text-gray-400"
                style="display: none;"
            >{{ __('Updated.') }}</p>
        </div>
    </form>
</section>