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
         after a delay. layoutHandler.init() (resources/js/layout.js) removes this class
         the moment Alpine takes over. --}}
    <script>
        if (localStorage.getItem('sidebarOpen') === 'false') {
            document.documentElement.classList.add('sidebar-closed');
        }
    </script>

    @stack('styles')
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
        <x-sidebar :nav-items="[
            ['label' => 'Dashboard', 'href' => route('dashboard'), 'route' => 'dashboard'],
            ['label' => 'Users', 'href' => route('users.view'), 'route' => 'users.*'],
        ]" />

        {{-- MAIN CONTENT AREA --}}
        <div class="flex-1 flex flex-col min-w-0 overflow-hidden">

            {{-- Impersonation Banner --}}
            @if(session('impersonating_original_id'))
            <div class="flex-shrink-0 flex items-center justify-between gap-4 px-4 sm:px-6 py-2 bg-amber-500 text-white text-sm">
                <span>You are viewing as <strong>{{ auth()->user()->name }}</strong> ({{ auth()->user()->email }}).</span>
                <form method="POST" action="{{ route('impersonate.stop') }}">
                    @csrf
                    <button type="submit" class="font-semibold underline hover:no-underline whitespace-nowrap">
                        Return to Super Admin
                    </button>
                </form>
            </div>
            @endif

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

    {{-- Toast notifications (filled by window.toast() from any component) --}}
    <div x-data class="fixed top-4 left-1/2 -translate-x-1/2 z-[100] w-80 max-w-[calc(100vw-2rem)] space-y-2 pointer-events-none">
        <template x-for="toast in $store.toasts.items" :key="toast.id">
            <div x-transition.opacity.duration.300ms
                class="pointer-events-auto rounded-md px-4 py-3 text-sm text-white shadow-lg flex items-start gap-2"
                :class="toast.type === 'success' ? 'bg-green-600' : 'bg-red-600'">
                <span class="flex-1" x-text="toast.message"></span>
                <button type="button" class="opacity-70 hover:opacity-100" @click="$store.toasts.dismiss(toast.id)">✕</button>
            </div>
        </template>
    </div>

    @stack('scripts')
</body>

</html>