{{-- A store the platform has paused (its Active switch off): its pages are closed to its people (EnsureStoreIsActive
     sends every one of them here), and this says why and what still works. --}}
<div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
    <div class="card p-10 text-center" dusk="dashboard-paused">
        <div class="mb-5 inline-flex h-16 w-16 items-center justify-center rounded-full bg-amber-50 dark:bg-amber-900/30">
            <x-icon name="pause-circle" class="h-8 w-8 text-amber-600 dark:text-amber-400" />
        </div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $store->name }} is paused</h2>
        <p class="mx-auto mt-2 max-w-md text-sm text-gray-500 dark:text-gray-400">
            Its pages are closed for now, and its screens keep playing. Contact support to turn it back on.
        </p>
        @if ($hasOtherStores)
            <a href="{{ route('stores.select') }}" class="btn-secondary mt-6" dusk="dashboard-paused-switch">Choose Another Store</a>
        @endif
    </div>
</div>
