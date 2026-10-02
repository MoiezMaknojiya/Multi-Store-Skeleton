# Schedule phase — spec

Owner ke saath tay shuda design. Scout ki buniyad `docs/COMPETITOR-SCOUT.md` hai.

Ye do cheezein banti hain:

1. **Daypart** — waqt ki ek named, dobara istemal hone wali window ("Breakfast 07:00–11:00").
2. **Schedule rule** — ek playlist item kis **din** aur us din ke **kis waqt** chalega. Har item ke kai rules ho sakte hain.

Plus screen par do cheezein jinke **against** rules parhi jati hain: **timezone**, aur jab
kuch bhi due na ho to **default media**.

> Screen ke apne operating hours pehle design mein the — 2026-09-10 ko hata diye gaye.
> §18 dekho.

> **2026-10-01 — Dayparts hata diye gaye.** Waqt ab rule par hi likha jata hai, schedule ki window
> ke andar: *All day*, ya *From … To …*. Neeche §2 (Daypart), §6 ki do daypart tables, §9 ke daypart
> endpoints, §10 ka Dayparts page aur faisle #1, #9, #11 ab **tareekh** hain. Aaj ka model §21 mein hai —
> pehle woh parho.

> **As built — 2026-09-17 tak sahi kiya gaya.** Ye document design ka hai, magar neeche
> ke "kya bana" hisse code se milaye gaye hain. Jo badla: manifest ke `blank` ka matlab
> (§8), activity log ke asal action names aur endpoints (§9), resolver ka asal API (§12),
> test files ke asal naam aur counts (§13, §16, §17), aur playlist par **channel line**
> ka naya hissa (§19). Migrations is cleanup mein squash ho gayin (21 files), isliye
> `add_schedule_columns_to_screens_table` aur
> `remove_screen_operating_hours_from_screens_table` ab maujood nahi — `screens` seedha
> `create_screens_table` mein `timezone` + `default_media_id` ke saath banti hai, aur un
> hataye hue columns ka koi nishan nahi.

---

## 1. Tay shuda faisle

> **2026-09-10 — design badla.** Owner ka faisla: **screen ke apne operating hours
> khatam.** Waqt sirf **file par** set hota hai, screen ke andar ja kar — "ye poster,
> Jumma, 11:00 se 15:00". Wajah: dukan khuli reh sakti hai jab andar ka deli band ho,
> aur deli ke hours har file par waise bhi dobara likhne parte. Ab ek hi jagah likha
> jata hai. Screen par sirf wo do cheezein bachi hain jinke **against** schedule parhi
> jati hai: **timezone** aur **default media**. Tafseel §18 mein.

| # | Faisla |
|---|---|
| 1 | ~~Daypart named + reusable hai — ek dafa banao, kai screens/items par lagao~~ — 2026-10-01: waqt rule par likha jata hai (§21) |
| 2 | Timezone **screen** par, default `America/Chicago`, owner badal sakta hai |
| 3 | Gap mein **default media** chalti hai, **screen** par set hoti hai |
| 4 | ~~Layer 1 (screen hours)~~ **+** Layer 2 (item window) **+** Layer 3 (recurrence) — §18 |
| 5 | Schedule **har screen par alag** (`playlist_items` par), + copy button |
| 6 | **Organization-level hours nahi** — organization khula reh sakta hai jab andar ka deli band ho |
| 7 | Rules na hon to item **24 ghante** chalta hai |
| 8 | Kuch due na ho → default media; wo bhi na ho → **kaali screen** (§18) |
| 9 | ~~`Dayparts` ka sidebar mein apna page, + jahan choose karte ho wahin inline "New"~~ — page hata (§21) |
| 10 | Schedule modal mein **"agle 7 din"** ka preview |
| 11 | ~~Daypart **retire** hota hai, delete nahi (live rules us par lage hote hain)~~ — daypart hi nahi raha (§21) |
| 12 | `Copy` = **poori playlist** (items + duration + schedules) target screen par replace; confirm modal pehle dikhaye ke kya mitega |

### Timezone ke baare mein ek baat

`America/Chicago` IANA zone hai — ye **CST aur CDT dono khud sambhalta hai**. Owner ko saal
mein do dafa ghari badalne ki zarurat nahi. Daypart ke waqt hamesha **wall-clock** hain
(yaani "subah 7 baje" ka matlab wahan ki ghari par 7 baje), isliye DST khud-ba-khud theek
rehta hai.

---

## 2. Daypart

Waqt ki ek window, jiska naam hai.

```
"Breakfast"        07:00 – 11:00
"Lunch"            11:00 – 15:00
"Evening"          16:00 – 20:00
"Deli hours"       07:00 – 20:00,  Sunday 09:00 – 16:00
"Late night"       22:00 – 02:00       ← end < start = aadhi raat cross
```

Do usool:

- **`end_time < start_time` ka matlab hai aadhi raat cross karna.** Koi alag field nahi.
  `22:00 – 02:00` yaani raat 10 se agli subah 2 baje tak.
- **Exceptions** — base window har din chalti hai; jis din alag ho, us din ki row daal do.
  Row mein waqt khali chhoro = **us din bilkul band**.

Daypart **organization ki cheez** hai — `Media` ki tarah `visibleTo` sirf organization par (creator par
nahi), kyunki "Deli hours" dukan ka common asset hai, kisi ek shakhs ki milkiyat nahi.

Daypart **delete nahi hota** jab tak koi playlist rule us par laga ho — `retire` karo.
Retired daypart naye pickers mein nahi dikhta, magar purane rules chalte rehte hain.

---

## 3. Screen par kya hai *(hours nahi — §18)*

Screen apne hours nahi rakhti. Us par sirf do cheezein hain, dono is liye ke **rules
inke against parhi jati hain**:

| Column | Kaam |
|---|---|
| `timezone` | rules **wall clock** hain — "Jumma 11:00" ka matlab wahi 11 baje jahan TV lagi hai |
| `default_media_id` | jab kuch bhi due na ho to kya chale; khali chhoro to kaali screen |

Deli ka waqt badle → **"Deli hours"** daypart ek jagah edit karo, jitne files us par lagi
hain sab badal jayen.

---

## 4. Item ka schedule rule

Har playlist item ke **0 ya zyada rules**. Item chalega agar **koi bhi ek rule** haan kahe
(OR). Rules bilkul na hon → item **hamesha** chalta hai, chobis ghante.

