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
        // The player runs in the WebView of whatever an organization puts behind its television, a Fire TV or a
        // cheap Android box, as old as Chrome 79 (owner, 2026-11-04: a commercial Android TV carried WebView 79):
        // newer syntax is written down to that (and why resources/views/player/index.blade.php carries its own
        // styles). The stylesheets keep Vite's own default, which the panel's Tailwind v4 needs anyway.
        target: ['chrome79', 'edge79', 'firefox78', 'safari14'],
        cssTarget: ['chrome111', 'edge111', 'firefox114', 'safari16.4', 'ios16.4'],
    },
});