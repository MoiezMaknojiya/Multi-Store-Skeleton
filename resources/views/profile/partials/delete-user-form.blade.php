<section class="space-y-6" x-data="deleteAccountForm">
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('Delete Account') }}
        </h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __('You leave every organization. What you added stays with the organizations. An only Owner makes someone else an Owner first.') }}
        </p>
    </header>

    <x-danger-button x-on:click.prevent="open">
        {{ __('Delete Account') }}
    </x-danger-button>

    <x-modal name="confirm-user-deletion" :show="$errors->userDeletion->isNotEmpty()" focusable>
        <form method="post" action="{{ route('profile.destroy') }}" class="p-6" x-on:click.stop novalidate
            @submit="validateBeforeSubmit($event)">
            @csrf
            @method('delete')

            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                {{ __('Are you sure you want to delete your account?') }}
            </h2>

            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                {{ __('Your account is removed for good. Enter your password to confirm.') }}
            </p>

            {{-- The plain-POST track, laid out like Delete Organization on Settings → Organizations. No native `required`:
                 client validation (validateBeforeSubmit) shows the same red-border + message UX as every
                 other form. The id stays `password` — unique on this page (the password form uses its own). --}}
            <div class="mt-6">
                <x-auth.form-field name="password" label="Your password" type="password" bag="userDeletion"
                    :required="true" autocomplete="current-password" />
            </div>

            <div class="mt-6 flex flex-wrap justify-end gap-3">
                <x-secondary-button x-on:click="close">
                    {{ __('Cancel') }}
                </x-secondary-button>

                <x-danger-button>
                    {{ __('Delete Account') }}
                </x-danger-button>
            </div>
        </form>
    </x-modal>
</section>
