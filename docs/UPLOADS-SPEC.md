# Uploading a file: dropped, sent in chunks, and shown as it goes — spec

> How a picture or a video reaches the server from any of the places that take one: the Media page, a
> channel's **Upload**, a campaign's advert, the Ad Builder's shelf and — through the shelf's own door — the Ad
> Builder editor's picker (2026-09-30). The owner asked on 2026-09-29 for "drag and
> drop, chunking, a good progress bar", a professional look in the panel's own design, and free software only.
> What a file may be — its formats, size and length, the organization's 512 MB, the server's reserve — is still ruled by
> `.claude/rules/02-project-conventions.md` (**Upload limits**), and nothing here loosens it.
>
> **Status: built, 2026-09-29.** Pictures made light on their way in: built, 2026-10-05 (§7).

---

## 0. Decisions taken (owner, 2026-09-29)

| Question | Answer |
| --- | --- |
| The library in the browser | **Uppy 6, its engine only**: `@uppy/core` and `@uppy/tus` (MIT, free, no account). Uppy's own Dashboard is not used: what a person sees is the panel's own Blade, Alpine and Tailwind (`<x-upload-dropzone>`). |
| How a file travels | **The tus protocol 1.0** (creation + termination): 5 MB chunks, each its own request. A dropped connection retries by itself and carries on from the last chunk the server has; the browser going offline waits for it to come back and carries on the moment it does; a connection that hangs without a word is noticed and sent again; the same file chosen again after a reload carries on too. Nothing reloads the page, and nothing is chosen again. |
| The server side | **No package**: the tus endpoints are the app's own (`UploadController`, `App\Services\ChunkedUploads`). `ankitpokhrel/tus-php` has had no release since February 2024 and would stop Laravel at Symfony 7, and every rule of this app must be asked before the first byte. |
| When a finished file joins its place | **Through the same door as before**: the form posts `upload` (the upload's id) instead of `file`, and the Form Request turns the finished upload into the `file` every existing rule already checks — formats read from the bytes, the video's length read from the file, the organization's room decided under its lock, the server's reserve. |
| The Media page and the shelf | **Several files at once**, each added to its place as soon as it arrives. A file's title is its name, changed later with Rename (on the Media page, the one thing a file keeps of its own since 2026-10-01). The box is on the page itself, never in a dialog (owner, 2026-09-30), and an "Added" row goes by itself after eight seconds, as a notification does. |
| The Ad Builder editor's picker | **The shelf's upload inside the picker** (owner, 2026-09-30): the file joins this ad's shelf — the organization worked in, or above the organizations the ad's own organization, which a new ad names first — and is on the picker's grid the moment it is in. |
| A channel's Upload and a campaign's advert | **One file**, sent as soon as it is chosen; Save waits until it has arrived. |
| A page left mid-upload | The browser asks first (its own words). |

## 1. The flow

1. A file is dropped on the box, or chosen with it (click, Enter or Space). The browser checks what it can before
   a byte leaves: the format and size (`fileError`), the organization's room (`storageError`), and a video's length, shape and
   poster frame (`readVideoMeta`, `videoLengthError`). A refusal is said on the file's own row, in the server's words.
2. `POST /uploads` opens an upload with its size and what it is for. The server refuses here, before any byte, what
   it would refuse at the end: the permission, the place (an organization the person works in, a channel within reach), the
   format by name, the size, the organization's room **counting every upload still open for that organization**, and the server's
   reserve counting every open upload's missing bytes.
3. `PATCH /uploads/{id}` carries each 5 MB chunk at its offset; `HEAD` says how far it got; `DELETE` gives it up.
4. With every byte in, the page posts the form it always posted, with `upload` in place of `file`. The row is
   made as it always was, and the upload is forgotten.

## 2. The server

| Route | Answers |
| --- | --- |
| `OPTIONS /uploads` | 204, `Tus-Version: 1.0.0`, `Tus-Extension: creation,termination`, `Tus-Max-Size` |
| `POST /uploads` | 201 and `Location`; 413 too large; 415 a format that is not taken; 422 a refusal with its reason; 429 too many open |
| `HEAD /uploads/{id}` | 200, `Upload-Offset`, `Upload-Length`, `Cache-Control: no-organization`; 404 for anybody else's |
| `PATCH /uploads/{id}` | 204 and the new `Upload-Offset`; 409 at the wrong offset; 415 not `application/offset+octet-stream`; 413 past the end |
| `DELETE /uploads/{id}` | 204 |

Every answer carries `Tus-Resumable: 1.0.0`. The routes sit behind `auth`, `verified` and the named limiter
`uploads` (a chunk is a request, and 5 MB chunks on a fast line are many), and keep CSRF: the browser sends the page's
token with every request.

**`uploads`** (a row per open upload, `App\Models\Upload`): a random uuid, the user, the organization it will count to (NULL for
the platform's library and the ads network; the foreign key cascades), `purpose` (`media`, `channel`, `campaign`,
`asset`), the file's name and claimed type, its `size` and the bytes `received`, and `expires_at` — 24 hours. The bytes
are `{id}.part` on the `uploads` disk (config/filesystems.php): `storage/app/private/uploads`, private, never under
`public/` — and a root of its own for the browser tests (`storage/app/dusk-uploads`) and the backend tests
(`storage/framework/testing/uploads`), as the `public` disk has, because the prune takes every part no row names and
one database names none of another's.

| Number | Value |
| --- | --- |
| Chunk the browser sends | 5 MB |
| Largest chunk the server takes | 16 MB |
| Open uploads per person | 20 |
| An unfinished upload is kept | 24 hours, then `uploads:prune` (hourly) takes it, and any part no row names |
| Uploads one page sends at once | 3 |
| A chunk that failed is sent again | at once, after 1, 3, 5, 10, 20 and 30 seconds, then every minute: some 25 minutes, the count starting again whenever a chunk gets through |
| A row that has sent nothing says "Connection trouble…" | after 6 seconds |
| …and its request is given up and sent again | after 30 seconds (a connection that died without a word would otherwise wait for the system's own timeout) |

An organization that is deleted takes its open uploads (`Organization::purgeContents`); an account that is deleted loses its rows
through the foreign key, and the next prune takes their parts.

## 3. The four doors

`StoreMediaRequest`, `ChannelAdRequest`, `CampaignRequest` and `BuilderAssetRequest` take `upload` beside `file`
(`Concerns\TakesAFinishedUpload`): the person's own upload, complete, unexpired and opened for that purpose, becomes
the request's `file` before any rule runs. Anything else is refused on `file`: "That upload is not finished, or it
has expired. Choose the file again." — and a request carrying both is refused too. The controllers forget the upload
once its row is made; a refused one stays for the person to try again, until it expires.

## 4. The browser

`resources/js/core/upload-dropzone.js` registers the Alpine component `uploadDropzone` (Uppy is loaded only when a
page has one), and `<x-upload-dropzone>` draws it. Two modes:

- **`add`** — the Media page, the shelf and the editor's picker: each file that arrives is posted to its door at
  once, and the component dispatches `upload-added` with the server's answer, so the page refreshes its list and its
  storage meter (the picker puts the new file first on its grid). Eight seconds after it, the "Added" row fades away
  (`ADDED_STAYS_MS`) — "3 of 5 added" still counts it until nothing is going any more — while a refused or a failed row stays
  for its reason and its Try again.
- **`form`** — a channel's Upload and a campaign's advert: the component dispatches `upload-ready` with the upload's
  id and what the browser measured, `upload-cleared` when the file is taken away, and `upload-busy` while bytes are
  still going, so the form can hold Save.

## 5. What a person sees

A box with a dashed border: an upload icon, "Drop files here or **choose files**" ("a file" where only one is
taken), and the formats and limits under it; it turns blue while a file is dragged over it. Below it, a row per
file: a preview (the picture itself, or the video's poster), the name, the size and a video's length, a bar in the
panel's blue with the percent, the speed and the time left, and Pause, Resume, Cancel or Try again. What each row
says: "Checking…" · "Waiting…" · "Uploading 45% · 2.4 MB/s · 12 s left" · "Paused at 45%" · "Connection lost.
Waiting for the internet…" · "Connection trouble at 45%. Trying again…" · "Adding…" · "Added" · "Added · made
lighter: 4.4 MB to 1.7 MB" (§7) · "Uploaded. It is added when you save." · or the reason it was refused, in red. The bar never goes back: a chunk sent again after a
pause or a lost connection starts from the last one the server kept, and the bar waits until it is caught up.
Several files show "3 of 5 added" above the rows. It works with the keyboard, says its changes to a screen reader,
turns dark with the panel, and fits a phone (`EveryPageFitsAPhoneTest`).

## 6. Tests

`tests/Feature/System/ChunkedUploadTest.php` (the protocol, the four doors, the early refusals, expiry and the prune),
`tests/Feature/Security/ChunkedUploadAttackTest.php` (somebody else's upload, offsets and lengths that lie, names
that are paths or not UTF-8, too many at once, a half-finished upload posted, one upload used twice, a PHP file
wearing a picture's name) and `tests/Browser/ChunkedUploadFlowTest.php` (several files dropped at once; a picture
larger than a chunk sent in pieces, one request each, and arriving byte for byte; paused and resumed; a lost
connection waited out on the same page — Chrome's own network emulation, offline and slow; cancelled on its way,
and gone from the server; a form holding Save until its file is in). The pictures it compares byte for byte are GIFs
of noise made in the page, because a GIF is stored exactly as it came (§7). The refusals as a person meets them are
`tests/Browser/UploadLimitsTest.php`; pictures made light, `tests/Feature/Signage/PictureOptimizerTest.php` and
`tests/Browser/PicturesMadeLighterTest.php`.

## 7. Pictures made light on their way in (owner, 2026-10-05)

> "upload par tasveer khud halki karne wala feature bana do, sub upload mein lagana aur yeh bhi dekhna k video bhi
> upload honti ha" — and "Badi tasveer ko chhota karna yeh bhi bana do".

Before a door stores a picture, `MediaStorage::put` asks `App\Services\PictureOptimizer` for a lighter one: on the
server, once every byte is in, on the file the door has already checked — so every rule of this spec still judges what
came. It is one place for all four doors (and `builder:examples`), and what it gives is what is stored, counted to the
organization's 512 MB and sent to the televisions.

| What came | What is stored |
| --- | --- |
| A JPEG, PNG or WebP over 4K | brought down to 3840 px on its longer side, as a WebP — always, whatever it then weighs, as WordPress brings down what is over its own threshold |
| A phone photo whose EXIF says it was held on its side | turned upright, as a WebP — always: an old WebView shows it on its side |
| Any other JPEG, PNG or WebP | a WebP when that is at least a tenth lighter; otherwise the file as it came |
| A palette PNG, or a lossless WebP, that keeps its size | a lossless WebP: its pixels exactly |
| A colour profile (an iPhone's Display P3, Adobe RGB…) | the very same profile, in the WebP's ICCP chunk, so a screen reads the colours as it read the upload's |
| A GIF; a PNG or WebP that moves; a CMYK JPEG; a grey or CMYK profile, or a split one with a part missing; a PNG whose gamma or primaries are not sRGB's; a file not built as its format says, or that GD cannot read; a picture too big for the memory one request has | the file as it came |
| A video | the file as it came, always: there is no ffmpeg on the server |

A WebP is written at 85 for a photograph (a JPEG) and 90 for a PNG or a WebP. Nothing the optimizer does refuses an
upload: whatever goes wrong, the file is stored as it came. The organization's wall and the server's reserve are asked
with the size that came, before it is made lighter. The Media page's and the shelf's answers carry
`lighter: {from, to}`, and the row says "Added · made lighter: 4.4 MB to 1.7 MB" — only when the two sizes read
differently (700 bytes made 60 read "1 KB" both times). A channel's Upload and an advert store the lighter file too and
say nothing of it: their dialog closes on Save. Files uploaded before 2026-10-05 are as they came.

Measured on photographs drawn for it (a gradient with grain), PHP held to the server's 384 MB:

| Picture | Came | Stored | Time | Memory over the request's own |
| --- | --- | --- | --- | --- |
| 12 MP, 4032 × 3024 | 4.4 MB | 1.7 MB, 3840 × 2880 | 2.7 s | 136 MB |
| 24 MP held on its side, 6000 × 4000 | 8.4 MB | 1.6 MB, 2560 × 3840 | 3.7 s | 188 MB |
| 48 MP, 8000 × 6000 | 16.4 MB | 1.6 MB, 3840 × 2880 | 4.2 s | 278 MB |

The memory is asked before GD opens anything (`PictureOptimizer::fitsInMemory`, with every step's pictures added up:
PHP keeps what one step frees for later instead of handing it back), so a picture that would not fit is stored as it
came rather than ending the request. `signage.optimize_pictures` (`SIGNAGE_OPTIMIZE_PICTURES`) switches it; it is on
everywhere but the Pest suite.
