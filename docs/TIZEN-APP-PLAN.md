# A Samsung Tizen TV app for the player: what holds, what must change, what to try on a TV first

Written 2026-11-03, before the Tizen app is started (owner: "ab mujhe Tizen TV app banani hai, is liye ensure karo ke
existing system properly tested ho aur Tizen TV app ke liye ready ho"). Samsung's own pages were read cell by cell; a
point not from Samsung says so. Nothing here was tried on a Samsung TV yet: there is none here.

## 1. Which TVs run which engine

Samsung, Web Engine Specifications, Table 1
(https://developer.samsung.com/smarttv/develop/specifications/web-engine-specifications.html). A 2023 or later set can
move to a newer Tizen by an OS upgrade (a 2024 UN60DU7200 reported Tizen 9.0 / Chromium 120 in October 2026, third party).

| Model year | Tizen | Engine | The player as built today (`build.target` chrome79, since 2026-11-04) |
|---|---|---|---|
| 2026 | 10.0 | Chromium 130 | runs |
| 2025 | 9.0 | Chromium 120 | runs |
| 2024 | 8.0 | Chromium 108 | runs |
| 2023 | 7.0 | Chromium 94 | runs; the first with WebCodecs, in principle |
| 2022 | 6.5 | Chromium 85 | runs |
| 2021 | 6.0 | Chromium 76 | runs: seen in Chromium 76.0.3809.0 itself (2026-11-05), pairing, every kind of item, a break, offline after a restart; every `clamp()` has a plain size before it |
| 2020 | 5.5 | Chromium 69 | not tried: `String.prototype.matchAll` (73) is used once (`pageSubresources`), and every API wants a check against 69 |
| 2019 and older | 5.0 and below | Chromium 63 and below | no: ES modules partly or not at all, `img.decode()` (64), optional catch binding (66) |

The User-Agent carries no `Chrome/` token (`Mozilla/5.0 (SMART-TV; LINUX; Tizen 9.0) AppleWebKit/537.36 (KHTML, like
Gecko) 120.0.6099.5/9.0 TV Safari/537.36`): a Tizen set is told by `Tizen` in it. The player's Fire TV check (`AFT`)
never matches one, so every Tizen set plays `<video>` as a landscape screen does today.

**Decided (owner, 2026-11-05): 2021 (Tizen 6.0) and later.** The app's `config.xml` says `required_version="6.0"`, so the store offers it to no older set. Samsung offers emulators for Tizen 7.0 to 10.0 alone (its `tv_extensions` server, 2026-11-05), so 2021 is checked in a Chromium 76 snapshot (Google's `chromium-browser-snapshots`, Win_x64/664998, 76.0.3809.0) and on a real set when one is at hand. A Chromium snapshot plays no H.264, so its videos there are WebM; a Samsung TV plays MP4 with its own decoder. 2020 would need Anime.js's `globalThis` (Chrome 71) and the player's `matchAll` (73) written out, and every API checked against 69.

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

## 6. Working on it by hand (set up 2026-11-04)

**What is installed.** VS Code's "Tizen Extension" (Samsung's, `tizen.vscode-tizen-csharp`; it does web apps too) keeps the
whole SDK in `%USERPROFILE%\.tizen-extension-platform\server\sdktools`:
- `data\tools`: `sdb.exe` and `tizen-core\tz.exe`.
- `sdk-data`: the emulators, and `profile\profiles.xml`, the signing profiles.

On top of it: the Samsung TV SDK 10.0, both web development and the emulator. That came from Ctrl+Shift+P → "Tizen:
Package Manager", or the same call made for it. The emulator is `T-samsung-10.0-x86_64` (1080p, Tizen 10.0, Chromium 130).
It runs on Windows' Hypervisor Platform (WHPX). Intel HAXM cannot run beside it, and is not needed.

**The step-by-step guide with pictures** is the project's `docs\setup-guide\index.html` (see **The app's project** below).
It covers VS Code, the extension, the TV SDK, the certificates back from the zip, the emulator and Run Project. The backup
zip carries it as `Restore-Guide`.

**Starting the emulator.** Double-click the project's `tools\Start-Samsung-TV-Emulator.bat`. It waits until the TV answers
`sdb`, about a minute.
- **Where it works:** any Windows 10/11 PC where the extension installed the TV SDK and an emulator. It finds the SDK by
  the extension's own `TIZEN_SDK` variable and `sdk.info`, else the default place.
