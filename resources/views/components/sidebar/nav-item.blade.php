{{-- Sidebar navigation link with icon, active state, and collapsed tooltip.
     Used in sidebar.blade.php for every menu item to eliminate per-link duplication. --}}
@props(['href', 'routeMatch', 'icon', 'label'])

@php
    $isActive = request()->routeIs($routeMatch);
@endphp

<a href="{{ $href }}"
    class="{{ $isActive ? 'nav-link-active' : 'nav-link' }}"
    :title="!sidebarOpen ? '{{ $label }}' : ''">
    <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icon }}" />
    </svg>
    <span x-show="sidebarOpen" data-sidebar-label class="whitespace-nowrap">{{ $label }}</span>
</a>