Ek rule ke do hisse, jo bilkul alag hain:

| Hissa | Sawal | Options |
|---|---|---|
| **DIN** | kaunse calendar days? | hamesha · date range · repeat (daily / weekly / monthly-day / monthly-weekday / yearly, har N par, `until` tak) |
| **WAQT** | us din ke andar kaunse ghante? | poora din · ek daypart · (daypart hi rakha hai, ad-hoc waqt ke liye "New daypart" inline) |

### Misal — `eid-offer.mp4`

| Rule | DIN | WAQT |
|---|---|---|
| A | 20 Mar – 22 Mar, koi repeat nahi | Evening (16:00–20:00) |
| B | Har Friday, hamesha | Lunch (11:00–15:00) |

Natija: Eid ke teen din **shaam ko**, aur har Jumma **dopeher ko**. Ek hi file, ek hi
playlist item, do rules.

---

## 5. Resolver — chalega ya nahi

Har item ke liye **ek** sawal. **Rule na kahe → item band.**

```
Koi rule haan kehta hai?   rules khali hon to khud-ba-khud haan
                           (rules screen ke timezone par parhi jati hain)
```

Sirf ek cheez rule ke haan kehne par bhi nahi chalti: Ad Builder ka woh page jo **unpublish** ho chuka ho.

> **2026-10-01 se file ki apni koi date nahi** (owner: "srif naam rakho"). Pehle `media.starts_at` /
> `media.expires_at` bhi poocha jata tha; ab Media Library mein file ka sirf naam hai (Rename), aur kab
> chalegi ye sirf playlist line ke rules batate hain. Dono dates aur description ke columns usi din hata diye gaye
> (`2026_10_01_200800_create_media_table` mein ab hain hi nahi).

Sab resolve **server par** hota hai, `DeviceController::playlist` mein — hamare maujooda
usool ke mutabiq, taake sasti TV ki ghalat ghari se farq na pare. Device sirf wo list dekhta
hai jo abhi chalni hai. Poll 30 second ka hai, to boundary par 30 sec ke andar switch.

> Upar ka pehla sawal **file** ka hai. Channel line ka pehla sawal alag hai, aur uska ek
> extra usool bhi hai — §19 dekho.

### Poora chalta hua example

**Screen:** `Deli TV` · timezone `America/Chicago` · default media **`welcome.jpg`**

**Playlist:**

| Item | Duration | Rules |
|---|---|---|
| `breakfast.jpg` | 10s | Breakfast (07:00–11:00), koi date limit nahi |
| `lunch.jpg` | 10s | Lunch (11:00–15:00) |
| `eid-offer.mp4` | 20s | **A:** 20–22 Mar + Evening (16:00–20:00) · **B:** har Friday + Lunch |
| `branding.jpg` | 8s | Deli hours (07:00–20:00, Sunday 09:00–16:00) |

**Jumma, 20 March:**

| Waqt | TV par kya chal raha hai |
|---|---|
| 06:00 | **kaali** — koi item due nahi *(default media hoti to wo chalti)* |
| 07:30 | `breakfast.jpg` · `branding.jpg` |
| 12:00 | `lunch.jpg` · **`eid-offer.mp4`** *(Rule B: Friday + Lunch)* · `branding.jpg` |
| 15:30 | `branding.jpg` akeli |
| 17:00 | **`eid-offer.mp4`** *(Rule A: 20 Mar + Evening)* · `branding.jpg` |
| 21:00 | **kaali** — Deli hours 20:00 par khatam |

**Jumma, 27 March** — 12:00 baje:

- Rule A ki dates (20–22 Mar) guzar chukin ❌
- Rule B kehta hai: Friday hai, Lunch ka waqt hai ✅

→ **`eid-offer.mp4` chalegi**, kyunki ek rule haan kehta hai. Use 25 March ke baad band karna ho
to Rule B par `ends_on = 25 Mar` lagao: file ki apni koi date nahi hoti (2026-10-01).

**Default media kab chalti hai:** jab har item filter ho jaye. Upar 06:00 aur 21:00 par
`welcome.jpg` chalti — kyunki us waqt koi bhi item due nahi. Set na ho to **kaali screen**
(§18 mein teeno "kuch nahi" ka farq).

---

## 6. Database

### `dayparts` (nayi)

| Column | Type | Note |
|---|---|---|
| `id` | id | |
| `organization_id` | FK organizations, cascade | organization ki cheez, nullable **nahi** |
| `name` | string(100) | `unique(organization_id, name)` |
| `start_time` | time | |
| `end_time` | time | `end < start` = aadhi raat cross |
| `is_retired` | bool, default false | delete nahi, retire |
| `created_by` | FK users, nullOnDelete | |
| timestamps | | |

### `daypart_exceptions` (nayi)

| Column | Type | Note |
|---|---|---|
| `daypart_id` | FK dayparts, cascade | |
| `weekday` | tinyint 1–7 | ISO: 1 = Monday … 7 = Sunday |
| `start_time` | time, **nullable** | dono null = **us din band** |
| `end_time` | time, nullable | |
| | | `unique(daypart_id, weekday)` |

### `screens` (nayi columns)

| Column | Type | Default |
|---|---|---|
| `timezone` | string(64) | `America/Chicago` |
| `default_media_id` | FK media, nullOnDelete, nullable | `null` = kaali |

> *As built:* ye alag "add columns" migration mein nahi hain — squash ke baad dono
> `2026_10_01_200900_create_screens_table` ke andar hi bante hain.

### `playlist_items` (schedule ka doosra malik — §19)

Ek line ya **file** hai (`media_id` + `duration_seconds`) ya **channel** (`channel_id`; table `channels` ke baad banta hai, `2026_10_01_201300_create_playlist_items_table` mein). Dono mein se
theek ek set hota hai, aur rules **line** par lagte hain — file ho ya channel.

### `schedule_rules` (nayi)

