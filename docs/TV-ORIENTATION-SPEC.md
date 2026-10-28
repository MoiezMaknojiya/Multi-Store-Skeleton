# A set mounted another way: who turns the picture

**Status, 2026-10-28: OPEN. Do not build or ship the "app turns the display" fix this document described on
2026-10-27. On a real Fire TV it does not turn the picture on the glass; it stretches it (§3.3).**

The panel is always 1920×1080. A screen can be mounted four ways, and something has to turn the content to
match. The player page does it with CSS. On a Fire TV that breaks for video. This document records what has
been measured, what failed, and what is left to try (§5).

## 1. The four ways

| `screens.orientation` | In the panel | The page's own turn |
|---|---|---|
| `landscape` | Landscape | none |
| `landscape_flipped` | Landscape (flipped 180°) | `rotate(180deg)` |
| `portrait` | Portrait (rotated 90°) | `rotate(90deg)`, stage 100vh × 100vw |
| `portrait_flipped` | Portrait (rotated 270°) | `rotate(270deg)`, stage 100vh × 100vw |

`Screen::ORIENTATIONS` is the list; `Screen::shape` folds the four into landscape or portrait for the
channels and the Ad Builder. On a landscape TV, Portrait (rotated 270°) shows the content's top on the
TV's LEFT edge: the TV is mounted with that edge up.

## 2. The bug (Roadstar 4, 2026-10-27)

A playlist of one ten-second video on a portrait Fire TV Stick: ten seconds sideways, small, a black bar on
one side, then twenty seconds the right way up, over and over. Pictures and Ad Builder pages were always
right. Three fixes went live that night and were all rolled back (commits `03407bc` … `3f2982b`, reverted
in `ecc5268`): replaying the video in place made it sideways all the time, copying its frames onto a canvas
gave black frames, and `opacity: .999` on the video made it black.

## 3. What the stick showed

Measured on the owner's Fire TV Stick 4K Max (model AFTKA, Fire OS 7.6.9.7 on Android 9, MediaTek mt8696,
Amazon WebView Chromium 132) over adb, `dumpsys SurfaceFlinger` every two seconds beside `screencap`, with
the Android player app paired to a portrait screen on the live site.

1. **Compositor first.** The video starts on the WebView's compositor: the stage's CSS turn applies, the
   picture is turned correctly and fills the glass. Only the app's own window is in the HWC layer table.
2. **Then the overlay.** A few seconds later Chromium promotes the video to a hardware overlay: a second HWC
   layer, `SurfaceView - …MainActivity`, Comp Type `Device`, Source Crop `0 0 1080 1920`, **Disp Frame
   `0 0 2160 2160`**, the video element's UN-turned layout rect (1080 wide, 1920 tall) clipped to the
   1920×1080 viewport, in the panel's 3840×2160 physical pixels. No CSS transform reaches that plane:
   sideways, small, a black bar. It stays so until the element is replaced, which is why the cycle followed
   the poll and why replaying in place made it permanent. Both portrait mountings behave the same.
3. **Turning the display does NOT turn the picture on the glass (found 2026-10-28, owner's photo of the
   TV).** Fire OS accepts the request, by `adb shell settings put system user_rotation N` or by the
   activity's `requestedOrientation`, and makes the LOGICAL display 1080×1920. But the HDMI output is never
   turned: SurfaceFlinger keeps the display at `orient= 0` and scales the 1080×1920 frame into the
   1920×1080 panel unturned. On the TV the content stands upright and is stretched sideways to fill a
   landscape screen. The overlay's Disp Frame of `0 0 3840 2160` is that stretch, not a turn.
   - **The trap that hid it for a day:** `screencap` and `screenrecord` capture the LOGICAL display, which is
     upright whichever way the glass gets it. With the display turned, only a photo of the TV tells the
     truth. With rotation 0, a screencap is exactly the panel. The HWC table's display line (`orient=`) and a
     source of 1080×1920 mapped onto 3840×2160 also give it away.
4. **The debug app of 2026-10-28** (`TheDisplaySolution-debug-orientation.apk`, a `SignagePlayer` JavaScript
   bridge whose `turnDisplay` set `requestedOrientation`) measured, for the record:

   | Value | Answered | Display rotation |
   |---|---|---|
   | landscape | true | 0 |
   | landscape_flipped | true | 0: Fire OS ignored `REVERSE_LANDSCAPE` |
   | portrait | true | 3 |
   | portrait_flipped | true | 1 |

   The WebView survived each turn without a reload (`configChanges`). All of it is moot after §3.3.

