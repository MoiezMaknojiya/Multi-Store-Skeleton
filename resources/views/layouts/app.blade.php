<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Laravel') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@100..900&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- Dark Mode Flash Prevention --}}
    <script>
        if (localStorage.getItem('darkMode') === 'true') {
            document.documentElement.classList.add('dark');
        }
    </script>

    {{-- Sidebar Flash Prevention: apply the persisted open/closed state before Alpine
         loads, so the sidebar renders correctly on first paint instead of popping in
         after a delay. layoutHandler.init() (resources/js/core/layout.js) removes this class
         the moment Alpine takes over. --}}
    <script>
        // On a small screen the sidebar always starts closed (layoutHandler says why).
        if (localStorage.getItem('sidebarOpen') === 'false' || ! window.matchMedia('(min-width: 1024px)').matches) {
            document.documentElement.classList.add('sidebar-closed');
        }
    </script>
</head>

<body class="font-sans antialiased bg-gray-100 dark:bg-gray-900"
    x-data="layoutHandler">

    {{-- Mobile Backdrop --}}
    <div x-show="sidebarOpen"
        class="fixed inset-0 z-20 bg-black/50 lg:hidden"
        @click="toggleSidebar()" x-cloak>
    </div>

    <div class="flex h-screen overflow-hidden">

        {{-- SIDEBAR --}}
        <x-sidebar />

        {{-- MAIN CONTENT AREA --}}
        <div class="flex-1 flex flex-col min-w-0 overflow-hidden">

            {{-- Impersonation Banner --}}
            <x-impersonation-banner />

            {{-- Header --}}
            <x-header>
                <x-slot name="header">{{ $header ?? '' }}</x-slot>
            </x-header>

            {{-- Content Slot --}}
            <main class="flex-1 overflow-y-auto p-4 sm:p-6 bg-gray-100 dark:bg-gray-900">
                {{ $slot }}
            </main>
        </div>
    </div>

    {{-- Toast notifications, and a redirect's flash message shown as one --}}
    <x-toasts />
</body>

</html>