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
   offline cache (the service worker's files) would need a path the app can read.

c) **Rejected by the owner:** writing a rotation flag into the MP4.

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