## 4. What is in the code now

The player is `ecc5268`'s code again. The two page commits made for the failed approach were reverted on
2026-10-28 on the owner's word, with their tests (`PlayerStageTurnTest`, `TurnedDisplayTest`):

- `e547105` had put the portrait quarter turns inside `@media (orientation: landscape)` in
  `player/index.blade.php`, so a viewport taller than wide was not turned.
- `2a79de3` had made `applyOrientation` in `player.js` call `window.SignagePlayer.turnDisplay(orientation)`,
  and `#stage[data-display-turned="true"]` turn nothing when the app answered true.

They were live from 2026-10-28 until the revert is deployed. With the release app (versionCode 1, no bridge)
they changed nothing. With the debug build on the owner's stick, the revert stops the page asking the app to
turn the display.

## 5. What is left to try

Every try is seen on the stick before anything goes live (§6), and with the display at rotation 0, so the
screencap is the panel.

a) **Keep the video on the compositor.** The compositor draws the video correctly turned (§3.1); only the
   overlay is wrong. Find a CSS property that makes Chromium leave the video quad on the compositor while
   still showing frames: for example a transform that is not an exact quarter turn, or a 3D transform on the
   video. `opacity: .999` and a canvas copy both gave black on 2026-10-27, so this may fail the same way. It
   can be tried live on the stick without a deploy: a debug build of the app exposes the WebView's DevTools
   (`setWebContentsDebuggingEnabled`), CSS goes in through `Runtime.evaluate`, and the HWC table says within
   seconds whether a `SurfaceView` layer appeared.

b) **Play video natively in the app.** The page hands a video to the app through a JavaScript bridge (its
   address, its box on the stage, the way the set is mounted), and the app plays it with ExoPlayer in a
   `TextureView` placed over the WebView and turned with `setRotation`. A TextureView is composited like
   any view, so it turns with the rest. This is a bigger change in both the page and the app, and the
   offline cache (the service worker's files) would need a path the app can read. **Rejected by the owner
   on 2026-10-28** ("mujhe meri hi application chahiye"): not to be built.

c) **Rejected by the owner:** writing a rotation flag into the MP4.

d) **Rejected by the owner on 2026-10-28:** any step a computer has to do on each TV (a `.bat`, `adb appops`).
   Turning the WebView view itself (`setRotation`) was talked through and not chosen: the overlay is a
   `SurfaceView`, which a view rotation does not reach.

So a) is the one left: §8 is its test plan.

## 6. Seeing it on a real stick

The emulator has no Amazon WebView. The owner's stick: Settings → My Fire TV → Developer options → ADB
debugging on, its IP from About → Network, then from the project folder:

```powershell
.\scripts\tv\capture-fire-tv.ps1 -Ip 192.168.1.71 -Seconds 60
```

It connects (accept "Allow USB debugging" on the TV the first time), installs nothing, and writes a folder of
`dumpsys SurfaceFlinger` every two seconds, a screencap every three, the logcat and a screen recording. In the
"Display 0 HWC layers" table of the dumps, the app's window is one row. A video on the overlay is a second
row, `SurfaceView - …`, and its **Disp Frame** says where the plane is. Today a portrait set shows
`0 0 2160 2160` there.

The screencaps are the panel only while the display is at rotation 0. If anything has turned the display,
check `adb shell dumpsys display` (the `rotation` in `mOverrideDisplayInfo`) and believe a photo of the TV.

## 7. Putting a stick back after a debug build

A debug build is signed with another key, so the release app does not install over it:
`adb uninstall com.thedisplaysolution.player`, install the release APK, and pair the stick again with Screens
→ Replace Device. Uninstalling the app also drops any orientation it requested. `adb shell settings delete
system user_rotation` clears a turn made by hand.

## 8. Raasta 1, the test plan for §5 a) (owner's choice, 2026-10-28)

The owner chose a page-only fix: a CSS rule that keeps Chromium from promoting the video to the overlay,
so it stays on the compositor, where the stage's turn applies (§3.1). If one works, it reaches every set
with a deploy and a "Reload player": no APK, no store, no permission.

