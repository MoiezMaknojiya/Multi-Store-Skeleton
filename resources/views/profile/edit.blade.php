<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Settings') }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <x-settings-tabs active="profile" />
            {{-- Profile info + password sit side by side on large screens (stacked on
                 mobile) so the page is not one tall column. --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
                <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                    @include('profile.partials.update-profile-information-form')
                </div>

                <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                    @include('profile.partials.update-password-form')
                </div>
            </div>

            {{-- The stores this person belongs to — here only while Settings has no Stores tab for them (no View Stores
                 where they work, or no store chosen), so anybody can still leave. The platform team is never a member. --}}
            @if (auth()->user()->globalRole() === null && ! auth()->user()->hasStoresTab())
                <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                    <div class="max-w-2xl">
                        @include('profile.partials.store-memberships')
                    </div>
                </div>
            @endif

            {{-- Delete account stays full width below (destructive, kept apart).
                 Hidden for super admins — their self-deletion is blocked (backend
                 enforces it too). --}}
            @unless(auth()->user()->isSuperAdmin())
                <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                    <div class="max-w-xl">
                        @include('profile.partials.delete-user-form')
                    </div>
                </div>
            @endunless
        </div>
    </div>
</x-app-layout>
