<x-focused-layout>
    <x-slot name="header">Select a Store</x-slot>

    <div class="mb-4">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            You belong to more than one store. Choose the one you want to work in.
        </p>
    </div>

    {{-- All the user's stores as cards; the grid wraps and scrolls naturally. --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        @foreach ($myStores as $store)
            <x-dashboard.store-card :store="$store" />
        @endforeach
    </div>
</x-focused-layout>