**The test build.** Since the revert (§4) the page asks nothing of the app, so any debug build behaves like
the release app. `TheDisplaySolution-debug-raasta1.apk` is the release app exactly: Android commit
`138e8f7` with only `WebView.setWebContentsDebuggingEnabled(BuildConfig.DEBUG)` added, built 2026-10-28
and left on the owner's Desktop at the office (not committed). Install it as §7 says, pair it with Replace
Device on the test screen, set that screen to **Portrait (rotated 90°)** with one 10-second video, and keep
the display at **rotation 0** (`adb shell settings delete system user_rotation`; check with `adb shell
dumpsys display`), so a screencap is the panel (§6).

**The candidates**, one at a time, pasted into DevTools (chrome://inspect → the stick → the player page →
Console) and watched for 60 s with `capture-fire-tv.ps1`: the promotion comes a few seconds in, so the
first screenshots prove nothing. A candidate works when the HWC table has **no second row**
(`SurfaceView - …`) for the whole minute AND every screencap is upright, full and not black, and a photo
of the TV agrees.

| | Rule on `#stage .media-layer video` | Why it may keep the video off the plane (viz `OverlayCandidateFactory`) | Risk |
|---|---|---|---|
| A | `opacity: 0.999` | `kFailOpacity`: checked first, for every quad | went black on 2026-10-27, but together with the replay in place; tried alone here |
| B | `mix-blend-mode: screen` | `kFailBlending`: only src and src-over may be planes; over the black layer `screen` draws the video unchanged | colours shift if anything not black is under it |
| C | `filter: saturate(1.0001)` | a filter puts the video in a render pass of its own, drawn by the compositor | some cost per frame |
| D | `clip-path: inset(0 round 1px)` | a rounded mask (`kFailMaskFilterNotSupported` where planes cannot clip round) | the corners lose a pixel |
| E | `transform: rotate(0.01deg)` | `kFailNotAxisAligned` | Amazon promoted the video although the stage is turned 90°, so its path may not look at the transform |
| F | `transform: rotateX(0.01deg)` | a 3D transform (`kFailNotAxisAligned3dTransform` in delegated contexts, not axis aligned otherwise) | as E |

```js
// Raasta 1, in the stick's DevTools console. Change RULE to the candidate; "Reload player" (or a page
// reload) takes it away again.
const RULE = 'opacity: 0.999';
let style = document.getElementById('raasta1');
if (!style) { style = document.createElement('style'); style.id = 'raasta1'; document.head.appendChild(style); }
style.textContent = `#stage .media-layer video { ${RULE} }`;
[document.getElementById('stage').dataset.orientation, getComputedStyle(document.querySelector('#stage .media-layer video') || document.body).opacity];
```

Write down for each: the HWC table (one row or two), the screencaps (upright, sideways or black), the photo,
and the WebView version (`adb shell dumpsys package com.amazon.webview.chromium | findstr versionName`). The
first candidate that holds for a full minute goes into the player's own CSS on `bug-fixing`, with a test, and
is deployed on the owner's word; then the Roadstar sets "Reload player". If none holds, it goes back to the
owner: §5 b) and d) are rejected.

### 8.1 Results, 2026-10-28 (home session, the owner's Fire TV Stick 4K Max): none holds

**How it was run.** WebView `132.amazon-webview-v132-6834-tv.6834.209.18`. The stick ran
`TheDisplaySolution-debug-orientation.apk` (debuggable, with the `SignagePlayer` bridge), restarted first. Two
departures from the plan above, both on purpose:

- **The revert was not live yet**, so the live page still asked the app to turn the display. Before every
  candidate the bridge was switched off inside the page through DevTools (`turnDisplay('landscape')`, then
  `window.SignagePlayer = undefined` and `data-display-turned="false"`), which leaves the page doing exactly
  what the reverted page does. Every dump of every run shows the app's window at Source Crop `1920 × 1080`,
  so the display was at rotation 0 throughout and every screencap is the panel.
- **The test screen was Portrait (rotated 270°), not 90°**: the owner had left it there. The overlay bug is the
  same for both portraits (§3.2), and only a candidate that held would have needed the 90° run.

Each candidate was put in a `<style id="raasta1">` through DevTools, its computed style read back from the
playing video, then 60 s of `capture-fire-tv.ps1`. No page reload happened during any run, since the style was
still there afterwards. Run 0 is the control, with no rule.

| | Rule on `#stage .media-layer video` | Computed on the video | HWC: dumps with the `SurfaceView` row | Overlay Disp Frame | Screencaps (every ~6 s) | Holds? |
|---|---|---|---|---|---|---|
| 0 | none (control) | – | 13 of 18 | `0 0 2160 2160` | full, then **sideways** while the overlay is up | – |
| A | `opacity: 0.999` | opacity 0.999 | 14 of 19 | `0 0 2160 2160` | full, then **black** while the overlay is up | no |
| B | `mix-blend-mode: screen` | screen | 14 of 19 | `0 0 2160 2160` | full / **black** | no |
| C | `filter: saturate(1.0001)` | saturate(1.0001) | 14 of 19 | `0 0 2160 2160` | full / **black** | no |
| D | `clip-path: inset(0 round 1px)` | inset(0px round 1px) | 13 of 17 | `0 0 2160 2160` | full / **black** | no |
| E | `transform: rotate(0.01deg)` | matrix(1, 0.000175, …) | 14 of 18 | `0 0 2160 2160` | full / **black** | no |
| F | `transform: rotateX(0.01deg)` | matrix3d(…) | 13 of 17 | `0 0 2160 2160` | full / **sideways**, as the control | no |

