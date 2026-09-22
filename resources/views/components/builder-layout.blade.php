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

    {{-- The app's own toasts — the shared component, never a copy: a second copy drifted to the bottom
         corner and the editor spoke in a different place from every other page. --}}
    <x-toasts />
</body>

</html>
