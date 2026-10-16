# Billing — the first step (owner, 2026-10-07 and 2026-10-08)

Billing itself (Stripe, payments, invoices, a limit on screens) comes later, in about six months. This step builds what an
organization and the platform need to see and decide until then, so that Stripe later only has to flip what is built here.

The owner's words: "One display is free but gama add will be block to unlock gama ads pay $10", "Make your ads in ad builder
or upload your own ads in media library for FREE", "ek screen free hongi aur us ko ek aur screen chiya toh $5 per month honga",
premium templates "$10", "har organization k pass billing ka button ho ... organization mein billing profile k ander ho jese
sub ki honti ha professional kaam chiya", "har organization ki row per edit k barabar mein", "abhi sub k liya premium khula rakho".

## 1. What costs what

| Item | Price | Notes |
|---|---|---|
| The first screen | Free | The screen paired first (`paired_at`, then `id`). |
| Every further screen | $5 a month | Counted, never refused, until billing starts. |
| Premium Templates | $10 a month | Every platform ad for every organization, used as the organization's own copy (Use This Template). |
| Platform Channels | $10 a month | Every channel the platform makes for every organization (GAMA and any other). |
| The organization's own ads, uploads and channels | Free | Whatever the organization makes itself, or the platform makes for that one organization — a channel too: Add Channel above the organizations has an Organization list (All organizations, or one), 2026-10-08. |

Each $10 is a subscription of its own, monthly (owner, 2026-10-10: "montly $10 ka ha subscription ha 2no feature ki 10$"): while a feature is unlocked it counts to the month's estimate (`features_monthly`, beside `screens_monthly`, making `monthly_total`), on Settings → Billing and in the platform's Billing dialog, which sums it as its switches stand before Save. The prices live once, in `App\Services\BillingSummary`.

## 2. Data

- `organizations.premium_templates_unlocked` and `organizations.platform_channels_unlocked` — booleans, **default true** (the
  owner: everything open for now, a new organization too). Migration `2026_10_08_100000`, guarded, reversible.
- `media.copied_from_id` — the platform library row an organization's row was copied from (§5); NULL otherwise, and again once
  that row is gone. Migration `2026_10_08_100100`.
