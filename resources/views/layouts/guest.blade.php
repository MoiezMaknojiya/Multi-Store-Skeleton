<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ filled($title ?? null) ? $title.' · ' : '' }}{{ config('app.name', 'Laravel') }}</title>
    <x-favicons />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@100..900&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="font-sans antialiased bg-white" x-data>
    <a href="#main-content" class="skip-link" dusk="skip-to-content">Skip to main content</a>

    <div class="min-h-screen flex">
        {{-- Left Auth Panel --}}
        <main id="main-content" tabindex="-1" class="flex-1 flex flex-col items-center justify-center px-6 py-12 lg:px-16 focus:outline-none">
            <div class="w-full max-w-md">
                {{-- On a phone the brand panel is not there: the logo and the name say where this is. Not a heading —
                     the page's own h1 is. --}}
                <div class="mb-8 flex items-center gap-2 lg:hidden" dusk="guest-brand">
                    <img src="/brand/logo.svg" alt="" width="32" height="32" class="h-8 w-8">
                    <span class="font-semibold text-gray-800">{{ config('app.name', 'Laravel') }}</span>
                </div>

                {{ $slot }}
            </div>
        </main>

        {{-- Right Brand Panel: the brand, not the page's heading (the form has that). --}}
        <div class="hidden lg:flex lg:w-1/2 bg-gray-900 flex-col items-center justify-center p-12 relative overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-gray-800 to-gray-950"></div>
            <div class="relative z-10 text-center">
                <div class="mb-8 flex justify-center">
                    <img src="/brand/logo.svg" alt="" width="96" height="96" class="h-24 w-24">
                </div>
                <p class="text-3xl font-bold text-white mb-4">{{ config('app.name', 'Laravel') }}</p>
                <p class="text-gray-400 text-sm max-w-xs mx-auto">Put your pictures, videos and ads on your organization's screens, from one place.</p>
            </div>
        </div>
    </div>
</body>
</html>