| Column | Type | Note |
|---|---|---|
| `playlist_item_id` | FK playlist_items, cascade | |
| `daypart_id` | FK dayparts, nullOnDelete, nullable | `null` = poora din |
| `starts_on` | date, nullable | `null` = hamesha se |
| `ends_on` | date, nullable | `null` = hamesha tak |
| `recurrence_type` | string, nullable | `daily` `weekly` `monthly_day` `monthly_weekday` `yearly` · `null` = seedhi date range |
| `recurrence_interval` | tinyint, default 1 | "har N" |
| `recurrence_weekdays` | json, nullable | `[1,5]` — weekly ke liye |
| `recurrence_monthday` | tinyint, nullable | `21` — monthly_day ke liye |
| `recurrence_ordinal` | tinyint, nullable | `3` = teesra, `-1` = aakhri |
| `recurrence_weekday` | tinyint, nullable | `4` = Thursday — monthly_weekday ke liye |
| `recurrence_until` | date, nullable | `null` = hamesha dohrao |
| `position` | uint | sirf dikhane ki tarteeb |

---

## 7. Ek zaroori baat — playlist ka whole-list PUT

Hamara playlist save **poori list ek PUT mein replace** karta hai. Agar rules
`playlist_items` par latke hain, to har save par cascade unhein maar dega.

**Hal:** rules PUT ke payload ke saath hi jayenge — bilkul waise jaise AbleSign ka schedule
modal **OK** kehta hai, **Save** nahi. Schedule playlist mein stage hoti hai aur playlist ke
apne "Save changes" ke saath commit hoti hai. Whole-list replace atomic rehta hai.

```jsonc
PUT /screens/{screen}/playlist
{
  "version": "a1b2c3d4e5f6g7h8",
  "items": [
    {
      "media_id": 12,
      "duration_seconds": 20,
      "rules": [
        { "daypart_id": 4, "starts_on": "2026-03-20", "ends_on": "2026-03-22" },
        { "daypart_id": 2, "recurrence_type": "weekly", "recurrence_weekdays": [5] }
      ]
    }
  ]
}
```

**`Screen::playlistFingerprint()` mein rules bhi shamil karne parenge** — warna do log ek
hi item ki schedule badlen aur 409 na aaye, khamoshi se ek ka kaam mit jaye.

Magar ehtiyat: fingerprint **stored** config par hash karta hai (media_id, position,
duration, rules) — **resolved** natije par nahi. Warna har daypart boundary par owner ko
jhoota *"someone else changed this"* milta.

---

## 8. Device manifest

`GET /device/playlist` — jaisa **bana** (`DeviceController::playlist`):

```jsonc
{
  "screen":      { "id": 3, "name": "Deli TV", "orientation": "landscape" },
  "server_time": "2026-03-20T12:00:00+00:00",
  "blank":       false,   // matlab neeche
  "version":     "a1b2c3d4e5f6g7h8",   // poore manifest ka fingerprint
  "items":       [ ... ],  // sirf wo lines jo ABHI eligible hain (har ek par apna `checksum`)
  "ad_break":    { "every_seconds": 3600, "items": [ ... ] }  // network advertising
}
```

**`blank: true` ka matlab badal gaya hai** (§18 ke baad): "screen apne hours se bahar hai"
nahi — ab iska matlab hai **playlist mein lines hain, abhi koi due nahi, aur default media
bhi nahi** (`ScheduleResolver::resolve()` → `blank`). Khali playlist par `blank` **false**
rehta hai, kyunki wahan "No content" sach aur kaam ka hai (§18 ka teen-matlab table).

- `blank: true` → player kaali screen dikhaye, "No content" ka text bhi nahi
  (`document.body.classList.toggle('closed', …)` — `resources/js/player.js`).
- `items` khali + default media set (aur wo unpublish hua Ad Builder page na ho) → server default media ko
  ek aam item bana ke bhej deta hai, `id: 0` ke saath. **Player ka code badalne ki zarurat
  nahi.**
- Har item par `checksum` — id + size + updated_at se bana cache key, content digest nahi.
- `version` alag cheez hai `Screen::playlistFingerprint()` se: ye manifest ka hai (blankness,
  orientation aur ad break bhi isme hain), wo **stored** playlist ka (§7).

---

## 9. Endpoints

Jaisa **bana** (`routes/web.php` — route names bhi wahin se):

```
GET    /dayparts                             dayparts.view                can:daypart-view
GET    /dayparts/data                        dayparts.data                can:daypart-view
POST   /dayparts                             dayparts.store               can:daypart-store
PUT    /dayparts/{daypart}                   dayparts.update              can:daypart-update
DELETE /dayparts/{daypart}                   dayparts.destroy             can:daypart-destroy

PUT    /screens/{screen}                     screens.update               can:screen-update
GET    /screens/{screen}/media-options       screens.media-options        can:screen-update
GET    /screens/{screen}/playlist            screens.playlist.view        can:screen-view
PUT    /screens/{screen}/playlist            screens.playlist.update      can:screen-playlist
POST   /screens/{screen}/playlist/preview    screens.playlist.preview     can:screen-playlist
GET    /screens/{screen}/playlist/copy-targets  screens.playlist.copy-targets  can:screen-playlist
POST   /screens/{screen}/playlist/copy       screens.playlist.copy        can:screen-playlist
GET    /screens/{screen}/available-media     screens.available-media      can:screen-playlist
GET    /screens/{screen}/available-channels  screens.available-channels   can:screen-playlist
```

Design mein `dayparts.index` aur `playlist.copy` likha tha; asal naam upar wale hain. Teen
endpoints design mein nahi the aur ban gaye: `playlist/preview` (7-din preview, server par
usi code se), `playlist/copy-targets`, aur `media-options` (default-media picker ka apna
endpoint, `screen-update` ke peeche — capability-complete usool).

**Daypart ki list ke liye naya AJAX endpoint nahi.** Jaise `$orientations` abhi Blade mein
jata hai, waise `$dayparts` bhi jata hai — lekin sirf `ScreenController::show()` se
(`ScreenController::daypartOptions()`), kyunke schedule editor sirf screen ke page par hai.
Isse capability-complete permissions ka masla khud hal ho jata hai — page ka apna permission
hi kaafi hai.

Har write par `visibleTo()->findOrFail()`, aur har mutation par `ActivityLog::record()`.
Asal action names (design ke `screen.hours_updated` / `playlist.schedule_updated` /
`playlist.copied` **kabhi nahi likhe gaye**):

| Action | Kahan |
|---|---|
| `daypart.created` · `daypart.updated` · `daypart.deleted` | `DaypartController` |
| `screen.paired` · `screen.repaired` · `screen.updated` · `screen.deleted` | `ScreenController` |
| `screen.playlist_updated` | `PlaylistController::update` (rules isi ke saath) |
| `screen.playlist_copied` | `PlaylistController::copy` |

