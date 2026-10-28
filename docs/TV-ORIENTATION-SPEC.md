# A set mounted another way: who turns the picture

The panel is always 1920×1080. A screen can be mounted four ways, and something has to turn the content
to match. Until 2026-10-27 the player page did it with CSS. On a Fire TV that breaks for video, and this
document is the handover for the fix: the **page's half is done** (§4), the **Android app's half is to
do** (§5), and §6 says how to see it working on a real stick before anything goes live.

## 1. The four ways

| `screens.orientation` | In the panel | The page's own turn | The app should request |
|---|---|---|---|
| `landscape` | Landscape | none | `SCREEN_ORIENTATION_LANDSCAPE` |
| `landscape_flipped` | Landscape (flipped 180°) | `rotate(180deg)` | `SCREEN_ORIENTATION_REVERSE_LANDSCAPE` |
| `portrait` | Portrait (rotated 90°) | `rotate(90deg)`, stage 100vh × 100vw | `SCREEN_ORIENTATION_PORTRAIT` |
| `portrait_flipped` | Portrait (rotated 270°) | `rotate(270deg)`, stage 100vh × 100vw | `SCREEN_ORIENTATION_REVERSE_PORTRAIT` |

`Screen::ORIENTATIONS` is the list; `Screen::shape` folds the four into landscape or portrait for the
channels and the Ad Builder.

## 2. The bug (Roadstar 4, 2026-10-27)

A playlist of one ten-second video on a portrait Fire TV Stick: ten seconds sideways, small, a black bar
on one side, then twenty seconds the right way up, over and over. Pictures and Ad Builder pages were
always right. Three fixes went live that night and were all rolled back (commits `03407bc` … `3f2982b`,
reverted in `ecc5268`): replaying the video in place made it sideways all the time, copying its frames
onto a canvas gave black frames, and `opacity: .999` on the video made it black.

## 3. What the stick showed

Measured on the owner's Fire TV Stick 4K Max (model AFTKA, Fire OS 7.6.9.7 on Android 9, MediaTek
mt8696, Amazon WebView Chromium 132) over adb, `dumpsys SurfaceFlinger` every two seconds beside
`screencap`, with the Android player app paired to a portrait screen on the live site:

1. The video starts on the WebView's **compositor**: the stage's CSS turn applies, the picture is upright
   and fills the glass. Only the app's own window is in the HWC layer table.
2. A few seconds later Chromium promotes the video to a **hardware overlay**: a second HWC layer,
   `SurfaceView - …MainActivity`, Comp Type `Device`, Source Crop `0 0 1080 1920`, **Disp Frame
   `0 0 2160 2160`** — the video element's UN-turned layout rect (1080 wide, 1920 tall) clipped to the
   1920×1080 viewport, in the panel's 3840×2160 physical pixels. No CSS transform reaches that plane:
   sideways, small, a black bar. It stays so until the element is replaced, which is why the cycle
   followed the poll and why replaying in place made it permanent. Portrait (rotated 270°) is the same.
3. **Turning the display at the OS level works on Fire OS**: `adb shell settings put system user_rotation 1`
   made the display 1080×1920, and the HWC turns the overlay plane with it. With the page drawing the
   screen flat (the test screen set to Landscape in the panel, so the page applied no turn and its stage
   was the viewport), the overlay's Disp Frame was the whole panel, `0 0 3840 2160`, and the video was
   upright and full-screen in every moment, overlay and compositor alike. Six screencaps over forty
   seconds, all the same. The setting was put back afterwards (`settings delete system user_rotation`).

So: the plane cannot be turned by the page, and taking the video off the plane shows nothing. The
display has to turn, and only the app can turn it.

## 4. The page's half (done, branch bug-fixing)

`resources/views/player/index.blade.php` and `resources/js/player.js`:

- The portrait quarter turns live inside `@media (orientation: landscape)`: on a viewport taller than
  wide — a display the app has turned — the stage is the viewport and nothing is turned. This alone
  covers both portrait mountings, and is what §3.3 proved on the stick.
