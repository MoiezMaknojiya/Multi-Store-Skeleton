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
| Premium Templates | $10 | Every platform ad for every organization, used as the organization's own copy (Use This Template). |
| Platform Channels | $10 | Every channel the platform makes for every organization (GAMA and any other). |
| The organization's own ads, uploads and channels | Free | Whatever the organization makes itself, or the platform makes for that one organization — a channel too: Add Channel above the organizations has an Organization list (All organizations, or one), 2026-10-08. |

Whether each $10 is monthly or paid once is decided when Stripe comes. The prices live once, in `App\Services\BillingSummary`.

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

- a note that billing has not started: nothing is charged, everything open for now;
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
  that would put a platform channel on a screen that does not carry it yet (422, the line named); a line already there keeps playing
  and stays on the playlist (owner's billing talk, to be looked at again when Stripe comes).

## 7. Tests

`BillingTest` (the summary's sums, the first screen free, the tab and who sees it, the platform's dialog and its switches, the
log), `BillingAttackTest` (an organization unlocking itself, a platform role without Change Billing, another organization's billing, ids
and shapes), `ContentLibraryIsOwnTest` (the picker and the save), `PlatformLinesMigrationTest` (both ways), `PremiumLocksTest` (both
locks, server and payloads), and in the browser `BillingFlowTest` (the tab, the platform dialog pressed hard, the locked gallery and
Channels tab, the unlock dialog, a phone) — plus the lists of `EveryPageRendersTest`, `EveryButtonWorksTest`,
`EveryPageFitsAPhoneTest` and `EveryQuestionSurvivesADoubleClickTest`.
