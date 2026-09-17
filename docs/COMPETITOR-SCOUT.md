# Competitor scout — what the paid products actually do

Written down because the last scout was lost when the conversation was compacted.
Anything here was seen with my own eyes in the live product on **2026-09-10**, not
recalled from training data. Read-only throughout: nothing was created, changed or
deleted in either account.

> **As of:** the notes describe those products on 2026-09-10 and OUR app as it stood
> that day. Ours has moved on since — the store-organization rebuild (2026-09-16/17)
> replaced the old three-tier idea with membership and permissions, and channels,
> campaigns and the schedule work landed after this scout. Re-scout before using any
> comparison here as an argument about what we are missing.

Two products:

- **AbleSign** — `app.ablesign.tv`, the account "Digitallift Food Menu" (5 screens,
  8 media files, Free plan). This is the one our screens/media/playlist work was
  modelled on.
- **Digital Lifts** — `palefacemarket.signcdn.com`, version 4.4.4. See the section at
  the bottom.

---

## 1. AbleSign — the map

Top nav: **Content · Screens · Groups · Websites** | **Admin ▾** | dark-mode toggle | avatar ▾

| Route | What it is |
|---|---|
| `/files` | Content library (media) |
| `/files/{id}` | One file: preview, metadata, tags, schedule |
| `/screens` | Screens, as **cards** not a table |
| `/screens/{id}` | One screen: playlist editor + content picker |
| `/screen_groups` | Screen groups (empty in this account) |
| `/websites` | Websites, a second kind of playable item |
| `/alerts`, `/alerts/new` | Offline/online alert rules |
| `/proof_of_play` | Playback report (opt-in) |
| `/activity_log` | Activity log |
| `/account` | Users · Workspaces · Plan · API Keys |

Avatar menu: My profile · Account settings · Add new screen · Help (knowledge base) ·
Send feedback · Log out.

---

## 2. Scheduling — THREE separate layers (this is the important part)

This is what we are about to build, so it is written out in full.

### Layer 1 — Screen operating hours (per screen)

Screen page → **Hours** button → modal "Screen operating hours".

- One dropdown: **"This screen [is in use during these times: | is in use outside of these times:]"**
  — an include/exclude flip over the SAME seven rows. That single control is what lets
  somebody say "closed 14:00–16:00" without inventing two windows per day.
- Seven rows, **Monday … Sunday**, each with a **Start** and an **End** time
  (defaults `00:00` → `23:59`).
- Footnote: **"Screen timezone: America/Chicago"** — the schedule is read in the
  SCREEN's timezone, not the account's and not the browser's.
- Checkbox: **"Blank the screen when not in use"**.
- Buttons: **Reset** / **Save**.

### Layer 2 — Daily display times (per playlist item)

Playlist item ⋮ → **Set daily display times** → modal "Daily display times".

- Checkbox: **"Only display this item between the following times:"** — unticked, the
  whole grid is greyed out. Off by default, so an item plays whenever the screen does.
- The same Monday–Sunday Start/End grid, and the same "Screen timezone: …" footnote.
- Buttons: **Reset** / **Clear** / **OK** — note **OK**, not Save: it is staged into the
  playlist and committed by the playlist's own "Save Changes". That matches our
  whole-list PUT exactly.

### Layer 3 — Periodic display (per playlist item)

Playlist item ⋮ → **Schedule periodic display** → modal "Periodic display".

> "Use this form to schedule the periodic display of a playlist item, such as the last
> weekday of the month or the first Tuesday of July."

- Checkbox **"Enable periodic display for {filename}"** (everything below is disabled
  until it is ticked).
- **Initial display**: Start date and time → End date and time (`MM/DD/YYYY hh:mm`).
- **Repeat**: `Never | Every 2 weeks | Every month | Every year | Custom`.
- **Custom** opens a full iCal-style recurrence builder:
  - Repeat every `[1]` `[Month ▾]`
  - On day `[10]` of the month
  - On the `[…]` (i.e. "the second Tuesday")
  - End repeat `[Never ▾]`

