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

## 9. Raasta 2: the server keeps a turned copy of each video (plan, 2026-10-28, not started)

Asked for by the owner after §8.1 and §8.2. Nothing below is built yet; §9.9 says where to start and §9.10 what
the owner still has to decide.

### 9.1 The idea

A Fire TV draws a video on its plane unturned, at the element's layout box (§3.2, §8.1). So give it a video whose
PIXELS are already turned, in an element laid out flat over the whole panel: the plane then shows exactly what
the page's CSS turn shows today. For a screen mounted another way, the server makes a copy of each video it may
play with the pixels turned the way the page turns the stage; the manifest sends that copy marked `flat`; the
player draws a `flat` item outside the turned stage, untransformed, filling the viewport. On every other player
the picture is the same: turned pixels drawn flat are the original pixels drawn turned. Pictures and Ad Builder
pages keep the CSS turn.

| `screens.orientation` | The page's turn today | The copy is made with |
|---|---|---|
| `landscape` | none | no copy |
| `portrait` | `rotate(90deg)`, clockwise | `transpose=1` (90° clockwise) |
| `portrait_flipped` | `rotate(270deg)` | `transpose=2` (90° counterclockwise) |
| `landscape_flipped` | `rotate(180deg)` | `transpose=1,transpose=1` |

### 9.2 Which videos get a copy

Only what a screen that is not `landscape` may play, for that screen's turn: library videos on its playlist, the
ads of every channel its playlist carries, its holding picture, and network adverts (`campaigns`, which keep their
own file) that reach it. **Not Ad Builder pages:** their videos play inside the page's frame, in the design's
coordinates; a later phase at most, and until then such a page with a video keeps the bug.

### 9.3 Data

A new table `video_turns` (its own reversible `create_video_turns_table` migration): `media_id` and
`campaign_id` (nullable, exactly one set, cascading foreign keys), `rotation` (90, 180, 270), `status`
(`pending`, `working`, `ready`, `failed`), `disk`, `path` (a random name, never an id), `size`, `attempts`,
`error`, timestamps. Unique on (`media_id`, `rotation`) and (`campaign_id`, `rotation`).

### 9.4 When a copy is asked for, and how it is made

**Asked for** by `VideoTurns::wantFor(Screen)` after every change that can put a video in front of such a
screen: a playlist save and Copy to Other Screens, an orientation changed or set at pairing, a holding picture
chosen, a channel ad added or changed, a campaign saved or given screens (`insertOrIgnore` of `pending` rows).
A daily sweep asks again for everything and deletes copies nothing has needed for seven days.

**Made** by `php artisan media:turn-videos`, scheduled every minute with `withoutOverlapping()`: the server has no
queue worker, only the scheduler's cron. One row at a time under a row lock, ffmpeg under `nice -n 19` with a
timeout, written to a temporary name, checked (length by `VideoDuration` within a second, width and height
swapped for a quarter turn), then `ready`. Stops after about 50 s. A failure is retried up to three times.

```
nice -n 19 ffmpeg -nostdin -y -i IN -map 0:v:0 -an -vf transpose=1 -c:v libx264 -preset veryfast -crf 21 -pix_fmt yuv420p -movflags +faststart OUT.mp4
```

No sound (the player plays every video muted). ffmpeg applies a phone video's own rotation first by default:
check that on a real phone video in step 2 of §9.9.

### 9.5 The manifest and the player

- **Manifest** (`DeviceController::manifestItem`, `campaignItem`, the ads of `channelEntry`, the holding
  picture): a video whose copy for the screen's turn is `ready` is sent as the copy (`url`, its own `checksum`)
  with `flat: true`; anything else as today. The offline timeline uses the same builders.
- **Player:** a `#flat` box beside `#stage`, the whole viewport, never transformed; a `flat` item is drawn
  there with `object-fit: contain`. The break between items, the pause under a break, the layer swap and the
  quiet poll must all hold for it.

### 9.6 Storage, disk, deletes, privacy

