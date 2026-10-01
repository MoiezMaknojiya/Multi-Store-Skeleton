# A library for everybody, and channels that pick from it — spec

> Where a channel's ads come from. The owner asked on 2026-09-19 that a channel stop being a shelf of its
> own: what anybody uploads belongs in a **media library**, and a channel picks from there — the library,
> the Ad Builder, or a fresh upload that lands in the library anyway. The platform gets a library of its
> own (2026-09-19), so its promos are uploaded once and used in as many channels as it likes.
> `docs/ORGANIZATION-SPEC.md` still rules who may do it and `.claude/rules/02-project-conventions.md`
> still rules how it is written.
>
> **Status: built, 2026-09-21** — all five stages below. `02`, `AUTH-SYSTEM-SPEC.md` and
> `docs/ORGANIZATION-SPEC.md` now say "a channel's ads are library rows" and "a library belongs to an organization,
> or to the platform". Where the build differs from the first draft of this page, the page was corrected to
> match the build (the migration moves no file, §2; the Media page's parameters, §4; the exact refusal, §5).

---

## 0. Decisions taken (owner, 2026-09-19)

| Question | Answer |
| --- | --- |
| Where an upload made inside a channel goes | **Into a media library** — the organization's for an organization's channel, the platform's for the platform's. A channel has no shelf of its own any more. |
| The platform's library | **`media.organization_id = NULL`**, the way `channels.organization_id` already says "this is the platform's". NOT `0`: `media.organization_id` carries a foreign key with `cascadeOnDelete`, and a `0` would mean dropping it (orphan rows when an organization is deleted) or inventing a fake organization row that every "all organizations" list would show. |
| How a channel holds an ad | **By id, never a copy:** `channel_ads.media_id`, required. Re-publish an ad and the channel shows the new page by itself. |
| The three ways to add an ad | **Library · Ad Builder · Upload.** |
| An Ad Builder ad in a channel | It is already a media row of `type = 'html'` once published, so the channel holds that same `media_id`. There is no second id and no copy. It plays for the length its design says (`Media::ownLength()`, 6 to 300 seconds since 2026-09-28); a page published before designs had a length runs on seconds like an image (`ChannelAd::MAX_IMAGE_SECONDS`). Changed after publishing, the channel keeps playing the published version until the changes are published (the industry's draft/publish model, `docs/AD-BUILDER-SPEC.md` §9); **unpublished**, its channel ad reads "Draft · not playing", no screen gets it and no picker offers it, until the next Publish brings it back. |
| The Ad Builder's own shelf | **Unchanged** (`builder/{organization}/assets/…`). Raw material for designs is not library content. |
| Can an organization see or play the platform's library files | **No.** An organization's Media page and its playlist picker show that organization's rows only; a platform file reaches a television through a **channel** and nothing else. |
| Deleting a library file that a channel uses | **Refused**, naming the channels: "Still used by …. Take it out of those channels first." Playlists keep today's behaviour — a deleted file simply leaves the screens it played on. |
| The platform's channels inside an organization | **Listed, read-only**: name, ad count, "Show ads". No Edit, no Delete, no Add ad. An organization's own channel stays its own and is never seen by another organization. |
| The one channel ad that exists today | **Migrated** into the platform's library (backup first, owner's approval). One row, one file — the model comes out uniform with no legacy mode to carry. |

---

## 1. What changes, in one line each

- `media.organization_id` becomes **nullable**: NULL means the platform's own library.
- Every media query learns the tier: an organization sees its own rows, the platform sees every organization's **and** its own, and nothing crosses over by accident.
- `channel_ads` keeps `channel_id`, `title`, `duration_seconds`, the dates and `position`, gains `media_id`, and loses every file column — the file lives in the library now.
- Uploading inside a channel goes through the same pipeline as the Media page and ends up in the right library.
- The Add-ad form grows two pickers beside the upload box.
- Deleting a media row is refused while a channel names it.
- An organization's Channels page also lists the platform's channels, without any action on them.

Nothing changes for the television: the manifest still sends `{type, url, checksum, duration, mime}` per ad.

---

## 2. Data

```
media
  organization_id   FK → organizations, NULLABLE, cascadeOnDelete      ← NULL = the platform's own library

channel_ads
  channel_id FK → channels, cascadeOnDelete
  media_id   FK → media,    cascadeOnDelete              ← new, required
  title              nullable; blank falls back to the media row's title
  duration_seconds   how long an IMAGE or an AD PAGE stays up (the channel's own number)
  starts_on, ends_on, position
  — type, mime_type, disk, path, thumbnail_path, size, width, height,
    orientation, media_duration_seconds are DROPPED: read them from the media row
```

- **Files on disk:** an organization's stay at `media/{organization_id}/…`; the platform's go to `media/platform/…`
  (`MediaStorage` picks the folder from the row's owner, one place, nowhere else).
- **`cascadeOnDelete` on `media_id`** is what makes a deleted organization tidy: `Organization::purgeMedia()` takes its
  rows and any channel ad pointing at them goes too. Deleting a media row by hand is refused first (§4), so
  the cascade only runs for an organization being deleted A to Z — a platform channel then simply loses that ad.
- **Reading an ad** goes through the media row (`type`, `mime_type`, `url`, `thumbnail_url`, the video's own
  length, `cacheKey()`), so a re-published ad moves the checksum and the television fetches it again.
  `ChannelAd`'s existing accessors keep their names; only their insides change.
- **Migration** (history: `2026_09_21_100100_let_channels_take_their_ads_from_the_library`, folded into the
  one-per-table files on 2026-10-01 once every database had run it; one row on the owner's database): a media row for each channel ad — the channel's organization, or the platform for a platform channel —
  naming its file **where it already lies** (`channels/{id}/…`): a folder name is only a folder name, the row is
  what says whose file it is, and a migration that touches no file cannot lose one. Then the ad points at the
  row and the file columns go. `down()` copies them back and deletes the rows it made. The platform's
  library itself was `2026_09_21_100000_let_the_platform_keep_a_media_library` (folded in the same day), whose `down()` made
  `organization_id` NOT NULL again only while the platform holds no file (and otherwise leaves the column open —
  neither deleting a library nor stopping the rollbacks behind it). A JSON backup of the owner's database is
  taken first and the owner says go.

---

## 3. Who sees which media

| | Own organization's rows | Another organization's | The platform's (NULL) |
| --- | --- | --- | --- |
| An organization's member (`media-view`) | ✅ Media page, playlist picker, channel picker | ❌ | ❌ |
| Platform user (`media-view` above the organizations) | ✅ | ✅ | ✅ |
| A television | only through its screen's playlist | ❌ | only inside a **channel** line |

- `Media::visibleTo` keeps its shape: an organization member sees `organization_id = session organization`; a platform user sees
  everything. The new rule is the one that did not exist before — an organization member must never match
  `organization_id IS NULL`, which is what a plain `where('organization_id', $organizationId)` already does.
- `PlaylistController::assertMediaBelongsToTheSameOrganization` is unchanged and therefore already refuses a
  platform row on a screen: the walls hold without a new rule.
- The platform's Media page gets the chooser the Ad Builder's Assets page has: **Platform** (the default) or
  one organization. It decides both what the listing shows and where an upload lands. An organization's Media page has no
  chooser and no way to reach the platform's rows.

---

## 4. Endpoints

| Method | URI | Who | What |
| --- | --- | --- | --- |
| GET | `/channels/{channel}/library` | `channel-store` / `channel-update` | The rows this channel may use, for the picker: id, title, type, orientation, duration, thumbnail. `?type=image\|video\|html`, `?search=`, paged. Capability-complete: it does NOT need `media-view`. |
| POST | `/channels/{channel}/ads` | as today | Takes **either** `media_id` **or** `file` (plus `title`, `seconds`, `starts_on`, `ends_on`). A `file` is stored in the channel's own library first, then referenced. |
| GET/POST | `/media/data`, `/media` | as today | For platform users: `/media/data?library=platform` (the page's default) or `?library={organization}`, and none for every library; an upload posts `organization_id` for an organization's library and nothing for the platform's. Inside an organization both are ignored — the session's organization decides. Each listed row carries `in_channels_message` (§5). |

- `media_id` is validated `['bail','integer','min:1']` and then resolved against the channel's own wall: an
  organization's channel takes that organization's rows, the platform's channel takes any organization's **or** the platform's own —
  anything else is a 422, never a 404-shaped guess at another organization's ids.
- The pickers for a platform channel ask which organization first (or Platform), exactly like the Assets page.

---

## 5. Deleting

- `MediaController::destroy` asks first (`Media::stillInAChannelMessage`) — if a channel shows the file,
  **422** on `file`: "Still used by the channel GAMA. Take it out of that channel first." / "Still used by
  the channels Alpha, Bravo, Charlie and 2 more. Take it out of those channels first." (names in order, at most
  three). The listing carries the same words per row (`in_channels_message`, one query for the page), so the
  panel shows them as a toast **before** any confirmation.
- Deleting an **Ad Builder design** whose published page a channel shows is refused the same way (422 on
  `name`), before its password is asked.
- Taking an ad out of a channel (its × button) removes the `channel_ads` row only; the library keeps the file.
- Deleting a channel removes its rows and touches no library file.
- Deleting an organization works as it does now, and the cascade takes any channel ad that borrowed its rows.
- The platform's own library rows belong to no organization, so nothing but an explicit delete removes them.

---

## 6. Panel

**Add ad** becomes three choices in one modal, picked with a switch at its top (**Media library · Ad Builder ·
Upload**, and **Keep this file** first when editing — only the way chosen is sent). A new ad starts on the
library; above the organizations the platform's channel asks which library (Platform, or an organization) beside the search:

1. **Library** — a grid of the rows this channel may use, searchable, filtered by kind.
2. **Ad Builder** — the same endpoint with `type=html`: published ads, each with its poster and name, so an
   ad reads as an ad and not as a page. An unpublished design has no media row yet, so it is not listed and
   the empty state says so.
3. **Upload** — today's box, with a line saying where the file will land ("It joins your media library" /
   "It joins the platform's library").

Seconds, dates and the title behave as they do now, whichever source was used; a video still has no seconds
field anywhere, and a blank title takes the file's own.

**An organization's Channels page** lists the platform's channels under the organization's own, each row marked "From the
platform" with no action but "Show ads". The listing comes from a new scope (`Channel::listableIn`), while
every write keeps `Channel::visibleTo`, so a POST or DELETE aimed at a platform channel from inside an organization
is still a 404.

---

## 7. Television

No change to the manifest's shape. An ad page inside a channel arrives as `type: 'html'`, and the player
already builds a sandboxed iframe for that type and times it like an image, so a channel of ad pages plays
without a line of new player code. What a browser test must show: a channel carrying one image and one
published ad, both playing in turn, and the ad re-published mid-run reaching the screen on the next poll.

---

## 8. Stages (the owner looks at each before the next) — all built, 2026-09-21

1. **The platform's library** — `media.organization_id` nullable, the folder for platform files, the tier in every
   media query, the Platform/organization chooser on the platform's Media page. Pest tests: an organization never sees or
   plays a platform row; a platform row survives an organization being deleted.
2. **Channels pick from the library** — `media_id` (required), the file columns dropped, the accessors, the
   picker endpoint, uploads landing in the right library, the delete refusal, and the migration of the one
   ad that exists today (backup first). Pest tests, including the organization wall on `media_id`.
3. **The Add-ad modal** — the three sources, the platform's chooser, the empty states.
4. **The Channels page** — the platform's channels listed read-only inside an organization.
5. **Browser tests and the documents** — Dusk for the flows above and the television check, then these
   decisions folded into `docs/ORGANIZATION-SPEC.md` and `.claude/rules/02`.

---

## 8a. A file plays from playlists or from channels, never both (owner, 2026-09-26)

"agar koi bhi file channel k ander assign ha toh woo playlist mein nahi dikhe warna woo 2 bar ho jayegi" —
then "add naah ho sake nahi, dikhao hi nahi". A file in a channel and on the playlist that carries the channel
plays twice in one pass, so:

- The playlist's Content library never shows a file any channel holds — the organization's own channel or the
  platform's, paused or out of its dates (`Media::scopeInNoChannel`, `PlaylistController::availableMedia`).
- A channel's Add-ad pickers never show a file a screen's playlist holds (`Media::scopeOnNoPlaylist`,
  `ChannelAdController::library`) — the platform's channel looking into an organization's library included.
- Each picker says so in one line ("Files that play in a channel are not listed here…", "Files on a screen's
  playlist are not listed here…"), so a missing file is never a mystery.
- Behind the pickers, an id posted by hand is refused: the playlist save with a 422 naming the file
  (`PlaylistController::assertFilesAreInNoChannel`), the Add-ad and the ad's change of file with a 422 naming
  the screens (`ChannelAdRequest`). An ad saved with the file it already shows keeps it, so a channel ad and a
  playlist line that shared a file before the rule can still be re-timed. Copy to other screens refuses a
  playlist still holding such a line, so it never spreads to more televisions.
- Two people at the same moment, one putting a file on a playlist and one putting it into a channel, cannot
  both land it: both writes lock the file's row inside their transaction and look again under the lock
  (`PlaylistController::assertFilesAreInNoChannelUnderLock`, `ChannelAdController::refuseAFileAPlaylistHolds`).
- Taken out of every channel — or off every playlist — a file is the other side's to choose again.
- The holding picture is neither a playlist line nor a channel's ad and keeps its own list.
- An Ad Builder ad follows the same rule as soon as it is published: while a channel shows it, the playlist's
  picker leaves it out (the "Show in playlists" tick it once needed is gone since 2026-10-01).

## 8b. A channel's Upload is held to the library's limits (owner, 2026-09-28)

A file uploaded inside a channel joins a library, so it meets the library's two walls
(`.claude/rules/02-project-conventions.md`, **Upload limits**):

- A video is **5 minutes** at most, measured by the server from the file itself (`App\Rules\VideoLength`), on
  the Add ad and on an ad's change of file alike. A file chosen from the library was measured when it went in.
- An organization's channel fills that **organization's 512 MB** like its Media page does (`MediaStorage::addToLibrary`,
  `OrganizationStorage`); the platform's channel fills the platform's own library, which has no wall. The Add-ad form
  reads how full the library is from `/channels/{channel}/ads` and refuses a file bigger than what is left
  before a byte is sent; the server decides. Taking an ad out frees nothing, because the file stays in its
  library — deleting it there does.

---

## 9. Questions already answered, so nobody asks again

- **Why not copy the file into the channel?** A copy goes stale: re-publishing an ad would leave the channel
  showing last week's page, and two rows would point at the same picture with no way to tell.
- **Why no `builder_ad_id`?** A published ad IS a media row. A second id would be a second truth.
- **Why NULL and not 0 for the platform?** `organization_user.organization_id = 0` works because that column deliberately
  carries no foreign key. `media.organization_id` does carry one, and it is what makes a deleted organization take its
  files with it.
- **Could the Ad Builder build a platform-owned ad now?** It becomes possible once a media row may belong to
  the platform, but it is not in this spec. Ask the owner before starting it.
