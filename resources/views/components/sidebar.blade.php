{{-- SIDEBAR - Main navigation panel with collapsible width. Closed on a phone it is invisible as well as narrow: a
     keyboard and a screen reader skip it, instead of walking through ten links nobody can see. --}}
<aside id="main-sidebar" :class="sidebarOpen ? 'w-64' : 'w-0 invisible lg:visible lg:w-19'"
    class="flex-shrink-0 flex flex-col
    fixed inset-y-0 left-0 z-30 overflow-hidden
    bg-white dark:bg-gray-900 border-r border-gray-200 dark:border-gray-700
    lg:static">

    {{-- Logo Section --}}
    <div class="flex items-center gap-3 px-5 py-3 border-b border-gray-200 dark:border-gray-700 min-h-[64px]">
        <img src="/brand/logo.svg" alt="" width="36" height="36" class="h-9 w-9 flex-shrink-0">
        <span x-show="sidebarOpen" data-sidebar-label
            class="text-gray-900 dark:text-white text-lg font-bold tracking-tight whitespace-nowrap">{{ config('app.name') }}</span>
    </div>

    {{-- Navigation links - each rendered by the nav-item component --}}
    <nav class="flex-1 overflow-y-auto overflow-x-hidden px-3 py-4 space-y-1" aria-label="Main">
        <x-sidebar.nav-item
            href="{{ route('dashboard') }}"
            routeMatch="dashboard"
            label="Dashboard"
            icon="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />

        {{-- Two different sidebars share this file. The platform team (a global role) works
             above the organizations: every organization, every account, the platform's own pages. An organization
             member works inside one organization: its screens and content, its team — and, when their
             role there carries them, its channels and activity, for that organization alone.
             The organizations themselves are the platform's page only: a member changes the organization they
             work in, and sees every organization they belong to, under Settings → Organizations (the user menu,
             and the account at the foot of this bar). See docs/ORGANIZATION-SPEC.md §8. --}}
        @php($__onPlatform = auth()->user()->globalRole() !== null)

        @if ($__onPlatform)
            {{-- Accounts, plus — for super admins — the platform's roles and the permission
                 catalogue. Each link takes the same locks as its routes. A super admin always
                 holds user-view (Gate::before in AppServiceProvider), so the group needs no
                 super-admin case of its own. --}}
            @php($__superAdmin = auth()->user()->isSuperAdmin())
            @php($__canPermissions = $__superAdmin && auth()->user()->can('permission-view'))
            @can('user-view')
            <x-sidebar.nav-group
                href="{{ route('users.view') }}"
                routeMatch="users.*"
                :expand="['roles.*', 'permissions.*']"
                label="Users"
                icon="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z">
                @if ($__superAdmin)
                <x-sidebar.nav-subitem href="{{ route('roles.view') }}" routeMatch="roles.*" label="Roles" />
                @endif
                @if ($__canPermissions)
                <x-sidebar.nav-subitem href="{{ route('permissions.view') }}" routeMatch="permissions.*" label="Permissions" />
                @endif
            </x-sidebar.nav-group>
            @endcan
        @endif

        {{-- Users first, then Organizations (owner, 2026-09-30). --}}
        @if ($__onPlatform && auth()->user()->can('organization-view'))
        <x-sidebar.nav-item
            href="{{ route('organizations.view') }}"
            routeMatch="organizations.*"
            label="Organizations"
            icon="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
        @endif

        @can('screen-view')
        <x-sidebar.nav-item
            href="{{ route('screens.view') }}"
            routeMatch="screens.*"
            label="Screens"
            icon="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
        @endcan

        {{-- Screens, then the files that fill them: the order somebody actually sets an organization up in. --}}
        @can('media-view')
        <x-sidebar.nav-item
            href="{{ route('media.view') }}"
            routeMatch="media.*"
            label="Media Library"
            icon="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
        @endcan

        {{-- The Ad Builder: design a 1920×1080 advert, publish it, and it joins the media a playlist plays. Its row
             opens the ads; Assets — the pictures and videos the ads are made of — is its sub-link, as Roles is under
             Users (owner, 2026-09-30: no tabs inside the pages). Both pages ask for View Ads, as their routes do. --}}
        @can('ad-view')
        <x-sidebar.nav-group
            href="{{ route('builder.index') }}"
            routeMatch="builder.index"
            :expand="['builder.assets']"
            label="Ad Builder"
            icon="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z">
            <x-sidebar.nav-subitem href="{{ route('builder.assets') }}" routeMatch="builder.assets" label="Assets" />
        </x-sidebar.nav-group>
        @endcan

        @unless ($__onPlatform)
            {{-- The organization's own people: its members and its roles. Accounts are the platform's page (owner's rule, 2026-09-17). --}}
            {{-- Inline form only in this file: Blade reads a block-form PHP section as starting at the
                 FIRST inline one above it, and the page stops compiling. --}}
            @php($__teamHome = collect(['member-view' => 'members.view', 'role-view' => 'roles.view'])->first(fn ($route, $permission) => auth()->user()->can($permission)))
            @if ($__teamHome)
            <x-sidebar.nav-group
                href="{{ route($__teamHome) }}"
                routeMatch="members.*"
                :expand="['roles.*']"
                label="Team"
                icon="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z">
                @can('member-view')
                <x-sidebar.nav-subitem href="{{ route('members.view') }}" routeMatch="members.*" label="Members" />
                @endcan
                @can('role-view')
                <x-sidebar.nav-subitem href="{{ route('roles.view') }}" routeMatch="roles.*" label="Roles" />
                @endcan
            </x-sidebar.nav-group>
            @endif
        @endunless

        {{-- The platform's own advertising, sold to brands and carried by organizations that
             agreed. Not an organization's page at all — only a super admin ever sees it. --}}
        @can('campaign-manage')
        <x-sidebar.nav-item
            href="{{ route('campaigns.view') }}"
            routeMatch="campaigns.*"
            label="Advertising"
            icon="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z" />
        @endcan

        {{-- Channels: above the organizations every channel; inside an organization, the organization's own
             channels for its own screens (see ChannelController). --}}
        @can('channel-view')
        <x-sidebar.nav-item
            href="{{ route('channels.view') }}"
            routeMatch="channels.*"
            label="Channels"
            icon="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
        @endcan

        {{-- Activity Log: above the organizations every organization's history; inside an organization, that
             organization's own (see ActivityLogController). --}}
        @can('activity-view')
        <x-sidebar.nav-item
            href="{{ route('activity.view') }}"
            routeMatch="activity.*"
            label="Activity Log"
            icon="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        @endcan

    </nav>

    {{-- The account at the foot of the bar opens Settings: the profile, and the organization's settings. --}}
    <div class="px-3 py-4 border-t border-gray-200 dark:border-gray-700">
        <a href="{{ route('profile.edit') }}" dusk="sidebar-settings" title="Settings"
           aria-label="Settings — {{ auth()->user()->name ?? 'User' }}"
           @if (request()->routeIs('profile.*', 'organization-settings.*')) aria-current="page" @endif
           class="flex items-center gap-3 px-2 py-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 {{ request()->routeIs('profile.*', 'organization-settings.*') ? 'bg-gray-100 dark:bg-gray-800' : '' }}">
            <div class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center text-white text-sm font-semibold flex-shrink-0" aria-hidden="true">
                {{ mb_strtoupper(mb_substr(auth()->user()->name ?: 'U', 0, 1)) }}
            </div>
            <div x-show="sidebarOpen" data-sidebar-label class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-900 dark:text-white truncate">
                    {{ auth()->user()->name ?? 'User' }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 truncate">Settings</p>
            </div>
        </a>
    </div>
</aside>
