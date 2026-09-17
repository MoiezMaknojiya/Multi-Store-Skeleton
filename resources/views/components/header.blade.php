{{-- Header --}}
<header
    class="flex items-center justify-between h-16 px-4 sm:px-6
           bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700
           flex-shrink-0">

    <div class="flex items-center gap-3">
        <button @click="toggleSidebar()"
            class="p-2 rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800">
            <svg data-sidebar-icon="closed" :class="sidebarOpen ? 'hidden' : 'block'" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
            <svg data-sidebar-icon="open" :class="sidebarOpen ? 'block' : 'hidden'" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h8" />
            </svg>
        </button>
        @isset($header)
            <div class="text-gray-800 dark:text-white font-semibold">
                {{ $header }}</div>
        @endisset
    </div>

    <div class="flex items-center gap-1" x-data="header()">
        {{-- Store switcher: store-tier users only (global users / super admins span
             every store and never switch). One query for the user's stores. --}}
        @php
            $__user = auth()->user();
            $__showSwitcher = $__user && $__user->globalRole() === null;
            $__stores = $__showSwitcher ? $__user->stores()->orderBy('name')->get(['stores.id', 'stores.name']) : collect();
            $__currentId = (int) session('current_store_id');
            $__currentStore = $__stores->firstWhere('id', $__currentId);
        @endphp
        @if($__showSwitcher && $__stores->isNotEmpty())
            <div class="relative mr-1" x-data="{ storeMenu: false }">
                <button @click="storeMenu = !storeMenu" dusk="store-switcher"
                    class="flex items-center gap-2 px-3 py-1.5 rounded-xs border border-gray-200 dark:border-gray-700 hover:bg-gray-100 dark:hover:bg-gray-800 text-sm">
                    <svg class="w-4 h-4 text-blue-600 dark:text-blue-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                    <span class="max-w-[9rem] truncate font-medium text-gray-700 dark:text-gray-300">
                        {{ $__currentStore['name'] ?? 'Select store' }}
                    </span>
                    <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>

                <div x-show="storeMenu" @click.outside="storeMenu = false" x-cloak
                    class="absolute right-0 mt-2 w-60 max-h-72 overflow-y-auto rounded-xs shadow-lg z-50 py-1 bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700">
                    <p class="px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Switch store</p>
                    @foreach($__stores as $__s)
                        <form method="POST" action="{{ route('store.switch') }}">
                            @csrf
                            <input type="hidden" name="store_id" value="{{ $__s['id'] }}">
                            <button type="submit" dusk="store-switch-{{ $__s['id'] }}"
                                class="w-full text-left px-4 py-2 text-sm flex items-center justify-between gap-2 hover:bg-gray-50 dark:hover:bg-gray-700
                                       {{ $__s['id'] === $__currentId ? 'text-blue-600 dark:text-blue-400 font-semibold' : 'text-gray-700 dark:text-gray-300' }}">
                                <span class="truncate">{{ $__s['name'] }}</span>
                                @if($__s['id'] === $__currentId)
                                    <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                    </svg>
                                @endif
                            </button>
                        </form>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Theme Toggle --}}
        <button @click="toggleDark()"
            class="p-2 rounded-xs text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800">
            <svg data-theme-icon="dark" :class="darkMode ? 'block' : 'hidden'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
            </svg>
            <svg data-theme-icon="light" :class="darkMode ? 'hidden' : 'block'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
            </svg>
        </button>

        {{-- User Dropdown --}}
        <div class="relative">
            <button @click="toggle()" dusk="user-menu" class="flex items-center gap-2 px-2 py-1.5 rounded-xs hover:bg-gray-100 dark:hover:bg-gray-800">
                <div
                    class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center text-white text-sm font-semibold">
                    {{ substr(auth()->user()->name ?? 'U', 0, 1) }}
                </div>
                <span
                    class="hidden sm:block text-sm font-medium text-gray-700 dark:text-gray-300">{{ auth()->user()->name ?? 'User' }}</span>
            </button>
            
            <div x-show="userMenu" @click.outside="close()" x-cloak
                class="absolute right-0 mt-2 w-48 rounded-xs shadow-lg z-50 py-1 bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700">
                <a href="{{ route('profile.edit') }}" dusk="user-menu-settings"
                    class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Settings</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" dusk="logout-button"
                        class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-50 dark:hover:bg-gray-700">Log
                        Out</button>
                </form>
            </div>
        </div>
    </div>
</header>