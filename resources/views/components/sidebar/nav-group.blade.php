{{-- Sidebar nav item with nested sub-links (e.g. Users > Roles, Permissions).
     The parent row navigates like a normal nav-item; the chevron button independently
     expands/collapses the child list. Auto-expands when the current route matches the
     parent or any pattern in `expand`, so a direct link to a child page still shows it nested.

     When the sidebar itself is collapsed to its icon-only rail, there's no room to show
     children inline — instead, hovering the row opens a flyout (teleported to <body> so the
     sidebar's `overflow-x-hidden` doesn't clip it) with the same links, so Roles/Permissions
     stay reachable even when Users is collapsed to just an icon. --}}
@props(['href', 'routeMatch', 'icon', 'label', 'expand' => []])

@php
    $isActive = request()->routeIs($routeMatch);
    $isOpenByDefault = $isActive || request()->routeIs(...$expand);
@endphp

<div x-data="{
        open: {{ $isOpenByDefault ? 'true' : 'false' }},
        flyout: false, flyoutTop: 0, flyoutLeft: 0, flyoutTimer: null,
        openFlyout() {
            if (sidebarOpen) return;
            clearTimeout(this.flyoutTimer);
            this.flyoutTop = this.$refs.navGroupRow.getBoundingClientRect().top;
            this.flyoutLeft = document.getElementById('main-sidebar').getBoundingClientRect().right + 8;
            this.flyout = true;
        },
        closeFlyout() {
            clearTimeout(this.flyoutTimer);
            this.flyoutTimer = setTimeout(() => { this.flyout = false }, 250);
        },
    }"
    class="space-y-1"
    @mouseenter="openFlyout()"
    @mouseleave="closeFlyout()">
    <div class="flex items-center gap-1" x-ref="navGroupRow">
        {{-- The label reaches Alpine through Js::from, never inside hand-written quotes: an apostrophe in
             it would close the string and break the whole row. --}}
        <a href="{{ $href }}"
            class="{{ $isActive ? 'nav-link-active' : 'nav-link' }} flex-1 min-w-0"
            :title="!sidebarOpen ? {{ Js::from($label) }} : ''">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icon }}" />
            </svg>
            <span x-show="sidebarOpen" data-sidebar-label class="whitespace-nowrap">{{ $label }}</span>
        </a>

        <button type="button" @click="open = !open" x-show="sidebarOpen" data-sidebar-label
            :aria-label="(open ? 'Collapse ' : 'Expand ') + {{ Js::from($label) }}"
            class="p-2 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800 dark:hover:text-gray-300 flex-shrink-0">
            <svg class="w-4 h-4" :class="open ? 'rotate-90' : ''" aria-hidden="true"
                fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
            </svg>
        </button>
    </div>

    {{-- Expanded sidebar: children listed inline, indented under the parent row. --}}
    <div x-show="sidebarOpen && open" x-cloak class="ml-8 space-y-1">
        {{ $slot }}
    </div>

    {{-- Collapsed sidebar (icon rail): same children shown in a flyout on hover. --}}
    <template x-teleport="body">
        <div x-show="!sidebarOpen && flyout" x-cloak
            :style="`top: ${flyoutTop}px; left: ${flyoutLeft}px;`"
            class="fixed z-40 min-w-40 rounded-lg shadow-lg bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 py-1.5 px-1 space-y-0.5"
            @mouseenter="clearTimeout(flyoutTimer); flyout = true" @mouseleave="closeFlyout()">
            <a href="{{ $href }}" class="{{ $isActive ? 'nav-link-active' : 'nav-link' }} !py-2 text-sm font-semibold">{{ $label }}</a>
            <div class="my-1 border-t border-gray-100 dark:border-gray-700"></div>
            {{ $slot }}
        </div>
    </template>
</div>
