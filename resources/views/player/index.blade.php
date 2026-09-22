<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ config('app.name') }} Player</title>

    {{-- Deliberately no CSRF token and no session: this page authenticates with
         the device token it was handed at pairing, never with a cookie. --}}
    @vite(['resources/css/app.css', 'resources/js/player.js'])

    <style>
        /* A TV is a fixed, dark, chrome-less surface — nothing here is scrolled,
           clicked or read up close, so the page owns the whole viewport. */
        html, body { height: 100%; margin: 0; background: #000; overflow: hidden; }
        body { font-family: ui-sans-serif, system-ui, sans-serif; color: #fff; }

        /* The arrow is hidden only while content is PLAYING — a shop wall should
           not have a mouse pointer parked on it. On the pairing and error screens
           a person is standing right there, and hiding their cursor just makes the
           set feel broken. show() puts this class on and takes it off. */
        body.kiosk { cursor: none; }

        /* Nothing on this screen's playlist is due right now: the panel goes dark —
           no picture, no message, nothing. A shop's television with "No content"
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
            font-size: clamp(56px, 14vw, 180px);
            letter-spacing: 0.12em;
            font-weight: 700;
            line-height: 1;
            /* Bright blue rather than white: it is the only thing on this screen
               anybody has to copy, and it should be obvious which. Light enough to
               stay legible on black on a cheap panel across a shop. */
            color: #5eb0ff;
        }
        .muted { color: #9aa4b2; }

        .centred {
            position: absolute; inset: 0;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            text-align: center;
        }

        /* Media fills the stage and is letterboxed rather than cropped: a portrait
           poster on a landscape screen must never lose its edges. */
        .media-layer { position: absolute; inset: 0; }
        /* The advert sits over both content layers and is opaque, so whatever is
           paused underneath is neither seen nor composited. */
        .media-layer-ad { z-index: 10; background: #000; }

        .media-layer img,
        .media-layer video {
            width: 100%; height: 100%;
            object-fit: contain;
            display: block;
            background: #000;
        }

        /* An ad from the Ad Builder is a page of its own: it fills the screen and scales its own
           1920x1080 stage inside, so there is nothing to fit here. */
        .media-layer iframe {
            width: 100%; height: 100%;
            border: 0; display: block;
            background: #000;
        }
    </style>
</head>
<body class="h-full">
    <div class="player-root">
        <div id="stage" data-orientation="landscape">

            {{-- Booting / talking to the server --}}
            <div id="view-loading" class="text-center">
                <p class="muted" style="font-size:20px">Starting up...</p>
            </div>

            {{-- Waiting to be adopted by a shop owner --}}
            <div id="view-pairing" hidden class="text-center" style="padding:5vh 4vw">
                {{-- Split into two spans the player can rewrite with textContent:
                     a device that has been set up before must be sent to Replace
                     device, not Add Screen, or the shop ends up with a duplicate
                     screen and its real one stranded. Never innerHTML — this text
                     is chosen by the server. --}}
                <p class="muted" style="font-size:clamp(16px,2.2vw,26px); margin:0 0 3vh">
                    <span id="pairing-lead" dusk="pairing-lead">Open your dashboard, go to</span>
                    <strong id="pairing-where" dusk="pairing-where" style="color:#fff">Screens &rarr; Add Screen</strong>,
                    and enter this code:
                </p>

                <p id="pairing-code" class="pairing-code" dusk="pairing-code">------</p>

                <p id="pairing-note" class="muted" style="font-size:clamp(13px,1.4vw,18px); margin:4vh 0 0">
                    This code changes every 15 minutes.
                </p>

                {{-- The device id. Not a secret and not a credential — a screen is
                     let in by its TOKEN, never by this — so it is safe on a wall.
                     It is here to tell one television from another when matching a
                     set against its row in the database. --}}
                <p id="pairing-uuid" dusk="pairing-uuid"
                   style="font-family:ui-monospace,Menlo,monospace; font-size:clamp(10px,1vw,14px);
                          color:#fff; opacity:.75; margin:2.5vh 0 0; word-break:break-all"></p>
            </div>

            {{-- Paired and showing content --}}
            <div id="view-content" hidden style="position:relative; width:100%; height:100%">

                {{-- Two stacked layers: one visible, one being prepared, so an item
                     change is a swap rather than a flash of black. --}}
                <div id="layer-a" class="media-layer" hidden dusk="layer-a"></div>
                <div id="layer-b" class="media-layer" hidden dusk="layer-b"></div>

                {{-- The network advertisement, over the top of both. The shop's own
                     content is PAUSED underneath, not torn down, so a two-hour video
                     carries on from 1:00:00 rather than starting again. Emptied when
                     the break ends — a hidden <video> keeps its decoder otherwise. --}}
                <div id="layer-ad" class="media-layer media-layer-ad" hidden dusk="layer-ad"></div>

                <div id="no-content" hidden class="centred">
                    <p style="font-size:clamp(24px,4vw,54px); font-weight:600; margin:0">No content</p>
                    <p class="muted" style="font-size:clamp(14px,1.6vw,20px); margin-top:2vh">
                        <span id="screen-name" dusk="screen-name"></span> is paired and waiting for a playlist.
                    </p>
                </div>
            </div>

            {{-- Cannot reach the server --}}
            <div id="view-error" hidden class="text-center">
                <p id="error-message" class="muted" style="font-size:20px"></p>
            </div>

        </div>
    </div>
</body>
</html>