- Permissions (migration `2026_10_08_100300`): **View Billing** (`billing-view`, an organization permission: inside an
  organization its own Billing tab; on a platform role every organization's Billing, read-only) — granted to Super-Admin and to the
  Owner and Admin roles by key; **Change Billing** (`billing-update`, a platform permission, never on an organization's role: the two
  switches) — granted to Super-Admin.

## 3. Inside an organization: Settings → Billing

A tab of Settings (`<x-settings-tabs>`, after Profile and Organizations), `GET /settings/billing` (`billing.show`,
`can:billing-view`, inside the organization worked in, behind `verified` and `organization.active`), shown to a member whose role
there holds View Billing (`User::hasBillingTab()`). A page, like every Settings tab:

- a note of what holds whatever the platform has switched: "Billing has not started yet, so nothing is charged." — never that
  every feature is open (2026-10-15: it said so over a locked one); which features are unlocked is said under **Features**;
- **Estimated every month** — the screens' total, with its sum (first screen free, N × $5);
- **Features** — Premium Templates and Platform Channels, each with its price and Unlocked / Locked; the organization's own ads and
  uploads, Free;
- **Screens** — every screen, when it was paired, and what it costs a month (the first Free), with the total;
- **Invoices** — none yet;
- **Questions or unlocking** — the contact email and phone from `config('signage.billing_contact_email' / '…_phone')`
  (`SIGNAGE_BILLING_EMAIL`, `SIGNAGE_BILLING_PHONE` in `.env.example`, empty until the owner gives them: then "Contact us").

## 4. Above the organizations: Billing on each organization's row

The Organizations page gets **Billing** beside Edit on every row (`can.billing` on the row: View Billing above the organizations).
It opens a dialog: the same summary from `GET /organizations/{organization}/billing` (`can:global-tier`, `can:billing-view`), and —
for whoever also holds Change Billing — a switch for each feature with Save Changes: `PUT /organizations/{organization}/billing`
(`can:global-tier`, `can:billing-update`, both booleans required), logged `organization.billing_updated` ("Locked Premium Templates for
Smart Stop", "Unlocked Platform Channels for …"), answered with the new summary. Without Change Billing the dialog only reads.

## 5. The Content Library is the organization's own

Owner, 2026-10-07: what the platform makes for every organization no longer reaches a screen's Content Library — it is a Premium
Template (copied) or a platform channel. `Media::playableOn` is the screen's organization's library alone; the playlist save refuses
any other row, naming a platform one ("… is the platform's, and the Content Library offers your own files alone. Take its line out.").
A platform file still reaches a television inside a channel. A file the platform uploads for ONE organization is that organization's
own.

**What was there before** (migration `2026_10_08_100200`): every playlist line naming a platform library row becomes the organization's
own — a platform ad's page through Use This Template (TemplateCopier: the organization's own ad with its own files) and Publish
(AdPublisher), one copy per template per organization however many lines name it; a plain platform picture or video copied into the
organization's library — and the line keeps its place, seconds and schedule. `media.copied_from_id` names what each came from, so
`down()` points the lines back and takes the copies away. On live (2026-10-08, after the owner cleaned up): Smart Stop's four screens
(Breakfast, Lunch, Burger, Tortas), each copy keeping the template's name.

## 6. The locks (point 2, approved from the mockups)

Nothing is hidden; it is shown with a lock. Both buttons open ONE dialog, `<x-billing.unlock-dialog>` (heading "Unlock Premium
Templates" or "Unlock Platform Channels", the $10, the contact lines, Close).

- **Premium Templates locked:** the gallery opens, posters and Preview work, a yellow note "Premium Templates are locked for
  {organization}", a Premium badge on every poster and **Contact Us to Unlock** in place of Use This Template. The server refuses
  `POST /builder/templates/{ad}` (403 with the same words). Copies made before stay the organization's.
- **Platform Channels locked:** the screen's Channels tab shows the platform's channels with a Premium badge, Show Ads works,
  **Unlock** in place of Add; the organization's own channels are free. The server refuses a playlist save or a copy to other screens
  that would put a platform channel on a screen that does not carry it yet (422, the line named). A line already there **stays on
  the playlist and plays nothing** (owner, 2026-10-08, from the mockup): the screen's answer and its offline timeline leave it out
  (`ScheduleResolver`), so a screen with nothing else shows its holding picture, or black; the playlist draws it in amber with a
  Premium badge, "locked: not playing on the screen", a note above the lines naming it, and only **Unlock** (the same dialog) and ×.
  Unlocked again, nobody touches the playlist: it plays on the screen's next answer, within 30 seconds.

## 7. Tests

`BillingTest` (the summary's sums, the first screen free, the tab and who sees it, the platform's dialog and its switches, the
log), `BillingAttackTest` (an organization unlocking itself, a platform role without Change Billing, another organization's billing, ids
and shapes), `ContentLibraryIsOwnTest` (the picker and the save), `PlatformLinesMigrationTest` (both ways), `PremiumLocksTest` (both
locks, server and payloads, and a locked line left out of the TV's answer and timeline), and in the browser `BillingFlowTest` (the tab, the platform dialog pressed hard, the locked gallery and
Channels tab, the unlock dialog, the locked line with ×, ↑ and ↓ double-pressed, a phone) — plus the lists of `EveryPageRendersTest`, `EveryButtonWorksTest`,
`EveryPageFitsAPhoneTest` and `EveryQuestionSurvivesADoubleClickTest`.

## 8. Extra storage (owner, 2026-10-15)

The owner: "kisi ko extra 1 gb dena ho toh uska 512mb full ho jaye toh kese bharao?" Asked how it should be given and what it
costs, the answers were: a number of GB, and free for now, only shown.

- **Data:** `organizations.extra_storage_gb` stores whole GB as an unsigned small integer, default 0. It comes from migration
  `2026_10_15_100000`, which is guarded and reversible. It is not in `$fillable`: only the platform sets it.
- **The wall:**
  - `OrganizationStorage::limitOf($extraGb)` is 512 MB plus the extra GB, and `limitFor($organizationId)` gives one
    organization's wall.
  - Every door that asks the wall reads it this way: `withRoom` and the opening of a chunked upload read it from the row they
    lock, while `assertRoomFor`, `TemplateCopier` and `summary()` read it fresh, so the meters show it too.
  - The refusal names the organization's own wall: "… left of its 1.5 GB".
  - Sizes from a GB up are said in GB on both sides (`OrganizationStorage::inWords`, `bytesInWords`). `DiskGuard`'s own copy of
    this was folded into it.
- **Giving it:**
  - It is set in the Billing dialog on the Organizations page, under the two switches, through the field "Extra storage (GB)".
    The field takes a whole number from 0 to `OrganizationStorage::MAX_EXTRA_GB` (50), and Change Billing alone may set it.
  - `PUT …/billing` takes `extra_storage_gb`. When it is left out, the extra stays as it was, because a page from before still
    sends the switches alone. Anything else is refused with "Extra storage is a whole number of GB, from 0 to 50.", under the
    field.
  - The dialog says the storage as it stands: "Uses 300 MB of 1.5 GB: 512 MB included and 1 GB extra, free for now".
  - When the new wall is below what the organization already holds, the dialog warns before the save: nothing is deleted, but it
    cannot upload until it deletes files to fit.
  - Each change is logged as `organization.billing_updated`, for example "Set extra storage to 1 GB (1.5 GB in all) for Alpha Mart"
    or "Removed the extra storage (512 MB in all) for …".
  - The dialog is `persistent` now that it has a field, and a switch counts as a change there (`modal.js`, `aria-checked`).
- **Reading it:**
  - With View Billing alone, the dialog shows "+1 GB" or "Included".
  - Settings → Billing has a Storage card: what is used of the wall, with the bar, Included 512 MB, Extra "1 GB, free for now",
    and "Need more room? Contact us below."
  - The month's sum lists "1 GB extra storage: Free for now". It adds nothing to the total.
- **Tests:** `ExtraStorageTest`, with additions in `BillingAttackTest` and `BillingFlowTest`.
