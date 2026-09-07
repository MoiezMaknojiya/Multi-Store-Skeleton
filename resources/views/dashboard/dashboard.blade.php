<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-gray-800 dark:text-white">Dashboard</h2>
    </x-slot>

    @auth
        {{-- Global users (Super-Admin or a custom global role) get the aggregate
             stats view. Store users with no store get the empty state. The store
             view is intentionally a clean slate — the active store is shown by the
             header switcher, and store-specific content can live here in future. --}}
        @if($view === 'global')
            @include('dashboard.partials.admin')
        @elseif($view === 'empty')
            @include('dashboard.partials.empty')
        @endif
    @endauth

</x-app-layout>
