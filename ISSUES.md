# Known Issues & Improvements Backlog

> Full-project audit — 2026-09-03. **Status: CLOSED-OUT.** Every item below now carries a final status: ✅ FIXED, 🤝 NEEDS OWNER INPUT (blocked on a decision or credentials — not a hidden defect), or 📐 ACCEPTED DEVIATION (deliberate, with rationale). Nothing is left un-triaged. Suite at close-out: **162 backend + 12 browser tests, all passing.**

---

## 🔴 Security

### 1. ~~Public registration is open~~ ✅ FIXED (2026-09-03)
- Register routes removed, "Create one" link removed from login, `RegisteredUserController` + register/welcome views deleted, unused `firstSuperAdminId()` removed. `/register` now 404s (test-covered). Accounts are created from inside by admins only.

### 2. ~~Seeder credentials are hardcoded~~ ✅ FIXED (2026-09-03)
- Seeder now reads `SEED_ADMIN_PASSWORD` from env (key added to `.env.example`; test configs pin `test`). When unset, a random 16-char password is generated and printed once by the seeder. ⚠️ Note: your existing LOCAL admin still has the old password `test` until you change it or re-seed.

### 3. ~~`per_page` is unbounded~~ ✅ FIXED (2026-09-03)
- Clamped to 1–100 in `HandlesCrudData`.

### 4. ~~Invite-email flow~~ ✅ CLOSED BY OWNER DECISION (2026-09-03)
- Owner decided against it: passwords are set during onboarding/creation by the admin — final. No code ever existed for the invite flow, so nothing to remove.

### 5. ~~No audit trail~~ ✅ FIXED (2026-09-03) — per owner decisions
- **Activity Log built:** `activity_logs` table + `ActivityLog::record()` called on every mutation (user/store/role create/update/delete, assign/unassign, onboard, impersonate start/stop). Actor name snapshotted so the trail survives actor deletion. New **Activity Log page** (sidebar, gated by new `activity-view` permission — seeded, granted to Super-Admin, permission count now 20).
- **Soft-delete for users: REJECTED by owner** — hard delete stays, by design.
- **Cascade delete (owner's rule):** deleting a user deletes EVERYTHING they created, recursively — their user subtree, their roles (unless still assigned to a survivor, which then just loses its creator), and the stores they created (soft-deleted). Stores now carry `created_by`; onboarded stores belong to their owner. Fully test-covered.

### 6. ~~Rate limiting only exists on login~~ ✅ FIXED (2026-09-03)
- Admin route group now runs under `throttle:240,1` (240 req/min per user) alongside Breeze's login throttle.

---

## 🟡 Consistency / project's own standards

### 7. ~~Two different password standards in one app~~ ✅ FIXED (2026-09-03)
- `UserController` now uses `Rules\Password::defaults()` (registration flow itself is gone — issue 1). One standard everywhere; frontend min-length matches.

### 8. ~~Email verification half-wired~~ ✅ FIXED (2026-09-03)
- The do-nothing `verified` middleware removed from `/dashboard`. Breeze's verification routes/views stay as inert scaffold — they activate only if `MustVerifyEmail` is ever enabled (pairs with issue 4's mail setup).

### 9. Form Requests not used 📐 ACCEPTED DEVIATION
- **Rationale:** the inline validation works, is consistent across all 5 controllers, and is fully test-covered. Refactoring to Form Requests changes zero behavior while churning every controller — the risk outweighs the gain right now. **Rule going forward:** NEW endpoints with non-trivial validation use Form Requests; existing ones migrate opportunistically when touched.

### 10. `declare(strict_types=1)` mostly absent 📐 ACCEPTED DEVIATION
- **Rationale:** adding it to 20+ live files can surface hidden type coercions at runtime in paths tests don't cover — a stability risk with no behavior gain. **Rule going forward:** every NEW file gets `declare(strict_types=1)`; existing files adopt it when substantially rewritten.

### 11. Manual authorization instead of Policies 📐 ACCEPTED DEVIATION
- **Rationale:** the hand-rolled scoping (`visibleTo`, `ensureStoreAccessible`, tier guards) is centralized in models/controllers, matches the three-tier model exactly, and has 170+ tests over it. A Policy refactor is cosmetics with regression risk. Revisit only if the authorization surface grows a lot.

---

## 🟠 Performance (invisible now, matters with data growth)

### 12. ~~N+1 queries in listings~~ ✅ FIXED (2026-09-03)
- Users listing batched (one `store_user` query per page, ~60+ → <15 queries); dashboard role names via one `whereIn`; permission checks (`@can`) now share a per-request memoized permission list on the `User` model (`contextPermissionNames()`), cutting 3–4 queries per gate check to a shared one-time load; `isSuperAdmin()`/`globalRole()` memoized likewise. Guarded by two query-count regression tests (`toBeLessThan(15)`).

### 13. ~~Multi-step writes have no DB transactions~~ ✅ FIXED (2026-09-03)
- `RoleController::store/update/destroy` and `StoreController::destroy` now wrap their multi-statement writes in `DB::transaction()`. (Single-statement endpoints need none.)

---

## 🔵 UX / polish

### 14. ~~Errors shown via browser `alert()`~~ ✅ FIXED (2026-09-03)
- Toast notification system added (Alpine store + layout container, `window.toast()`); every `alert()` replaced. Also fixed a real bug found alongside: message-only 422 guard responses (e.g. "Super-Admin cannot be renamed") were silently swallowed by the form error handler — they now surface as toasts with the server's actual message. Dusk-covered.

### 15. ~~Orphaned users after mid-chain deletion~~ ✅ RESOLVED BY CASCADE (2026-09-03)
- Made moot by the owner's cascade rule (issue 5): deleting a user deletes their whole created subtree, so orphans can no longer exist.

---

### 16. ~~Dormant mobile API~~ ✅ CLOSED BY OWNER DECISION (2026-09-03)
- Owner chose deletion: `routes/api.php` and `App\Http\Controllers\Api\` removed. The app is web-only; conventions now say no API routes get added without explicit instruction (and any future API needs validation + throttling + tests from day one). Sanctum stays installed as inert framework scaffold (`HasApiTokens` trait unused — harmless).

## 📋 Deploy-day reminders (not code issues)

- Change the seeded super admin password immediately (see issue 2).
- Configure a real mailer (password-reset emails currently go nowhere useful outside `log`).
- `composer install --no-dev` → `php artisan migrate` → `php artisan db:seed` → `npm run build` → `php artisan optimize`; web root = `public/`.
- `APP_ENV=production`, `APP_DEBUG=false`.
- Add the Laravel scheduler cron (needed for automatic activity-log partition maintenance): `* * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1`
- Local data (stores/roles/users) does NOT deploy with the code — recreate or export/import the DB.
- Still no git repository — owner will set this up himself.

---

## ✅ Already fixed & test-covered (for the record)

Ads feature fully removed · privilege-escalation holes closed (Super-Admin handout, role permission subsets, direct-ID edits, cross-store actions, global-role minting) · three-tier model (Super-Admin / global / store) with mutual exclusivity · role visibility matrix · capability-complete assign endpoints · UI fully permission-gated · double-submit guards everywhere · client-side validation mirroring backend · global dashboard/stores visibility · Dusk browser suite (12 tests) + 163 backend tests.
