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
    | an organization may have no internet at all, so an advert that depends on fonts.googleapis.com is an
    | advert that renders in Times New Roman on the day the organization's wifi drops.
    |
    | Picking a family in the editor installs it: the server asks Google for every weight from 100 to 900,
    | Google answers with the ones the family really has, and those are written to
    | `storage/app/public/fonts/{slug}/` with a small stylesheet and recorded in `builder_fonts`. From
    | then on nothing leaves the building. Every week `fonts:refresh` asks again for each installed
    | family and adds any weight Google has added since (owner, 2026-10-05: "google font k jese jese new
    | weight aye dalte raho").
    |
    | The weights below are what the picker shows for a family not installed yet: what Google had on
    | 2026-10-05. Adding a family: put it here with its weights, keep the name exactly as Google spells
    | it, and say which kind it is so the picker can group it. Nothing else to do.
    |
    | 2026-10-06 (owner: "achay achay font jo website, banner, poster, flyer, logo and pamphlet mein use
    | honte ho aur google walay ho toh woo add karo"): 87 families more — the ones design guides name most
    | for posters, flyers, banners, logos and menu boards, with a new Script group — each name and its
    | weights as Google's own CSS2 API answered them that day.
    |
    | 2026-10-07 (owner: "ad builder k ander font se urdu aur arabic wala font hata do puri terha se"): the
    | eight Urdu and Arabic families are gone, and with them their group — 130 Google families remain. None
    | was installed on the live site, and no design there named one.
    |
    */

    'families' => [
        // ── Sans-serif: the workhorses ─────────────────────────────────────
        ['name' => 'Inter', 'kind' => 'sans', 'weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Roboto', 'kind' => 'sans', 'weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Open Sans', 'kind' => 'sans', 'weights' => [300, 400, 500, 600, 700, 800]],
        ['name' => 'Lato', 'kind' => 'sans', 'weights' => [100, 300, 400, 700, 900]],
        ['name' => 'Montserrat', 'kind' => 'sans', 'weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Poppins', 'kind' => 'sans', 'weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Nunito', 'kind' => 'sans', 'weights' => [200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Raleway', 'kind' => 'sans', 'weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Work Sans', 'kind' => 'sans', 'weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'DM Sans', 'kind' => 'sans', 'weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Manrope', 'kind' => 'sans', 'weights' => [200, 300, 400, 500, 600, 700, 800]],
        ['name' => 'Outfit', 'kind' => 'sans', 'weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Rubik', 'kind' => 'sans', 'weights' => [300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Barlow', 'kind' => 'sans', 'weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Figtree', 'kind' => 'sans', 'weights' => [300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Plus Jakarta Sans', 'kind' => 'sans', 'weights' => [200, 300, 400, 500, 600, 700, 800]],
        ['name' => 'Noto Sans', 'kind' => 'sans', 'weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Source Sans 3', 'kind' => 'sans', 'weights' => [200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Mulish', 'kind' => 'sans', 'weights' => [200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Karla', 'kind' => 'sans', 'weights' => [200, 300, 400, 500, 600, 700, 800]],
        ['name' => 'Roboto Condensed', 'kind' => 'sans', 'weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Barlow Condensed', 'kind' => 'sans', 'weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Archivo', 'kind' => 'sans', 'weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Jost', 'kind' => 'sans', 'weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'League Spartan', 'kind' => 'sans', 'weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Josefin Sans', 'kind' => 'sans', 'weights' => [100, 200, 300, 400, 500, 600, 700]],
        ['name' => 'Kanit', 'kind' => 'sans', 'weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Sora', 'kind' => 'sans', 'weights' => [100, 200, 300, 400, 500, 600, 700, 800]],
        ['name' => 'Space Grotesk', 'kind' => 'sans', 'weights' => [300, 400, 500, 600, 700]],
        ['name' => 'Bricolage Grotesque', 'kind' => 'sans', 'weights' => [200, 300, 400, 500, 600, 700, 800]],
        ['name' => 'Lexend', 'kind' => 'sans', 'weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Urbanist', 'kind' => 'sans', 'weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Quicksand', 'kind' => 'sans', 'weights' => [300, 400, 500, 600, 700]],
        ['name' => 'Comfortaa', 'kind' => 'sans', 'weights' => [300, 400, 500, 600, 700]],
        ['name' => 'Exo 2', 'kind' => 'sans', 'weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Ubuntu', 'kind' => 'sans', 'weights' => [300, 400, 500, 700]],
        ['name' => 'Titillium Web', 'kind' => 'sans', 'weights' => [200, 300, 400, 600, 700, 900]],
        ['name' => 'PT Sans', 'kind' => 'sans', 'weights' => [400, 700]],
        ['name' => 'Fira Sans', 'kind' => 'sans', 'weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Libre Franklin', 'kind' => 'sans', 'weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Teko', 'kind' => 'sans', 'weights' => [300, 400, 500, 600, 700]],

        // ── Display: the ones an advert shouts with ────────────────────────
        ['name' => 'Anton', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Bebas Neue', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Oswald', 'kind' => 'display', 'weights' => [200, 300, 400, 500, 600, 700]],
        ['name' => 'Archivo Black', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Alfa Slab One', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Titan One', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Righteous', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Fredoka', 'kind' => 'display', 'weights' => [300, 400, 500, 600, 700]],
        ['name' => 'Baloo 2', 'kind' => 'display', 'weights' => [400, 500, 600, 700, 800]],
        ['name' => 'Chewy', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Passion One', 'kind' => 'display', 'weights' => [400, 700, 900]],
        ['name' => 'Luckiest Guy', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Bungee', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Black Ops One', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Russo One', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Bowlby One', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Ultra', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Bangers', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Concert One', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Paytone One', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Lilita One', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Staatliches', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Fjalla One', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Changa One', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Shrikhand', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Bagel Fat One', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Monoton', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Sigmar', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Dela Gothic One', 'kind' => 'display', 'weights' => [400]],
        ['name' => 'Unbounded', 'kind' => 'display', 'weights' => [200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Big Shoulders Display', 'kind' => 'display', 'weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Bevan', 'kind' => 'display', 'weights' => [400]],

        // ── Script and handwriting: menus, flyers, logos — a name, a word, never a paragraph ──
        ['name' => 'Pacifico', 'kind' => 'script', 'weights' => [400]],
        ['name' => 'Lobster', 'kind' => 'script', 'weights' => [400]],
        ['name' => 'Permanent Marker', 'kind' => 'script', 'weights' => [400]],
        ['name' => 'Dancing Script', 'kind' => 'script', 'weights' => [400, 500, 600, 700]],
        ['name' => 'Great Vibes', 'kind' => 'script', 'weights' => [400]],
        ['name' => 'Sacramento', 'kind' => 'script', 'weights' => [400]],
        ['name' => 'Satisfy', 'kind' => 'script', 'weights' => [400]],
        ['name' => 'Caveat', 'kind' => 'script', 'weights' => [400, 500, 600, 700]],
        ['name' => 'Kaushan Script', 'kind' => 'script', 'weights' => [400]],
        ['name' => 'Yellowtail', 'kind' => 'script', 'weights' => [400]],
        ['name' => 'Allura', 'kind' => 'script', 'weights' => [400]],
        ['name' => 'Parisienne', 'kind' => 'script', 'weights' => [400]],
        ['name' => 'Courgette', 'kind' => 'script', 'weights' => [400]],
        ['name' => 'Cookie', 'kind' => 'script', 'weights' => [400]],
        ['name' => 'Damion', 'kind' => 'script', 'weights' => [400]],
        ['name' => 'Amatic SC', 'kind' => 'script', 'weights' => [400, 700]],
        ['name' => 'Indie Flower', 'kind' => 'script', 'weights' => [400]],
        ['name' => 'Shadows Into Light', 'kind' => 'script', 'weights' => [400]],
        ['name' => 'Patrick Hand', 'kind' => 'script', 'weights' => [400]],
        ['name' => 'Marck Script', 'kind' => 'script', 'weights' => [400]],
        ['name' => 'Alex Brush', 'kind' => 'script', 'weights' => [400]],
        ['name' => 'Lobster Two', 'kind' => 'script', 'weights' => [400, 700]],
        ['name' => 'Oleo Script', 'kind' => 'script', 'weights' => [400, 700]],
        ['name' => 'Berkshire Swash', 'kind' => 'script', 'weights' => [400]],
        ['name' => 'Playball', 'kind' => 'script', 'weights' => [400]],
        ['name' => 'Grand Hotel', 'kind' => 'script', 'weights' => [400]],
        ['name' => 'Pinyon Script', 'kind' => 'script', 'weights' => [400]],
        ['name' => 'Rock Salt', 'kind' => 'script', 'weights' => [400]],

        // ── Serif and slab: prices, names, anything that wants to look settled ─────
        ['name' => 'Playfair Display', 'kind' => 'serif', 'weights' => [400, 500, 600, 700, 800, 900]],
        ['name' => 'Merriweather', 'kind' => 'serif', 'weights' => [300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Lora', 'kind' => 'serif', 'weights' => [400, 500, 600, 700]],
        ['name' => 'Libre Baskerville', 'kind' => 'serif', 'weights' => [400, 500, 600, 700]],
        ['name' => 'Bitter', 'kind' => 'serif', 'weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Cormorant Garamond', 'kind' => 'serif', 'weights' => [300, 400, 500, 600, 700]],
        ['name' => 'DM Serif Display', 'kind' => 'serif', 'weights' => [400]],
        ['name' => 'Abril Fatface', 'kind' => 'serif', 'weights' => [400]],
        ['name' => 'Noto Serif', 'kind' => 'serif', 'weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'EB Garamond', 'kind' => 'serif', 'weights' => [400, 500, 600, 700, 800]],
        ['name' => 'Bodoni Moda', 'kind' => 'serif', 'weights' => [400, 500, 600, 700, 800, 900]],
        ['name' => 'Fraunces', 'kind' => 'serif', 'weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Crimson Text', 'kind' => 'serif', 'weights' => [400, 600, 700]],
        ['name' => 'Prata', 'kind' => 'serif', 'weights' => [400]],
        ['name' => 'Cinzel', 'kind' => 'serif', 'weights' => [400, 500, 600, 700, 800, 900]],
        ['name' => 'Marcellus', 'kind' => 'serif', 'weights' => [400]],
        ['name' => 'Italiana', 'kind' => 'serif', 'weights' => [400]],
        ['name' => 'Yeseva One', 'kind' => 'serif', 'weights' => [400]],
        ['name' => 'Rozha One', 'kind' => 'serif', 'weights' => [400]],
        ['name' => 'Arvo', 'kind' => 'serif', 'weights' => [400, 700]],
        ['name' => 'Roboto Slab', 'kind' => 'serif', 'weights' => [100, 200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Zilla Slab', 'kind' => 'serif', 'weights' => [300, 400, 500, 600, 700]],
        ['name' => 'PT Serif', 'kind' => 'serif', 'weights' => [400, 700]],
        ['name' => 'Source Serif 4', 'kind' => 'serif', 'weights' => [200, 300, 400, 500, 600, 700, 800, 900]],
        ['name' => 'Instrument Serif', 'kind' => 'serif', 'weights' => [400]],
        ['name' => 'Spectral', 'kind' => 'serif', 'weights' => [200, 300, 400, 500, 600, 700, 800]],

        // ── Monospace and numerals ────────────────────────────────────────
        ['name' => 'Roboto Mono', 'kind' => 'mono', 'weights' => [100, 200, 300, 400, 500, 600, 700]],
        ['name' => 'JetBrains Mono', 'kind' => 'mono', 'weights' => [100, 200, 300, 400, 500, 600, 700, 800]],
        ['name' => 'Space Mono', 'kind' => 'mono', 'weights' => [400, 700]],
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
