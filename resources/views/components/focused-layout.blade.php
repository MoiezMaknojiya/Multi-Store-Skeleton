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
</head>

{{-- A focused, sidebar-less shell for pages that stand on their own (e.g. the store
     picker). Just a slim top bar with the brand and the user menu, then the content. --}}
<body class="font-sans antialiased bg-gray-100 dark:bg-gray-900" x-data="layoutHandler">
    <div class="min-h-screen flex flex-col">
        {{-- Impersonation Banner — "Log in as" a member of several stores lands here first --}}
        <x-impersonation-banner />

        {{-- Slim top bar --}}
        <header class="flex items-center justify-between h-16 px-4 sm:px-6 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2 text-gray-800 dark:text-white font-semibold">
                <span class="inline-flex items-center justify-center w-8 h-8 rounded-xs bg-blue-600 text-white">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                </span>
                {{ config('app.name', 'Laravel') }}
            </a>

            <div class="flex items-center gap-1" x-data="header()">
                {{-- Theme Toggle --}}
                <button @click="toggleDark()" :aria-label="darkMode ? 'Switch to light mode' : 'Switch to dark mode'"
                    class="p-2 rounded-xs text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800">
                    <svg data-theme-icon="dark" :class="darkMode ? 'block' : 'hidden'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                    <svg data-theme-icon="light" :class="darkMode ? 'hidden' : 'block'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                    </svg>
                </button>

                {{-- User Dropdown --}}
                <div class="relative">
                    <button @click="toggle()" dusk="user-menu" class="flex items-center gap-2 px-2 py-1.5 rounded-xs hover:bg-gray-100 dark:hover:bg-gray-800">
                        <div class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center text-white text-sm font-semibold">
                            {{ mb_strtoupper(mb_substr(auth()->user()->name ?: 'U', 0, 1)) }}
                        </div>
                        <span class="hidden sm:block text-sm font-medium text-gray-700 dark:text-gray-300">{{ auth()->user()->name ?? 'User' }}</span>
                    </button>

                    <div x-show="userMenu" @click.outside="close()" x-cloak
                        class="absolute right-0 mt-2 w-48 rounded-xs shadow-lg z-50 py-1 bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700">
                        <a href="{{ route('profile.edit') }}" dusk="user-menu-settings"
                            class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Settings</a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" dusk="logout-button"
                                class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-50 dark:hover:bg-gray-700">Log Out</button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-1 p-4 sm:p-6">
            <div class="max-w-5xl mx-auto">
                @isset($header)
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">{{ $header }}</h2>
                @endisset
                {{ $slot }}
            </div>
        </main>
    </div>

    {{-- Toast notifications, and a redirect's flash message shown as one --}}
    <x-toasts />
</body>

</html>
