# Ad Builder — spec

> A visual builder for 1920×1080 television adverts. The owner asked for "an Elementor-like builder"
> (2026-09-17). This file is what gets built and why; `docs/STORE-ORGANIZATION-SPEC.md` still rules who
> may do it, and `.claude/rules/02-project-conventions.md` still rules how the code is written.

---

## 0. Decisions already taken (owner, 2026-09-17)

| Question | Answer |
| --- | --- |
| Who builds ads | **Both sides.** A store's people build their store's ads; the platform builds for any store and sees them all. |
| How an ad reaches a screen | **It becomes a media row of `type = 'html'`.** The playlist picker, schedule rules, copy-playlist, the device manifest and the cache key then work unchanged. |
| Where the Builder's images and videos live | **Its own shelf** (`builder/{store}/assets/…`), not the store's Media library. |
| Delivery | **Staged**, with the owner looking at each stage before the next. |
| Canvas | **1920×1080, fixed.** No other size, no aspect-ratio change, no responsive breakpoints. Content outside the frame is clipped. Re-confirmed by the owner on 2026-09-19, with the reason: every screen they sell to is Full HD or better — "is se kum ha hi nahi" — and a portrait canvas (1080×1920) was offered and declined, because a portrait television is a landscape panel turned on the wall and the player already turns the picture with it (`#stage[data-orientation]`). |
| Duration | The playlist line carries the seconds, exactly like an image. Animations are independent of it and loop for as long as the ad is on screen. |

---

## 1. What was taken from Elementor (studied live in the editor, 2026-09-17)

The reference was Elementor's **Editor V4** ("Atomic Elements"). What is worth copying:

- **Panel shape.** A left panel with the element's name at the top and **three tabs** — *General* (what it
  is), *Style* (how it looks), *Interactions* (how it moves) — and inside Style a column of **collapsible
  sections**: Layout, Spacing, Size, Position, Typography, Background, Border, Effects.
- **Progressive disclosure.** Typography shows six controls and a **"Show more"** that reveals line
  height, letter spacing, word spacing, decoration, transform, direction, style, stroke. The common
  controls are never buried; the rare ones are never in the way.
- **Layered backgrounds.** Background is a list of **overlay layers** with a `+`; each layer is
  *Image | Gradient | Color* with its own position, repeat, size and attachment. This is exactly the
  multi-layer model the owner asked for — we add per-layer **opacity**, **blend mode**, **video** and
  drag-to-reorder.
- **Interactions.** One interaction is `Trigger → Effect → Type (In/Out) → Direction → Repeat →
  Duration → Delay → Easing`, listed by a readable name ("On page load: Fade In"). Free Elementor
  offers three effects (Fade, Slide, Scale); ours runs on an animation library (Anime.js), so it offers more and — the part Elementor
  has no answer for — **a loop** that keeps running while the ad is on screen.
- **Effects section.** Blend mode, Opacity, Box shadow, Transform, Transitions.
- **Right-click menu with shortcuts.** Duplicate `Ctrl+D`, Copy `Ctrl+C`, Paste `Ctrl+V`, **Paste style**
  `Ctrl+Shift+V`, Reset style, Structure `Ctrl+I`, Delete.
- **Structure panel.** A tree of everything on the page, each row with a visibility toggle — our Layers
  panel, plus lock and rename.
- **Canvas toolbar.** A small floating bar on the selected element (drag handle, add, delete).

What is deliberately **not** copied: the flow layout (sections/columns/flex/grid). A television advert is
a **fixed stage with absolutely positioned elements** — designers think in x/y, not in flexbox — so the
builder is a free canvas with snapping, not a document flow. There are no breakpoints either: one canvas,
one output.

---

## 2. Vocabulary

- **Ad** — one design (`builder_ads` row). Has a name, a **document**, a poster and, once published, a
  media row.
- **Document** — the JSON the editor reads and writes: stage background layers + an ordered list of
  elements. The source of truth for editing.
- **Element** — one thing on the stage: `text`, `image`, `video`, `shape`.
- **Stage** — the 1920×1080 frame itself, with its own background layer stack.
- **Asset** — an image or video uploaded for use inside ads (`builder_assets`), on the Builder's own shelf.
- **Publish** — compile the document to a self-contained HTML file and write/refresh the `media` row that
  playlists use.
- **Draft / Published / Changes not published** — the industry's draft/publish model (owner, 2026-09-21:
  "publish wala kaam jo industry standard k hisab se best" — Xibo, Contentful, Strapi all work this way, §9).
  **Draft**: on no screen — never published, or taken off with **Unpublish**. **Published**: the screens show
  exactly this design. **Changes not published** (Contentful's "Changed", Strapi's "Modified"): saved changes
  the screens do not show yet — they keep the published version until **Publish changes**, and **Discard
  changes** goes back to it.

---

## 3. Data model

```
builder_ads
  id, store_id → stores (cascade), name,
  document        json          -- the editable design
  thumbnail_path  string|null   -- poster captured in the browser at save
  media_id        → media|null  -- the published copy a playlist points at (nullOnDelete)
  published_at    datetime|null -- null = draft (never published, or unpublished) — on no screen (§9)
  published_document json|null  -- the design as last published: changes are measured against it, and
  published_name  string|null   --   Discard changes goes back to it (NULL for an ad published before 2026-09-21
                                --   and changed since — kept again at its next Publish)
  in_playlists    bool (false)  -- may a shop's own playlist play it, or is it for channels only (§9)?
  created_by, updated_by → users (nullOnDelete)
  timestamps, index (store_id, updated_at)

builder_assets
  id, store_id → stores (cascade), kind ('image'|'video'), title,
  disk, path, thumbnail_path|null, mime_type, size,
  width|null, height|null, duration_seconds|null,
  created_by → users (nullOnDelete), timestamps, index (store_id, kind)

builder_fonts                     -- stage 2
  id, family (unique), slug (unique), kind ('sans'|'serif'|'display'|'mono'|'urdu'),
  weights json, files json, css_path, size,
  installed_by → users (nullOnDelete), timestamps
```

