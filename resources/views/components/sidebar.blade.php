{{-- SIDEBAR - Main navigation panel with collapsible width --}}
<aside id="main-sidebar" :class="sidebarOpen ? 'w-64' : 'w-0 lg:w-19'"
    class="flex-shrink-0 flex flex-col
    fixed inset-y-0 left-0 z-30 overflow-hidden
    bg-white dark:bg-gray-900 border-r border-gray-200 dark:border-gray-700
    lg:static lg:translate-x-0">

    {{-- Logo Section --}}
    <div class="flex items-center gap-3 px-5 py-3 border-b border-gray-200 dark:border-gray-700 min-h-[64px]">
        <div class="w-9 h-9 rounded-xs bg-blue-600 flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
        </div>
        <span x-show="sidebarOpen" data-sidebar-label
            class="text-gray-900 dark:text-white text-lg font-bold tracking-tight whitespace-nowrap">{{ config('app.name') }}</span>
    </div>

    {{-- Navigation links - each rendered by the nav-item component --}}
    <nav class="flex-1 overflow-y-auto overflow-x-hidden px-3 py-4 space-y-1">
        <x-sidebar.nav-item
            href="{{ route('dashboard') }}"
            routeMatch="dashboard"
            label="Dashboard"
            icon="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />

        @canany(['user-view', 'role-view', 'permission-view'])
        {{-- Header links to the first page this user is actually allowed to open --}}
        <x-sidebar.nav-group
            href="{{ auth()->user()->can('user-view') ? route('users.view') : (auth()->user()->can('role-view') ? route('roles.view') : route('permissions.view')) }}"
            routeMatch="users.*"
            :expand="['roles.*', 'permissions.*']"
            label="Users"
            icon="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z">
            @can('role-view')
            <x-sidebar.nav-subitem href="{{ route('roles.view') }}" routeMatch="roles.*" label="Roles" />
            @endcan

            @can('permission-view')
            <x-sidebar.nav-subitem href="{{ route('permissions.view') }}" routeMatch="permissions.*" label="Permissions" />
            @endcan
        </x-sidebar.nav-group>
        @endcanany

        @can('store-view')
        <x-sidebar.nav-item
            href="{{ route('stores.view') }}"
            routeMatch="stores.*"
            label="Stores"
            icon="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
        @endcan

        @can('activity-view')
        <x-sidebar.nav-item
            href="{{ route('activity.view') }}"
            routeMatch="activity.*"
            label="Activity Log"
            icon="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        @endcan

    </nav>

    {{-- Bottom User Profile --}}
    <div class="px-3 py-4 border-t border-gray-200 dark:border-gray-700">
        <div class="flex items-center gap-3 px-2 py-2">
            <div class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center text-white text-sm font-semibold flex-shrink-0">
                {{ substr(auth()->user()->name ?? 'U', 0, 1) }}
            </div>
            <div x-show="sidebarOpen" data-sidebar-label class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-900 dark:text-white truncate">
                    {{ auth()->user()->name ?? 'User' }}</p>
                <p class="text-xs text-gray-400 dark:text-gray-500 truncate">
                    {{ auth()->user()->email ?? '' }}
                </p>
            </div>
        </div>
    </div>
</aside>
