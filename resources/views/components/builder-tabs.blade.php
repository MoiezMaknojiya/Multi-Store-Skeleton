{{-- The Ad Builder's two tabs (docs/AD-BUILDER-SPEC.md §5). Ads lists what has been saved and starts a new one
     with New ad, Assets holds the pictures and videos the designs are made of. There is no Create tab (owner,
     2026-09-29): New ad on the Ads tab is the one way in. Each tab is a page of its own, so a refresh, a
     bookmark and the back button all behave. --}}
@props(['active'])

@php($__tabs = [
    'ads' => ['label' => 'Ads', 'route' => 'builder.index', 'can' => 'ad-view'],
    'assets' => ['label' => 'Assets', 'route' => 'builder.assets', 'can' => 'ad-view'],
])

<nav class="flex gap-6 border-b border-gray-200 dark:border-gray-700" aria-label="Ad Builder" dusk="builder-tabs">
    @foreach ($__tabs as $key => $tab)
        @can($tab['can'])
            {{-- The open tab is the one with aria-current; tab-link colours it (app.css). --}}
            <a href="{{ route($tab['route']) }}" dusk="builder-tab-{{ $key }}" class="tab-link"
               @if ($active === $key) aria-current="page" @endif>{{ $tab['label'] }}</a>
        @endcan
    @endforeach
</nav>
