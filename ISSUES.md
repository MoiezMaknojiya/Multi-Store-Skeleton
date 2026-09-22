# Known Issues & Improvements Backlog

> Full-project audit — 2026-09-03. **Status: CLOSED-OUT.** Every item below now carries a final status: ✅ FIXED, 🤝 NEEDS OWNER INPUT (blocked on a decision or credentials — not a hidden defect), or 📐 ACCEPTED DEVIATION (deliberate, with rationale). Nothing is left un-triaged. Suite at close-out: **162 backend + 12 browser tests, all passing.**
>
> **Superseded in part (2026-09-16):** the people model was rebuilt as store-as-organization — power from membership, email invitations, no `created_by` ownership of people (`docs/STORE-ORGANIZATION-SPEC.md`). Items 1, 4, 5, 11 and 15 describe the model as it was on 2026-09-03 and carry a ↪ note below; the rest still stands.
>
> **Re-checked against the code (2026-09-17).** This stays a record of the audit, but every item below was verified again after the ads/channels work and the cleanup at the foot of this file. Items 6, 7, 8, 11, 12 and 16 had drifted and now carry a ↪ note. Items 9 and 10 stood as accepted deviations whose "rule going forward" was **not being followed**; the owner has since decided both — see "↪ Decided 2026-09-17" under each.

---

## 🔴 Security

### 1. ~~Public registration is open~~ ✅ FIXED (2026-09-03)
- Register routes removed, "Create one" link removed from login, `RegisteredUserController` + register/welcome views deleted, unused `firstSuperAdminId()` removed. `/register` now 404s (test-covered). Accounts are created from inside by admins only.
- ↪ Later reversed: public signup is back (account + store + Owner membership in one form, throttled by the named `signup` limiter).

### 2. ~~Seeder credentials are hardcoded~~ ✅ FIXED (2026-09-03)
- Seeder now reads `SEED_ADMIN_PASSWORD` from env (key added to `.env.example`; test configs pin `test`). When unset, a random 16-char password is generated and printed once by the seeder. ⚠️ Note: your existing LOCAL admin still has the old password `test` until you change it or re-seed.

### 3. ~~`per_page` is unbounded~~ ✅ FIXED (2026-09-03)
- Clamped to 1–100 in `HandlesCrudData`.

### 4. ~~Invite-email flow~~ ✅ CLOSED BY OWNER DECISION (2026-09-03)
- Owner decided against it: passwords are set during onboarding/creation by the admin — final. No code ever existed for the invite flow, so nothing to remove.
- ↪ Superseded 2026-09-16: the owner chose the industry-standard model — people join by email invitation and set their own password; no admin creates accounts or knows a password.