Files (the `public` disk, like media and channels):

```
builder/{store_id}/assets/{random}.{ext}          the asset
builder/{store_id}/assets/thumbs/{random}.jpg     its thumbnail
builder/{store_id}/ads/{ad_id}/index.html         the published ad
builder/{store_id}/ads/{ad_id}/poster.jpg         the ad's poster
builder/{store_id}/ads/{ad_id}/published.jpg      the published media row's own copy of that poster
fonts/{slug}/{weight}-{style}-{n}.woff2           self-hosted Google fonts (one file per weight, style and subset)
fonts/{slug}/font.css                             the family's own stylesheet, pointing at those files
```

**Deleting.** A store's deletion purges its ads, its assets and their files — `Store::purgeBuilder()`,
called from `purgeContents()` alongside `purgeMedia()`/`purgeChannels()`, rows inside the transaction and
files after commit (the rule in `02-project-conventions.md`). Deleting an ad deletes its published media
row (so it leaves every playlist it was on), its HTML, the row's poster copy and its own poster. Deleting that
media row from the library instead takes only the page and the row's copy, never the design's `poster.jpg`
(`MediaController::destroy` also keeps a thumbnail that a design still names — a row published before rows had
a copy of their own). Deleting an asset is refused while an ad still uses it, and says which ads (the same
shape as a role that somebody still holds).

---

## 4. Permissions

Four new rows in `Permission::STORE` — a store's own work, and on a platform role they reach every store:

| Name | Label |
| --- | --- |
| `ad-view` | View Ads |
| `ad-store` | Create Ads |
| `ad-update` | Update Ads |
| `ad-destroy` | Delete Ads |

A migration of its own inserts them, grants all four to Super-Admin, and grants them to the Owner and
Admin starter roles by key. Assets have no separate permission: uploading one is part of creating an ad
(`ad-store`), removing one part of `ad-destroy` — a permission has to be enough for its own job.

---

## 5. Routes

Inside the `['auth', 'throttle:admin']` group, sidebar item **Ad Builder** (gated `@can('ad-view')`):

| Method | URI | Name | Gate |
| --- | --- | --- | --- |
| GET | `/builder` | `builder.index` | `ad-view` |
| GET | `/builder/create` | `builder.create` | `ad-store` |
| GET | `/builder/{ad}` | `builder.edit` | `ad-update` |
| GET | `/builder/assets` | `builder.assets` | `ad-view` |
| GET | `/builder/data` | `builder.data` | `ad-view` |
| POST | `/builder` | `builder.store` | `ad-store` |
| PUT | `/builder/{ad}` | `builder.update` | `ad-update` |
| POST | `/builder/{ad}/duplicate` | `builder.duplicate` | `ad-store` |
| POST | `/builder/{ad}/publish` | `builder.publish` | `ad-update` |
| POST | `/builder/{ad}/unpublish` | `builder.unpublish` | `ad-update` |
| POST | `/builder/{ad}/discard` | `builder.discard` | `ad-update` |
| POST | `/builder/{ad}/in-playlists` | `builder.in-playlists` | `ad-update` |
| GET | `/builder/{ad}/preview` | `builder.preview` | `ad-view` or `ad-update` (in the controller) |
| DELETE | `/builder/{ad}` | `builder.destroy` | `ad-destroy` (password) |
| GET | `/builder/assets/data` | `builder.assets.data` | `ad-view` |
| POST | `/builder/assets` | `builder.assets.store` | `ad-store` |
| DELETE | `/builder/assets/{asset}` | `builder.assets.destroy` | `ad-destroy` |
| GET | `/builder/fonts` | `builder.fonts` | `ad-view`, `ad-store` or `ad-update` (in the controller) |
| POST | `/builder/fonts` | `builder.fonts.store` | `ad-store` or `ad-update` (in the controller) + `throttle:font-install` |

The three "in the controller" routes carry no `can:` — a route's `can:` cannot say "any of" — and their
controllers answer 403 without one of those permissions: the editor opens with Create Ads or Update Ads, and
neither needs View Ads.

`BuilderAd::visibleTo($user)` / `BuilderAsset::visibleTo($user)`: above the stores, every store's (each row
saying whose it is); inside a store, that store's own and nothing else (none at all with no store chosen) —
anything else is a 404: on top of the permission check, every controller action looks its target up again
through that scope (`visibleTo(…)->findOrFail()`), not through `ResolvesCurrentStore`. Deleting an ad is a
**big delete** (the password, `ConfirmsPassword`); everything else is one confirmation.

---

## 6. The document

