{{-- Store card on the store-selection page. Shows store name, location, role
     and active status, and switches the session store when picked.

     The WHOLE card is the click target, not just the "Open store" words: the
     card is what a person reads as one thing, so it is what they aim at. That is
     done with the stretch-to-box utility on the button — an overlay that covers
     this box — rather than by wrapping everything in a <button>, which would put
     block elements inside a button and is not valid HTML.

     Consequence to respect: nothing else in this card may become clickable. The
     overlay sits above the content and would swallow the click. --}}
@props(['store'])

<div class="relative card
            transition hover:border-blue-500 hover:shadow-md
            focus-within:border-blue-500 focus-within:ring-2 focus-within:ring-blue-500/40">
    <div class="p-5">
        {{-- Header: icon, name, location, status badge --}}
        {{-- A long name wraps at its words (min-w-0) instead of pushing the status out of the card. --}}
        <div class="flex items-start justify-between gap-3">
            <div class="flex min-w-0 items-center gap-3">
                <div class="w-10 h-10 rounded-xs bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center flex-shrink-0" aria-hidden="true">
                    <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                </div>
                <div class="min-w-0">
                    <h2 class="font-semibold text-gray-800 dark:text-white break-words">{{ $store['name'] }}</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ $store['city'] }}, {{ $store['state'] }}
                    </p>
                </div>
            </div>
            {{-- Active, or paused by the platform (closed to its people until it is turned back on). --}}
            <span class="{{ $store['is_active'] ? 'badge-success' : 'badge-neutral' }}">
                <span class="w-1.5 h-1.5 rounded-full {{ $store['is_active'] ? 'bg-green-500' : 'bg-gray-400' }}" aria-hidden="true"></span>
                {{ $store['is_active'] ? 'Active' : 'Paused' }}
            </span>
        </div>

        {{-- What the person is there (the store's internal slug is nobody's business on this page). --}}
        <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-700">
            <div class="flex items-center justify-between text-xs">
                <span class="text-gray-500 dark:text-gray-400">Your Role</span>
                <span class="badge-info">{{ $store['role'] }}</span>
            </div>
        </div>

        {{-- Open store. Still a real button inside a real form — keyboard
             and screen readers get an ordinary control; stretch-to-box only widens
             where a mouse may land. --}}
        <div class="mt-4">
            <form method="POST" action="{{ route('store.switch') }}">
                @csrf
                <input type="hidden" name="store_id" value="{{ $store['id'] }}">
                <button type="submit" dusk="switch-store-{{ $store['id'] }}" aria-label="Open {{ $store['name'] }}"
                    class="stretch-to-box cursor-pointer text-xs text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 inline-flex items-center gap-1 focus:outline-none">
                    Open Organization
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </button>
            </form>
        </div>
    </div>
</div>