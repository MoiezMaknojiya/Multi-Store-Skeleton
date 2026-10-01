{{-- The Settings tabs (owner's rules, 2026-09-17): the person's own profile, and Organizations — for an organization member working
     in an organization whose role there holds View Organizations (User::hasOrganizationsTab). Each tab is a page of its own, so every
     plain form on them keeps returning to where it was sent from. --}}
@props(['active'])

@php($__organizationTab = auth()->user()->hasOrganizationsTab())

<nav class="flex gap-6 border-b border-gray-200 dark:border-gray-700" aria-label="Settings" dusk="settings-tabs">
    {{-- The open tab is the one with aria-current; tab-link colours it (app.css). --}}
    <a href="{{ route('profile.edit') }}" dusk="settings-tab-profile" class="tab-link"
       @if ($active === 'profile') aria-current="page" @endif>Profile</a>

    @if ($__organizationTab)
        <a href="{{ route('organization-settings.edit') }}" dusk="settings-tab-organization" class="tab-link"
           @if ($active === 'organization') aria-current="page" @endif>Organizations</a>
    @endif
</nav>
