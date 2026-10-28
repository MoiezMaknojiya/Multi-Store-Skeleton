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

**The Android app's source** is its own repository, `thedisplaysolution-android`, kept beside this one (on the
owner's PCs, `D:\Site	hedisplaysolution-android`): Kotlin, a Compose shell around one `WebView` in `MainActivity.kt`
(`javaScriptEnabled`, `domStorageEnabled`, `mediaPlaybackRequiresUserGesture = false`, hardware acceleration left
on), two flavours (`site` self-updating, `play` for Google Play), `versionCode 1`. Its `main` carries commit
`bbd6a81` (2026-10-28): the `SignagePlayer` bridge whose `turnDisplay` requests the activity's orientation, and
`setWebContentsDebuggingEnabled(BuildConfig.DEBUG)`. The bridge serves the disproved approach (§3.3) and is unreleased;
the debugging line is worth keeping for §6. Whether to revert the bridge there is the owner's call.

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

### 9.9.1 Step 1 done, 2026-10-28: a turned video holds on the stick

The test video (`01M5RE6GHENFEA9R7BTESVK4YB.mp4`, 1080 × 1920, 10 s, H.264) was turned for Portrait (rotated 270°)
with ffmpeg 6.1.1 on the home PC (`-vf transpose=2 -c:v libx264 -preset veryfast -crf 21 -pix_fmt yuv420p -an
-movflags +faststart`: 1920 × 1080, 10 s, 2.2 MB, no rotation flag, 0.6 s on that many-core PC, which says nothing
about the server's one vCPU). Its frame matched the old 270° CSS picture exactly: the logo on the left. The owner
uploaded it, put it alone on the test screen's playlist and set the screen to Landscape. Then two 60 s captures on
the stick, the display at rotation 0 in every dump, so every screencap is the panel:

| Run | Page | Dumps with the overlay | Overlay Disp Frame | Screencaps |
|---|---|---|---|---|
| 1 | the live page as it was, its bridge switched off in DevTools | 16 of 17 | `0 0 3840 2160`, the whole panel | 9 of 9 full and turned right |
| 2 | after the owner's reload, on the reverted page (`player-BPFlxHPc.js`, live since about 02:15) | 15 of 18 | `0 0 3840 2160` | 9 of 9 full |

So the plane that drew the video sideways in a 1080-pixel square (§3.2) draws a video whose pixels are already
turned across the whole panel, the right way round, for as long as it holds it. This is the proof §9 needed before
any code: the server's turned copy, sent flat, is what a portrait Fire TV can show. Run 2 is exactly a customer's
set today: the release page, a Landscape screen, a turned file.

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

## 11. WebCodecs: the page decodes the video itself (prototype, 2026-10-28): HOLDS

The owner did not want §9's second copy of every video on the server's disk. The stick's WebView (Chrome 132)
has WebCodecs, and `VideoDecoder.isConfigSupported` says yes to hardware decoding of H.264 at 1080 × 1920 and
at 4K, VP9 and VP8. So the page can play a video with no `<video>` element at all: open the file itself
(mp4box.js), decode it frame by frame with `VideoDecoder`, and draw each `VideoFrame` on a `<canvas>` inside
the stage. Nothing is a media element, so nothing goes to the Fire TV's plane, and the canvas turns with the
stage's CSS like a picture. No second file, no server work, no new APK.

**The prototype** (`scripts/tv/webcodecs-prototype.js`, numbers from `webcodecs-prototype-stats.js`) was put into
the live player page through the debug build's DevTools, mp4box.js 0.5.3 loaded the same way: the player's own
`<video>` elements lose their source and are hidden as they appear; their file is fetched, demuxed, decoded a few
frames ahead, each frame drawn when its time comes, the decoder reset and the file played again at its end.

**On the stick**, the test screen Portrait (rotated 90°), the original 1080 × 1920 menu video, the display at
rotation 0, 60 s of `capture-fire-tv.ps1`:

| | The player as it is | The prototype |
|---|---|---|
| Dumps with the `SurfaceView` overlay | 13–16 of 17–18 (§8.1, §9.9.1) | **0 of 15** |
| Screencaps | sideways while the overlay is up | **8 of 8 full**, turned exactly as the CSS turns the stage |
| Frames drawn | – | **27.7 a second** of the video's 30; 135 skipped as late in 65 s (about 7 %) |
| Decoder errors | – | none, 7 loops |
| The app's CPU (`top`, of four cores) | about 56 % | about 108 % |

So this is the first fix that works inside the page on a turned Fire TV, without a copy. Before it is the
player's way, these are open:

- **Long videos:** the prototype reads the whole file into memory; a five-minute video must be streamed (mp4box
  takes the file in pieces; the service worker's parts and range requests already exist, §15 of the Ad Builder
  spec). The stick has 1.7 GB, about 600 MB free.
- **WebM:** mp4box.js reads MP4 only. A WebM on a turned Fire TV needs a WebM demuxer, or keeps today's
  `<video>`.
- **Where to use it:** only where the `<video>` is wrong: a screen not landscape, on a Fire TV (`AFT` in the
  User-Agent), with `VideoDecoder` present. Everything else, and every WebView older than Chrome 94 (no WebCodecs;
  the player's floor is Chrome 80), keeps `<video>` as today.
- **The player's loop:** a video's end, its length as the backstop, the pause under an advert break and the
  replay all go through the `<video>` element today; a WebCodecs item has to give the same events.
- **Smoothness and CPU:** 27.7 of 30 frames, double the CPU. Drawing on a `VideoFrame` callback schedule, or
  through WebGL, may do better; seen on the stick first.
- **Size:** mp4box.js is about 157 KB minified; loaded only on a Fire TV that needs it.
- **Ad Builder pages** with a video inside: the same technique would go into the ad runtime; later.

## 12. Plan: the player decodes a turned screen's video itself (2026-10-28; built §12.10, on the stick §12.11)

The owner chose §11's way after seeing it on the TV ("Haan, theek ha"). This plan replaces §9 (server copies),
which stays on file in case §12 meets a wall. §12.1 to §12.9 are the plan as written before any of it was built;
§12.10 says what was built and §12.11 what the stick showed.

### 12.1 What changes, in one paragraph

On a screen that is not `landscape`, on a Fire TV, where `VideoDecoder` exists and the switch of §12.6 is on, a
video item (a playlist line, a channel's ad, a network advert, the holding picture) is played by a new element,
`<decoded-video>`, instead of `<video>`: it reads the MP4 itself, decodes it with WebCodecs and draws each frame
on a canvas of its own, so it turns with the stage like a picture and nothing goes to the plane. It looks to the
player like a `<video>`: the same events and the few properties the loop uses. Everything else, and every set
that does not meet all four conditions, plays exactly as today.

### 12.2 `<decoded-video>`: the contract (`resources/js/core/decoded-video.js`, a custom element)

What `player.js` uses of a `<video>` today, and the element gives the same:

| `player.js` uses | Where | `<decoded-video>` |
|---|---|---|
| `src`, `muted`, `autoplay`, `playsInline`, `preload` | `buildNode` | `src` starts the load; the rest are accepted and ignored (it is silent, starts itself) |
| `play()` (a promise), `pause()` | `playCurrent`, `pauseContent`, `resumeContent`, `playAd`, `reveal` | the clock runs or stops; the position is kept |
| `loadeddata` | reveal | after the first frame is drawn |
| `ended` | `advance`, an advert's `finish` | after the last frame's time has passed |
| `error` | `broken`, an advert's `finish` | a fetch, demux or decoder failure, or no frame within 10 s |
| `duration`, `currentTime` | `remainingOnScreen`, `resumeContent` | seconds, from the file's own times |
| `tagName === 'VIDEO'` | five places | a helper `isVideoNode(node)` takes both tag names; the five checks call it |

Inside: the file is read in pieces with `Range` requests guided by mp4box.js (`appendBuffer` returns where it
needs to read next), so the `moov` is found wherever it is and a five-minute video is never whole in memory;
samples are extracted in batches and released (`releaseUsedSamples`) once decoded; the decoder runs a few
frames ahead (at most 8 `VideoFrame`s held, every one closed after its draw); frames are drawn on
`requestAnimationFrame` when their time comes, a late one dropped. Taken off the page
(`disconnectedCallback`), it aborts its reads, closes its decoder and frees its canvas. The element never loops
by itself: the player decides what comes next, as it does for `<video>`.

### 12.3 `player.js`

- `buildNode`: a video item becomes `<decoded-video>` when `decodesTurnedVideo(item)` says so (§12.1's four
  conditions, plus the file being MP4: `item.mime` `video/mp4`). mp4box.js and the element are a dynamic
  `import()`, a chunk of their own that only such a set ever fetches.
- The five `tagName === 'VIDEO'` checks go through `isVideoNode`.
- Nothing else in the loop changes: reveal, the backstop timer, breaks, `skipBroken`, the offline timeline.

### 12.4 The worker (`public/player-sw.js`)

Nothing to change, to be confirmed on the stick: `media()` already answers a `Range` request by slicing the
cached whole (`partial()`), so pieces read online and offline alike; a file not cached yet goes to the network,
which answers ranges (Nginx).

### 12.5 The server

- A switch, `config('signage.decode_turned_video')` (`SIGNAGE_DECODE_TURNED_VIDEO`, in `.env.example`), sent in
  the manifest as `screen.decode_turned_video`. Off, every set plays `<video>` as today; turned off after a bad
  night, every set is back to today's way on its next poll, with no deploy and no reload.
- Nothing else: no table, no ffmpeg, no copy.

### 12.6 Where it applies, and where it does not (phase 1)

- **Applies:** a Fire TV (`/\bAFT/` in the User-Agent), Chrome 94 or newer (`VideoDecoder`), a screen that is
  not landscape, an MP4 video.
- **Does not, and keeps today's `<video>`:** WebM files (mp4box reads MP4 only; a WebM demuxer is a later phase),
  landscape screens, other devices (whether they have the plane bug at all is unknown; they can be added by
  widening the check once seen), WebViews older than Chrome 94, and the videos inside Ad Builder pages (the ad
  runtime is a later phase).

### 12.7 Tests

- **Pest:** the manifest carries `decode_turned_video` from config; `PlayerOnOldWebViewsTest` learns that
  `VideoDecoder` and mp4box.js may appear only in the decoded chunk and only behind the feature check (§12.9, 2).
- **Dusk** (Chrome has WebCodecs): with the decoded path forced (a test-only override), a portrait screen's video
  is a `<decoded-video>` whose canvas draws frames, `loadeddata` swaps the layers, `ended` moves the loop on, a
  network break pauses it and it resumes where it was, a broken file is skipped, a landscape screen still gets
  `<video>`, and the switch off gives `<video>` everywhere. Each seen failing first.
- **On the stick, the tests that decide** (`capture-fire-tv.ps1`, rotation 0, 60 s each): Portrait (rotated 90°),
  Portrait (rotated 270°), Landscape (flipped 180°): no `SurfaceView` row, every screencap full and right, about 30
  frames a second; a five-minute video for ten minutes with `dumpsys meminfo` (memory flat); a playlist of a
  picture and two videos (the swaps); a network break over a video (pause and resume); the line pulled (offline,
  from the cache); the switch turned off (back to `<video>` on the next poll). Then the owner's photo.

### 12.8 Order of work

1. The switch and its manifest field (server), with its Pest test.
2. `decoded-video.js`, with mp4box.js added as a dependency (BSD-3-Clause), and the Dusk tests.
3. `player.js`: `decodesTurnedVideo`, `isVideoNode`, the dynamic import.
4. The stick tests of §12.7, the photo; deploy with the switch off, turn it on for the test screen's stick, then
   for everyone on the owner's word.

### 12.9 What the owner decides first

1. **The switch's default** on the live site: off until the stick tests pass, then on (recommended), or on at
   once.
2. **The Chrome 80 rule.** The project's rule says nothing a television runs calls an API newer than Chrome 80.
   This needs a stated exception: a newer API is allowed only in a chunk loaded behind its own feature check,
   with the Chrome 80 path left whole.
3. **Fire TV only** for now (recommended), or every Android set on a turned screen.
4. **WebM** in phase 1, or later (recommended: later; MP4 is what phones and editors make).

### 12.10 Built, 2026-10-28

The owner said "kardo" with §12.9's recommendations: the switch off on the live site until the stick tests pass,
the stated exception to the Chrome 80 rule (in the rules), Fire TV only, WebM later.

- `config('signage.decode_turned_video')` (`SIGNAGE_DECODE_TURNED_VIDEO`, off by default) is sent as
  `screen.decode_turned_video` in every playlist answer (`DecodeTurnedVideoSwitchTest`).
- `resources/js/player/decoded-video.js`: the `<decoded-video>` custom element, mp4box.js 0.5.3 (an npm dependency,
  BSD-3-Clause), in a chunk of its own of about 156 KB (35 KB gzipped) that only a set which may use it fetches.
  It reads the file in Range pieces of 2 MB guided by mp4box (index first or last), takes frames out as soon as the
  index is read, holds at most 90 waiting and 6 decoded, lets mp4box drop every piece it has handed out, prefers the
  hardware decoder and falls back to any, honours the turn in the file's own header, gives up with `error` when no
  frame comes in 15 s, when the file has no index or ends short, and draws at most 1920 pixels a side.
- `player.js`: `decodesTurnedVideo` (switch or the set's own `signage.decode_turned_video`, a Fire TV, WebCodecs, a
  screen not landscape, `video/mp4`), `loadDecoder` (the chunk, at start-up when wanted and on every answer), and
  `isVideoNode` in the five places that asked `tagName === 'VIDEO'`; the switch is part of the "nothing changed"
  key, so turning it plays the playlist again the other way.
- `DecodedVideoTest` (Dusk, three tests, seen failing on the player before): a portrait screen draws its video
  through the decoder with the panel's right half red and its left half blue, pause holds and play carries on; files
  with the index first, the index last and a turn in the header are read in 700-byte pieces and shown upright to
  their end, a file that is no video gives `error`; a decoded video moves the loop on, while a set told '0' and a
  landscape screen play a `<video>`. `PlayerOnOldWebViewsTest` holds the exception.
- Not covered yet: WebM, Ad Builder pages' own videos, and a set that boots with no line before it ever fetched the
  chunk (the worker keeps only the page's own scripts, so such a set plays `<video>` until the line comes back).

**What remains is the stick (§12.7)**: deploy with the switch off, set the test stick's own override with DevTools
(`localStorage.setItem('signage.decode_turned_video', '1')`, then reload the player), and capture each of Portrait
(rotated 90°), Portrait (rotated 270°) and Landscape (flipped 180°), a long video for memory, a break, offline, and
the override back to '0'. Then the owner's photo, then the switch on.

### 12.11 On the stick, 2026-10-28: every turned orientation holds

Deployed with the switch off (live serves `player-*.js` with its `decoded-video-*.js` chunk); the test stick (Fire TV
Stick 4K Max, Amazon WebView 132, the debuggable `TheDisplaySolution-debug-orientation.apk`) told '1' through
DevTools, the owner turning the test screen in the panel between runs, its playlist two portrait menu videos (MP4,
10 s each, about 5 MB, so every round also swaps one decoded video for the next). Each run: the display at rotation 0,
so a screencap is what the panel shows, 60 s of `capture-fire-tv.ps1` (a SurfaceFlinger dump and a screencap every
few seconds) while the page was sampled four times a second.

| Screen in the panel | `SurfaceView` (the plane) in the HWC table | Screencaps | On screen | Frames drawn | CPU (of 400%) | Errors |
|---|---|---|---|---|---|---|
| Portrait (rotated 90°) | 0 of 15 dumps | 8 of 8 full, upright, the logo on the right | `<decoded-video>` only | 31.7 a second | 108% | none |
| Portrait (rotated 270°) | 0 of 14 | 7 of 7 full, upright, the logo on the left | `<decoded-video>` only | 27.2 a second | 92% | none |
| Landscape (flipped 180°) | 0 of 19 | 10 of 10, upside down with bars at the sides, as a TV hung upside down needs | `<decoded-video>` 248 of 248 samples | 31.9 a second | 110% | none |

Before the fix the same stick moved the video to the plane a few seconds into each round on a turned screen (§3.2),
sideways or small. The decoder's reads are answered by the worker from its cache (`workerStart` set, nothing
transferred), the same path the set takes offline.

**Memory, ten minutes on the stick** (`dumpsys meminfo` and `ps` every 30 s, at 180° and then 270° after the owner
turned the screen at minute three, which reloaded the page): the page's renderer process (`sandboxed_process0`) held
80 to 96 MB, the app's graphics memory moved between 44 and 150 MB as one element gave way to the next with no climb,
the set's free memory stayed between 520 and 670 MB, and the page's JavaScript heap at 10 MB while it could be read. 16
decoded elements came and went in the first two and a half minutes (some 60 over the ten at that pace); nothing
grew round by round.

**The set's own override back to '0'** (at 270°, the page reloaded): `<video>` on screen again and the plane in 6 of 9
dumps, today's bug, so the override decides; back to '1': `<decoded-video>`, the plane in 0 of 8 dumps, the screencap
full and turned for 270°. The stick is left on '1'.

**Offline** (at 270°): the network refused to the page and to its worker through DevTools
(`Network.emulateNetworkConditions` on both targets, in the one DevTools session that also sampled, since this WebView
drops a first client when a second connects; adb over the same Wi-Fi keeps working): a fetch to the server failed and
`navigator.onLine` was false at the start and at the end of the minute, while a Range read of the video still answered
206 from the worker's cache. All 248 samples were `<decoded-video>`, 7 rounds played at 30 frames a second, no
errors; the network given back, the server answered again.

**Not tried on the stick yet:** a five-minute video (the reads come in 2 MB pieces and mp4box drops each piece once
handed out, so memory should not depend on the length; the ten-minute run above covers the rounds), and a network
break over a decoded video (`DecodedVideoTest` covers pause and resume in Chrome). Then the owner's photo of the TV,
and the switch on for everyone (`SIGNAGE_DECODE_TURNED_VIDEO=true` in the live `.env`, then `php artisan optimize`;
`false` turns it off again, every set going back to `<video>` on its next poll).
