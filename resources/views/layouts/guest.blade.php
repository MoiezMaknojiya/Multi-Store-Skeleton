<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ filled($title ?? null) ? $title.' · ' : '' }}{{ config('app.name', 'Laravel') }}</title>
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
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-xs bg-blue-600 text-white" aria-hidden="true">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                    </span>
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
                    <div class="w-16 h-16 rounded-xs bg-blue-600 flex items-center justify-center">
                        <svg class="w-9 h-9 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                    </div>
                </div>
                <p class="text-3xl font-bold text-white mb-4">{{ config('app.name', 'Laravel') }}</p>
                <p class="text-gray-400 text-sm max-w-xs mx-auto">Put your pictures, videos and ads on your shop's screens, from one place.</p>
            </div>
        </div>
    </div>
</body>
</html>