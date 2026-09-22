{{-- The Ad Builder's three tabs (docs/AD-BUILDER-SPEC.md §5). Create opens the editor on an empty stage,
     Ads lists what has been saved, Assets holds the pictures and videos the designs are made of. Each tab
     is a page of its own, so a refresh, a bookmark and the back button all behave. --}}
@props(['active'])

@php($__tabs = [
    'create' => ['label' => 'Create', 'route' => 'builder.create', 'can' => 'ad-store'],
    'ads' => ['label' => 'Ads', 'route' => 'builder.index', 'can' => 'ad-view'],
    'assets' => ['label' => 'Assets', 'route' => 'builder.assets', 'can' => 'ad-view'],
])

<nav class="flex gap-6 border-b border-gray-200 dark:border-gray-700" aria-label="Ad Builder" dusk="builder-tabs">
    @foreach ($__tabs as $key => $tab)
        @can($tab['can'])
            <a href="{{ route($tab['route']) }}" dusk="builder-tab-{{ $key }}"
               @if ($active === $key) aria-current="page" @endif
               @class([
                   '-mb-px border-b-2 px-1 pb-3 text-sm font-medium transition-colors',
                   'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400' => $active === $key,
                   'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => $active !== $key,
               ])>{{ $tab['label'] }}</a>
        @endcan
    @endforeach
</nav>
