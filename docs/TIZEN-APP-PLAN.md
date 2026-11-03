# A Samsung Tizen TV app for the player: what holds, what must change, what to try on a TV first

Written 2026-11-03, before the Tizen app is started (owner: "ab mujhe Tizen TV app banani hai, is liye ensure karo ke
existing system properly tested ho aur Tizen TV app ke liye ready ho"). Samsung's own pages were read cell by cell; a
point not from Samsung says so. Nothing here was tried on a Samsung TV yet: there is none here.

## 1. Which TVs run which engine

Samsung, Web Engine Specifications, Table 1
(https://developer.samsung.com/smarttv/develop/specifications/web-engine-specifications.html). A 2023 or later set can
move to a newer Tizen by an OS upgrade (a 2024 UN60DU7200 reported Tizen 9.0 / Chromium 120 in October 2026, third party).

| Model year | Tizen | Engine | The player as built today (`build.target` chrome80) |
|---|---|---|---|
| 2026 | 10.0 | Chromium 130 | runs |
| 2025 | 9.0 | Chromium 120 | runs |
| 2024 | 8.0 | Chromium 108 | runs |
| 2023 | 7.0 | Chromium 94 | runs; the first with WebCodecs, in principle |
| 2022 | 6.5 | Chromium 85 | runs |
| 2021 | 6.0 | Chromium 76 | **does not start**: the built script keeps `??` (25 times), a syntax error before Chromium 80 |
| 2020 | 5.5 | Chromium 69 | **does not start** (`??`); `String.prototype.matchAll` (73) is used once too (`pageSubresources`) |
| 2019 and older | 5.0 and below | Chromium 63 and below | no: ES modules partly or not at all, `img.decode()` (64), optional catch binding (66) |

The User-Agent carries no `Chrome/` token (`Mozilla/5.0 (SMART-TV; LINUX; Tizen 9.0) AppleWebKit/537.36 (KHTML, like
Gecko) 120.0.6099.5/9.0 TV Safari/537.36`): a Tizen set is told by `Tizen` in it. The player's Fire TV check (`AFT`)
never matches one, so every Tizen set plays `<video>` as a landscape screen does today.

**To decide:** the oldest Samsung TV to support. 2022 and later (Tizen 6.5) needs no change to the build; 2021 needs the
player's scripts written down to Chromium 76 (the `??` operators, one build setting); 2020 adds `matchAll` and a check of
every API against Chromium 69.

## 2. How the app loads the player

- A **hosted** app (`<tizen:content src="https://…">`) is refused by the Samsung store except by agreement, needs a
  partner group, and has no Tizen APIs (https://developer.samsung.com/smarttv/develop/faq/hosted-applications.html).
- A **packaged** app is the way. Two shapes:
  - It navigates its main frame to `https://sign.thedisplaysolution.com/player` (`<access origin>`, `tizen:allow-navigation`,
    the `internet` privilege). Proven on a Tizen 9 set (third party). Once navigated, `tizen` and `webapis` are gone: the
    screensaver, keys and AVPlay can no longer be called.
  - It keeps its own page and shows the player in a full-screen iframe. Samsung: an iframe "can only display content", no
    clicks, no Samsung APIs inside it (FAQ Q22) — which signage does not need — and the packaged page stays alive for
    `webapis.appcommon.setScreenSaver(SCREEN_SAVER_OFF)`. Whether the player's service worker works inside that iframe is
    not verified anywhere.
- Store certification may treat a launcher that only loads one URL as hosted (unverified).

## 3. What the player relies on, and Tizen

- **Service worker and Cache Storage** (offline play, §15 of AD-BUILDER-SPEC): Samsung's table says yes from Tizen 3.0; a
  third party saw one register and control a remote https page on Tizen 9. Not verified inside an iframe.
- **localStorage, fetch with Range, IntersectionObserver, custom elements, `postMessage`, iframe `sandbox` with
  `allow-same-origin`, `object-fit`, CSS opacity transitions** (Fade): all there from Tizen 5.5 or earlier.
- **SVG pictures** are "not supported" and may draw wrongly (FAQ Q19): the player page names `/icon.svg` only as a
  favicon; an Ad Builder page draws no SVG.

## 4. The three risks a real TV must answer first

1. **One video decoder.** Third parties report a second media element stalling the first (Tizen 7, Tizen 9); Samsung
   documents two or three videos at once for its signage displays only. The player keeps the next video paused on its
   first frame behind the one on screen (`prepareNext`), a break's next video advert likewise (`prepareAd`), and a page
   made ready keeps its video paused at its start. On a TV with one decoder each of those may freeze what is on screen.
   If it does, the player needs a "one video at a time" mode for Tizen: a picture or a page made ready ahead as now, a
   video only once the one on screen has let its decoder go — the switch between two videos then holds the last picture
   for as long as the new one takes to start, as every switch between two videos did on the Fire TV before 2026-11-02
   (about half a second, TV-ORIENTATION-SPEC §12.14).
2. **A turned screen.** A consumer Samsung TV cannot turn its picture, and Samsung says a media element keeps "an
   independent coordinate system" (https://developer.samsung.com/smarttv/develop/guides/fundamentals/managing-screen-resolution.html):
   a `<video>` turned by CSS probably comes out as on the Fire TV, sideways. The Fire TV's answer, the player decoding the
   video itself (`<decoded-video>`, WebCodecs), can only exist from Tizen 7.0 (Chromium 94) and is not confirmed there;
   Samsung's own WASM Player has a "video texture" mode with the same idea. Samsung's signage displays have a Rotation
   setting of their own (third party).
3. **The screensaver and auto power off.** Only a packaged page can switch the screensaver off; a consumer TV also turns
   itself off after four hours with no remote ("Auto Power Off", in the TV's menu), and "Autorun Last App" is a setting the
   owner of the TV switches on by hand.

## 5. What to try on a Samsung TV, in this order

1. The player in a packaged app's iframe: does it pair, play every kind (EverythingOnOnePlaylistTest's playlist), and does
   its service worker take the page over (offline play)?
2. Two videos: one playing, one paused on its first frame behind it (risk 1).
3. A portrait screen: a CSS-turned `<video>`, and on Tizen 7 or later whether `VideoDecoder` is there (risk 2).
4. `setScreenSaver` held for hours; memory over a day (an app installed from Tizen Studio gets 120 MB).
5. The Tizen version of every TV the customers have, to settle §1's decision.
