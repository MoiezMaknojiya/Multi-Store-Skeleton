<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-white leading-tight">{{ __('Ad Builder') }}</h2>
    </x-slot>

    {{-- The Create tab: which way the screen is mounted comes first, because it cannot change after
         (docs/AD-BUILDER-SPEC.md §12) — every element's box is in stage pixels, so a design for the other
         shape is another design. The Ads tab's New ad dialog asks the very same. --}}
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <x-builder-tabs active="create" />

        <div class="max-w-2xl rounded-lg bg-white p-6 shadow-sm dark:bg-gray-800" dusk="new-ad-choice">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">New ad — which way is the screen?</h3>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Chosen once, for this ad: it cannot be changed after.
            </p>

            <div class="mt-5">
                @include('builder.partials.orientation-choice')
            </div>
        </div>
    </div>
</x-app-layout>