Jahan subject mit chuka ho (delete), entry ko organization `organizationId:` argument se milta hai —
`screen.deleted` aur `daypart.deleted` aise hi likhe jate hain.

---

## 10. UI

### Dayparts page (sidebar mein naya)

Maujooda CRUD ka wahi shakl — table + modal, `x-crud.*` components, search, pagination.
Modal mein: Name\* · Start\* · End\* · "Retired" checkbox · Exceptions ki rows
(`[Day ▾] [start] [end] [×]` + "Add exception").

Modal ke neeche plain English summary, taake owner ko yaqeen ho:
> *"Har roz 07:00–20:00 · Sunday 09:00–16:00 · Monday band"*

### Screen settings (maujooda Edit modal mein)

Naye fields: **Timezone** (searchable) aur **Default media** (library picker). Bas — hours
yahan nahi, file par hain.

### Playlist item ka schedule (naya modal)

Item ke ⋮ mein **`Schedule`**:

```
◉ Always                                          ← default
○ Only at certain times

    ┌────────────────────────────────────────────────┐
    │ Days  [Date range ▾]   20 Mar → 22 Mar         │
    │ Time  [Evening (16:00–20:00) ▾]          [ × ] │
    │       Every day from 20–22 Mar, 16:00–20:00    │
    ├────────────────────────────────────────────────┤
    │ Days  [Repeat ▾]  weekly · Fri · forever       │
    │ Time  [Lunch (11:00–15:00) ▾]            [ × ] │
    │       Every Friday, 11:00–15:00                │
    └────────────────────────────────────────────────┘
    + Add another

    Next 7 days:  Fri 20 Mar 11:00–15:00 · Fri 20 Mar 16:00–20:00 ·
                  Sat 21 Mar 16:00–20:00 · Sun 22 Mar 16:00–20:00
                                                        [Cancel] [OK]
```

- **OK**, Save nahi — playlist ke apne "Save changes" ke saath commit.
- Poora recurrence builder `Repeat` ke **andar** chhupa hai. Upar sirf `Always` aur
  `Date range` — 90% owner isse aage nahi jayenge.
- Har rule ke neeche plain English summary.
- **Next 7 days** preview — recurrence expander waise bhi likhna hai, ye usi ka doosra istemal.

### Playlist row par nishan

Jis item par schedule ho, us par ek chhota **⏱ badge** + summary line. Aur jo item **abhi**
eligible nahi, wo greyed dikhe.

---

## 11. Validation

**Hard errors** (save rukega):

- `starts_on > ends_on`
- `recurrence_until < starts_on`
- `weekly` chuna magar koi weekday nahi
- `monthly_day` chuna magar `monthday` 1–31 se bahar
- `monthly_weekday` chuna magar ordinal ya weekday khali
- `interval` 1–52 se bahar
- daypart ka naam organization mein pehle se maujood
- exception mein sirf ek waqt bhara (ya dono, ya dono khali)

**Warnings** (save hoga, magar owner ko bataya jayega) — ye wo hain jo owner ne maange:

| Halat | Message |
|---|---|
| `recurrence_until` guzar chuka | ⚠️ *"Is rule ka waqt guzar chuka hai."* |
| ~~Screen ke hours hain magar default media nahi~~ | **khatam** — screen ke hours hi nahi rahe (§18) |

Warning **rokti nahi**. (File ki expiry wali warning 2026-10-01 ko khatam: file ki apni date hi nahi rahi.)

> *As built:* hard errors sab lage hue hain (`PlaylistController::writeItems` +
> `resources/js/core/validate.js`), magar ye warning messages **server se nahi aate**. Channel
> line par `channelWarning()` amber mein batata hai (`resources/views/screens/show.blade.php`).
> Baqi warnings abhi baqi kaam hain.

---

## 12. Service

`app/Services/ScheduleResolver.php` — jaisa **bana**. Service par sirf **ek** public method
hai; `screenIsOpen()` aur `ruleMatches()` kabhi likhe hi nahi gaye (pehla §18 mein khatam ho
gaya, doosra model par chala gaya):

```php
// App\Services\ScheduleResolver
resolve(Screen $screen, ?CarbonInterface $at = null): array
// ['blank' => bool, 'items' => Collection<PlaylistItem>, 'fallback' => ?Media, 'local_time' => CarbonImmutable]
// private fallbackFor(Screen $screen, CarbonImmutable $instant): ?Media
```

Faisla lene wale methods models par hain, resolver unhein bulata hai:

```php
App\Models\PlaylistItem::isDueAt(CarbonInterface $localMoment): bool   // rules khali = haan
App\Models\ScheduleRule::coversAt(CarbonInterface $moment): bool
App\Models\ScheduleRule::coversDay(CarbonInterface $moment): bool
App\Models\ScheduleRule::occurrences(CarbonInterface $from, int $days = 7): array  // 7-din preview
App\Models\ScheduleRule::fingerprint(): string
App\Models\Daypart::coversAt() · windowFor(int $isoWeekday) · openWindowDay() · crossesMidnight() · isInUse()
App\Models\Screen::localTime(?CarbonInterface $at = null): CarbonImmutable
```

Do baatein jo yahan ahem hain:

- `resolve()` **ek hi lamha** poore manifest ke liye parhta hai (`$instant`), warna ek line jis
  ka daypart loop ke beech band ho raha ho ek hi jawab mein andar aur bahar dono ho sakti thi.
- `DeviceController::playlist` isi ko call karta hai, aur `occurrences()` UI ke preview ko bhi
  feed karta hai (`PlaylistController::preview`) — ek hi logic, do jagah, isliye test ek hi
  jagah.

---

## 13. Tests

> **Ye plan tha; asal files kam aur mukhtalif hain.** `DaypartOvernightTest`,
> `DaypartExceptionTest`, `ScheduleRuleValidationTest`, `DevicePlaylistScheduleTest` aur
> `PlaylistFingerprintTest` **kabhi banaye nahi gaye** — un ka kaam upar wali chaar files
> aur `PlaylistConcurrencyTest` mein simat gaya. Neeche jo hai wo asal coverage hai
> (counts `tests/` se ginay gaye).

**Pest (feature)** — `tests/Feature/`

