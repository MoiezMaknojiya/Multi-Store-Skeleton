import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        tailwindcss(),
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/player.js'],
            refresh: true,
        }),
    ],
    build: {
        // The player runs in the WebView of whatever a shop puts behind its television, a Fire TV or a cheap
        // Android box, as old as Chrome 80: newer syntax is written down to that (the Android app's floor, and why
        // resources/views/player/index.blade.php carries its own styles). The stylesheets keep Vite's own default,
        // which the panel's Tailwind v4 needs anyway.
        target: ['chrome80', 'edge80', 'firefox78', 'safari14'],
        cssTarget: ['chrome111', 'edge111', 'firefox114', 'safari16.4', 'ios16.4'],
    },
});