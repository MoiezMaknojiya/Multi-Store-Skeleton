<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('Ad Builder') }} · {{ config('app.name', 'Laravel') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@100..900&display=swap" rel="stylesheet">

    {{-- The animation library and the ad runtime — the very files a published ad loads, from the same
         addresses, so the editor's ▶ Play is exactly what a television plays. Loaded before the app so
         they are there when the editor starts. --}}
    @foreach (\App\Services\AdCompiler::MOTION_SCRIPTS as $script)
        <script src="{{ \App\Services\AdCompiler::scriptUrl($script) }}"></script>
    @endforeach

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- Dark Mode Flash Prevention --}}
    <script>
        if (localStorage.getItem('darkMode') === 'true') {
            document.documentElement.classList.add('dark');
        }
    </script>
</head>

{{-- The editor's own shell: no sidebar, no page chrome, no scrolling. A design tool wants the whole
     window, and the top bar it does have is its own (save, undo, zoom) rather than the app's. The one
     thing it shares with the other shells is the "Log in as" banner, so the way back is here too; the
     editor fills whatever height is left under it. --}}
<body class="flex h-screen flex-col overflow-hidden bg-gray-100 font-sans antialiased dark:bg-gray-900">
    <x-impersonation-banner />

    {{ $slot }}

    {{-- Toasts, the same ones the rest of the app uses. --}}
    <div class="fixed bottom-4 right-4 z-[100] space-y-2" x-data>
        <template x-for="toast in $store.toasts.items" :key="toast.id">
            <div class="flex items-center gap-3 rounded-lg px-4 py-3 text-sm shadow-lg"
                 x-bind:class="toast.type === 'success'
                     ? 'bg-green-600 text-white'
                     : 'bg-red-600 text-white'">
                <span x-text="toast.message"></span>
                <button type="button" class="opacity-70 hover:opacity-100" @click="$store.toasts.dismiss(toast.id)"
                        aria-label="Dismiss notification" title="Dismiss">&times;</button>
            </div>
        </template>
    </div>
</body>

</html>