- `applyOrientation` (run on every playlist answer, every 30 s, and at load) tells the shell which way
  the set is mounted: `window.SignagePlayer.turnDisplay(orientation)` with one of the four values of
  §1. **An app that answers `true` has turned the display**, and the page sets
  `data-display-turned="true"` on `#stage`, whose rule (`width: 100%; height: 100%; transform: none`,
  after every turn in the stylesheet) turns nothing for any of the four. This is what covers a set
  mounted upside down, which no viewport shape can tell. An app without the method, or one that
  answers `false`, leaves the page turning the stage as it always did, so the page can go live before
  the app and nothing changes until the app ships.
- `window.SignagePlayer` was already the shell's hook for `localUrl` (offline caching, never built).
  A Java object added with `addJavascriptInterface` exposes only its annotated methods, so an app that
  adds `turnDisplay` alone leaves `localUrl` undefined and the item URLs unchanged.
- Tests: `tests/Feature/Signage/PlayerStageTurnTest.php` (the CSS and the script) and
  `tests/Browser/TurnedDisplayTest.php` (the same screen in a 1920×1080 and a 1080×1920 window, then
  with a shell that answers true for each of the four ways, then one that answers false).

## 5. The app's half (to do, in the Android project `com.thedisplaysolution.player`)

1. **A JavaScript interface named `SignagePlayer`** on the player's WebView:

   ```kotlin
   class SignagePlayerBridge(private val activity: Activity) {
       @JavascriptInterface
       fun turnDisplay(orientation: String): Boolean {
           val wanted = when (orientation) {
               "landscape" -> ActivityInfo.SCREEN_ORIENTATION_LANDSCAPE
               "landscape_flipped" -> ActivityInfo.SCREEN_ORIENTATION_REVERSE_LANDSCAPE
               "portrait" -> ActivityInfo.SCREEN_ORIENTATION_PORTRAIT
               "portrait_flipped" -> ActivityInfo.SCREEN_ORIENTATION_REVERSE_PORTRAIT
               else -> return false
           }
           activity.runOnUiThread {
               if (activity.requestedOrientation != wanted) activity.requestedOrientation = wanted
           }
           return true
       }
   }
   // in PlayerWebView / MainActivity, before loadUrl:
   webView.addJavascriptInterface(SignagePlayerBridge(this), "SignagePlayer")
   ```

   It is called from the page's JavaScript thread, hence `runOnUiThread`. Answer `true` whenever the
   value is one of the four, even when nothing had to change: the page asks on every poll.

2. **Keep the WebView alive through the turn.** By default Android recreates the activity when its
   orientation changes, which destroys the WebView and reloads the page (it would still come right, one
   reload per turn, but the watchdog and the pairing state would churn). On `MainActivity` in the
   manifest:

   ```xml
   android:configChanges="orientation|screenSize|screenLayout|smallestScreenSize|keyboardHidden"
   ```

   and no fixed `android:screenOrientation`. The WebView then simply relays out at 1080×1920, the page
   sees the new viewport, and `applyOrientation` runs again on the next poll (or sooner: a `resize`
   needs nothing, the CSS follows the viewport by itself).

3. **If Fire OS ignores `requestedOrientation` on a TV** (it honoured the system setting in §3.3; the
   activity request is the proper API and should be honoured the same way, but it has not been tried):
   plan B is the system setting itself, `Settings.System.putInt(resolver, "user_rotation", 1)` with
   `accelerometer_rotation` 0, which needs `WRITE_SETTINGS` — granted on a TV with
   `adb shell appops set com.thedisplaysolution.player WRITE_SETTINGS allow`, the way
   `scripts/tv/TheDisplaySolution-AutoStart.bat` grants the auto-start permission. Rotation values:
   0 landscape, 1 portrait, 2 landscape flipped, 3 portrait flipped.