| File | Tests | Kya |
|---|---|---|
| `DaypartCrudTest` | 18 | organization wall, unique name, retire, delete jab in-use ho |
| `DaypartWindowTest` | 10 | `end < start` ka aadhi raat cross · exceptions (Sunday alag waqt, Sunday bilkul band) |
| `ScheduleRuleTest` | 16 | paanchon recurrence types, `occurrences()`, hard errors |
| `ScheduleResolverTest` | 13 | boundary ke waqt, timezone, DST, "2 baje chalu hui TV" |
| `PlaylistScheduleTest` | 15 | rules PUT ke saath · doosre organization ka daypart refuse · reorder par rules na maren |
| `ScreenScheduleTest` | 12 | device manifest sahi filter kare · `blank` flag · default media |
| `PlaylistConcurrencyTest` | 6 | rules badlein → fingerprint badle → 409; identical save conflict nahi |
| `ChannelManifestTest` | 14 | channel line ka schedule, screen ke local date par (§19) |
| `PlaylistChannelTest` | 20 | channel line save/read, organization wall (§19) |

**Dusk** — `tests/Browser/`

| File | Tests | Kya |
|---|---|---|
| `DaypartUiTest` | 2 | daypart banao, exception daalo, summary sahi dikhe |
| `ScheduleUiTest` | 4 | rule set karo, 7-din preview dekho, OK → Save → reload par bacha ho; asli TV ka kaala honta |
| `PlaylistFlowTest` | 7 | playlist banane ka poora flow, Copy modal |
| `PlaylistChannelUiTest` | 2 | channel line add karo aur save karo (§19) |

---

## 14. Kya **nahi** ban raha

Ye scout mein mile magar is phase se bahar hain:

- Screen groups / display groups
- Screen tags aur unse filtering
- Proof of play (playback report)
- Offline alerts
- Website / URL as a playlist item
- Transitions, shuffle
- Media folders

---

## 15. Tarteeb — chaar phase, har ek alag se chalne layak

| Phase | Kya | Kyun pehle |
|---|---|---|
| **1 ✅** | `dayparts` + `daypart_exceptions`, CRUD page, permissions, tests | baqi sab isi par khara hai |
| **2 ✅** | Screen ke hours + timezone + blank + default media, resolver ka "khuli/band" hissa | akela hi kaam ka hai — raat ko TV band |
| **3 ✅** | `schedule_rules`, item ka modal, resolver ka "rule" hissa, 7-din preview | asal faida yahan hai |
| **4 ✅** | Copy playlist (+ confirm modal jo batata hai kya mitega) | polish |

Har phase ke baad Pest + Dusk chalenge aur localhost par live verify hoga.

---

## 16. Phase 1 — kya bana (mukammal)

| Cheez | Kahan |
|---|---|
| Migrations | `create_dayparts_table`, `create_daypart_exceptions_table` |
| Models | `Daypart` (`coversAt`, `windowFor`, `crossesMidnight`, `syncExceptions`, `WEEKDAYS`), `DaypartException`, trait `Concerns\HasClockTimes` |
| Request | `DaypartRequest` (create + update, ek hi rules) |
| Controller | `DaypartController` (index · data · organization · update · destroy) |
| Routes | `/dayparts` CRUD, har ek apni `can:daypart-*` ke peeche |
| Permissions | `daypart-view/store/update/destroy` — migration `2026_10_01_202300_insert_permissions_and_starter_roles` mein, starter roles ko (Owner aur Admin ko charon, Staff aur Viewer ko `daypart-view`) |
| UI | `dayparts/index.blade.php` + `dayparts-table.js` + sidebar link |
| Tests | `DaypartCrudTest` (18) · `DaypartWindowTest` (10) · Dusk `DaypartUiTest` (2) |

**Phase 1 mein ek asli bug mila aur likh diya gaya:** Blade ka `@json` bina flags ke encode
karta hai, to uske quotes raw rehte hain aur `x-data="…"` attribute pehli string par hi
band ho jata hai — Alpine ko aadha expression milta hai aur poora component khamoshi se mar
jata hai (koi error page nahi, bas khali table). Hal: `{{ Js::from([...]) }}`. Rule
`.claude/rules/02-project-conventions.md` mein daal di gayi hai.

**Phase 2 mein pehla kaam:** daypart ka delete tab refuse karna jab koi screen ya rule us par
laga ho — abhi kuch us par lagta hi nahi, isliye guard ka koi matlab nahi tha. *(Ho gaya —
`Daypart::isInUse()`.)*

---

## 17. Phase 2, 3, 4 — kya bana (mukammal)

### Migrations
| | |
|---|---|
| ~~`add_schedule_columns_to_screens_table`~~ | squash ke baad khatam — `timezone` (default `America/Chicago`) aur `default_media_id` ab `create_screens_table` ke andar hain. *(`daypart_id` aur `blank_when_closed` bhi daale the — §18 mein hata diye, aur squash ne un ka nishan bhi mita diya.)* |
| `create_schedule_rules_table` | per-item rules: daypart + date range + poora recurrence |

### Code
| Cheez | Kahan |
|---|---|
| `ScheduleRule` | `coversAt` · `coversDay` · `occurrences` · `fingerprint` · paanchon recurrence patterns |
| `Daypart::openWindowDay()` | window kis DIN shuru hui — aadhi raat ke aar paar ka jawab |
| `Daypart::isInUse()` | in-use daypart delete nahi hota, retire honta hai |
| `Screen` | `localTime()` · `defaultMedia()` · `playlistFingerprint()` mein rules *(`isOpenAt()` aur `daypart()` §18 mein gaye — model par nahi hain)* |
| `PlaylistItem::isDueAt()` | rules khali = haan; warna koi ek rule haan kahe |
| `ScheduleResolver::resolve()` | ek hi public method — `blank`, `items`, `fallback`, `local_time` ka faisla (§12) |
| `DeviceController::playlist` | manifest mein `blank` flag; fallback `id: 0` wala aam item ban ke jata hai |
| `PlaylistController` | PUT ke saath rules · `preview` · `copy` · `copyTargets` · `availableMedia` · `availableChannels` |
| `ScreenController` | `timezone` + `default_media_id` (dono `sometimes`) · `mediaOptions` · `daypartOptions` *(hours/blank nahi)* |
| UI | screens Edit modal (Timezone + Default media) · playlist item ka Schedule modal + 7-din preview · Copy modal |
| Player | `body.closed` — ab "kuch due nahi aur default media nahi" par poori kaali, "No content" nahi |

