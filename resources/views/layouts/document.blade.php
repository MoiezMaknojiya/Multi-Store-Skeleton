<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @auth<meta name="session-context" content="{{ \App\Http\Middleware\RefuseAStaleTab::contextFor(request()) }}">@endauth
    <title>{{ $title }} · {{ config('app.name', 'Laravel') }}</title>
    <x-favicons />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@100..900&display=swap" rel="stylesheet">
    {{-- The panel's light or dark, as the person chose it there. --}}
    <script>
        try {
            if (localStorage.getItem('darkMode') === 'true') document.documentElement.classList.add('dark');
        } catch (e) { /* Storage refused: the light page. */ }
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="font-sans antialiased bg-white text-gray-800 dark:bg-gray-900 dark:text-gray-200" x-data>
    <a href="#main-content" class="skip-link" dusk="skip-to-content">Skip to main content</a>

    <header class="border-b border-gray-200 dark:border-gray-800">
        <div class="mx-auto max-w-2xl px-4 py-3 sm:px-6">
            <a href="{{ url('/') }}" class="touch-target inline-flex items-center gap-2 font-semibold text-gray-800 dark:text-white" dusk="document-brand">
                <img src="/brand/logo.svg" alt="" width="32" height="32" class="h-8 w-8">
                {{ config('app.name', 'Laravel') }}
            </a>
        </div>
    </header>

    <main id="main-content" tabindex="-1" class="mx-auto max-w-2xl px-4 pt-10 pb-16 sm:px-6 focus:outline-none">
        {{ $slot }}
    </main>
</body>
</html>