4. **A debug build the stick can be watched through:** `WebView.setWebContentsDebuggingEnabled(true)`
   in debug builds only, so `adb forward tcp:9222 localabstract:webview_devtools_remote_<pid>` opens
   Chrome DevTools on the player page (today's release build cannot be inspected at all).

5. **Release:** a higher `versionCode`, uploaded through Developer → TV App for the sticks that got the
   app from the website; the Amazon Appstore copy through Amazon. The sticks keep the same key.

### Android Studio checklist, in order

The release APK on the owner's stick (versionCode 1, "1.0") names these classes: `MainActivity`,
`PlayerWebView`, `PlayerWebViewClient`, `AppUpdater`, `Watchdog`/`WatchdogService`, and a Compose theme
(`ui/theme/...`), so the WebView is most likely created inside an `AndroidView` composable. The page's
address is a constant, `https://sign.thedisplaysolution.com/player`.

1. Find where the WebView is configured (`PlayerWebView`, or the `AndroidView` factory in
   `MainActivity`): where `settings.javaScriptEnabled = true` and `loadUrl(...)` are. Add, before
   `loadUrl`, `addJavascriptInterface(SignagePlayerBridge(activity), "SignagePlayer")`. Imports:
   `android.webkit.JavascriptInterface`, `android.content.pm.ActivityInfo`, `android.app.Activity`.
   The bridge class of step 1 above can live beside `PlayerWebView`.
2. In `AndroidManifest.xml`, on `MainActivity`: add the `android:configChanges` line of step 2, and
   remove any `android:screenOrientation="landscape"` (a TV app often carries one; with it, the request
   is refused).
3. For the test build only, `WebView.setWebContentsDebuggingEnabled(BuildConfig.DEBUG)` once, before the
   WebView is created (step 4 above).
4. Build → Build APK (debug). It is signed with the debug key, so it does NOT install over the release
   copy on the stick: `adb uninstall com.thedisplaysolution.player`, then
   `adb install -r app\build\outputs\apk\debug\app-debug.apk`. The stick then shows a pairing code:
   Screens → Replace Device on the test screen in the panel.
5. Set the test screen to Portrait (rotated 90°), play a video, and watch with
   `scripts\tv\capture-fire-tv.ps1` (§6): the display should go to 1080×1920 by itself within one poll
   (30 s), the overlay's Disp Frame should read `0 0 3840 2160`, and the screencaps should be upright and
   full. Then Portrait (rotated 270°), then Landscape (flipped 180°), then Landscape: each should turn the
   display within a poll, pictures and videos alike.
6. If the display does not turn: `adb logcat | findstr /i "orientation SignagePlayer"` — is `turnDisplay`
   being called at all (log it), and does `requestedOrientation` change? If it is called and nothing
   turns, Fire OS refused the request: go to plan B (step 3 above).
7. Release: `versionCode` 2 (or the next), release key, Generate Signed Bundle / APK → APK, upload on
   Developer → TV App. The page must already be live (§7).

## 6. Seeing it on a real stick (nothing for this goes live without it)

The emulator has no Amazon WebView. The owner's stick: Settings → My Fire TV → Developer options →
ADB debugging on, its IP from About → Network, then from the project folder:

```powershell
.\scripts\tv\capture-fire-tv.ps1 -Ip 192.168.1.71 -Seconds 60
```

It connects (accept "Allow USB debugging" on the TV the first time), installs nothing, and writes a
folder of `dumpsys SurfaceFlinger` every two seconds, a screencap every three, the logcat and a screen
recording. Read the "Display 0 HWC layers" table of the dumps: the app's window is one row; a video
on the overlay is a second row, `SurfaceView - …`, and its **Disp Frame** says where the plane is. With
the fix in, a portrait set shows `0 0 3840 2160` for the overlay and the screencaps are upright and
full; without it, `0 0 2160 2160`.

To try the end state before the app exists: pair the stick to a test screen set to **Landscape** in the
panel (the page then turns nothing), run `adb shell settings put system user_rotation 1` (3 for the
other portrait, 2 for upside down), watch, and put it back with `adb shell settings delete system
user_rotation`. That is exactly what §3.3 did.

## 7. Deploy order

The page first (it changes nothing on today's app), the app second. A set where the app is not yet
updated keeps today's picture, bug and all, until it is.

## 8. Raasta 1: keep the video off the overlay, the page alone (owner's choice, 2026-10-28)

The owner wants no ExoPlayer and no per-TV computer step (no `.bat`, no `appops`), so the first thing
tried is a page-only fix: a CSS rule that keeps Chromium from promoting the video to the hardware
overlay, so it stays on the compositor where the stage's CSS turn applies (§3.1, the first seconds of
every round were upright). If one works, it reaches every set with a deploy and a "Reload player":
no APK, no store, no permission. §5 (the app turns the display) stays the fallback.

**Test as a customer's set is, not as the bridge build is.** The debug app of §5 says "turned" through
`SignagePlayer`, and the page then turns nothing; a customer's app has no bridge. So test with
**`TheDisplaySolution-debug-raasta1.apk`**: Android commit `138e8f7` (the release code, no bridge)
built as a debug APK with `WebView.setWebContentsDebuggingEnabled(BuildConfig.DEBUG)` added, made on
2026-10-28 and left on the owner's Desktop at the office (not committed: the line is the only change).
`adb uninstall com.thedisplaysolution.player`, `adb install -r` it, pair it with Replace Device on the
test screen, set that screen to **Portrait (rotated 90°)**, one 10-second video on its playlist.

**The candidates**, one at a time, each pasted into DevTools (chrome://inspect → the stick → the player
page → Console) and watched for 60 s with `capture-fire-tv.ps1` (§6): the promotion comes a few seconds
in, so the first screenshots prove nothing. A candidate works when the HWC table has **no second row**
(`SurfaceView - …`) for the whole minute AND every screencap is upright, full and not black.

| | Rule on `#stage .media-layer video` | Why it may keep the video off the plane (viz `OverlayCandidateFactory`) | Risk |
|---|---|---|---|
| A | `opacity: 0.999` | `kFailOpacity`: checked first, for every quad | went black on 2026-10-27, but together with the replay in place; tried alone here |
| B | `mix-blend-mode: screen` | `kFailBlending`: only src/src-over may be planes; over the black layer `screen` draws the video unchanged | colours if anything not black is under it |
| C | `filter: saturate(1.0001)` | a filter puts the video in a render pass of its own, drawn by the compositor | some cost per frame |
| D | `clip-path: inset(0 round 1px)` | a rounded mask (`kFailMaskFilterNotSupported` where planes cannot clip round) | the corners lose a pixel |
| E | `transform: rotate(0.01deg)` | `kFailNotAxisAligned` | least likely: Amazon promoted the video although the stage is turned 90°, so its own path may not look at the transform |

```js
// Raasta 1, in the stick's DevTools console. Change RULE to the candidate; "Reload player" (or a page
// reload) takes it away again.
const RULE = 'opacity: 0.999';
let style = document.getElementById('raasta1');
if (!style) { style = document.createElement('style'); style.id = 'raasta1'; document.head.appendChild(style); }
style.textContent = `#stage .media-layer video { ${RULE} }`;
[document.getElementById('stage').dataset.orientation, getComputedStyle(document.querySelector('#stage .media-layer video') || document.body).opacity];
```

Write down for each: the HWC table (one row or two), the screencaps (upright / sideways / black), and
the WebView version (`adb shell dumpsys package com.amazon.webview.chromium | findstr versionName`).
The first candidate that holds for a full minute goes into the player's own CSS on `bug-fixing`, with a
test, and is deployed on the owner's word; then the Roadstar sets "Reload player". If none holds, §5.
