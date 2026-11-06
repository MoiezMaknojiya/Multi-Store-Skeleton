<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ config('app.name') }} Player</title>

    {{-- A progressive web app (docs/AD-BUILDER-SPEC.md §15): a television or an Android box can install
         the player and open it full screen with no browser bar, and its worker keeps it playing offline. --}}
    <link rel="manifest" href="{{ route('player.manifest') }}">
    <meta name="theme-color" content="#000000">
    {{-- The app's own icons (scripts/make-brand-icons.php): the player's manifest names the same pictures. --}}
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="icon" href="/icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    {{-- A watchdog (docs/AD-BUILDER-SPEC.md §15): a page whose script never ran — a deploy's new script
         that could not be fetched, a browser that choked on it — would sit on "Starting up..." for ever,
         and nobody reloads a television. The player marks itself started; without the mark, the page
         asks again in a minute, and keeps asking until the line brings a working copy. --}}
    <script>
        setTimeout(function () { if (!window.signagePlayerStarted) window.location.reload(); }, 60000);
    </script>

    {{-- Deliberately no CSRF token and no session: this page authenticates with
         the device token it was handed at pairing, never with a cookie.

         And no stylesheet but its own: the panel's is Tailwind v4, which needs Chrome 111, while a
         Fire TV or a cheap Android box runs a WebView as old as Chrome 79 — which the player's script
         is built for too (build.target in vite.config.js). --}}
    @vite('resources/js/player.js')

    {{-- A Fire TV on a turned screen decodes its videos itself (docs/TV-ORIENTATION-SPEC.md §12), with a chunk the
         player imports only there. Named here, the worker keeps it with the page: a set that starts with no line (a
         power cut takes the router too) has its decoder, and its videos never come on sideways. Asked for at once,
         beside the player's script, it is in before the first video too. --}}
    @if (preg_match('/\bAFT/', (string) request()->userAgent()))
        @foreach (\App\Services\BuiltScripts::withImports('resources/js/player/decoded-video.js') as $script)
            <link rel="modulepreload" href="{{ $script }}">
        @endforeach
    @endif

    <style>
        /* What this page used of Tailwind's reset, written out. */
        *, ::before, ::after { box-sizing: border-box; }
        html { line-height: 1.5; -webkit-text-size-adjust: 100%; }
        p { margin: 0; }
        /* A view whose class sets its display still hides while it carries the hidden attribute. */
        [hidden] { display: none !important; }
        .text-center { text-align: center; }

        /* A TV is a fixed, dark, chrome-less surface — nothing here is scrolled,
           clicked or read up close, so the page owns the whole viewport. */
        html, body { height: 100%; margin: 0; background: #000; overflow: hidden; }
        body { font-family: ui-sans-serif, system-ui, sans-serif; color: #fff; }

        /* The arrow is hidden only while content is PLAYING — an organization wall should
           not have a mouse pointer parked on it. On the pairing and error screens
           a person is standing right there, and hiding their cursor just makes the
           set feel broken. show() puts this class on and takes it off. */
        body.kiosk { cursor: none; }

        /* Nothing on this screen's playlist is due right now: the panel goes dark —
           no picture, no message, nothing. An organization's television with "No content"
           written across it at three in the morning looks broken; a black one looks
           switched off, which is what it should look like. Hidden rather than
           emptied, so the next poll brings it straight back. */
        body.closed .media-layer,
        body.closed #no-content { visibility: hidden; }

        .player-root { height: 100vh; width: 100vw; display: flex; align-items: center; justify-content: center; }

        /* The panel is always 1920x1080. A portrait screen is the same panel with
           the content rotated, so the stage swaps its own width and height. */
        #stage { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; }
        #stage[data-orientation="landscape_flipped"] { transform: rotate(180deg); }
        #stage[data-orientation="portrait"],
        #stage[data-orientation="portrait_flipped"] {
            width: 100vh; height: 100vw;
        }
        #stage[data-orientation="portrait"] { transform: rotate(90deg); }
        #stage[data-orientation="portrait_flipped"] { transform: rotate(270deg); }

        .pairing-code {
            font-family: ui-monospace, "SF Mono", Menlo, monospace;
            /* A Samsung TV of 2021 (Chromium 76) has no clamp(): it keeps the size before it. */
            font-size: 180px;
            font-size: clamp(56px, 14vw, 180px);
            letter-spacing: 0.12em;
            font-weight: 700;
            line-height: 1;
            /* Bright blue rather than white: it is the only thing on this screen
               anybody has to copy, and it should be obvious which. Light enough to
               stay legible on black on a cheap panel across an organization. */
            color: #5eb0ff;
        }
        .muted { color: #9aa4b2; }

        /* Four offsets rather than inset, which a WebView older than Chrome 87 ignores. */
        .centred {
            position: absolute; top: 0; right: 0; bottom: 0; left: 0;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            text-align: center;
        }

        /* Media fills the stage and is letterboxed rather than cropped: a portrait
           poster on a landscape screen must never lose its edges. */
        .media-layer { position: absolute; top: 0; right: 0; bottom: 0; left: 0; }
        /* An Ad Builder page made ready behind the item on screen (player.js, putBehind) is laid out at its full size,
           unseen, so its stage, fonts and pictures are in place the moment it comes on. Hidden as the rest are, its frame
           had no size at all. */
        .media-layer[hidden][data-page] { display: block !important; visibility: hidden; }
        /* The advert sits over both content layers and is opaque, so whatever is
           paused underneath is neither seen nor composited. */
        .media-layer-ad { z-index: 10; background: #000; }
        /* The advert on screen and the next one, made ready behind it, stand in the same place: the next fades in
           over it. */
        .media-layer-ad > * { position: absolute; top: 0; left: 0; }

        .media-layer img,
        .media-layer video {
            width: 100%; height: 100%;
            object-fit: contain;
            display: block;
            background: #000;
        }

        /* A turned Fire TV's video, decoded by the player itself (resources/js/player/decoded-video.js): its canvas is
           letterboxed like any picture, and turns with the stage. */
        .media-layer decoded-video {
            display: block;
            width: 100%; height: 100%;
            background: #000;
        }
        .media-layer decoded-video canvas {
            display: block;
            width: 100%; height: 100%;
            object-fit: contain;
        }

        /* An ad from the Ad Builder is a page of its own: it fills the screen and scales its own
           1920x1080 stage inside, so there is nothing to fit here. */
        .media-layer iframe {
            width: 100%; height: 100%;
            border: 0; display: block;
            background: #000;
        }
    </style>

    {{-- A Samsung TV (Tizen in its user agent) has a part of the player of its own, player/samsung.js: the question its
         RETURN asks, and how it empties a page's frame. Its chunk, its look and its box are on this page there alone,
         so a change made for a Samsung TV never reaches an Android TV or a Fire TV, and neither downloads it (owner,
         2026-11-06). Named here, it is in before the first key. --}}
    @php($onASamsungTv = (bool) preg_match('/\bTizen\b/i', (string) request()->userAgent()))
    @if ($onASamsungTv)
        @foreach (\App\Services\BuiltScripts::withImports('resources/js/player/samsung.js') as $script)
            <link rel="modulepreload" href="{{ $script }}">
        @endforeach
        <style>
            /* Nothing on a page is pressed: a press on it (a mouse, a Smart Remote's pointer) took the keyboard into
               the frame, and RETURN never reached the player again. */
            .media-layer iframe { pointer-events: none; }

            /* The exit question (player/exit-question.js): over whatever plays, inside the stage so it turns with it.
               Measured by the panel's short side (vmin, 1080 px on a TV's 1920 x 1080 page), which is also a turned
               stage's width, so it is the same in every orientation and on every size of page. Margins rather than
               gap, which a flex box older than Chrome 84 ignores. */
            #stage { position: relative; }
            .exit-question {
                position: absolute; top: 0; right: 0; bottom: 0; left: 0; z-index: 50;
                display: flex; align-items: center; justify-content: center;
                background: rgba(0, 0, 0, 0.72);
            }
            .exit-question-box {
                max-width: 80%; padding: 4.444vmin 5.926vmin; text-align: center;
                background: #111827; border: 2px solid #374151; border-radius: 1.481vmin;
            }
            .exit-question-title { font-size: 4.444vmin; font-weight: 700; line-height: 1.2; }
            .exit-question-note { margin-top: 1.296vmin; font-size: 2.222vmin; }
            .exit-question-buttons { margin-top: 3.704vmin; display: flex; justify-content: center; }
            .exit-question-buttons button {
                min-width: 20.37vmin; margin: 0 1.296vmin; padding: 1.296vmin 2.963vmin;
                font-family: inherit; font-size: 2.778vmin; font-weight: 700;
                color: #e5e7eb; background: #1f2937;
                border: 3px solid #4b5563; border-radius: 1.111vmin; outline: none; cursor: pointer;
            }
            /* The chosen button is said by its colour, the way a TV's own menus say it: the remote has no pointer. */
            .exit-question-buttons button.chosen { color: #fff; background: #2563eb; border-color: #93c5fd; }
        </style>
    @endif
</head>
<body>
    <div class="player-root">
        <div id="stage" data-orientation="landscape">

            {{-- Booting / talking to the server --}}
            <div id="view-loading" class="text-center">
                <p class="muted" style="font-size:32px;font-size:clamp(18px,2.2vw,32px)">Starting up...</p>
            </div>

            {{-- Waiting to be adopted by an organization owner --}}
            <div id="view-pairing" hidden class="text-center" style="padding:5vh 4vw">
                {{-- Split into two spans the player can rewrite with textContent:
                     a device that has been set up before must be sent to Replace
                     device, not Add Screen, or the organization ends up with a duplicate
                     screen and its real one stranded. Never innerHTML — this text
                     is chosen by the server. --}}
                <p class="muted" style="font-size:26px;font-size:clamp(16px,2.2vw,26px); margin:0 0 3vh">
                    <span id="pairing-lead" dusk="pairing-lead">Open your dashboard, go to</span>
                    <strong id="pairing-where" dusk="pairing-where" style="color:#fff">Screens &rarr; Add Screen</strong>,
                    and enter this code:
                </p>

                <p id="pairing-code" class="pairing-code" dusk="pairing-code">------</p>

                {{-- Where the dashboard is: the person at the set may be holding a phone and nothing else. --}}
                <p class="muted" style="font-size:18px;font-size:clamp(13px,1.4vw,18px); margin:3vh 0 0" dusk="pairing-panel-address">
                    The dashboard: <strong style="color:#fff">{{ request()->getHost() }}</strong>
                </p>

                <p id="pairing-note" class="muted" style="font-size:18px;font-size:clamp(13px,1.4vw,18px); margin:2vh 0 0">
                    This code changes every 15 minutes.
                </p>

                {{-- The device id. Not a secret and not a credential — a screen is
                     let in by its TOKEN, never by this — so it is safe on a wall.
                     It is here to tell one television from another when matching a
                     set against its row in the database. Labelled beside it: player.js
                     writes the id alone into its own span. --}}
                <p style="font-family:ui-monospace,Menlo,monospace; font-size:14px;font-size:clamp(10px,1vw,14px);
                          color:#fff; opacity:.75; margin:2.5vh 0 0; word-break:break-all">
                    Device ID <span id="pairing-uuid" dusk="pairing-uuid"></span>
                </p>
            </div>

            {{-- Paired and showing content --}}
            <div id="view-content" hidden style="position:relative; width:100%; height:100%">

                {{-- Two stacked layers: one visible, one being prepared, so an item
                     change is a swap rather than a flash of black. --}}
                <div id="layer-a" class="media-layer" hidden dusk="layer-a"></div>
                <div id="layer-b" class="media-layer" hidden dusk="layer-b"></div>

                {{-- The network advertisement, over the top of both. The organization's own
                     content is PAUSED underneath, not torn down, so a two-hour video
                     carries on from 1:00:00 rather than starting again. Emptied when
                     the break ends — a hidden <video> keeps its decoder otherwise. --}}
                <div id="layer-ad" class="media-layer media-layer-ad" hidden dusk="layer-ad"></div>

                <div id="no-content" hidden class="centred">
                    <p style="font-size:54px;font-size:clamp(24px,4vw,54px); font-weight:600">No content</p>
                    <p class="muted" style="font-size:20px;font-size:clamp(14px,1.6vw,20px); margin-top:2vh">
                        <span id="screen-name" dusk="screen-name"></span> is paired and waiting for a playlist.
                    </p>
                    {{-- What to do about it, on the set where somebody is looking at it. --}}
                    <p class="muted" style="font-size:17px;font-size:clamp(12px,1.3vw,17px); margin-top:1.5vh">
                        Add files to its playlist in the dashboard, under Screens.
                    </p>
                </div>
            </div>

            {{-- Cannot reach the server --}}
            <div id="view-error" hidden class="text-center">
                <p id="error-message" class="muted" style="font-size:32px;font-size:clamp(18px,2.2vw,32px)"></p>
            </div>
            @if ($onASamsungTv)
                {{-- A Samsung TV's RETURN asks before it closes the app (resources/js/player/exit-question.js). --}}
                <div id="exit-question" class="exit-question" hidden role="dialog" aria-modal="true"
                     aria-labelledby="exit-question-title" dusk="exit-question">
                    <div class="exit-question-box">
                        <p id="exit-question-title" class="exit-question-title">Exit {{ config('app.name') }}?</p>
                        <p class="exit-question-note muted">This screen stops playing until the app is opened again.</p>
                        <div class="exit-question-buttons">
                            <button type="button" id="exit-question-stay" dusk="exit-question-stay">Stay</button>
                            <button type="button" id="exit-question-exit" dusk="exit-question-exit">Exit</button>
                        </div>
                    </div>
                </div>
            @endif

        </div>
    </div>
</body>
</html>