### Layer 0 — File start/expiry (per file, all screens)

`/files/{id}` → right panel → **Schedule**:

> "Media will not show on any screens before the start date or after the expiry date.
> **Leave blank to always show.**"

- Start date & time, Expiry date & time.
- Content library **⋮ → Delete expired content** is the housekeeping for it.
- Content library **Sort** offers **Start date** and **Expiry date**, both directions.

**We already have Layer 0** (`media.start_at` / `expires_at`, resolved server-side in
`DeviceController::playlist`), and the wording of their help text matches our behaviour,
including blank = always. Layers 1–3 are the gap.

---

## 3. The screen page

Header: thumbnail (with a **Change** link — the image is uploadable, see §4), Online /
Offline badge, name, description, "Last seen …", **Details**, orientation label,
**Settings**, **Hours**.

**Details** opens a right-hand drawer with three tabs:

- **Information** — Last seen · Status (`OK`) · Last playlist change · Manufacturer ·
  Model · **Storage total** (6240 MB) · **Storage free** (5648 MB) · Device started ·
  Initial setup · **App version** (2.5.2).
- **History** — device event timeline ("No events to display" here).
- **Screenshots** — "See what's currently displayed on this device." + a **Take
  Screenshot** button. On this device: *"Sorry, this device does not support taking
  screenshots."* So a live remote screenshot exists but is hardware-dependent, and the
  card thumbnail is NOT necessarily it.