```jsonc
{
  "version": 1,
  "stage": {
    "width": 1920, "height": 1080,
    "background": {
      "color": "#0b1220",
      "layers": [                       // a LIST, painted bottom-up: index 0 is furthest back
        { "id": "bg1", "type": "gradient", "visible": true, "opacity": 1, "blend": "normal",
          "gradient": { "kind": "linear", "angle": 165, "stops": [{ "color": "#0b1d3a", "at": 0 }, { "color": "#5fa8e8", "at": 100 }] } },
        { "id": "bg2", "type": "image", "assetId": 12, "size": "custom", "scale": 12, "position": "left top",
          "repeat": "repeat", "opacity": 0.25, "blend": "screen", "visible": true },
        { "id": "bg3", "type": "color", "color": "#1e3a8a", "opacity": 0.6, "blend": "multiply", "visible": true },
        { "id": "bg4", "type": "video", "assetId": 31, "fit": "cover", "opacity": 1, "blend": "normal", "visible": true }
      ]
    }
  },
  "elements": [                         // a LIST; `z` is the paint order, renumbered 0…n-1 on every change
    {
      "id": "el_a1", "type": "text", "name": "Headline",
      "x": 160, "y": 220, "w": 1100, "h": 180, "rotation": 0, "opacity": 1, "z": 2,
      "locked": false, "visible": true,
      "text": "Winter sale",
      "style": { "fontFamily": "Poppins", "fontWeight": 700, "fontSize": 96, "color": "#ffffff", "align": "left",
                 "lineHeight": 1.1, "letterSpacing": 0, "…": "the rest of §7a, plus border and blend" },
      "animations": {
        "in":   { "effect": "fade", "direction": "up", "distance": 80, "scale": 0.6, "degrees": -90, "blur": 20,
                  "duration": 0.8, "delay": 0.2, "ease": "power2.out" },
        "loop": { "effect": "float", "axis": "y", "amount": 10, "amountX": 0, "amountY": 0, "duration": 2.4,
                  "delay": 0, "yoyo": true, "ease": "cubic(0.25,0.1,0.6,1.3)" },   // a curve from the Ease Visualizer
        "out":  { "effect": "zoom", "…": "the entrance's keys", "at": 12 }            // at 12 s from the start
      }
    },
    { "id": "el_b2", "type": "image", "assetId": 14, "…": "the box keys",
      "style": { "fit": "cover", "position": "center center", "radius": 16, "flipX": false, "flipY": false,
                 "border": { "width": 6, "style": "solid", "color": "#ffffff" },
                 "shadow": { "x": 0, "y": 30, "blur": 60, "spread": 0, "color": "rgba(0,0,0,0.45)" },
                 "filters": { "brightness": 110, "saturate": 120 }, "blend": "normal" } },
    { "id": "el_c3", "type": "shape", "…": "the box keys",
      "style": { "shape": "ellipse", "fill": "#2563eb", "gradient": null, "radius": 16, "border": null, "shadow": null } }
  ]
}
```

Rules the compiler and the editor both hold to: ids are opaque strings; `z` is the paint order and is
normalised on every reorder; coordinates are **stage pixels** (not percentages) so what is designed is
what a 1920×1080 television shows; anything outside the frame is clipped by the stage's `overflow:hidden`.