### 5. ~~No audit trail~~ ✅ FIXED (2026-09-03) — per owner decisions
- **Activity Log built:** `activity_logs` table + `ActivityLog::record()` called on every mutation (user/store/role create/update/delete, assign/unassign, onboard, impersonate start/stop). Actor name snapshotted so the trail survives actor deletion. New **Activity Log page** (sidebar, gated by new `activity-view` permission — seeded, granted to Super-Admin, permission count now 20).
- **Soft-delete for users: REJECTED by owner** — hard delete stays, by design.
- **Cascade delete (owner's rule):** deleting a user deletes EVERYTHING they created, recursively — their user subtree, their roles (unless still assigned to a survivor, which then just loses its creator), and the stores they created (soft-deleted). Stores now carry `created_by`; onboarded stores belong to their owner. Fully test-covered.
  ↪ Superseded 2026-09-16: nothing cascades through people any more — deleting an account removes the account and its memberships only, and deleting a store purges everything the store owns.

### 6. ~~Rate limiting only exists on login~~ ✅ FIXED (2026-09-03)
- Admin route group now runs under `throttle:240,1` (240 req/min per user) alongside Breeze's login throttle.
- ↪ 2026-09-17: the bare form is gone. The admin group is `throttle:admin` — a **named** limiter keyed on `admin:{user id or IP}`, still 240/min per person (`routes/web.php`, `AppServiceProvider::registerRateLimiters`). Named on purpose: the bare `throttle:n,1` form keys on domain+IP only for a request with no logged-in user, so every route using it shares one counter per visitor — which is how a shop's TVs once spent the signup form's budget. Every throttle in the app is now named: `signup`, `admin`, `invitations`, `invitation-response`, `device-register`, `device-pair`, `device-api`, `password-reset` (the forgotten-password pair — see the adversarial pass below) and `font-install` (the Ad Builder's font installer). The login form keeps its own 5-attempt counter inside `App\Http\Requests\Auth\LoginRequest` (Breeze's scaffold code, now that the package itself is gone).

---

## 🟡 Consistency / project's own standards

### 7. ~~Two different password standards in one app~~ ✅ FIXED (2026-09-03)
- `UserController` now uses `Rules\Password::defaults()` (registration flow itself is gone — issue 1). One standard everywhere; frontend min-length matches.
- ↪ 2026-09-17: both halves of that sentence have moved on, but the issue stays closed. Public signup is back (`routes/auth.php` → `RegisteredUserController`, throttled by the named `signup` limiter), and `UserController` validates no passwords at all any more — it creates nobody, because people join by invitation and set their own password. `Rules\Password::defaults()` is now the one standard in the four places a password is actually set or changed: `Auth\RegisteredUserController`, `Auth\NewPasswordController`, `Auth\PasswordController` and `InvitationResponseController`. The client mirrors it in `resources/js/pages/register-form.js` and `resources/js/pages/password-form.js`.

### 8. ~~Email verification half-wired~~ ✅ FIXED (2026-09-03) — and ✅ DONE DIFFERENTLY (2026-09-17)
- 2026-09-03: the do-nothing `verified` middleware removed from `/dashboard`. Breeze's verification routes/views stayed as inert scaffold.
- ↪ 2026-09-17, owner's decision: the inert scaffold was **deleted** rather than kept. Gone: the four `Auth\VerifyEmail*` / `EmailVerification*` controllers, `resources/views/auth/verify-email.blade.php`, their routes, `tests/Feature/Auth/EmailVerificationTest.php`, the `email-verification` rate limiter and `UserFactory::unverified()`. There is no `MustVerifyEmail` anywhere.
- `users.email_verified_at` **stays**, and is used: accepting an emailed invitation creates the account already marked verified, because opening the link proved the inbox. Public signup does not verify an address — which is exactly why the dashboard lists no invitations to accept and only the emailed link may accept one.
- The `confirm-password` flow (`Auth\ConfirmablePasswordController`, `auth/confirm-password.blade.php`, `tests/Feature/Auth/PasswordConfirmationTest.php`) went with it. Worth recording **why that is a net gain**: its POST was the one password check in the app that sat outside the shared wrong-password counter. Every re-confirmation now goes through `Concerns\ConfirmsPassword::confirmPassword()`, which keeps one counter per person (`confirm-password:{id}`, 5 wrong attempts a minute, then 429) across every big delete and the password change — so an open session can no longer be used to guess the password through a side door.

### 9. Form Requests not used 📐 ACCEPTED DEVIATION
- **Rationale:** the inline validation works, is consistent across all 5 controllers, and is fully test-covered. Refactoring to Form Requests changes zero behavior while churning every controller — the risk outweighs the gain right now. **Rule going forward:** NEW endpoints with non-trivial validation use Form Requests; existing ones migrate opportunistically when touched.
- ↪ 2026-09-17, **the rule going forward is not being followed.** Stated plainly rather than glossed either way: the branch added 7 Form Requests (`CampaignRequest`, `ChannelRequest`, `ChannelAdRequest`, `DaypartRequest`, `StoreMediaRequest`, `UpdateMediaRequest`, plus the existing `ProfileUpdateRequest`), and at the same time added several controllers with non-trivial validation done inline — `PlaylistController` (3 call sites, including the whole `items.*.rules.*` tree), `NetworkAdsController` (3), `ScreenController` (2), `DeviceController` (2), `StoreSettingsController` (3), `MemberController`, `InvitationController`, `InvitationResponseController`, `PlatformInvitationController`. `ChannelController` does both.
- **Open question for the owner:** either the rule is retired (inline validation is simply how this app validates, and Form Requests are used where a request is reused across create+update), or it stands and those controllers are on a list to migrate. It should not stay half-true. No behaviour is at risk either way — the validation itself is test-covered.
- ↪ **Decided 2026-09-17** (owner — `.claude/rules/01-tech-and-principles.md`, Core Principle 4): the old rule is retired, so none of those controllers is on a list to migrate. A Form Request is used when a rule set is long, shared between endpoints, or carries its own messages or authorization; `$request->validate()` in the action when an endpoint has a handful of rules nothing else needs — which is how most of this app validates, on purpose. Either way the rules live in one place per endpoint, and every rule list whose later rules assume a type begins with `bail`.

### 10. `declare(strict_types=1)` mostly absent 📐 ACCEPTED DEVIATION
- **Rationale:** adding it to 20+ live files can surface hidden type coercions at runtime in paths tests don't cover — a stability risk with no behavior gain. **Rule going forward:** every NEW file gets `declare(strict_types=1)`; existing files adopt it when substantially rewritten.
- ↪ 2026-09-17, **the rule going forward is not being followed either.** Of the 42 new PHP files under `app/` on this branch, exactly **2** declare strict types (`Services/DevicePairing.php`, `Services/MediaStorage.php`) — the other 40 do not, including every new controller, model and Form Request.
- **Open question for the owner:** retire the rule, or schedule a sweep. Adding the declaration to 40 files that already passed 607 tests at the time is far less risky than the original 2026-09-03 case, since these files are new and well covered — but it is still a change with no behaviour gain, and it should be a decision rather than a drift.
- ↪ **Decided 2026-09-17** (owner — `.claude/rules/01-tech-and-principles.md`, Core Principle 3): the rule is retired and no sweep is scheduled. `declare(strict_types=1)` is deliberately not used across the project — request input arrives as strings and ids from route parameters, so switching it on everywhere would trade working coercion for TypeErrors. The three files that carry it (`Services/DevicePairing.php`, `Services/MediaStorage.php`, `Http/Controllers/Platform/ImpersonateController.php`) may keep it; a new file adds it only when it never reads raw request input, never as a sweep. The other half stays mandatory: a type declaration and an honest nullability on every method, property and closure parameter.

### 11. Manual authorization instead of Policies 📐 ACCEPTED DEVIATION
- **Rationale:** the hand-rolled scoping (`visibleTo`, `ensureStoreAccessible`, tier guards) is centralized in models/controllers, matches the three-tier model exactly, and has 170+ tests over it. A Policy refactor is cosmetics with regression risk. Revisit only if the authorization surface grows a lot.
- ↪ 2026-09-16: the people checks now live in `App\Services\StoreTeam` and `ResolvesCurrentStore` (user/role `visibleTo` and `ensureStoreAccessible` are gone); the rationale is unchanged.
- ↪ 2026-09-17, two numbers in that rationale are wrong. The model is **two tiers**, not three: platform (a membership on the sentinel `store_id = 0` carrying a global role, of which `Super-Admin` is one) and store (memberships in real stores), mutually exclusive — `AppServiceProvider::registerTierGates` defines exactly `global-tier` and `super-admin-tier`. And the suite was, at the time, **607 Pest tests** (`tests/Feature`, 605 `test()` declarations, one of them over a 3-case dataset) plus **61 Dusk tests** (`tests/Browser`) — today's counts are in the footer below. The rationale itself still holds, and the authorization surface has grown a lot since — which is the trigger the item named for revisiting it.

---

## 🟠 Performance (invisible now, matters with data growth)

### 12. ~~N+1 queries in listings~~ ✅ FIXED (2026-09-03)
- Users listing batched (one `store_user` query per page, ~60+ → <15 queries); dashboard role names via one `whereIn`; permission checks (`@can`) now share a per-request memoized permission list on the `User` model (`contextPermissionNames()`), cutting 3–4 queries per gate check to a shared one-time load; `isSuperAdmin()`/`globalRole()` memoized likewise. Guarded by two query-count regression tests (`toBeLessThan(15)`).
- ↪ 2026-09-17: the two guards are no longer the same assertion. `tests/Feature/System/DashboardTest.php:37` still asserts `toBeLessThan(15)`; the users-listing guard is now `tests/Feature/Platform/PlatformUsersTest.php:175`, `expect($many)->toBe($few)` — a page of many accounts must cost *exactly* what a page of few costs, which is a stricter statement than a ceiling and catches a re-introduced N+1 that happens to stay under 15.

### 13. ~~Multi-step writes have no DB transactions~~ ✅ FIXED (2026-09-03)
- `RoleController::store/update/destroy` and `StoreController::destroy` now wrap their multi-statement writes in `DB::transaction()`. (Single-statement endpoints need none.)

---

## 🔵 UX / polish

### 14. ~~Errors shown via browser `alert()`~~ ✅ FIXED (2026-09-03)
- Toast notification system added (Alpine store + layout container, `window.toast()`); every `alert()` replaced. Also fixed a real bug found alongside: message-only 422 guard responses (e.g. "Super-Admin cannot be renamed") were silently swallowed by the form error handler — they now surface as toasts with the server's actual message. Dusk-covered.

### 15. ~~Orphaned users after mid-chain deletion~~ ✅ RESOLVED BY CASCADE (2026-09-03)
- Made moot by the owner's cascade rule (issue 5): deleting a user deletes their whole created subtree, so orphans can no longer exist.
- ↪ 2026-09-16: moot for a different reason — nobody is anybody's child any more, so there is no chain to orphan.

---

### 16. ~~Dormant mobile API~~ ✅ CLOSED BY OWNER DECISION (2026-09-03)
- Owner chose deletion: `routes/api.php` and `App\Http\Controllers\Api\` removed. The app is web-only; conventions now say no API routes get added without explicit instruction (and any future API needs validation + throttling + tests from day one). Sanctum stays installed as inert framework scaffold (`HasApiTokens` trait unused — harmless).
- ↪ 2026-09-17: the scaffold is gone too, so nothing of the token API is left. Removed: the `laravel/sanctum` package from `composer.json`, `config/sanctum.php`, the `HasApiTokens` trait on `User`, the `0001_01_01_000003_create_personal_access_tokens_table` migration and the table itself. `tests/Feature/System/DatabaseSchemaTest.php:58` now asserts `Schema::hasTable('personal_access_tokens')` is false, so it cannot creep back unnoticed.
- The web-only rule itself is unchanged, with one deliberate exception that is **not** an API: `routes/device.php` — four stateless endpoints for a television, registered outside the `web` group, each behind a named per-device limiter. See `.claude/rules/02-project-conventions.md` → "Screens & the device API".

## 📋 Deploy-day reminders (not code issues)

- Change the seeded super admin password immediately (see issue 2).
- Configure a real mailer. Not only password resets any more: **invitations are now the only way anybody joins a store or the platform team**, so with no working mailer nobody can be added at all. `Invitation::sendLink()` reports a refusing mail server instead of throwing (the response carries `email_sent: false` and the invitation stands for Resend), which makes a broken mailer visible rather than fatal — but it is still broken. Keys live in `.env.example` (`MAIL_*`).
- **`php artisan storage:link` — required, and was missing from this list.** Uploaded media and channel files live on the `public` disk under `storage/app/public/`, and the player fetches them over HTTP; without the symlink every image and video 404s on a fresh deploy. `config/filesystems.php` also declares a second link (`public/dusk-storage`), created by the same command and harmless outside `APP_ENV=dusk`. The `local` disk is deliberately `'serve' => false` — nothing is served from it, so the framework's `/storage/{path}` route is not registered and the symlink is the only route to a file.
- `composer install --no-dev` → `php artisan migrate` → `php artisan db:seed` → `php artisan storage:link` → `npm ci` → `npm run build` → `php artisan optimize`; web root = `public/`.
- `APP_ENV=production`, `APP_DEBUG=false`.
- Add the Laravel scheduler cron (needed for automatic activity-log partition maintenance): `* * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1`
- Local data (stores/roles/users) does NOT deploy with the code — recreate or export/import the DB.
- ~~Still no git repository — owner will set this up himself.~~ Done: the project is a git repository.

---

## 🧹 Cleanup pass — 2026-09-17 (for the record)

Not audit findings; a deliberate tidy-up landed alongside the channels work. Recorded here because several items above describe what it removed.

- **Migrations squashed to 21 files.** The upgrade migrations written during the store-organization rebuild (merging same-named store roles, dropping `store-transfer`, removing the soft-deleted stores and `stores.deleted_at`) and their tests are gone, along with the separate "add schedule columns" / "remove screen operating hours" pair — a fresh install now creates every table in its final shape. Nothing in the tree depends on an intermediate state.
- **Sanctum removed outright** (issue 16), and **Breeze's email-verification and confirm-password scaffolding deleted** (issue 8) — controllers, views, routes, tests, the `email-verification` limiter, `UserFactory::unverified()`. `laravel/breeze` itself is off `composer.json`.
- **Seeders reduced to `DatabaseSeeder`**: `DaypartSeeder` and `BackfillDaypartPermissionsSeeder` removed — the daypart permissions are inserted by migration (`2026_09_16_110200`) and the seeder only puts the Owner role back should it be missing.
- **Dead code removed** across models, controllers, JS and CSS, plus the unused Breeze view components (`dropdown`, `dropdown-link`, `nav-link`, `responsive-nav-link`, `application-logo`) and `resources/js/dropdown.js`. Three npm packages dropped: `@tailwindcss/forms`, `autoprefixer`, `postcss` (Tailwind v4 needs none of them).
- **`GET /roles/{role}/permissions` removed.** A role's permissions arrive with the Roles listing; `GET /roles/assignable` is the one endpoint the form needs, and it answers only what the role in the form may hold.
- **The `local` disk no longer serves anything** — `'serve' => false` in `config/filesystems.php`, so the framework does not register `/storage/{path}` for `storage/app/private`. See the deploy note about `storage:link`.
- **`items.*.rules.*.daypart_id` gained `min:1`** (`PlaylistController::rules`). A posted `0` reads as "no id" to `filled()`, so it would have sailed past the store-wall check and died on a foreign key instead of a 422 — the same reason every other posted id in that payload is `min:1`.
- **Deleting an account now also withdraws the invitations addressed to that email** — `User::booted()`'s `deleted` hook calls `invitationsToEmail()->delete()` alongside removing the person's sessions and password-reset token, so nothing is left pointing at a deleted account and a stale link cannot recreate it.

---

## ✅ Already fixed & test-covered (for the record)

Privilege-escalation holes closed (Super-Admin handout, role permission subsets, direct-ID edits, cross-store actions, global-role minting) · **two-tier** model (platform / store) with mutual exclusivity · role visibility matrix · capability-complete assign endpoints · UI fully permission-gated · double-submit guards everywhere · client-side validation mirroring backend · platform dashboard/stores visibility · **816 Pest tests (96 of them adversarial, 134 for the Ad Builder) + 106 Dusk tests**.

> The old footer read "Ads feature fully removed · three-tier · 12 browser + 163 backend tests". All three were stale: the ads feature (campaigns, network ads, channels) is what this branch **builds** — it was removed once, in 2026-09-03's cleanup, and deliberately rebuilt afterwards — the model is two tiers, and the counts have moved on.

---

## 🕵️ Adversarial pass — 2026-09-17 (attacks, and what they found)

The owner asked for the app to be attacked rather than demonstrated ("as a top hacker test karna, app break karne ka try karna"). Nine files went into `tests/Feature/Security/` — the store wall, privilege escalation, input abuse, uploads, the device API, invitation links, sessions and passwords, the middleware stack, and a sweep of every route as five different kinds of person — and a tenth, `AdBuilderAttackTest`, came with the Ad Builder. **96 attacks in those 10 files today, and the authorization model held on every one of them:** no cross-store read or write, no self-promotion, no Super-Admin look-alike, no invitation replay, no pairing-code theft, no upload the player cannot render, no listing that leaks another store's row, and no route that answers 500 to anybody — after the five defects below were fixed.

**Fixed: `?search[]=x` answered 500 on every listing in the panel.** A query parameter can be any shape; an array reaching a `like` clause is an "Array to string conversion", which Laravel raises as an error. `HandlesCrudData::paginatedResponse` now reads `search`, `page` and `per_page` through a documented `plainValue()` (anything that is not one plain value reads as "not sent", so the listing stays forgiving and the clamp is unchanged), and the two media pickers that read a search term of their own (`ScreenController::mediaOptions`, `PlaylistController::availableMedia`) validate it instead — a bad shape is a clean 422 there.

**Fixed: `?device_uuid[]=x` answered 500 on the open device surface.** The `device-pair` limiter builds its cache key from the query string, and a rate limiter runs BEFORE the endpoint's validation — so `pair-status` never got as far as its own `string` rule. The limiter now falls back to the address for any shape that is not one plain value, exactly as it does for a request with no uuid at all.

**Fixed: a role or channel name posted as an array answered 500.** Laravel runs every rule for an attribute unless the list starts with `bail`, so `name[]=x` failed `string` and then reached the closure that folds the name for comparison, where casting an array to a string raised the error. `RoleController::validated` and `ChannelRequest` now `bail`.

**Fixed: every big delete in the app answered 500 to `password[]=x`.** The same missing word, in the place it mattered most: `ConfirmsPassword::confirmPassword()` validated `['required', 'string', 'current_password']`, and `current_password` hashes whatever it is handed — so a password posted as an array failed `string` and then reached the hasher anyway. It reached every form that re-confirms a password: deleting a store, an account, a role, a member, a channel, a campaign or a permission, taking a platform role, and changing your own password. Now `bail`.

**Fixed: the playlist schedule preview answered 500 to a "rule" that was not an array.** `PlaylistController::preview` validated `rules` as an array but nothing inside it, then mapped it with a closure typed `array $posted`. It now validates every rule with the SAME per-rule rules the PUT uses (`ruleRules()`, re-keyed from `items.*.rules` to `rules`), so the preview can no longer be handed a shape — or a value — that the save itself would refuse.

**Fixed (hardening, not a crash): the forgotten-password pair had no limiter of its own.** `POST /forgot-password` and `POST /reset-password` were the only guest writes with no named limiter. The password broker throttles the SAME address (once a minute) and says nothing about a list of addresses being walked one at a time — which sends one email per address and, because the form answers whether an address has an account, reads the list back while it goes. Both now carry `throttle:password-reset`: 10 a minute per visitor, keyed like the invitation link's public end.

**Fixed (owner's decision, 2026-09-17): the forgotten-password form no longer says whether an address has an account.** Laravel's default named the outcome — "We can't find a user with that email address" against "We have emailed your password reset link" — so anybody could read back which addresses are accounts here, one request at a time; "please wait before retrying" said it just as loudly, because only a real account is throttled. `PasswordResetLinkController::store` now answers with the same neutral line either way and lets the broker decide whether an email actually goes out. The trade the owner accepted: somebody who mistypes their own address gets that same line and no email. `SessionAndPasswordAttackTest` holds it in place (the two answers identical, no errors, exactly one notification sent, a malformed address still a plain validation error).

**Dependencies:** `composer audit` reported 16 advisories across two transitive packages, neither called by application code — `guzzlehttp/guzzle` (Dusk and the framework's HTTP client) and `league/commonmark` (the framework's Markdown mail). Updated with the owner's go-ahead to 7.15.5 and 2.10.1; `composer audit` now reports none, and both suites pass on them.

Two behaviours were examined and left alone, deliberately: **`X-HTTP-Method-Override`** is honoured by Symfony on a POST, but it lands on the same route with the same rules (a member is not removed without the password), a method the app treats as a write is still CSRF-checked, and a cross-site form cannot set a header — so there is nothing to gain; and **`/up`** (Laravel's health check) answers 200 to a guest on purpose. Worth doing at deploy time rather than in code: set `SESSION_SECURE_COOKIE=true` behind HTTPS (`http_only` and `same_site=lax` are already the defaults this app ships), and keep `APP_DEBUG=false` so a 500 never prints a trace.