- **Before starting:** it asks the emulator's `check-whpx.exe` whether Windows Hypervisor Platform is on, and says how to
  switch it on if not.
- **A second copy:** it refuses one.
- **Why a file and not the button:** the extension's own Launch button fails on a Windows user folder with a space in it. It
  hands `emulator.cmd` to cmd without quotes: "'C:\Users\Moiez' is not recognized", in the emulator's
  `logs\T-samsung-10.0-x86_64-stderr.log`. Its Stop button answers "Route not found".
- **To close it:** press the X on its window and answer Yes to "If you force stop an emulator…". That is the normal way.

**Certificates.** A Samsung TV, and since TV Extension 7.0.1 the TV emulator too, installs only what a **Samsung**
certificate signed.
- A Tizen certificate is refused with "Invalid certificate chain with certificate in signature" [118, -12]. Tried
  2026-11-04.
- **Making one:** Ctrl+Shift+P → "Tizen: Create Certificate" → Samsung → TV. The author part signs in with the Samsung
  account. The distributor part lists the devices it may install on, by DUID: the emulator's and each real TV's.
- **The profile made:** "The Display Solution", in `%USERPROFILE%\SamsungCertificate\The Display Solution\`.
- **Keep the author certificate and its password for good.** An update in the Samsung store must be signed with the same
  author.
- A distributor certificate cannot take more devices later: a new TV means a new distributor certificate, with the TV's
  DUID beside the emulator's.
- **What the first certificates hold:** made 2026-11-04 with thedisplaysolutions@gmail.com, Public privilege, both valid
  until 2027-11-04. The distributor holds one DUID, this PC's emulator (`XTCJYJZXZBZVK`).
- **Backup:** the project's `tools\Backup-Samsung-Certificate.bat` zips the whole `SamsungCertificate` folder onto the
  Desktop, with the setup guide inside as `Restore-Guide`.
  - It has no password, the owner's choice of 2026-11-04, so keep it private.
  - It uses Windows' own `tar`, which writes standard "/" names; the zips of Windows PowerShell 5.1 write "\".
- **Restoring on another PC:** "Tizen: Create Certificate" → "Import an existing author key file" / "… distributor key
  file", with their passwords. Keep those passwords: whether the folder's `.pwd` files work on another Windows account is
  not known.

**The app's project.** It is its own folder, outside this repository: `D:\TizenStudioProjects\TheDisplaySolutionPlayer`.
Every file of the TV app lives there.
- **How it was made:** `tz new … -t Basic_Empty -T web -p tv-samsung-10.0`.
- **Identity:** the app id is `ynKUOvnETZ.TheDisplaySolutionPlayer`, and the TV shows the name "The Display Solution",
  version 1.0.0.
- **The folders:** `tools\` holds the two helpers above, and `docs\setup-guide\` the guide with its `img\`.
- **What goes to a TV:** `tz pack` puts EVERY file of the folder into the package unless `tizen_web_project.yaml`'s
  `excludes:` names it. A whole 300 KB guide and the `.bat` files went in before `docs/*` and `tools/*` were added
  there; a new folder that is not the app's joins that list.
- **The build list:** `tizen_web_project.yaml` lists the files that go into the package. A new file is added to its
  `files:`.

**Building and running.** With the emulator (or a TV) up: in VS Code, the Tizen icon → Build Project, then Run Project.
Or by hand, in the project's folder:
- `tz build`
- `tz pack` (signs with the active profile)
- `tz install -e emulator-26101`
- `tz run -e emulator-26101`

`sdb devices` lists what is connected. A new web project is `tz new -n Name -t Basic_Empty -T web -p tv-samsung-10.0`
(`-T web`, not `web_app`).

**Seeing inside the app (Web Inspector).**
1. `sdb shell 0 was_kill <package id>`. The next command waits for ever while the app is already running.
2. `sdb shell 0 debug <app id>`. It answers "port: N".
3. `sdb forward tcp:N tcp:N`.
4. In Chrome, go to `chrome://inspect` → Configure → add `localhost:N`, then inspect the app's page. The Samsung TV's shell
   is closed (`intershell_support:disabled`): only `sdb shell 0 …` commands like these work.

**A real Samsung TV.**
1. On the TV: open Apps, type 12345 on the remote, switch Developer mode on, enter this PC's IP address, then restart the
   TV.
2. On the PC: `sdb connect <TV's IP>`, or "Tizen: Device Manager" → add the TV.
3. Make a distributor certificate with its DUID (see above).

## 7. The first version, and what the emulator answered (2026-11-04)

The app's commit `4ebf437` (its own repository) works like this. Its page switches the screensaver off, checks that
the server answers at `/up` (200, no session started), and opens `https://sign.thedisplaysolution.com/player` as the
page itself. With no network it shows "No internet connection. Trying again…" and tries every 10 s. The player then
pairs and plays as on every other set; on the emulator it showed its pairing code.

**Answers that settle §2–§4:**

| Question | Answer on the TV 10.0 emulator |
|---|---|
| The player in a frame of the app's page | Refused before it starts: nginx sends `X-Frame-Options: SAMEORIGIN` on every page, and a page the worker serves says `frame-ancestors 'self'`. Framing would need both changed, and the app's page is `file:///index.html`, an opaque origin, whose third-party storage may not last. |
| The player opened as the app's page | Works: same origin, its own `localStorage` (the `signage.device.*` keys), pairing code shown. |
| Service worker | **None.** `register()` fails with "The Service Worker system has shutdown", whether the player is opened from the app's page or is the app's own `<content>`. `'serviceWorker' in navigator` is still true, so feature detection lies. With no worker there is no offline play, and the warm-up and versioned caches of AD-BUILDER-SPEC §15 do nothing. |
| Storage | `navigator.storage.estimate()` gives a quota of 80 MB, less than a few videos. |
| Screensaver | `webapis.appcommon.setScreenSaver(SCREEN_SAVER_OFF)` answers OK on the app's page. Whether it holds after the page has gone to the player is for a real TV. `webapis` is not there on the player's page. |
| Cache API from a page | `caches.keys()` answers (an empty list), so the Cache API is there without a worker. |

**Still open, for a real Samsung TV:**
- Is a real set's web runtime as closed to service workers as the emulator's?
- Does the screensaver stay off once the player has replaced the app's page?
- One decoder (§4.1), the turned video (§4.2) and auto power off (§4.3).

**If a real set also has no worker, offline play on Samsung needs a player of its own kind.** It would keep the files
itself, through the page's Cache API or Tizen's own file storage (beyond 80 MB), and play them from there, instead of
leaning on a worker. That is a change to the player, not to this app.

## 8. RETURN, EXIT and the exit question (owner, 2026-11-06)

Samsung's Key Policy is mandatory: RETURN must do something on every screen, and EXIT closes the app. A shop's TV
must not close at a stray press, so RETURN asks first, on the player and on the app's own page alike:

- **The question:** "Exit The Display Solution?" over whatever plays, with **Stay** chosen and **Exit** beside it, and
  "This screen stops playing until the app is opened again." LEFT and RIGHT choose, ENTER presses, a pointer remote
  clicks. RETURN again, or 10 seconds with no key, closes it, and the TV goes on where it was. A held key opens it once.
- **Where it lives:** on the player, `resources/js/player/exit-question.js` and its box inside `#stage` (it turns with
  the stage). It is on where the user agent says Tizen, or where the set's `localStorage` says
  `signage.exit_question = '1'` (`'0'` turns it off): how `ExitQuestionTest` tries it in Chrome. Other televisions keep
  their own ways (the Android app's menu holds Back). The app's own page (`main.js`, `index.html`) draws the same
  question for its Starting and No internet screens, and opens the player only once it is answered.
- **How Exit closes the app:** the player's page comes from the server, and the runtime gives such a page **no `tizen`
  and no `webapis`** (TV 10.0 emulator: both `undefined` on `/player`, `history.length` 2). The app's page has them. So
  the player's Exit goes `history.back()`, to `file:///index.html`, and that page, opened by going back (Navigation
  Timing's `back_forward`, or a `pageshow` from the back-forward cache), calls
  `tizen.application.getCurrentApplication().exit()` before anything else.
- **Seen on the TV 10.0 emulator, 2026-11-06,** with a test copy of the app pointing at a throwaway server
  (`storage/app/private/qa/2026-11-06-tizen`): the emulator's own remote sends RETURN as keyCode 10009, "XF86Back",
  and the runtime does nothing with it by itself; the question opens over a picture and over a playing video (which
  plays on under it); RIGHT then OK closed the app to Smart Hub; opened again, the app went straight to the player.
- **Emulator gotcha:** its network leaves a connection to a closed port hanging instead of refusing it, and a hanging
  probe answers much later. A test of "the line comes back while the question is open" is run in Chrome instead.