No photo was taken: a photo was asked for a candidate that held, and none did. The run folders are
`%TEMP%\fire-tv-capture\run-20261028-011023` (control) to `run-20261028-011740` (F) on the home PC.

**What the runs say about the cause.**

- **CSS does not decide whether the video goes to the plane.** Every candidate, each one a reason for viz
  (Chromium's compositor) to refuse a plane, still got the `SurfaceView` row, a few seconds after each new
  video element, as often as the control did.
- **The plane's box comes from the element's layout box, not from what the compositor draws.** E and F turn
  the drawn video and A to D change how it is drawn, yet the Disp Frame stayed `0 0 2160 2160`, the un-turned
  layout rect, every time. So this is not viz's overlay promotion: the WebView itself switches the video to
  a `SurfaceView` placed at the element's box, and the compositor only leaves a hole there.
- **A to E turn the hole black.** With an effect on the video, the compositor no longer leaves a clear hole: it
  paints the box black over the plane, so the panel shows black where the video should be. This is the black
  of `opacity: .999` on 2026-10-27, now reproduced alone. F changes nothing at all.

Per the plan above, this goes back to the owner: §5 b) and d) are rejected, and a) has nothing left in this
list.

### 8.2 One more page-only try, 2026-10-28: a tiny video feeding a canvas (does not hold either)

The canvas of `2fb9efa` (2026-10-27) kept the video element full size and only `visibility: hidden`, so the
WebView still took it to its plane at that size and the canvas got no frames. Tried here: every video on the
stage shrunk to **2 × 2 px** (`position: absolute; width: 2px; height: 2px`), its frames drawn with
`drawImage` on every animation frame onto a canvas beside it that takes the video's place and turns with the
stage. Installed through DevTools on the same stick, same conditions as §8.1 (bridge off in the page, rotation
0 throughout, Portrait (rotated 270°)), with a sampler in the page every 250 ms beside a screencap every
~1 s and the HWC table every ~3 s.

- **The canvas does get frames, some of the time.** For some video elements it is lit for their whole ten
  seconds, drawn at 30 to 44 frames a second, the menu right way up and full screen on the panel.
- **For others it is black for their whole ten seconds, while the video plays** (its `currentTime` running,
  no dropped frames). Exactly then the HWC table holds the overlay again, now at the shrunken element's box,
  `SurfaceView … Client 0 0 4 8`: the WebView took even a 2 × 2 px video to its plane, and a video on the plane
  gives the page nothing to copy. The panel is pure black for those seconds. First run: 3 screencaps of 9
  black. Second run: two elements in a row black, 20 s of black screen.
- **Releasing the finished videos' decoders** (`removeAttribute('src')` and `load()` on every paused video as a
  new one starts) changed nothing: black still came, two elements in a row.
- **Cost:** the player's process at 83 to 93 % of a core while drawing, and about 200 MB.
- During the first run the test's own script held every canvas in a list after the player had dropped them
  (1080 × 1920, ~8 MB each), the stick ran out of memory, and the player's process died once
  (`has died: fore TOP`, 01:30:12); its WatchdogService started it again 16 s later. That was the test's leak,
  fixed for the second run, not the approach; the second run did not crash.

So whether a video goes to the plane does not depend on its size either; the WebView decides per element, and a
canvas can only show the elements it leaves alone. A page-only fix would have to choose between seconds of
black and seconds of sideways, which is not a fix. Back to the owner, with what is left outside the page:
a turned copy of each video made on the server, videos turned before upload, or other hardware.
