{{-- Indented child link rendered inside a nav-group (e.g. Roles/Permissions under Users). --}}
@props(['href', 'routeMatch', 'label'])

@php
    $isActive = request()->routeIs($routeMatch);
@endphp

<a href="{{ $href }}" class="{{ $isActive ? 'nav-link-active' : 'nav-link' }} !py-2" @if ($isActive) aria-current="page" @endif>
    <span>{{ $label }}</span>
</a>