- Counting copies to the organization's 512 MB is the owner's call (§9.10): the rule counts every file a row
  names, but a copy exists only because of how a Fire TV draws video.
- `DiskGuard::assertRoomFor` is asked before every copy; with no room it is `failed` and the original plays.
- A video's or a campaign's delete, and an organization's purge, take the copies and their files after commit.
- `/privacy` and its date change in the same commit: the server keeps turned copies of customers' videos.

### 9.7 The server

- ffmpeg is not installed: the owner, as root, `apt install ffmpeg` (Ubuntu 24.04: 6.1), also added to
  `deploy/server/setup.sh`.
- One vCPU shared with Nginx, PHP and MySQL: one copy at a time at the lowest priority. The time a copy takes is
  an estimate until step 2 measures it: seconds to tens of seconds for a ten-second clip, a few minutes for a
  five-minute video; the panel is slower meanwhile.
- A copy is about the size of its original; the disk is 40 GB with the 5 GB reserve.

### 9.8 Tests

Pest for every trigger, the command (a Process fake, and one real-ffmpeg test skipped where it is missing), the
manifest's `flat` item and its fallback, deletes and purge, the reserve, the migration, the attacks. Dusk for the
`#flat` box through a break and a layer swap. And the test that decides, on the stick: `capture-fire-tv.ps1` at
rotation 0 for 60 s on each of the three turned orientations, the overlay at `0 0 3840 2160`, every screencap
full and right way up, and the owner's photo.

### 9.9 Order of work

1. **Prove it before any code:** the owner exports the test video turned by hand (90° counterclockwise for
   Portrait (rotated 270°)), uploads it, plays it on the test screen set to **Landscape**, and 60 s of capture
   plus a photo must show it full and right way up.
2. Measure on the server: a ten-second and a five-minute 1080 × 1920 video through the command, timed, and one
   phone video with a rotation of its own.
3. Backend (migration, model, `VideoTurns`, command, triggers, manifest), 4. the player's `#flat` box,
   5. tests, the stick, the photo, deploy on the owner's word.

### 9.10 What the owner decides before step 3

1. Are copies counted to the organization's 512 MB?
2. Ad Builder pages with a video stay as they are for now?
3. ffmpeg on the server (installed by the owner as root).

## 10. What Xibo does (read on 2026-10-28, before deciding on §9)

The owner asked how Xibo, the open-source signage product, plays video and turns a screen, before building §9.
Read from the repositories and the vendor's forum; the Android player is closed source, so its facts come from
the vendor's own words there.

| Player | What draws the layout | Where video plays | Turning the screen |
|---|---|---|---|
| Xibo for Android (official, closed) | the app's own views; a WebView only for web pages and embedded HTML | **native**: `VideoView` in 2015, ExoPlayer since; into a `SurfaceView` by default, a display-profile option "Use a SurfaceView for Video Rendering?" turns it off for a `TextureView` on devices that need it | the display profile's Orientation, "only if the device supports rotation"; the app does not turn the picture itself |
| Xibo for Linux (official, C++/GTK, archived 2024) | GTK widgets | **outside the WebView**: GStreamer `playbin` into a `gtkglsink` widget; WebKitGTK only for HTML | none in the player; the OS |
| Electron player (official, current, Linux and Windows) with the Xibo Layout Renderer | a Chromium page | `<video>` through video.js | none in the player; the OS |
| xiboplayer (community, 2026: PWA, Electron, Chromium kiosk) | a browser page, `renderer-lite.js` | `document.createElement('video')`, `object-fit`, autoplay, playsInline | none in the player (its only `rotate` is a spinner) |
| Arexibo (community, Rust) | Qt WebEngine, which is Chromium | the page's `<video>` | none in the player |

So no Xibo player turns a video itself. The browser players leave the turn to the operating system's display
rotation, and the Android app to the device. On the Fire Stick the vendor's founder wrote on 2026-02-01 that
"the device doesn't support rotation", their own Signage Stick neither, and that "Amazon recommends that you
rotate your content, which we know is a heavy lift"; a 2021 thread about portrait on a Fire Stick ended in a
feature request. Xogo, another signage product, documents the same for Fire TV: no portrait, pre-rotate the file.

