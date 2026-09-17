{{-- The Settings tabs (owner's rules, 2026-09-17): the person's own profile, and Stores — for a store member working
     in a store whose role there holds View Stores (User::hasStoresTab). Each tab is a page of its own, so every
     plain form on them keeps returning to where it was sent from. --}}
@props(['active'])

@php($__storeTab = auth()->user()->hasStoresTab())

<nav class="flex gap-6 border-b border-gray-200 dark:border-gray-700" aria-label="Settings" dusk="settings-tabs">
    <a href="{{ route('profile.edit') }}" dusk="settings-tab-profile"
       @if ($active === 'profile') aria-current="page" @endif
       @class([
           '-mb-px border-b-2 px-1 pb-3 text-sm font-medium transition-colors',
           'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400' => $active === 'profile',
           'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => $active !== 'profile',
       ])>Profile</a>

    @if ($__storeTab)
        <a href="{{ route('store-settings.edit') }}" dusk="settings-tab-store"
           @if ($active === 'store') aria-current="page" @endif
           @class([
               '-mb-px border-b-2 px-1 pb-3 text-sm font-medium transition-colors',
               'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400' => $active === 'store',
               'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => $active !== 'store',
           ])>Stores</a>
    @endif
</nav>
