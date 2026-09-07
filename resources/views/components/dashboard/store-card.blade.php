{{-- Store card displayed on the user dashboard. Shows store name, location, role,
     slug, active status, and a "Manage Store" button that switches the session store. --}}
@props(['store'])

<div class="bg-white dark:bg-gray-800 rounded-xs border border-gray-100 dark:border-gray-700 overflow-hidden">
    <div class="p-5">
        {{-- Header: icon, name, location, status badge --}}
        <div class="flex items-start justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xs bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                </div>
                <div>
                    <h4 class="font-semibold text-gray-800 dark:text-white">{{ $store['name'] }}</h4>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ $store['city'] }}, {{ $store['state'] }}
                    </p>
                </div>
            </div>
            {{-- Active/Inactive badge --}}
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium
                {{ $store['is_active'] ? 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400' }}">
                <span class="w-1.5 h-1.5 rounded-full {{ $store['is_active'] ? 'bg-green-500' : 'bg-gray-400' }}"></span>
                {{ $store['is_active'] ? 'Active' : 'Inactive' }}
            </span>
        </div>

        {{-- Meta details: slug and role --}}
        <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-700">
            <div class="flex items-center justify-between text-xs">
                <span class="text-gray-500 dark:text-gray-400">Slug</span>
                <code class="bg-gray-100 dark:bg-gray-700 px-1.5 py-0.5 rounded-md text-gray-700 dark:text-gray-300">
                    {{ $store['slug'] }}
                </code>
            </div>
            <div class="flex items-center justify-between text-xs mt-2">
                <span class="text-gray-500 dark:text-gray-400">Your Role</span>
                <span class="font-medium text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-900/30 px-2 py-0.5 rounded-md">
                    {{ $store['role'] }}
                </span>
            </div>
        </div>

        {{-- Manage Store action --}}
        <div class="mt-4">
            <form method="POST" action="{{ route('store.switch') }}">
                @csrf
                <input type="hidden" name="store_id" value="{{ $store['id'] }}">
                <button type="submit" dusk="switch-store-{{ $store['id'] }}" class="text-xs text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 inline-flex items-center gap-1">
                    Manage Store
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </button>
            </form>
        </div>
    </div>
</div>