What it means here: the wall we met is the one everybody meets. Nobody turns a video inside a WebView on a
Fire TV; the one app that plays video natively still needs the device to turn, which this device does not.
The vendors' answer for a portrait Fire Stick is a turned video file, which §9 makes the server do and which
§5 b) and d) rejected on the app side. Xibo's `TextureView` option shows what a native path would look like
(a view that turns with the rest), and §5 b) rejected it.

Sources: xibosignage/electron-player, xibosignage/xibo-layout-renderer (`src/Modules/Media/VideoMedia.ts`),
xibosignage/xibo-linux (`player/control/media/player/GstMediaPlayer.cpp`, `webview/WebViewGtk.cpp`),
xiboplayer/xiboplayer (`packages/renderer/src/renderer-lite.js`), xiboplayer/arexibo (README);
community.xibo.org.uk threads 3688 (2015, 2017: VideoView, the WebView rejected), 37022 (2026: the Fire Stick
and rotation), 26099 (2021: portrait on a Fire Stick), 24010 and 15391 (the SurfaceView option);
docs.xibosignage.com display settings ("Android, webOS and Tizen can control rotation if the device supports it").

### 10.1 How the community players launch their browser (read 2026-10-28)

The owner asked how the `xiboplayer` repositories run their WebView. They run no WebView at all: each is a
Linux desktop browser in kiosk mode pointed at a local page, and the page is the renderer of §10.

- **xiboplayer-chromium** (`xiboplayer/launch-kiosk.sh`): a local Node server, then Chromium with `--kiosk`,
  `--autoplay-policy=no-user-gesture-required`, `--ignore-gpu-blocklist --enable-gpu-rasterization
  --enable-zero-copy --enable-features=CanvasOopRasterization`, and the usual kiosk quieting flags. It picks a
  GPU from `/sys/class/drm` and sets the VA-API driver for video decode. Only on a virtual GPU (a VM) does it
  fall back to `--disable-gpu --disable-gpu-compositing --disable-gpu-rasterization`, because the driver
  crashes otherwise. Nothing about overlays, nothing about rotation.
- **xiboplayer-electron** (`src/main.js`): the same GPU selection and kiosk window around the same page.
  Nothing about rotation.
- **xiboplayer-kiosk**: a Fedora/Debian image with a GNOME kiosk session that starts one of the two above as a
  user service. No rotation of its own: the display is whatever GNOME has.

### 10.2 "Hardware Accelerate Web Content" (the owner's idea of 2026-10-28): rejected on the evidence

Xibo for Android's display profile has "Hardware Accelerate Web Content" (Off / Off when transparent / On).
The idea: turn the WebView's hardware acceleration off in our app, so the Fire TV cannot put the video on
its plane and the page's CSS turn reaches it. Two findings say no, without a build:

1. In Xibo that option governs the WebView its web and embedded widgets use, and nothing else: Xibo's
   layout video never plays in a WebView (§10, VideoView then ExoPlayer). Their staff explain the option only
   in terms of transparent web widgets (the default, "Off when transparent", is "not the default setting for
   good reason"; "On" can make transparent web content impossible on some hardware). It has never turned a
   video for them.
2. Chromium's own WebView documentation (`android_webview/docs/software_draw_deprecated.md`): software draw,
   which is what `android:hardwareAccelerated="false"` or `setLayerType(View.LAYER_TYPE_SOFTWARE)` on the
   WebView or any parent puts it in, "is deprecated and only maintained for backward compatibility", "is very
   slow", "uses more memory", and "has known missing features, such as `<video>` or `<webgl>`"; "Do not call
   `setLayerType(View.LAYER_TYPE_SOFTWARE)` on WebView or any parent of WebView." Every video the player shows
   is a `<video>`; in software draw it would not be drawn at all. A 2013 report on the same path: "Sound
   starts, but video doesn't."

So the idea trades a sideways video for no video. Not worth a build.
