<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The fonts the Ad Builder offers
    |--------------------------------------------------------------------------
    |
    | A curated list rather than the whole of Google Fonts, for three reasons: a picker with 1,600
    | families in it is not a picker, the Google Fonts Developer API would need a key nobody wants to
    | manage, and every family offered here is one we can DOWNLOAD and host ourselves — a television in
    | a shop may have no internet at all, so an advert that depends on fonts.googleapis.com is an
    | advert that renders in Times New Roman on the day the shop's wifi drops.
    |
    | Picking a family in the editor installs it: the server fetches the weights below, writes them to
    | `storage/app/public/fonts/{slug}/` with a small stylesheet, and records it in `builder_fonts`.
    | From then on nothing leaves the building.
    |
    | Adding a family: put it here with the weights worth having (400 and 700 cover almost every
    | design), keep the name exactly as Google spells it, and say which kind it is so the picker can
    | group it. Nothing else to do.
    |
    */

    'families' => [
        // ── Sans-serif: the workhorses ─────────────────────────────────────
        ['name' => 'Inter', 'kind' => 'sans', 'weights' => [400, 500, 700, 900]],
        ['name' => 'Roboto', 'kind' => 'sans', 'weights' => [400, 500, 700, 900]],
        ['name' => 'Open Sans', 'kind' => 'sans', 'weights' => [400, 600, 700, 800]],
        ['name' => 'Lato', 'kind' => 'sans', 'weights' => [400, 700, 900]],
        ['name' => 'Montserrat', 'kind' => 'sans', 'weights' => [400, 600, 700, 900]],
        ['name' => 'Poppins', 'kind' => 'sans', 'weights' => [400, 500, 600, 700]],
        ['name' => 'Nunito', 'kind' => 'sans', 'weights' => [400, 600, 700, 900]],
        ['name' => 'Raleway', 'kind' => 'sans', 'weights' => [400, 600, 700, 800]],
        ['name' => 'Work Sans', 'kind' => 'sans', 'weights' => [400, 600, 700]],
        ['name' => 'DM Sans', 'kind' => 'sans', 'weights' => [400, 500, 700]],
        ['name' => 'Manrope', 'kind' => 'sans', 'weights' => [400, 600, 700, 800]],
        ['name' => 'Outfit', 'kind' => 'sans', 'weights' => [400, 600, 700, 900]],
        ['name' => 'Rubik', 'kind' => 'sans', 'weights' => [400, 500, 700]],
        ['name' => 'Barlow', 'kind' => 'sans', 'weights' => [400, 600, 700]],
        ['name' => 'Figtree', 'kind' => 'sans', 'weights' => [400, 600, 700, 900]],
        ['name' => 'Plus Jakarta Sans', 'kind' => 'sans', 'weights' => [400, 600, 700, 800]],
        ['name' => 'Noto Sans', 'kind' => 'sans', 'weights' => [400, 700]],
        ['name' => 'Source Sans 3', 'kind' => 'sans', 'weights' => [400, 600, 700]],
        ['name' => 'Mulish', 'kind' => 'sans', 'weights' => [400, 700, 900]],
        ['name' => 'Karla', 'kind' => 'sans', 'weights' => [400, 700]],

        // ── Display: the ones an advert shouts with ────────────────────────
        ['name' => 'Anton', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Bebas Neue', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Oswald', 'kind' => 'display', 'weights' => [400, 600, 700]],
        ['name' => 'Archivo Black', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Alfa Slab One', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Titan One', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Righteous', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Fredoka', 'kind' => 'display', 'weights' => [400, 600, 700]],
        ['name' => 'Baloo 2', 'kind' => 'display', 'weights' => [400, 700, 800]],
        ['name' => 'Chewy', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Lobster', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Pacifico', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Permanent Marker', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Passion One', 'kind' => 'display', 'weights' => [400, 700, 900]],
        ['name' => 'Luckiest Guy', 'kind' => 'display', 'weights' => [400]],

        // ── Serif: prices, names, anything that wants to look settled ─────
        ['name' => 'Playfair Display', 'kind' => 'serif', 'weights' => [400, 600, 700, 900]],
        ['name' => 'Merriweather', 'kind' => 'serif', 'weights' => [400, 700, 900]],
        ['name' => 'Lora', 'kind' => 'serif', 'weights' => [400, 600, 700]],
        ['name' => 'Libre Baskerville', 'kind' => 'serif', 'weights' => [400, 700]],
        ['name' => 'Bitter', 'kind' => 'serif', 'weights' => [400, 600, 700]],
        ['name' => 'Cormorant Garamond', 'kind' => 'serif', 'weights' => [400, 600, 700]],
        ['name' => 'DM Serif Display', 'kind' => 'serif', 'weights' => [400]],
        ['name' => 'Abril Fatface', 'kind' => 'serif', 'weights' => [400]],
        ['name' => 'Noto Serif', 'kind' => 'serif', 'weights' => [400, 700]],

        // ── Monospace and numerals ────────────────────────────────────────
        ['name' => 'Roboto Mono', 'kind' => 'mono', 'weights' => [400, 700]],
        ['name' => 'JetBrains Mono', 'kind' => 'mono', 'weights' => [400, 700]],
        ['name' => 'Space Mono', 'kind' => 'mono', 'weights' => [400, 700]],

        // ── Urdu / Arabic script, for a shop that writes in it ────────────
        ['name' => 'Noto Nastaliq Urdu', 'kind' => 'urdu', 'weights' => [400, 700]],
        ['name' => 'Noto Kufi Arabic', 'kind' => 'urdu', 'weights' => [400, 700]],
        ['name' => 'Cairo', 'kind' => 'urdu', 'weights' => [400, 600, 700, 900]],
        ['name' => 'Almarai', 'kind' => 'urdu', 'weights' => [400, 700, 800]],
    ],

    /*
    | The families always available without installing anything, because every device has something
    | close enough. Kept first in the picker so a design can be started with no download at all.
    */
    'system' => [
        ['name' => 'Arial', 'kind' => 'system'],
        ['name' => 'Helvetica', 'kind' => 'system'],
        ['name' => 'Georgia', 'kind' => 'system'],
        ['name' => 'Times New Roman', 'kind' => 'system'],
        ['name' => 'Courier New', 'kind' => 'system'],
        ['name' => 'Verdana', 'kind' => 'system'],
        ['name' => 'Tahoma', 'kind' => 'system'],
        ['name' => 'Trebuchet MS', 'kind' => 'system'],
    ],

    /*
    | Where the downloaded files live on the `public` disk, and how long the installer waits on Google
    | before giving up (the editor says so and the design keeps its family name either way).
    */
    'directory' => 'fonts',
    'timeout' => 20,

];