### Tests
`ScheduleRuleTest` (16) · `ScheduleResolverTest` (13) · `PlaylistScheduleTest` (15) ·
`ScreenScheduleTest` (12) · `PlaylistConcurrencyTest` (6) · Dusk `ScheduleUiTest` (4, jismein
asli TV ka kaala honta bhi). Poori list §13 mein.

### Do baatein jo design ka natija hain
1. **Rules PUT ke saath jate hain.** Alag endpoint hota to har reorder par cascade unhein
   maar deta. `PlaylistScheduleTest` mein ye regression alag se test hai.
2. **Fingerprint mein rules bhi hain**, warna do log ek hi item ke hours badlen aur 409 na
   aaye — ek ka kaam khamoshi se mit jaye.

---

## 18. Screen ke operating hours hataye gaye (2026-09-10)

Owner ka faisla. Screen ab apne hours nahi rakhti — waqt sirf **file par** set hota
hai, screen ke andar ja kar.

**Wajah:** dukan 24 ghante khuli reh sakti hai jab andar ka deli 8 baje band ho jaye.
Aur agar deli ke hours screen par bhi hon aur file par bhi, to ek hi baat do jagah
likhi jati — aur do jagah likhi hui baat kabhi na kabhi ek doosre se ulat jati hai.

### Kya hata

| Cheez | Kahan tha |
|---|---|
| `screens.daypart_id` | column — `remove_screen_operating_hours_from_screens_table` ne hataya; 2026-09-17 ke squash ne wo migration bhi mita di, ab `create_screens_table` mein ye column banta hi nahi |
| `screens.blank_when_closed` | wahi migration, wahi anjaam |
| `Screen::isOpenAt()` · `Screen::daypart()` | model — ab maujood nahi |
| screen ke khule/band hone ka hissa | service — `ScheduleResolver` par ab sirf `resolve()` hai (§12; `screenIsOpen()` naam ka method kabhi ship nahi hua) |
| "Operating hours" + "Go dark" | Screen Edit modal |
| Listing mein "Deli hours · 07:00–20:00" | ab wahan **timezone** likha hai |

### Kya bacha — aur kyun

| Cheez | Kyun zaroori hai |
|---|---|
| `screens.timezone` | file ke rules **wall clock** hain. "Jumma 11:00" ka matlab wahi 11 baje jahan TV lagi hai |
| `screens.default_media_id` | jab kuch bhi due na ho to kya chale |
| ~~Dayparts~~ | 2026-10-01 tak file ke rule ka "kis waqt se kis waqt tak" yahi tha; ab rule khud kehta hai (§21) |
| Schedule rules | poora feature isi ka hai |

### "Kuch nahi" ke ab teen matlab hain

Ye ahem hai: TV par ghalat "kuch nahi" dikhana kharab lagta hai.

| Halat | TV par | Kyun |
|---|---|---|
| Playlist **khali** hai | "No content — waiting for a playlist" | naye paired screen ke liye ye sach aur kaam ka hai |
| Items hain, **abhi koi due nahi**, default media **hai** | default media | din ke gap mein dukan apni branding dikhati hai |
| Items hain, **abhi koi due nahi**, default media **nahi** | **poori kaali**, koi text nahi | raat 3 baje "No content" likha TV kharab lagta hai; kaala TV band lagta hai |