**Every number has one table.** `AdCompiler::LIMITS` (the box, type, frames, shadows, filters, gradients,
layers) and `AdAnimations::NUMBERS` (each slot's numbers, with their defaults) are what the compiler clamps
to, what `BuilderAdRequest` builds its rules from, and what the editor is handed to hold its inputs inside —
so the three can never disagree. **Every key is named in the rules**, because what is saved is
`validated()`, and Laravel drops from it any key of a checked array that has no rule of its own
(`AdDocumentRulesTest` saves a document with every key the editor writes and expects all of them back).

---

## 7. The editor

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ ← Back   Ad name           [Undo][Redo]   [zoom −][fit][+]   [Preview][Save]  │
├───────────────┬──────────────────────────────────────────┬───────────────────┤
│ ADD           │                                          │ LAYERS            │
│ ▸ Text        │        ┌────────────────────────┐        │ ▸ Headline        │
│ ▸ Image       │        │   1920 × 1080 stage    │        │ ▸ Product shot    │
│ ▸ Video       │        │   (scaled to fit)      │        │ ▸ Background      │
│ ▸ Shape       │        └────────────────────────┘        │                   │
│ ─────────────  │                                          │ (drag to reorder, │
│ SELECTED       │   rulers · guides · snapping             │  eye = hide,      │
│ General/Style/ │                                          │  lock, rename)    │
│ Animation      │                                          │                   │
└───────────────┴──────────────────────────────────────────┴───────────────────┘
```

- **Stage.** Scaled to fit the viewport (zoom 10–400 %, "fit" resets), centred on a grey field so the frame
  edge is obvious, and pannable. Rulers along two edges, guides dragged out of them, and snapping to the
  stage's edges and centre lines, the guides and other elements' edges (an 8 px threshold, with a visible
  line).
- **Selection.** Click to select, `Shift`-click to add, marquee-drag on empty stage to select many, `Ctrl+A`
  for all, `Esc` to clear. Eight resize handles plus a rotate handle (one element at a time); `Shift` keeps
  the ratio, `Alt` resizes from the centre; arrows nudge 1 px, `Shift+arrows` 10 px — the whole selection.
- **Panel.** When nothing is selected (or **Background** is clicked at the foot of the Layers panel): the
  **Stage** panel — the stage's colour and its layers, front first, each with show/hide, ▲▼ to reorder,
  duplicate and delete, and below the list the selected layer's own controls (opacity, blend, and its
  colour, gradient editor, or picture/video with size, scale, repeat and a nine-point position). When
  something is selected: its name, then two tabs — *Style* (Size & Position with x/y/w/h/rotation/opacity
  and blend, then Typography with Show more, or Shape, or Picture: replace, fit, focus point, corners,
  mirror, frame, shadow and eight filters) and *Animation* (below).
- **Layers panel.** The list, front on top, drag to reorder, eye to hide, padlock to lock, double-click
  to rename, right-click for the same menu as the canvas.
- **Right-click / shortcuts.** Cut `Ctrl+X`, Copy `Ctrl+C`, Paste `Ctrl+V`, Paste style `Ctrl+Shift+V`,
  Paste animation `Ctrl+Alt+V`, Duplicate `Ctrl+D`, Delete, Bring forward / backward `Ctrl+]` / `Ctrl+[`, to
  front / back with `Shift`, Lock `Ctrl+L`, Undo `Ctrl+Z`, Redo `Ctrl+Y`/`Ctrl+Shift+Z`, Save `Ctrl+S`, Play
  `Ctrl+P`, Zoom `Ctrl+0/=/−` and `Shift+0/1`, align `Alt+A/H/D/W/V/S`, rulers `Shift+R` — all listed under `?`.
- **Undo/redo.** A history of document snapshots (50 deep) with a History list beside the buttons, like
  Elementor's; every mutation goes through one `commit(label)` so nothing can skip it, and a save does not
  clear it.
- **Autosave.** A saved ad with changes is saved every 30 s (a switch in the bar turns it off); leaving with
  unsaved work asks first.

---

## 7a. Fonts (stage 2, built)

- **`config/fonts.php` is the list.** 51 Google families grouped by kind (sans · display · serif · mono ·
  urdu) with the weights worth fetching, plus eight system faces that need no download. Only a family on
  that list can be installed, so a font name typed by hand can never make the server fetch an arbitrary URL.
- **Installing is one request, once, for the whole installation.** `GoogleFontInstaller` asks Google for the
  family's stylesheet (with a browser user-agent, or Google answers in TTF), downloads **every** `@font-face`
  it names — one per weight per subset, all of them, because dropping the subsets is how an Urdu advert
  loses exactly the characters it needed — writes them under `fonts/{slug}/`, and saves a stylesheet of our
  own beside them pointing at the local copies. `builder_fonts` records what is on disk, so the row's
  existence is the answer to "can a television render this with no internet?".
- **The editor loads the family the moment it is picked** (a `<link>` added to the panel's own page), so the
  stage shows the real face rather than a promise.
- **A published advert carries its fonts INSIDE the page** (`AdFontEmbedder`, 2026-09-19), as `data:` URLs —
  never links. The television plays the page in a sandboxed frame and the draft preview is sandboxed too:
  both have an opaque origin, a browser fetches a font with CORS, and so every font file on our own server
  was refused ("blocked by CORS policy", checked in real Chrome) and the advert fell back to a system face
  on every screen. A `data:` URL is never a CORS request, and needs nothing from any web server. Only what
  the design can show travels: per family, the installed weight a browser would pick for each weight asked
  for (CSS font matching), and only the subsets whose `unicode-range` covers a character the design writes
  (both cases, for `text-transform`). A variable font — one file for every weight, like Noto Nastaliq
  Urdu's 233 KB — is carried once for the range (`font-weight: 400 700`), which halved the Urdu example's
  page. Only a file the family's own row names is ever read. A family nobody installed is simply a name
  the device resolves — the page never reaches out to Google.
- A font is not store content: it belongs to the installation, like a colour. What is store-scoped is the
  design that names it.

---

## 8. Animations (Anime.js)

**The library is Anime.js 4 (MIT)** — owner's decision, 2026-09-19. It replaced GSAP, whose licence forbids
"no-code visual animation builders" that compete with Webflow — exactly what this editor is. Nothing a
design stores changed: the ease names keep GSAP's familiar spelling (`power2.out`), and the runtime maps
each onto the identical Anime.js curve (power1…4 are its Quad…Quint; back, elastic and bounce take the same
defaults). The four published examples were republished onto it the same day.

Three slots per element, each optional: **in** (once, when the ad appears), **loop** (for ever, while it
is on screen), **out** (once, at a moment counted from when the ad appeared — it stops the loop).

| Slot | Effects (stage 4, built) |
| --- | --- |
| in / out | fade · slide (up/down/left/right + distance) · zoom (from/to a size) · rotate (degrees) · blur (px) · flip (3-D, by direction) · wipe (a clip-path reveal) · bounce (a slide on a bounce ease) |
| loop | float (axis, distance px) · pulse (grow %) · sway (tilt °) · drift (x and y px) · spin (clockwise or not, seconds per turn) · blink (faintest %) · shake (px) · Ken Burns (zoom % and pan, the picture zooming inside its own frame) |

Every slot has **duration** and an **ease**; an entrance has a **delay**, an exit its **start time**, a loop
its **wait first** and **back and forth** (yoyo). The owner's example — "fade in, then float up and down
about 10 px, for ever" — is `in: fade` + `loop: float, axis y, amount 10, duration 2.4, ease sine.inOut`,
and it is literally the coffee cup in the Fresh Coffee example.

**Easing** is a dropdown of eases (`none`, `power1…4`, `sine`, `expo`, `circ`, `back`, `elastic`,
`bounce` × `in/out/inOut`, grouped) plus **Custom curve…**. Under it, the **Ease Visualizer**: the curve
drawn from the runtime's own `AdRuntime.ease()` — the function a television eases with — (time across,
progress up, room above and below for the eases that overshoot), ▶ to run a dot along it and along a
track at the ease's pace, and — for a custom curve — two pink handles to drag, which write
`cubic(x1,y1,x2,y2)`; the runtime turns that into Anime.js's `cubicBezier()`. **Preview this element**
plays one element's slots, and every change to them replays it;
**▶ Play** in the top bar plays the whole ad with the television's own runtime, the stage locked until Stop,
Esc or a click.

Everything is data: the compiled page carries each element's slots as JSON the runtime **parses**, rebuilt
by `AdAnimations` from its lists and tables first. Nothing depends on how many seconds the playlist gives
the ad — `in` runs once at load, `loop` repeats on its own clock until the item ends.

Not built (said rather than half-built): typewriter, mask reveal and marquee.

---

## 9. Output and playback

**Publish** compiles the document into one self-contained document:

```html
<!doctype html><html><head><meta charset="utf-8">
  <style>@font-face{…src:url(data:font/woff2;base64,…)…}</style>        <!-- embedded, §7a -->
  <style>/* reset + stage; .ad-pending hides an element until its entrance takes it over */</style>
</head><body>
  <div class="ad-stage" id="ad-stage">
    <div class="ad-bg" style="background-color:…">…one .ad-layer per visible layer…</div>
    <div class="ad-el ad-pending" data-anim-id="el_a1" style="left…;top…;z-index…">   <!-- the box -->
      <div class="ad-anim">                                                        <!-- what moves -->
        <div class="ad-content ad-text" dir="auto" style="…">Winter sale</div>     <!-- what shows -->
      </div>
    </div>
  </div>
  <script>/* scale the 1920×1080 stage to the screen */</script>
  <script type="application/json" id="ad-animations">{…every value rebuilt by AdAnimations…}</script>
  <script src="/ad-runtime/anime.min.js?v=…"></script>
  <script src="/ad-runtime/runtime.js?v=…"></script>
  <script>/* JSON.parse the block, AdRuntime.run(); whatever happens, reveal everything */</script>
</body></html>
```

- **Three layers per element, on purpose.** The box says where it is; `.ad-anim` is what an animation moves;
  the content keeps its own styles. A blur-in therefore never wipes a picture's filters, and a spin never
  fights its rotation. The editor's stage has the same three layers, so its preview is the same motion.
- **The motion files live at a fixed address, `public/ad-runtime/`** — never under a route's name (see §11) —
  copied from npm's `animejs` package (its official MIT bundle, licence header and all) by
  `scripts/copy-builder-vendor.mjs` on every `npm run build`, each addressed with a fingerprint of its
  contents so no screen keeps a stale copy. A still ad loads none of it.

- The stage is `1920×1080` and the page scales it with a CSS transform to whatever the screen is, so a
  1920×1080 television is pixel-exact and anything else is letterboxed rather than reflowed.
- The file is written to `builder/{store}/ads/{ad}/index.html`; the ad's **media row** (`type = 'html'`,
  `mime_type = 'text/html'`, `path` = that file) is created or refreshed. Its `thumbnail_path` names a copy of
  its own, `builder/{store}/ads/{ad}/published.jpg`, which every publish rewrites from the design's poster
  (`AdPublisher::posterFor`); when the design has no poster the copy is removed and `thumbnail_path` is NULL.
  A copy rather than the design's file, because deleting the row from the Media library takes the files it
  names, and the design must keep its poster.
- The **player** (`resources/js/player.js`) gains one branch: an item of type `html` is shown in a
  sandboxed `<iframe>` sized to the screen, built in the hidden layer when its turn comes and shown once it
  has loaded (the previous item stays on screen meanwhile), and torn down when the next item replaces it.
  It is **not** pre-warmed: `preloadNext()` warms only a plain picture, and skips videos and ad pages, which
  fetch their own pieces.
- `DeviceController::playlist` needs no new shape: `{type: 'html', url, checksum}` already fits, and the
  checksum changes whenever the file is republished, so a device refetches it.
- Schedules, copy-playlist, the store wall and deletion all work because the ad *is* a media row.
- **Draft and publish, the industry's way** (owner, 2026-09-21: "publish wala kaam jo industry standard k
  hisab se best" — researched against Xibo's layouts, Contentful's "Changed" and Strapi 5's "Modified"; it
  replaced, the same day, a first rule that took a changed ad off the screens):
  - **A save changes the draft only.** `BuilderController::update` saves the design — by hand or by autosave —
    and the screens keep playing the published page until **Publish changes**; nobody sees the changes before
    that. The ad reads **Changes not published** (`BuilderAd::hasUnpublishedChanges`, against the version
    Publish kept in `published_document`/`published_name`). A save that changes nothing — compared as content,
    `BuilderAd::wouldChangeWith`: keys in any order, 1 = 1.0, an empty setting = a missing one — changes nothing,
    not even the history; a poster is not a change (it is written without touching `updated_at`).
  - **Discard changes** (`POST /builder/{ad}/discard`, `AdPublisher::discardChanges`) puts the design, its name
    and its poster back as they were published. Refused (422) for an ad not published, one up to date, and one
    published before versions were kept and changed since (no version to go back to — its next Publish keeps one).
  - **Unpublish** (`POST /builder/{ad}/unpublish`, `AdPublisher::unpublish`) clears `published_at`: from then on
    the page is **nobody's** — no screen plays it (`Media::isPlayableNow`, as a line, as a channel's ad
    (`ChannelAd::statusOn` 'draft') or as a holding picture) and no picker or library lists it
    (`Media::scopeWithoutDrafts`). Nothing is deleted: every playlist line and channel ad holding it keeps its
    place — the panel draws the line faded, "Draft — not playing until it is published in the Ad Builder", the
    channel's ad reads "Draft · not playing" — and the next Publish, which refreshes the same row, brings it back
    everywhere at once. One confirmation, no password: it deletes nothing.
  - **Where an ad may play — "Show in playlists"** (`builder_ads.in_playlists`, owner's rule, 2026-09-22: "agar
    woh same ad channel mein hui aur playlist mein toh masla hoga"). An ad inside a channel AND on the playlist
    that carries that channel plays twice in one pass, so **an ad is for channels only until it is ticked**: a
    new ad starts unticked, and publishing does not change that (every ad published before the tick existed was
    ticked by its migration — nothing came off a television). Ticked, it joins the shop's own pickers — the
    playlist's (`PlaylistController::availableMedia`) and the holding picture's (`ScreenController::mediaOptions`),
    both through `Media::scopeWithoutChannelOnly` — and the walls behind them let it through
    (`PlaylistController::assertAdsMayBeOnAPlaylist`, `ScreenController::assertMayPlayByItself`; 422 naming the ad
    otherwise). A channel's pickers never narrow: every published ad is offered there, ticked or not. The tick
    itself is `POST /builder/{ad}/in-playlists` (Update Ads), **refused while a screen still carries the ad**,
    naming the screens (`Media::stillOnScreensMessage`) — nothing is pulled off a television behind somebody's
    back — and it is not a change to the design (written without touching `updated_at`, like a poster). It lives
    in the editor's Publish ▾ menu; the listing shows it beside the status badge as **Playlists** or **Channels
    only** (a draft shows neither). `builder:examples` ticks the examples it publishes: they exist to be played.
  - **The editor's bar** says where the ad stands — "Published", "Changes not published", "Draft · not on screens"
    (the whole sentence in its tooltip) — and the Publish button says what it will do: Publish, **Publish
    changes**, Publish again. Its ▾ holds Discard changes and Unpublish, each confirmed. The listing's badge
    reads the same three states (`BuilderAd::status`: draft, changed, published).
  - **On a laptop the bar keeps every control** (below 1280 px): Rulers and Keyboard shortcuts move into "More"
    (⋯), Play and Preview keep only their symbols (the tooltip and the screen-reader name stay), the name box
    narrows, and the status lines shorten with an ellipsis rather than draw over a button — Save and Publish
    always keep their words (browser-tested at 1100 px: no control overlaps another).

---

## 10. Stages (each one is finished, tested and shown before the next starts)

| Stage | What lands |
| --- | --- |
| **1a — the canvas** ✅ *(built 2026-09-17)* | `builder_ads` + `builder_assets`, the four `ad-*` permissions, the Builder section with its three tabs, the editor (top bar, Add panel, 1920×1080 stage with zoom and snapping guides, Layers panel, properties panel), Text · Image · Video · Shape, drag / eight resize handles / rotate, z-order, lock, hide, rename, undo/redo with coalescing, the keyboard set, save/load, the Ads gallery with duplicate and password-protected delete, and the Assets shelf with upload and "used by". |
| **1b — on the television** ✅ *(built 2026-09-17)* | `AdCompiler` (design → one self-contained page, every value read through a reader that can only produce something safe), Publish → a `media` row of `type = html` refreshed in place so playlists keep it, the player's `html` branch (a sandboxed frame), and the whole path proven end to end in a browser: publish → picker → playlist → a television playing the ad. |
| **2 — words** ✅ *(built 2026-09-17)* | The full typography set in the panel with Elementor's **Show more** (font, size, weight, colour, alignment buttons · line height, letter and word spacing, vertical anchor, case, decoration, italic, padding, a coloured panel with its radius, a text shadow and an outline), `builder_fonts` + `config/fonts.php` (51 curated Google families in five kinds, plus the system faces), and an installer that **downloads a family once and serves it from this server for good** — every subset kept, so an Urdu or Arabic advert keeps its own face, and a shop's television never asks fonts.googleapis.com for anything. |
| **3 — pictures** ✅ *(built 2026-09-18)* | The Stage panel: the stage colour and up to 12 background layers — colour, linear/radial gradient (2–6 stops), picture (cover/contain/actual size or a scale %, tile across/down, nine-point position) and video (cover/contain) — each with opacity, 16 blend modes, show/hide, reorder and duplicate. Pictures and videos: replace, five fits, a focus point, corners, mirror, a frame, a drop shadow and eight filters. Shapes: rectangle or ellipse, one colour or a gradient, frame and shadow. A blend mode on any element. The editor's stage draws all of it with the compiler's own rules (`styles.js`, `background.js`). |
| **4 — motion** ✅ *(built 2026-09-18)* | An animation library (GSAP 3.15 at first; Anime.js 4.5, MIT, since 2026-09-19 — §8), the shared runtime, and the Animation tab: in/loop/out with every effect in §8, the Ease Visualizer with draggable custom curves, per-element preview and ▶ Play. Plus `php artisan builder:examples {store}`: four finished ads — Winter Sale, Fresh Coffee, Grand Opening and an Urdu Burger Deal — with their own drawn pictures, stored exactly as the editor would store them. |
| **5 — the polish** ✅ *(built 2026-09-18)* | Everything in §10a: many selected and moved as one, marquee, align and distribute, the clipboard with paste style and paste animation, the right-click menu, bring to front / send to back, the Layers panel's drag and rename, rulers and guides with snapping, zoom and pan, the History panel, autosave, the shortcuts list, the empty-stage hint, posters taken in the browser and re-drawn by the server, the sandboxed draft preview, and the platform's shop filter — with the full test sweep, adversarial suite included. |

### 10a. Stage 5 in detail (planned 2026-09-18, the owner: "sare stages kardo")

**Selecting many.** `selectedIds` replaces the single id (`selected` stays: the one element when exactly
one is chosen). Shift/Ctrl-click on the stage or in the Layers panel adds or removes; dragging on empty
stage draws a marquee that selects what it touches (Shift keeps the old selection); Ctrl+A selects every
visible, unlocked element. A drag, an arrow nudge, Delete, Duplicate, Copy/Cut and the order commands act on
the whole selection. Resize and rotate stay one element at a time. With several chosen, the panel shows
their count, Align, Distribute, order, one opacity for all, Duplicate and Delete.

**Align & distribute.** Left · centre · right · top · middle · bottom — to the stage for one element, to
the selection's bounds for several (Alt+A/H/D/W/V/S). Distribute across or down spaces three or more evenly,
the outermost two staying put.

**Clipboard (Elementor's model).** Ctrl+C copies the selection, Ctrl+X cuts it, Ctrl+V pastes copies
(new ids, 32 px further each time, on top, selected). Ctrl+Shift+V pastes only the copied element's STYLE
onto the selection — the keys that make sense for each kind (text ← text, picture ← picture or video, shape
← shape; frame, shadow, corners and blend between any two). Ctrl+Alt+V pastes its animations. The clipboard
lives in `localStorage`, so it carries from one ad to another; an element whose picture is not on this
shop's shelf is left out, and the person is told.

**Order.** Bring to front / send to back (Ctrl+Shift+] / Ctrl+Shift+[) beside the one-step commands.

**Right-click menu** on an element, a layer or the empty stage: Cut, Copy, Paste, Paste style, Paste
animation, Duplicate, Delete, the four order commands, Lock, Hide, Select all.

**Layers panel.** Drag a row to reorder; double-click a name to rename it where it is.

**Rulers & guides.** Rulers along the top and the left of the stage in stage pixels, drawn inside the zoomed
frame so they line up at any zoom. Dragging out of a ruler makes a guide; a guide is dragged to move it and
back onto its ruler to remove it; elements snap to guides as they do to edges. Guides belong to the design:
`document.guides = {x: [...], y: [...]}` (at most 50 each, stage pixels), named in the rules like every other
key, ignored by the compiler. Shift+R shows or hides rulers and guides.

**Moving around.** Ctrl+0 or Shift+1 fit, Shift+0 100 % (Ctrl+1 is deliberately left unbound — it is the
browser's own first tab), Ctrl+= / Ctrl+− zoom, Ctrl+wheel zoom, the wheel pans, and Space+drag pans. The
frame is a wrapper around the stage, so selection handles are never clipped at the stage's edge while the
stage itself still clips like a television.

**History panel.** Every step keeps its label; a list beside undo/redo shows them, newest first, and a click
goes back (or forward) to that step.

**Autosave.** Every 30 s, a saved ad with changes is saved again — never mid-gesture or while typing — and
the bar says when. A person can switch it off (remembered in the browser). Leaving with unsaved work still
asks first.

**Posters.** Save and Publish photograph the stage in the browser (`modern-screenshot`, MIT) as a 640×360
JPEG; the server keeps it only if GD can read it as a picture no larger than 4096 × 4096, and writes GD's own
re-encoding — never the bytes it was sent (`MediaStorage::storePoster`, which the media library's video
posters use too). The listing, the media library and the playlist picker show it; its address carries a
version so no cache shows an old one. A copy gets its own poster file rather than sharing its original's.

**Draft preview.** `GET /builder/{ad}/preview` (`ad-view` or `ad-update`, checked in the controller; the
store wall) answers the page the compiler would publish, from the SAVED design, full screen in a new tab —
with `Content-Security-Policy: sandbox allow-scripts` (it runs in an opaque origin, like the player's frame)
and `Cache-Control: no-store, private`. Nothing is written.

**Platform listing.** Above the stores, the Ads and Assets tabs gain a shop filter (`?store_id=`); inside a
store it is not offered, and sending it cannot widen what `visibleTo` allows. On the Assets tab the same Shop
list says where a platform upload goes: the page sends it as `store_id`, and with no shop chosen, or one that
does not exist, the upload answers 422 on `file` ("Choose the shop in the Shop list first…"). A store's person
always uploads to the store they work in; a `store_id` they send is not read.

**Keyboard help & empty stage.** `?` (or the ⌨ button) lists every shortcut. An empty stage says where to
start; the hint is drawn outside the stage, so no poster or published page ever carries it.

**Tests.** Pest for the guides' rules, posters (real, re-encoded; lies refused), the preview (sandboxed, the
wall, nothing written) and the filter; attacks for each; Dusk for every editor gesture above and every
button of the panels; then both full suites, and the ads played on a real player screen in the browser —
`AdExamplesOnTelevisionTest` pairs a television, puts the four examples on its playlist and reads each
headline from inside the sandboxed frame once its animation has brought it in.

**Not in scope** (say so rather than half-build it): no flow layout, no responsive breakpoints, no custom
CSS box, no third-party embeds, no per-ad duration, no animation timeline scrubber (the ad is not a video).

---

## 11. Notes from building it

- **`structuredClone` throws on an Alpine proxy.** Undo kept a snapshot of the document on every change and
  every one of those calls threw, silently, so undo had nothing to go back to and the button did nothing.
  `clone()` falls back to a JSON round trip (the document is plain data, so it is exact). A browser test
  caught it; no server test ever could have.
- **`transform: scale()` does not change layout size.** The stage is 1920 px wide inside a narrower column,
  so the flexbox squeezed it first and scaled the squeezed box — the frame came out portrait. `shrink-0`.
- **Selection happens on `pointerdown`, not `click`**, so that a click and a drag are one gesture. A
  synthetic `.click()` therefore selects nothing: a browser test drives the Layers panel instead.
- **A file input inside a `hidden` wrapper is out of reach** of the keyboard and of WebDriver. `sr-only`.
- **A poster of a stage full of Alpine attributes came out blank.** `modern-screenshot` copies the stage
  into an SVG, which is XML, and `x-bind:style`, `:key` and `@pointerdown.self` are not attribute names XML
  allows (its own clean-up keeps anything with a colon). The SVG failed to parse, nothing was drawn, and
  every poster was its bare background colour — while the browser test, which checked only the size,
  passed. `poster.js` strips Alpine's attributes from the copy (`onCloneEachNode`), and the test now reads
  the stage colour and the shape's blue out of the poster's pixels.
- **A folder in `public/` named like a route takes the route away.** The runtime first lived in
  `public/builder/`, and from then on `/builder` and `POST /builder` answered with the folder — a 404 under
  `php artisan serve`, a listing or a 403 under Apache (`RewriteCond !-d`) — while every feature test passed,
  because none of them goes through a web server. A browser test caught it; the files moved to
  `public/ad-runtime/`, and `PublicFolderRouteCollisionTest` now fails the moment any folder collides.
- **`validated()` keeps only keys that have rules.** The background layers had rules for their id, type and
  opacity only, so a layer's colour, gradient and blend were silently dropped on every save. Every key now
  has a rule, and a test saves every key back.
- **PHP writes an empty JSON object back as an empty array**, and a property set on a JS array is dropped
  by the next `JSON.stringify`. The editor makes every element's `style` and `animations` an object again
  when a document is loaded (`normaliseDocument`).
- **Alpine turns camelCase style keys into kebab-case**, so `webkitTextStroke` came out as the unknown
  `webkit-text-stroke`; the key is written `'-webkit-text-stroke'`.
- **A `<template>` inside an `<svg>` is an SVG element**, not an HTML template, so Alpine cannot stamp it:
  the Ease Visualizer's handles are shown and hidden with `x-show`.
- **A loop must not jump.** A float or a sway that starts at one end of its swing makes the element hop
  there when the loop begins; the runtime first eases out to that end from where the element rests.
- **A browser pane that paints once a second is not a screen.** GSAP slowed time down there (lag
  smoothing — a 0.8 s entrance took a minute); Anime.js keeps real time, but the pane never reports the
  stage visible to an `IntersectionObserver`, so a published page there starts on its 4 s fallback. The
  same pane holds back anything Alpine shows "on the next frame" (`x-show`), so a menu can look missing
  there while it works everywhere else.
- **Panels spread into one component share one namespace.** The view panel's `frameStyle()` and the
  editor's `frameStyle(element)` had the same name; the later one won silently and the zoomed frame lost
  its style. The canvas one is `canvasStyle()` now — check new panel methods against the others.
- **An advert must not start its clock while it is hidden.** The player loads the next item a beat early,
  behind the one showing, so the boot script waits for an `IntersectionObserver` to see the stage (with a
  4 s fallback) before the entrances begin.
- **A poster is re-drawn, never stored as sent.** `MediaStorage::storePoster` reads the size from the
  header first (so a small file claiming 50 000 pixels is refused before GD allocates for it), decodes with
  GD, and writes GD's own JPEG — which also retired the media library's habit of storing a video poster's
  bytes exactly as the browser sent them.
- **A copy owns its poster file.** Sharing the original's file meant deleting the original would take the
  copy's picture with it.
- **Fonts in a sandboxed frame are another site's.** The player's frame and the draft preview have an
  opaque origin, and a font is fetched with CORS, so Chrome refused every font file on our own server and
  the adverts showed system faces on the televisions. The fonts now travel inside the page (§7a). Nothing
  in the feature tests could see it: only a real browser in an opaque origin does.
- **A test that forgot `Storage::fake('public')` deleted the owner's published ads.** Pest runs as `testing`,
  whose public disk was the REAL `storage/app/public`, and its fresh database numbers stores and ads from 1
  exactly like the real one — so the store-deletion test's `purgeBuilder()` removed the folders
  `builder/1/ads/1` and `builder/1/ads/2` of the owner's own example ads. Three fixes: the tests' public disk
  has a throwaway root of its own (`config/filesystems.php`, pinned by `TestDiskIsolationTest`), the
  purge unlinks only the paths its rows name and never a folder, and that test file fakes the disk.
- **An ad page's playlist line takes its seconds like a picture** (§0), but the row offered the seconds box
  to images only, so every ad line was stuck at the default 10 s. `isTimed()` covers both. The same rows
  squeezed every title to nothing at 1024–1280 px; a container query now moves their controls under the
  title when the column is narrow. The Ads listing's cards had the same fault at 1024 px (the name 0 px
  wide, Delete pushed out of the card): two columns until 1280 px, and the buttons wrap under the name.
- **The desktop app's browser pane is not a television.** It refuses sandboxed frames and every request an
  opaque-origin page makes (`ERR_BLOCKED_BY_CLIENT`), so a player there shows black while real Chrome plays
  the ad: watch televisions in Dusk (`AdExamplesOnTelevisionTest`). Two more traps: opening
  `localhost:8001` in the pane logs it out of `localhost:8000` (cookies ignore ports), and a player tab left
  open during a Dusk run gets 401s from the swapped `.env` and throws its token away.
