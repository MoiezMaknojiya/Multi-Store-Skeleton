{{-- Indented child link rendered inside a nav-group (e.g. Roles/Permissions under Users). --}}
@props(['href', 'routeMatch', 'label'])

@php
    $isActive = request()->routeIs($routeMatch);
@endphp

<a href="{{ $href }}" class="{{ $isActive ? 'nav-link-active' : 'nav-link' }} !py-2 text-sm">
    <span class="whitespace-nowrap">{{ $label }}</span>
</a>