Manifest ka `blank: true` ab **teesri** surat ka matlab hai (pehle "screen ke hours ke
bahar" tha).

### Owner ke sawal ka jawab — TV 9 baje band, 2 baje khula

Chalta hai, aur pehle se chal raha tha. Wajah: **schedule server par resolve honti
hai**, TV par nahi. Jab TV 2 baje chalu ho kar `/device/playlist` maangti hai, jawab
**usi lamhe** ka hota hai — subah ka kuch yaad nahi rakha jata aur koi "catch up" nahi
hoti. Iske do test hain:

- `ScheduleResolverTest` → *a television switched on at two in the afternoon gets the two-o'clock playlist*
- `ScreenScheduleTest` → *a television switched on at two in the afternoon is handed the two-o'clock playlist* (device manifest se)

### Ek nateeja jo jaan lena chahiye

Raat ko TV **khud** kaali nahi hoti — wo tab kaali honti hai jab us waqt ke liye kuch
schedule na ho **aur** default media set na ho. Yaani agar organization din ke gap mein branding
dikhana chahti hai **aur** raat ko kaala, to abhi dono ek saath nahi ho sakte: default
media chabis ghante chalega. Zarurat pare to `22:00 – 06:00` jaisa waqt de kar
branding par ulta rule lagaya ja sakta hai — magar wo abhi banaya nahi gaya.

---

## 19. Channel lines — is document ke baad aaya hissa (2026-09-15 ke baad)

Ye document likhte waqt playlist par sirf **files** thin. Channels baad mein bane, aur
schedule ka poora system un par bhi lagta hai — isliye ye document channels ke baghair
**mukammal nahi parha jana chahiye**. Tafseel `.claude/rules/02-project-conventions.md`
("Channels") mein hai; yahan sirf itna jo schedule se juda hai:

- **Line do shakl ki hoti hai.** `playlist_items` par ya `media_id` + `duration_seconds`
  set hai (file), ya `channel_id` (channel) — theek ek. `channel_id`
  `2026_10_01_201300_create_playlist_items_table` mein hai, aur channel line ke paas
  seconds nahi hote: wo utni der chalti hai jitni us din ki ads.
- **Rules line par lagte hain, file ho ya channel.** `schedule_rules` ka malik
  `playlist_item_id` hai, isliye §4 ka poora rule builder channel line par waise hi chalta
  hai.
- **Resolver ka pehla sawal channel ke liye alag hai** (`ScheduleResolver::resolve()`):
  file ke liye "file zinda hai?" hai, channel ke liye "channel on-air hai aur aaj ki
  tareekh mein koi ad hai?" (`Channel::liveAdsOn()`, screen ke **local** date par).
  Uske baad:

  | Channel line | Kab chalti hai |
  |---|---|
  | apna schedule rakhti hai | usi schedule par, bilkul file ki tarah |
  | koi schedule nahi | jab organization ki **apni** koi file due ho — taake jo screen organization ne jaan kar kaali rakhi hai, channel use roshan na kar de |
  | koi schedule nahi **aur** playlist par koi file live hi nahi | phir bhi chalti hai — sirf channels wali screen kaali rakhne ko nahi kehti |

- **Manifest mein ek entry jati hai**, item list ke andar (`DeviceController::channelEntry`):

  ```jsonc
  { "id": "p12", "type": "channel", "per_pass": 2,
    "ads": [ { "id": "p12-a3", "type": "image", "url": "…", "checksum": "…",
               "duration": 10, "mime": "image/jpeg" } ] }
  ```

  Ads **poori** jati hain, ek pass tak kaati hui nahi: sirf player ko pata hota hai ke ek
  pass kab khatam hua, isliye `per_pass` ko player hi ghumata hai (`null` = har pass mein
  saari ads).
- **Fingerprint mein channel line prefix hoti hai** — `Screen::playlistFingerprint()`
  `'ch'.$channel_id` likhta hai, warna channel 7 aur file 7 ek jaise parhe jate.
- **Organization wall wahi hai:** ek screen sirf platform ke channels aur apne hi organization ke channels
  utha sakti hai (`Channel::availableTo`, `PlaylistController::assertChannelsAreAvailable`,
  warna 422).

Tests: `ChannelManifestTest` (14) · `PlaylistChannelTest` (20) · Dusk `PlaylistChannelUiTest` (2).

---

## 20. Folder layout (2026-09-17)

Is spec mein controller/request/JS ke naam chhote likhe hain; 2026-09-17 ke layout ke baad ye kahan rehte hain:

| Spec mein | Asli jagah |
| --- | --- |
| `ScreenController` · `PlaylistController` · `MediaController` · `ChannelController` · `ChannelAdController` (`DaypartController` 2026-10-01 ko gaya) | `app/Http/Controllers/Signage/` |
| `StoreMediaRequest` · `UpdateMediaRequest` · `ChannelRequest` · `ChannelAdRequest` (`DaypartRequest` 2026-10-01 ko gaya) | `app/Http/Requests/Signage/` |
| `DeviceController` | `app/Http/Controllers/Device/` |
| `CampaignController` · `NetworkAdsController` (+ `CampaignRequest`) | `app/Http/Controllers/Advertising/`, `app/Http/Requests/Advertising/` |
| `validate.js` · `crud-table-base.js` · `playlist-defaults.js` · `media-file.js` | `resources/js/core/` |
| `media-table.js` · `screens-table.js` · `channels-table.js` (`dayparts-table.js` 2026-10-01 ko gaya) | `resources/js/tables/` |
| `screen-playlist.js` · `channel-ads.js` | `resources/js/pages/` |
| `player.js` · `app.js` | `resources/js/` (Vite ke do entry points) |

Schedule ke tests `tests/Feature/Signage/` mein hain (`ScheduleResolverTest`, `ScheduleRuleTest`, `PlaylistScheduleTest`,
`ScreenScheduleTest`, `ScheduleRuleTimesTest`, `ChannelManifestTest`), aur playlist/media par hone wale hamle
`tests/Feature/Security/` mein (`OrganizationWallAttackTest`, `InputAbuseAttackTest`, `FileUploadAttackTest`,
`DeviceApiAttackTest`). Naming aur folder ke usool `.claude/rules/02-project-conventions.md` mein likhe hain.

---

## 21. Dayparts hataye gaye — waqt ab rule par hai (2026-10-01)

Owner ka sawal: *"dayparts ko hata k kahi Playlist mein jab schedule set karte ha waha nahi dal sakte? ya koi
better option batao"* — phir *"srif Xibo nahi aur bhi ha unka bhi pata karo aur jo best ho woo batao"* — phir
*"theek ha kardo"*.

### Kya dekha

23 signage products ke docs dekhe ke waqt kahan aur kaise set hota hai:

| Tareeqa | Products |
|---|---|
| Waqt item ya playlist ke schedule mein hi likha jata hai | Yodeck, ScreenCloud, OptiSigns, NoviSign, Rise Vision, Signagelive, TelemetryTV, BrightSign, Scala, Poppulo, embed signage, Samsung VXT, Navori, Appspace, Screenly, Fugo, Look, Mvix, piSignage, Pickcel, AbleSign |
| Naam wale dayparts ki alag library | Xibo — aur wahan bhi "Custom" chun kar waqt wahin likha ja sakta hai |
| Day parts har screen ke apne andar (ads network) | Broadsign |

Screenly ne July 2026 mein apna schedule dobara banaya aur wohi shakal chuni jo hamari thi — din + waqt ke rules,
kai rules ek saath, saada lafzon ka khulasa — sirf waqt wahin likha jata hai. Hamare yahan waqt ke liye doosre page
par ja kar naam wala daypart banana parta tha (aur local database mein ek bhi rule kisi daypart par laga hua nahi tha).

### Naya model

Rule ke do hisse wahi hain (§4), sirf WAQT badla:

| Hissa | Sawal | Options |
|---|---|---|
| **DIN** | kaunse calendar days? | hamesha · date range · repeat — jaisa tha |
| **WAQT** | us din ke andar kaunse ghante? | **poora din**, ya **`start_time` se `end_time` tak** — rule par hi likha hua |

- `schedule_rules.start_time` / `end_time` — `time`, nullable. **Dono NULL = poora din.** Ek ke baghair doosra nahi
  (`required_with`), aur dono barabar nahi (`different`).
- **`end_time < start_time` ka matlab aadhi raat cross** — jaisa daypart mein tha. Aur wohi baat jo §5 mein thi:
  raat 12:30 par jo ghante khule hain wo **kal** shuru hue the, isliye rule ke din **us din** ke against parhe jate
  hain (`ScheduleRule::coversAt` → `openWindowDay`). "Jumma ki raat 22:00–02:00" Hafte ki subah 00:30 par chalta hai.
- **Kisi din alag waqt** (daypart ki exception): usi line par **ek aur rule** — line tab chalti hai jab **koi bhi**
  rule haan kahe. "Deli: Mangal se Hafta 07:00–20:00, Itwaar 09:00–16:00, Peer band" = do rules:
  weekly `[2,3,4,5,6]` 07:00–20:00, aur weekly `[7]` 09:00–16:00. Peer kisi rule mein nahi, to band.
- Timezone wahi screen ka (§1) — waqt wall clock hai.

### UI (schedule ki window)

```
Days  [Every day ▾]
Time  [Between times ▾]   11:00 → 15:00            Runs past midnight.   ← sirf jab end < start
      Every day · 11:00 AM – 3:00 PM                                    [Remove]

[+ Add a Schedule]  [Copy to Other Lines]
```

- **Time**: `All day` ya `Between times` — doosre par do `type="time"` khaane (`rule-start-time-N`, `rule-end-time-N`).
  Days wali line ki hi shakal.
- **Weekdays / Weekends** — weekly repeat mein din ke buttons ke baad do quick picks (`setWeekdays`): Peer–Jumma,
  ya Hafta–Itwaar, jo tick the unki jagah.
- **Copy to Other Lines** — jo cheez naam wala daypart deta tha (ek tabdeeli, kai lines) uska badal. Window ke andar
  doosri lines ki list khulti hai (`otherLines`, `copyToKeys`, Select All); **OK** is line ke saath ticked lines ko bhi
  yehi schedule deta hai — har ek ki apni copy, unke purane schedule ki jagah. Sab kuch page par staged, `Save Changes`
  tak (jaise har edit). Screens ke darmiyan `Copy to Other Screens` pehle se hai.
- **Ended** — jis line ke **saare** rules ka aakhri din guzar chuka ho (date range ka `ends_on`, repeat ka
  `recurrence_until`), us par `Ended` ka pill (`scheduleEnded`). "Aaj" screen ka apna hai: playlist ke GET aur PUT
  dono `local_date` bhejte hain — browser ki tareekh kabhi nahi. Aadhi raat cross karne wale ghante aakhri din ki agli
  subah tak chalte hain, isliye aisa rule ek din baad "Ended" hota hai.
- **Next 7 days** server se hi aata hai (wohi code jo TV ko jawab deta hai), magar **adhoora rule poocha hi nahi jata**: waqt ka ek
  khaana khali ho ya weekly repeat mein koi din na ho to us ki wajah preview ki jagah likhi aati hai (`ruleProblems`), aur
  server ko aisi request nahi jati jo woh sirf 422 se lautata. Jawab aane tak heading "working…" kehti hai, "Nothing in the
  next 7 days" nahi.
- Validation window mein, rule ke neeche, server ke hi lafzon mein: *"Time: give both a start and an end, or choose All
  day."* · *"Time: the start and the end cannot be the same. To run past midnight, set an end earlier than the start."* ·
  adha likha waqt: *"Time: enter the whole time, or choose All day."*

### Kya hata

| Cheez | Kahan tha |
|---|---|
| Dayparts page + sidebar link | `resources/views/dayparts/index.blade.php`, `dayparts-table.js`, `/dayparts` ke 5 routes |
| `DaypartController`, `DaypartRequest`, `Daypart`, `DaypartException`, `DaypartFactory` | — |
| `dayparts`, `daypart_exceptions` tables · `schedule_rules.daypart_id` | migration ne hataye (neeche) |
| `GET /screens/{screen}/daypart-options` | playlist page ab koi list nahi mangta |
| 4 permissions: `daypart-view/store/update/destroy` | catalogue 41 → 37. Jo playlist badal sakta hai (`screen-playlist`) wohi waqt set karta hai |
| `PlaylistController::assertDaypartsBelongToTheSameOrganization` | doosre organization ka hawala ab ho hi nahi sakta |

### Migration — `2026_10_02_100000_put_the_time_on_the_schedule_rule`

Jo rule kisi daypart par laga tha, us mein wohi waqt khud likh diya jata hai — TV par kuch nahi badalta:

1. **Pehle parho, pehle inkaar.** Daypart ke har din ke ek jaise ghante: rule ko wahi ghante mil jate hain. Kuch dinon
   ke alag ghante (ya band din): rule **har alag ghanton ke liye ek rule** ban jata hai, un dinon par weekly — pehla
   usi row par, baaqi usi line par us ke baad. Jo rule kabhi chal hi nahi sakta tha (daypart us din band) wo "kabhi
   nahi" hi rehta hai (weekly, koi din nahi) — rule ke baghair line chabis ghante chalti.
2. Do cheezein is tarah nahi kahi ja saktin, aur migration **kuch badle baghair ruk jati hai**, rule ka naam le kar:
   aisa repeat jo "sirf in dinon" nahi keh sakta (har N din, mahine ki tareekh, saal mein ek baar) alag-ghanton wale
   daypart par; aur aisa daypart jis ka ek din pichhli raat ke band hone se pehle khul jata ho, jab rule dinon mein farq
   karta ho.
3. `down()` tables, column aur permissions wapas deta hai — har (organization, ghante) ka ek daypart, ghanton ke naam se.

Saboot: `ScheduleTimesMigrationTest` 63 joron (rule ke din × daypart) ke liye do mahine ke **har aadhe ghante** par purane
rows ka jawab — purane models ke usoolon se nikala hua — migration ke baad wali line ke jawab se milata hai.

### Tests

| File | Kya |
|---|---|
| `ScheduleRuleTimesTest` (naya, 10) | ghanton ka hisab: kinare, aadhi raat, "jis din khule", do rules = alag din, timezone, DST, fingerprint |
| `ScheduleTimesMigrationTest` (naya, 9) | upar wala saboot, split rows, inkaar, wapsi |
| `ScheduleRuleTest`, `ScheduleResolverTest`, `PlaylistScheduleTest`, `ScreenScheduleTest`, `ChannelManifestTest`, `OfflineTimelineTest` | dayparts ki jagah rule ke apne ghante; `changePoints` ab rules ke waqt se |
| `ValidationWordsTest`, `InputAbuseAttackTest` | waqt ke inkaar ke lafz, aur waqt har ghalat shakal mein |
| Dusk `ScheduleUiTest` | waqt likhna, quick picks, aadhi raat ka note, Copy to Other Lines, Ended |
| gaye | `DaypartCrudTest` (18), `DaypartWindowTest` (10), Dusk `DaypartUiTest` (2) |

### Jo cheez kam hui

Pehle "Deli hours" ek jagah badalne se us par lagi har line badal jati thi. Ab ek line ka schedule badal kar
**Copy to Other Lines** dabana hota hai (aur doosri screens ke liye Copy to Other Screens). 21 products isi tarah chalte hain.