**Settings** modal: Screen orientation · Name\* · Description · **Tags** ("Enter optional
tags to help organize your screens") · **Advanced Settings** ▾:

- **Fit content to the screen**: `Ensure the screen is filled (possible zoom)` /
  `Ensure the entire content is displayed (possible letterbox)` — cover vs contain.
- **Timezone**: `America/Chicago (GMT-06:00)` — per screen.
- **Synchronize playback with other screens** (+ "How to synchronize playback timing"),
  which enables **Role of this screen**: `Leader` / follower, and "Select the leader
  screen". Video-wall sync.

Body: tabs **Content | Websites**, then the playlist on the left and the library on the
right, drag-and-drop between them.

**Playlist ⋮**: Set playlist transitions · **Shuffle play** · **Copy playlist to other
screens** · Clear playlist.
Header also has **Preview playlist** and **Save changes** (disabled until dirty), and a
running total: **"2 Items • 20 Secs"**.

**Playlist item**: thumbnail · name · "Image • landscape • 3 days ago" · **DURATION**
box in Secs · drag handle · ⋮ with:
Set daily display times · Schedule periodic display · **Set a custom transition** ·
Move item up · Move item down · **Duplicate item** · Remove from playlist.

Custom transition modal: Transition + Transition speed, both defaulting to "Use playlist
default", with the current default stated: *"Transitions in this playlist are set to: None."*

---

## 4. Screens listing

Cards, not rows. Each card: a **picture of the screen**, an Online (green) / Offline
(red) badge, the name, "Last seen X ago", and a ⋮ whose only entry is **Delete screen**.

The picture is a real photo of the shop for the demo screen and a generic grey TV for the
rest — combined with the **Change** link on the detail page, that says it is an uploadable
thumbnail, with the live device screenshot as a separate, hardware-gated feature.

**Sort**: Date added (newest/oldest) · Alphabetical (asc/desc) · **Time last seen (newest
first / oldest first)** — "oldest first" floats the dead screens to the top.

**Filters**: Date added · **Screen orientation: Landscape / Portrait (+90 Degrees) /
Upside Down (+180 Degrees) / Reverse Portrait (+270 Degrees)** · Status: Offline /
Online · Tags · Reset / Apply.

**Add Screen** is a two-step wizard: app-store badges (Google Play, Amazon Appstore,
BrightSign), "When the app first launches it will display a pairing code", one
**Pairing code \*** field, and **Next** — the name and settings come after the code is
accepted. They need a native app; our player is just a URL, which is our advantage.

---

## 5. Content library

Cards with thumbnail, a **duration badge on video thumbnails** (`0:15`, `0:20`, `0:08`),
name, and "Image • landscape • 3 days ago".

**File ⋮ / Actions ▾**: **Add to the playlists of multiple screens** · **Remove from all
playlists** · **Move to a different folder** · **Replace file** · Delete file ·
**File management — manage multiple files at once**.

**Add to the playlists of multiple screens** modal: *Add at position* `[Start of playlist ▾]`,
*Duration* `[10] Seconds`, then a checklist of every screen (thumbnail, name, "Last seen …
• Landscape orientation") with a select-all, then **Apply**.

**Library ⋮**: New folder · **Delete expired content**.
**Sort**: Upload date · Alphabetical · Start date · Expiry date (both directions).
**Filters**: Uploaded (Any time) · Media type Videos/Images · Orientation Landscape/
Portrait · Tags.

**File detail** (`/files/{id}`): large preview; meta strip **Uploaded · Type · Size
(909 KB) · Orientation · Dimensions (1920x1080)**; right panel Title\* · Description ·
Tags · Schedule (§2 Layer 0) · Save Changes.

---

## 6. The rest

- **Groups** (`/screen_groups`) — empty-state copy worth stealing: *"If you have multiple
  screens with the same playlist, a screen group allows you to manage them all in one
  place. Simply create a group playlist, and then assign screens to the group."*
  Illustration + explanation + CTA.
- **Websites** (`/websites`) — a playable item that is a URL, with a live thumbnail of the
  site. **Add Website** takes URL\* + Name\*, warns *"A reliable internet connection is
  required to display online content"*, and **More Options** reveals three modes:
  **Add Website URL | Paste HTML | Upload File**.
- **Alerts** (`/alerts/new`) — the best-designed screen in the product:
  Name\* · Notify me when a screen goes offline · … comes back online ·
  **Wait before alerting (minutes)** = 30, explained as *"Screens can briefly disconnect
  due to normal network fluctuations. This setting prevents unnecessary alerts by waiting
  before notifying you."* · **"Only alert during operating hours"** — it reuses the Hours
  schedule from §2, so nobody is paged at 3am about a shop that is shut · Scope: all
  screens / only screens with certain tags · Recipients (account users) + other email
  addresses.
- **Playback report / proof of play** (`/proof_of_play`) — opt-in, off by default:
  *"See exactly what content was displayed on your screens and for how long. Devices
  report the actual playback time to ensure accuracy."* This is the feature that makes
  selling advertising possible.
- **Activity log** (`/activity_log`) — Date range ("Last 30 days") · Refresh · Filters ·
  Search; columns Date | User | **Category** (chip, e.g. "Screen") | Details | **Item**
  (an open-in icon linking to the subject). 31 entries, 10 per page.
- **Account** (`/account`) — avatar, "Joined Aug 2026", stat tiles **5 Screens** /
  **8 Media files**; tabs **Users · Workspaces · Plan · API Keys**.
  - *Workspaces* = our stores: "Default workspace — 5 screens • 8 Media files • 1 User",
    with a `Default` chip.
  - *API Keys* — they have a public API; we deliberately do not.

### Pricing — they meter STORAGE, not screens

- **Free** — $0/mo, **500 MB** library ("Stores approx 15 short videos").
- **Storage Plus** — **$9.99/mo**, **100 GB** ("approx 3,000 short videos").
- Both: "Build playlists and schedule content", "Display 4K videos, websites and more",
  **"Unlimited users"**, "Reliable under all conditions". **Screens are not metered at
  all.** A storage meter ("194 MB storage used") sits above the plan cards.

---

## 7. What we have that they do not

- The player is a **web page**, so any TV with a browser works — no app store, no
  BrightSign box.
- Multi-store isolation with a real permission model (roles, per-store walls). Their
  Workspaces exist but every plan says "unlimited users" with no role system in sight.
- Optimistic locking on the playlist save (`playlistFingerprint`, 409 on stale) — their
  "Save changes" gives no sign of one.

## 8. What they have that we do not (ranked by worth to a shop owner)

1. **Scheduling layers 1–3** — the whole point of the next phase.
2. **Screen tags** + filtering by them; media tags too.
3. **Add one file to many screens at once**, and **Copy playlist to other screens**.
4. **Replace file** — swap the artwork, keep it on every playlist it is already on.
5. **Offline alerts**, debounced, and only during operating hours.
6. **Screen groups** — one playlist, many screens.
7. **Duration badge on video thumbnails**; dimensions and size on the file page.
8. **Fit mode** (cover vs contain) per screen, and four orientations by rotation degrees.
9. **Device detail drawer**: storage free, app version, uptime, device history.
10. **Proof of play** — the prerequisite for selling advertising.
11. Folders for media; **Delete expired content**.
12. Websites as a playable item (URL / pasted HTML / uploaded file).
13. Activity log date-range filter, category chip, and a link to the subject.
14. Shuffle play; transitions (playlist default + per-item override).

---

## 9. Digital Lifts (`palefacemarket.signcdn.com`, v4.4.4)

Digital Lifts is a **rebranded Xibo CMS** on Xibo's cloud (`signcdn.com`), version 4.4.4.
That matters: it is the mature, open-source end of this market, ten years deeper than
AbleSign, and it shows. Where AbleSign is a shop-owner's tool, this is an operator's
tool. The account has 3 displays (`PalefaceMarket-Tv1/2/3`), 2 users, 99.3 MiB library.

Sidebar: **Dashboard** (Schedule, Dayparting) · **DESIGN** (Campaigns, Layouts,
Templates, Resolutions) · **LIBRARY** (Playlists, Media, DataSets, Menu Boards) ·
**DISPLAYS** (Displays, Display Groups, Sync Groups, Display Settings, Player Versions,
Commands) · **ADMINISTRATION** (Users, User Groups, Settings, Applications, Modules,
Transitions, Tasks, Tags, Folders, Fonts) · **REPORTING** (All Reports, Report Schedules,
Saved Reports) · **ADVANCED** (Log, Sessions, Audit Trail, Report Fault) · **DEVELOPER**.

### 9.1 Dayparting — a named, reusable time window

`/daypart/view`. This is the single best idea in either product. A **daypart** is a
first-class named entity ("Breakfast", "Happy Hour"), not seven rows retyped on every
item. Two are built in: **Always** ("Event runs always") and **Custom** ("User specifies
the from/to date").

**Add Daypart** — three tabs, **General | Description | Exceptions**:

- General: **Name** · **Retired** ("Retire? It will no longer be visible when
  scheduling" — so old dayparts are hidden, never deleted out from under the events
  using them) · **Start Time** · **End Time**, whose help text is the answer to the
  overnight problem:
  > *"Enter the end time for this daypart. **If the end time is before the start time,
  > then the daypart will cross midnight.**"*
  No extra field, no second window — `end < start` means it wraps.
- Exceptions: *"If there are any exceptions enter them below by selecting the Day from
  the list and entering a start/end time."* Repeating rows of
  `[Day ▾] [start] [end] [+]`, where the day select is literally
  `exceptionDays[] = Mon | Tue | Wed | Thu | Fri | Sat | Sun`.

So the model is **one base window + per-weekday exceptions**, not seven equal rows.
Far less to fill in for the common case ("open 9–6, Sunday 11–4").

### 9.2 The Schedule — one event, many knobs

`/schedule/view`, with **Grid** and **Calendar** views and a Day/Week/Month/Year range.
The grid columns are the feature list in miniature: Event Type · Name · Start · End ·
Event · Display Groups · **SoV** · **Max Plays per Hour** · **Geo Aware?** ·
**Recurring?** · Recurrence Description · **Priority?** · **Criteria?** · Created On ·
Updated On · Modified By.

This account's three events are `Lunch (10:30am to 3pm)`, `Breakfast` and `Burger` —
each a Layout on one TV, `Always`, unlimited. (They named the meal into the event title
instead of using a daypart, which is worth remembering: the feature exists and the shop
still did it by hand.)

**Schedule Event** is a four-step wizard — **1 Content · 2 Displays · 3 Time ·
4 Optional** — plus a **Duplicate** button on an existing event. Read straight off the
form, every field:

**1 Content** — `eventTypeId`:
`Layout | Command | Overlay Layout | Interrupt Layout | Campaign | Action | Video/Image |
Playlist | Synchronised Event | Data Connector`, then the matching picker
(`campaignId` / `mediaId` / `playlistId` / `syncGroupId` / `commandId` / `dataSetId`),
plus **Preview** ("Preview your selection in a new tab"). An **Action** event carries an
`actionTriggerCode` — *"Web hook trigger code for this Action"* — i.e. an outside system
can push a layout onto a screen.

**2 Displays** — `displayGroupIds[]`, *"Please select one or more displays / groups for
this event to be shown on."* One event, many screens.

**3 Time**
- `dayPartId`: **Always | Custom** (+ any named daypart) —
  *"Select the dayparting information for this event. To set your own times select
  custom and to have the event run constantly select Always."*
- `fromDt` **Start Time** / `toDt` **End Time**.
- `relativeTime` **Use Relative time?** — *"Switch between relative time inputs and Date
  pickers for start and end time"* — then `hours` / `minutes` / `seconds`, i.e. "run for
  the next 2 hours" instead of picking a clock time.
- `shareOfVoice` **Share of Voice** — *"The amount of time this Layout should be shown,
  in seconds per hour"* — plus `shareOfVoicePercentage` **As a percentage**.

**4 Optional**
- `name` — *"Optional Name for this Event (1-50 characters)"*
- `layoutDuration` **Duration in loop** — *"Set how long this item should be shown each
  time it appears in the schedule. Leave blank to use the Media Duration set in the
  Library."* (duration defaults live on the media, the event only overrides)
- `resolutionId`, `backgroundColor` — *"Optionally set a colour to use as a background
  for if the item selected does not fill the entire screen."*
- `displayOrder` — *"the order this event should appear in relation to others when there
  is more than one event scheduled"*
- `isPriority` **Priority** — *"events with the highest priority play in preference to
  lower priority events"*
- `maxPlaysPerHour` — *"Limit the number of times this event will play per hour on each
  display. For unlimited plays set to 0."*
- `syncTimezone` **Run at CMS Time?** — *"When selected, your event will run according to
  the timezone set on the CMS, otherwise the event will run at Display local time."*
  **This is the timezone question, answered as a per-event switch.**
- `recurrenceType` **Repeats**: `None | Per Minute | Hourly | Daily | Weekly | Monthly |
  Yearly`; `recurrenceRepeatsOn[]` = Monday…Sunday for Weekly;
  `recurrenceMonthlyRepeatsOn` — *"Should this Event Repeat by Day of the month (e.g.
  Monthly on Day 21) or by a Weekday in the month (e.g. Monthly on the third Thursday)"*;
  `recurrenceDetail` **Every**; `recurrenceRange` **Until** — *"Leave empty to Repeat
  indefinitely."*
- `isGeoAware` **Geo Schedule?** — *"select an area by drawing a polygon or rectangle
  layer on the map"* (+ hidden `geoLocation`).

### 9.3 Default Layout — what plays when nothing is scheduled

`/layout/view` lists layouts with **Status (Published)**, Duration, Thumbnail, Owner,
Sharing, **Valid?**, Stats?. One row is the **Default Layout**, described by the product
itself as:

> *"Displays need a default layout to show when no content is available, or when there is
> a problem with the scheduled content."*

It is also settable per display (**Display row menu → Default Layout**). That is the
answer to our open "what shows during a schedule gap?" question, and it also covers the
error case, not just the gap.

The other layouts are `breakfast-static` / `breakfast-video`, `burger-static` /
`burger-video`, `lunch-static` / `lunch-video` — a static and a video variant per meal
period. Layouts have a **draft → Published** lifecycle and a **Valid?** flag the CMS sets.

### 9.4 Displays

`/display/view` — a **table** with a **folder tree** beside it. Columns: ID · Display ·
Display Type · Status · **Authorised?** · Logged In · Last Accessed · **MAC Address** ·
Remote · **Faults?**. Filters include **XMR Registered?**, Tags, Display Group, Display
Profile. Button: **Add Display (Code)** — same pairing-code idea as ours.

Note that **Authorised** is separate from logged-in: a device can connect and sit waiting
for a human to authorise it. Ours folds the two together (the code IS the authorisation),
which is simpler and fine for a shop.

**Display row menu** — the operational depth is all here:
Manage · Edit · Delete · **Authorise** · **Default Layout** · Select Folder · Check
Licence · Schedule · Jump to Scheduled Layouts · Assign Files · Assign Layouts ·
**Request Screen Shot** · **Collect Now** · Trigger a web hook · **Purge All** · Display
Groups · Share · **Wake on LAN** · **Send Command** · Transfer to another CMS.

**Manage** (`/display/manage/{id}`) is a per-device diagnostics page headed
*"Display PalefaceMarket-Tv1 is currently logged-in, seen 3 minutes ago."* and containing:

- **File Status – Count of Files** and **File Status – Size of Files**, two pies of
  Downloaded vs Missing.
- **Reported Player Faults**: Code · Reason · Date · Expires — the player reports its own
  errors back to the CMS.
- **Dependencies** (player APK, fonts, bundle), **Layouts**, **Media**, **Widgets**,
  **Widget Data** — each with Size / **Complete** / **Downloaded**, so "did the TV
  actually finish downloading the new poster?" is answerable at a glance.
- **Bandwidth** with a From/To date filter.

### 9.5 Display Setting Profiles (per platform)

`/displayprofile/view` — one profile per platform: **Android · ChromeOS · webOS (LG) ·
Linux · Tizen (Samsung SSSP) · Windows**. Settings worth stealing, grouped as the form
groups them:

- *general*: **Collect interval** (their poll interval is a setting, ours is hard-coded
  30 s) · XMR WebSocket / Public Address (their push channel) · **Enable stats
  reporting?** + Aggregation level · Record geolocation on each Proof of Play.
- *network*: **Download Window Start/End Time** — fetch big files only in a chosen window
  · **Update Window Start/End** (Android) · Force HTTPS · **Operating Hours** ·
  Authentication Whitelist.
- *advanced*: **Duration for Empty Layouts** · Maximum concurrent downloads · Screen Shot
  Size · Expire Modified Layouts · Notify current layout.
- Android adds: Licence Code · Password Protect Settings · **Restart Wifi on connection
  failure?** · Orientation · Screen Dimensions · **Start during device start up?** ·
  **Automatic Restart** · Start delay · **Use CMS time?** · WebView plugin/caching.

### 9.6 Library, users, reporting, audit

- **Library** (`/library/view`) — a table: ID · Name · Type · **Thumbnail** ·
  **Duration** · Size · **Owner** · **Sharing** · File Name · **Stats?** · **Expires**.
  Filters: Tags, Owner, Owner User Group, Type, Retired, **Layout ID**, Orientation.
  Buttons **Add Media** and **Add media (URL)** — the CMS fetches a file from a URL.
  Duration is a property of the *media*, overridable per scheduled event (§9.2).
  **Stats?** is a per-file proof-of-play toggle.
- **Users** (`/user/view`) — Username · Homepage · **Home folder** · Email ·
  **Library Quota** · Retired?, filtered by **User Type**. Their isolation is
  folders + user groups; ours is stores. Per-user storage quota is an idea we do not have.
- **Reporting** (`/report/view`), grouped:
  - *Audit*: API Requests History · Session History
  - *Display*: Display Statistics: Bandwidth (chart) · **Time Connected** ·
    **Time Connected Summary** · **Display Alerts**
  - *Proof of Play*: Proof of Play (**Export**) · Proof of Play (report) ·
    **Chart: Summary by Layout, Media or Event** · **Chart: Distribution by …**
  - *Library*: Library Usage
  - plus **Report Schedules** and **Saved Reports** — a report can be generated on a
    schedule and kept.
- **Audit Trail** (`/audit/view`) — ID · Date · User · **Entity** · **Entity ID** ·
  **IP Address** · Message · **Object** (a magnifier showing the changed fields), with
  From/To date, user, entity, entity-id, IP and message filters, and **Export**.
- **Dashboard** (`/statusdashboard`) — tiles **3 Displays · 99.3 MiB Library Size ·
  2 Users · 3 Now Showing**; **Bandwidth Usage (Limit 6144 MiB)** as a monthly bar chart;
  **Library Usage (Limit 1.5 GiB)** as a pie; a **Display Activity** table (Display /
  Logged In / Authorised); **Display Status** and **Display Content Status** donuts,
  "Click on the chart for a breakdown". Note the two hard limits — like AbleSign, the
  metered resources are **storage and bandwidth**, not screens.

### 9.7 Concepts they have that have no equivalent in our model

- **Layout** — a composed screen (regions/zones, widgets: clock, text, weather, RSS,
  DataSets, Menu Boards), not a single full-screen file. Our playlist item is one file;
  theirs can be a whole designed page. This is the biggest architectural gap, and also
  the biggest scope.
- **Campaign** — an ordered set of layouts scheduled as one thing.
- **Display Group** and **Sync Group** — grouping for scheduling, and for frame-accurate
  multi-screen sync.
- **DataSets / Menu Boards** — structured data driving the content (a price list that
  updates everywhere at once). Directly relevant to a food shop.
- **Commands / Send Command / Wake on LAN** — control the hardware, not just the content.
- **Modules / Module Templates** — a plugin system.

---

## 10. What this changes for our schedule phase

Both products agree on the shape, and where they differ, Digital Lifts is the better
teacher:

1. **Two levels of time, not one.** A screen has operating hours; an item has its own
   window inside them. AbleSign builds both; we have neither.
2. **Name the window, reuse it.** Digital Lifts' **daypart** beats AbleSign's
   seven-rows-per-item. A shop types "Breakfast 07:00–11:00" once.
3. **`end < start` means it crosses midnight.** Settled, no extra field.
4. **One base window + per-weekday exceptions** is less typing than seven equal rows.
5. **Timezone belongs to the screen**, and Digital Lifts adds a per-event
   *"Run at CMS Time?"* escape hatch. Our stores have no timezone column at all — that is
   the first migration this phase needs.
6. **Gaps need a default.** *"Displays need a default layout to show when no content is
   available, or when there is a problem with the scheduled content."* Not just gaps —
   errors too.
7. **Priority + display order** decide who wins when two things want the same slot.
8. **Recurrence is worth having but is the expensive half.** AbleSign ships
   `Never / 2 weeks / month / year / Custom`; Digital Lifts ships a full RRULE. Layers 1
   and 2 are worth far more per hour of work than layer 3.
9. **Advertising needs Share of Voice + Max plays per hour + proof of play** — three
   fields and a report. Worth knowing now, since this branch is called `ads-feature`.
10. **Retire, don't delete.** *"Retire? It will no longer be visible when scheduling"* —
    a window still referenced by live events must not vanish.

