<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('Profile Information') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __("Update your account's profile information and email address.") }}
        </p>
    </header>

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6"
        x-data="profileInfo()" @submit="validateBeforeSubmit($event)">
        @csrf
        @method('patch')

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
            <x-auth.form-field name="first_name" label="First Name" :required="true" :value="$user->first_name" autofocus autocomplete="given-name" />

            <x-auth.form-field name="last_name" label="Last Name" :required="true" :value="$user->last_name" autocomplete="family-name" />
        </div>

        {{-- The phone reaches Alpine through Js::from, never inside hand-written quotes (an apostrophe or a
             backslash would break the whole component), and only as one plain value: a flashed phone[]=x
             is an array, and an array here was a 500 on the profile page. --}}
        @php($phone = old('phone', $user->phone))
        <div x-data="{ phone: {{ Js::from(is_scalar($phone) ? (string) $phone : '') }} }">
            <x-auth.form-field name="phone" label="Phone (10 digits)" type="tel" :required="true" :value="$user->phone"
                placeholder="1234567890" x-model="phone" x-on:input="phone = phone.replace(/\D/g, '').slice(0, 10)" />
        </div>

        <x-auth.form-field name="email" label="Email" type="email" :required="true" :value="$user->email" autocomplete="username" />

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Update') }}</x-primary-button>

            @if (session('status') === 'profile-updated')
                <p
                    x-show="show"
                    class="text-sm text-gray-600 dark:text-gray-400"
                >{{ __('Updated.') }}</p>
            @endif
        </div>
    </form>
</section>