# Multi-Store Authorization System — Complete Portable Specification

> **Purpose.** This document is a complete, self-contained specification of the user / store / role / permission system of the "Multi-Store Skeleton" project (originally built in Laravel). It exists so the SAME system can be re-implemented on another stack (Python) **without access to the original codebase or its author**. Every rule, condition, constraint, default, exact guard order, and the *reason* behind it is written down. Error messages that the UI or tests depend on are quoted **verbatim**.
>
> Conventions: "422" = rejection with a message (two shapes exist — see §18.1); "403" = forbidden; "404" = hidden/not found (used deliberately so ids outside your visibility don't leak existence); "429" = rate-limited. "Actor" = the authenticated user performing a request. "Target" = the user/role/store being acted on. Laravel-specific terms are translated in §22.

---

## 1. Core concepts and vocabulary

The system is a multi-tenant ("multi-store") admin panel with **three mutually exclusive tiers of users**:

1. **Super-Admin** — holds the role *named exactly* `Super-Admin`, assigned on the **sentinel row** `store_id = 0` of the `store_user` pivot. Super admins are the only tier allowed to: assign global roles, remove global (store-0) assignments, create/edit/delete global roles, set the signup-default flag, impersonate users, and run activity-log storage maintenance.
2. **Global users** — hold any role flagged `is_global = true`, also on the store-0 sentinel. They span every store: see **all stores**, assign/remove users in **any** store, and get the global statistics dashboard. **Their powers are still limited to their role's permissions** — being global widens *where* they act, never *what* they can do.
3. **Store users** — assigned to real stores via `store_user`, exactly **one role per store** (DB-enforced unique). They act only inside stores they belong to, in the currently selected store context (§5, §7).

**Tier exclusivity (hard rule):** a user is EITHER global (has a store-0 row) OR store-assigned — never both. Assigning a store to a global user is rejected, and assigning a global role to a store-assigned user is rejected ("remove the other tier first", §8.1). *Reason (owner decision): mixing the tiers makes visibility and permissions too complex to reason about.*

**No enforced hierarchy (owner decision):** there are NO role levels, no rank comparisons, no "manager outranks worker" logic anywhere. Users build their own de-facto hierarchy through `created_by` chains and through which permissions they put into the roles they create. *Reason: the owner explicitly rejected enforced hierarchy — customers shape their own structure.*

**The sentinel row:** `store_user.store_id` has **no foreign key** and defaults to `0`. `0` never matches a real store; it marks "this assignment is global". Any query that joins `store_user` to `stores` silently drops sentinel rows; code that needs the global assignment queries the pivot directly with `store_id = 0`.

---

## 2. Data model (exact schema)

All tables have auto-increment integer `id` PK and `created_at`/`updated_at` unless stated otherwise.

### 2.1 `users`
| column | type | constraints |
|---|---|---|
| first_name, last_name, phone | string | NOT NULL |
| email | string | NOT NULL, **UNIQUE** |
| email_verified_at | timestamp | nullable. **A port needs only this column** (nulled on email change §14.2, set by the seeder) — no verification routes/emails are required; the original's scaffolded verification and confirm-password screens are unused (no route requires password confirmation) and may be omitted |
| password | string | stored bcrypt-hashed (hash-on-write) |
| remember_token | string | nullable — backs the login "remember me" cookie (§18.3); rotated on password reset (§18.4) |
| created_by | FK → users.id | nullable, **ON DELETE SET NULL** |

- **There is deliberately NO `store_id` on users** — unlike `roles` (§2.3). A user's stores are exactly their `store_user` rows; nothing records "the store they were created in", because visibility does not depend on it (§6.1).
- **`name` is computed, not stored:** `trim(first_name + ' ' + last_name)` — used for all display and for activity-log actor snapshots.
- **NO soft delete on users** (owner decision). Deletion = hard delete + cascade (§15).
- Companion tables: `password_reset_tokens` (email PK, token, created_at) and a server-side `sessions` table.

### 2.2 `stores`
| column | type | constraints |
|---|---|---|
| name | string | NOT NULL |
| slug | unsigned bigint | **UNIQUE**, auto-generated: current Unix time in microseconds as an integer, STRING-concatenated with a random integer 10–999, then parsed as one number; regenerated in a loop while it collides. Exact formula is not sacred — any generator of unique unsigned bigints the caller never supplies is acceptable |
| street | string | NOT NULL |
| suite | string | nullable |
| city | string | NOT NULL |
| state | string(2) | NOT NULL |
| zip_code | string(10) | NOT NULL |
| country | string | NOT NULL |
| is_active | boolean | default true |
| created_by | FK → users.id | nullable, ON DELETE SET NULL |
| deleted_at | timestamp | nullable — **stores ARE soft-deleted** (unlike users) |

### 2.3 `roles`
`name` string NOT NULL (**NOT unique — not in the DB and not in validation**) · `created_by` FK users nullable SET NULL · `is_global` bool default false · `is_signup_default` bool default false · `store_id` FK → stores.id nullable ON DELETE SET NULL.
- **Names are deliberately not unique:** the role **id** is the identity and everything joins on it. "Cashier" may exist many times — one per store, each with its own permission set — because the same person can run several stores and grant different powers in each. The ONLY name rule is that `Super-Admin` is reserved (§10.2).
- `isGlobal()` helper: `is_global == true OR name == 'Super-Admin'` (name check = deliberate anchor; the migration adding `is_global` backfilled `true` onto Super-Admin).
- `is_signup_default` invariant: at most ONE role holds it; never on a global role; super-admin-set only (§10.3).
- `store_id` invariant: the store the role was built in — stamped on create from `session.current_store_id`, NULL for global roles and for roles created by super/global admins (no store context). NULL = store-less = usable in any store. Flipping a role to global clears it back to NULL. Drives per-store role isolation (§6.2) and the assign guard (§8.1 step 5).

### 2.4 `permissions`
`name` string NOT NULL, **UNIQUE at the DATABASE level** (a real unique index — unlike `roles.name`, which is validation-only) plus validation-layer uniqueness; it is the authorization key · `label` string nullable — display name, regex `^[a-zA-Z0-9 ]*$`, message **"Label can only contain letters, numbers, and spaces."** Display name falls back to raw `name` when label is null (exposed to clients as `display_name`).

### 2.5 `role_has_permissions`
`role_id` FK CASCADE, `permission_id` FK CASCADE, **UNIQUE (role_id, permission_id)**, timestamps.

### 2.6 `store_user` (the heart)
`user_id` FK CASCADE · `store_id` unsigned bigint **NO FK, default 0** (so sentinel 0 can exist) · `role_id` FK roles CASCADE · **UNIQUE (store_id, user_id)** — DB guarantee of one role per store per user · timestamps.

### 2.7 `activity_logs`
`id` · `actor_id` nullable (FK **dropped** on MySQL when partitioned — §16.5; on other drivers SET NULL) · `actor_name` string NOT NULL (snapshot) · `action` string(100) indexed · `subject_type` string(100) nullable (short class name: `User`, `Store`, `Role`, `Permission`) · `subject_id` nullable · `description` string(1000) nullable · `created_at` indexed, **no updated_at** (DATETIME on MySQL).

---

## 3. Permission catalog (exactly 20)

Names follow `{module}-{action}`; the seeder creates exactly these 20 (count asserted by tests):

| name | label (exact seeded value) | gates |
|---|---|---|
| user-view | View Users | users page + listing data |
| user-store | Create Users | creating a user directly |
| user-update | Update Users | editing another user |
| user-destroy | Delete Users | deleting another user (cascade §15) |
| user-store-view | View User Store Assignments | opening a user's assignments modal / listing rows |
| user-store-assign | Assign User to Store | assigning store+role; ALSO gates both option-list endpoints (§19.2) |
| user-store-unassign | Remove User from Store | removing an assignment |
| store-view | View Stores | stores page + listing |
| store-store | Create Stores | creating a store |
| store-update | Update Stores | editing a store |
| store-destroy | Delete Stores | soft-deleting a store |
| role-view | View Roles | roles page + listing + reading a role's permissions + **GET /roles/assignable** (§7.1) |
| role-store | Create Roles | creating a role |
| role-update | Update Roles | updating a role |
| role-destroy | Delete Roles | deleting a role |
| permission-view | View Permissions | permissions page + listing |
| permission-store | Create Permissions | creating a permission |
| permission-update | Update Permissions | renaming/relabeling |
| permission-destroy | Delete Permissions | deleting a permission |
| activity-view | View Activity Log | activity page, data, partition status (maintenance ALSO needs super admin, §16.5) |

**Naming gotcha (real reimplementation trap):** `user-store` means **CREATE a user** (from the REST "store" action) and belongs to the User module; only `user-store-view` / `user-store-assign` / `user-store-unassign` are the store-ASSIGNMENT permissions. The role-form checklist groups by prefix with exactly this special case, displaying groups in the fixed order User Management, User-Store Assignments, Store Management, Role Management, Permission Management (alphabetical inside each group by name) — any prefix outside that order list is appended AFTER it with a capitalized-prefix fallback title, so `activity-view` renders as a sixth group "Activity", last (visible e.g. in a super admin's checklist).

**Authorization wiring:** at boot, one gate per permission ROW is registered (name → check per §7). Route middleware `can:{name}` enforces per endpoint. There is **no implicit super-admin bypass** in the check — super admins pass because the seeder syncs all 20 permissions onto the Super-Admin role; their extra powers are separate explicit `isSuperAdmin()` checks.

**Capability-complete principle (owner convention):** a permission must be self-sufficient for its task. Option lists feeding an action come from dedicated endpoints gated by the action's own permission — or at minimum a permission of the SAME module — never by another module's `-view`. Concretely: the assign modal's dropdowns load from `/users/assignable-stores` and `/users/assignable-roles`, both gated `user-store-assign` (§19.2); the role form's checklist loads from `/roles/assignable`, gated `role-view` (§7.1).

---

## 4. Identity rules

### 4.1 Super-Admin definition
`isSuperAdmin()` = does ANY `store_user` row for this user join to a role **named exactly** `'Super-Admin'` (any store_id; in practice always the sentinel):

```sql
SELECT EXISTS(SELECT 1 FROM store_user JOIN roles ON roles.id = store_user.role_id
              WHERE store_user.user_id = ? AND roles.name = 'Super-Admin')
```

**The name is a locked system anchor.** Eight deliberate server-side literal name-check sites: `isSuperAdmin()`, `firstSuperAdminId()`, the super-admin exclusion subquery in user visibility (§6.1), `Role::isGlobal()`, the Super-Admin exclusion in the global-user branch of role visibility (§6.2), the users-listing lookup that computes each row's `is_super_admin` flag (§19.5), the rename-lock comparison (§10.4), and the reserved-name guard on role create/update (§10.1, case-insensitive). The client additionally checks the name in the assign/onboard modals (§19.2). Therefore:
- Renaming the Super-Admin role → 422 **"The Super-Admin role is a system role and cannot be renamed."**
- Deleting it is blocked while ANY user holds it — **including via the store-0 sentinel** (the check must read raw pivot rows; a stores-join would miss the sentinel).

### 4.2 First super admin
`firstSuperAdminId()` = `user_id` of the `store_user` row with the LOWEST pivot `id` among rows whose role is named Super-Admin (earliest assignment order, NOT lowest user id); **null when none exists**. Used for signup attribution (§9.1); when null, the self-registered owner gets `created_by = NULL` (belongs to nobody's visibility or cascade).

### 4.3 Global role
`globalRole()` = the Role on the pivot row `(user_id, store_id = 0)`, else null — a **raw pivot query** (store 0 has no stores row, so relationship joins can't see it). "Global user" below = `globalRole() != null`. A Super-Admin is also a global user; code distinguishing the two checks `isSuperAdmin()` first.

### 4.4 Per-request memoization (performance contract)
`isSuperAdmin()`, `globalRole()` (negative results too, via a separate "resolved" flag), and the per-context permission-name list are memoized **per user object per request**; an explicit refresh of the object clears all of them first. *Reason: one page render triggers dozens of checks. Tests assert bounded query counts for the users listing and dashboard regardless of row count — preserve "O(1) queries per repeated check".*

---

## 5. Session store context
- Selected store lives in the session as `current_store_id`, set ONLY by store-switch (§13) and cleared by impersonation start/stop (§14.1). NOTE: it is stored as the raw request value — from an HTML form that is the STRING "3"; comparisons downstream are loose, so a port must normalize or compare loosely.
- `currentRole()` = role on the pivot row (user, current_store_id), resolved **through the stores relationship** — a soft-deleted or missing store yields null (→ zero permissions). Session value 0/unset also yields null.
- A user removed from their current store loses its permissions **on the next request** (checks re-derive from the pivot every request, subject only to the per-request memo).

---

## 6. Visibility rules

Visibility is **one level deep by `created_by`** — you see what you created, not your grandchildren. It is both the read filter and the mandatory write guard (§6.4).

### 6.1 Users — `visibleTo(viewer)`
1. **Always excludes the viewer's own row** (self-service is Profile-only, §14.2).
2. Non-super viewer → only rows with `created_by = viewer.id`. **No store condition — see the note below.**
3. Super-admin viewer → **all users EXCEPT other super admins**, unless that super admin has `created_by = viewer.id`.

```sql
-- super-admin branch
WHERE id != :viewer AND (
  id NOT IN (SELECT su.user_id FROM store_user su JOIN roles r ON r.id = su.role_id
             WHERE r.name = 'Super-Admin')
  OR created_by = :viewer)
```

*Reason for rule 3 (a real incident): a promoted super admin used to be able to see and edit the admin who promoted them. Now a promoted super cannot see or touch their promoter; a super still manages the supers they created.*

**Users are NOT store-scoped, and that is a deliberate decision (rule 2).** Roles are locked to the store they were built in (§6.2), people are not: whoever you created stays visible to you in **every store you work in**, whatever store you happened to create them in. *Reason (owner's call, after weighing the alternative): a person who owns store A and manages store B must be able to staff B with their own people. If users were store-scoped, they would not even appear in B's list, so every cross-store placement would have to go through a super admin — pure friction, and no security gained: the actor already holds power in both stores. The wall that matters is the ROLE — inside B they can only hand out B's own roles (§8.1), so the powers a person receives there are exactly what B allows, never what A allowed.*
- A port must NOT add a store filter here just because roles have one. The asymmetry is the design: **people move, powers don't.**
- The `created_by` wall still does the privacy work: two owners sharing a store never see each other's people.

Other consequences (all tested): a store user sees ONLY users they created — even people sharing their stores are invisible if created by someone else (cross-store privacy wall); each level lists only its direct children; nobody ever sees themselves in the list.

### 6.2 Roles — three-tier matrix
| viewer | SEES | ASSIGNS | MODIFIES/DELETES |
|---|---|---|---|
| Super admin | all roles | all roles | all (subject to §10 guards) |
| Global user | all **non-global** roles, ANY creator | any visible role, in any store | ONLY own-created — others → 403 **"You can only modify roles you created."** (*seeing ≠ editing*) |
| Store user | only roles **they created IN the current store** | same set (backend-enforced, §8.1) | same set |

Global roles (Super-Admin included) are visible/assignable/editable by super admins only — **with one documented exception** (two paragraphs down). *Reason global users see all non-global roles: they onboard and assign across stores and need e.g. a super-admin-created "Store Owner" role in their dropdown; ownership still controls modification.*

**Per-store role isolation (roles never travel):** `roles.store_id` (nullable FK → stores, null on delete) records the store a role was built in; it is stamped on create from `session.current_store_id` and is NULL for global roles and for roles created by super/global admins (who have no store context). A NULL `store_id` means store-less — usable in any store, which is what lets an admin-made "Owner"/"Manager" role serve every store. The store-user branch of the scope is therefore `created_by = viewer AND store_id = session.current_store_id`, and **with no store selected it returns nothing**. Consequences: a role built in store A is invisible from store B, so it cannot be listed, edited, deleted or assigned from there; two people working in the SAME store still cannot see each other's roles (the `created_by` wall holds inside a store too); and a user who spans two stores needs one role per store. `assignStore` adds the matching write-side guard (§8.1). *Reason (a real incident): a manager assigned a cashier role in store Alpha and then removed that assignment from inside store Beta — work done in one store must not be reachable from another, since permissions are themselves per-store.*

**Edge (documented, deliberate simplicity):** the store-user branch applies no `is_global` exclusion — if a super admin later flips a store-user-created role to global, its creator still passes the modify guard: they can edit its name/permissions AND delete it once unassigned (§10.4 covers both). *Assigning* it stays super-only via §8.1. Note the flip also clears `store_id` to NULL (a global role must not stay pinned to one store), so the creator only keeps seeing it if they are a super/global user themselves — for a plain store user the role disappears from their list. This is the sole exception to the "supers only" sentence above and to §1's "create/edit/delete global roles" claim. 

### 6.3 Stores
Super admin OR global user → ALL stores. Store user → only assigned stores. Unassigned stores appear nowhere (dashboard, listing, dropdowns).

### 6.4 Visibility as WRITE guard (non-negotiable)
Route middleware alone is NOT enough. Every write endpoint re-scopes its target:
- users/roles: fetch through the visibility scope, 404 outside it;
- stores: accessibility check (member unless global/super) → 404.

*Reason: IDOR — otherwise anyone with e.g. `user-update` could edit ANY user by id.* 404 (not 403) is deliberate: existence must not leak. **Deliberate read-only exceptions:** `GET /roles/{role}/permissions` (§10.5) and the impersonation target lookup (§14.1) are NOT visibility-scoped.

---

## 7. Permission resolution (core algorithm)

```
context_key = str(session.current_store_id) if set else 'global'   # memo key
role  = currentRole()  if session.current_store_id  else globalRole()
names = role ? {p.name for p in role.permissions} : {}
allowed = permission_name in names
```

- A store user with NO store selected has **zero permissions** (must switch in first — which is why the switch endpoint itself carries no permission gate, §13).
- A global user needs no store selected; their sentinel role answers in the `'global'` context.
- The SAME user has different roles → different powers per store; switching flips capabilities instantly (browser-tested: Add-User button present in one store, absent in the other; `/roles` can be 200 in one and 403 in the other).

### 7.1 `assignablePermissions()` + its endpoint
- Super admin → ALL permissions. Anyone else → exactly their current-context role's permission set.
- **`GET /roles/assignable`** (gated `role-view` — NOT role-store; capability-complete for the role form) returns the actor's assignable permissions as a plain (unpaginated) array `[{id, name, label, display_name}]` ordered by name. It feeds the role-form checklist and §10.2's subset rule.

---

## 8. Assignment rules

### 8.1 Assign — `POST /users/{user}/stores` (permission `user-store-assign`), guards in EXACT code order:
1. Target scoped `visibleTo(actor)` → else **404**.
2. Target `isSuperAdmin()` → **422 "Super Admin cannot be assigned to stores."** — first-class guard, BEFORE field validation, regardless of role type (even another global role).
3. Field validation: `role_id` required + must exist in roles; `store_id` nullable + must exist in stores. NOTE: the store-exists rule does NOT exclude soft-deleted stores (such an id passes validation — the store-role branch re-checks liveness in step 5), and a literal `store_id = 0` FAILS this validation (0 is never a client-suppliable store).
4. **If the role `isGlobal()`** (flag OR named Super-Admin):
   - actor not super admin → **403 "Only a Super Admin can assign global roles."** (a global user on the sentinel cannot mint peers);
   - target has any real-store rows ("real" = raw `store_user` rows with store_id > 0, regardless of the store's soft-delete state) → **422 "This user is assigned to stores and cannot take a global role. Remove their store assignments first."**;
   - `store_id` is **forced to 0**; any store sent along is ignored (tested).
5. **If it is a store role, in this exact order:**
   - missing `store_id` → 422 field error on `store_id`: **"A store is required for this role."**;
   - the store is soft-deleted → 422 field error on `store_id`: **"The selected store is not available."** (`exists:stores,id` in step 3 ignores soft-deletes, so liveness is re-checked here — otherwise a phantom assignment to a dead store is created);
   - the role must be within `Role::visibleTo(actor)` → else **404** (same rule as onboarding; the dropdown filter is cosmetic, this is the enforcement — without it any assigner could hand out role ids they cannot see, i.e. permissions beyond their own). *With per-store role isolation (§6.2) this alone already blocks a store user from reusing another store's role — they cannot see it.*;
   - target is a global user → **422 "This user holds a global role and cannot be assigned to stores. Remove the global role first."**;
   - actor is not a global user AND the target store is not the actor's CURRENT store → **403 "You can only assign users within the store you are currently in."** *Reason: permissions are per-store — the `can:` gate authorised the CURRENT store's role, so the write must land in that same store. Belonging to the target store is not enough; the actor must be switched into it. Global users span every store.*;
   - the role is store-scoped (`store_id` not NULL) and does not equal the target store → **422 "That role belongs to a different store."** — this one applies to EVERYONE, global users and super admins included.
   *Order is deliberate: "may you act in this store at all?" (403) is answered before "is this role valid here?" (422).*
6. **LAST:** a pivot row already exists for (target, resolved store) → **422 "User already assigned to this store."** (Because this runs last, e.g. "duplicate + non-member actor" yields the membership 403, not the duplicate 422.) Race note: two concurrent assigns can both pass this check; the DB's UNIQUE(store_id, user_id) then rejects the second attach as an UNHANDLED error (the original does not catch it — a port may catch the constraint violation and return the same 422).
7. Attach `(store_id, role_id)`; log `user.assigned` (description: store branch → `Assigned {name} ({email}) to store #{id}`, global branch → `Assigned global role to {name} ({email})`). Success message: **"User assigned to store successfully."** — EXCEPT the global branch, which names the actual role: **`Global role "{role name}" assigned.`**

**Status-code pattern (tested):** guards about WHO the actor is → 403; guards about the TARGET's state → 422; missing/invalid fields → 422 field errors.

### 8.2 Unassign — `DELETE /users/{user}/stores/{store}` (permission `user-store-unassign`; the `{store}` URL segment must be numeric, `0` allowed — a non-numeric value never matches the route and yields **404 before any guard runs**), EXACT order:
1. **If store == 0 (removing a global/Super-Admin assignment) — BEFORE any scoping:**
   - actor not super admin → **403 "Only a Super Admin can remove Super-Admin access."**;
   - target == actor → **422 "You cannot remove your own Super-Admin access."** (self-lockout prevention).
   *Order is deliberate: `visibleTo` always excludes self, so scoping first would turn the friendly self-removal 422 into a confusing 404.*
2. THEN target scoped `visibleTo(actor)` → else 404.
3. For store != 0: actor not global AND that store is not the actor's CURRENT store → **403 "You can only remove users from the store you are currently in."** *Same per-store reasoning as §8.1: an assignment made in another store must be managed from THAT store's context — this is what stops a manager from undoing in store B what was done in store A.*
4. Detach. **Idempotent:** removing a nonexistent assignment is a silent success — still returns the success message AND still logs `user.unassigned`. Success messages: store-0 → **"Super-Admin access removed."**; otherwise **"User removed from store successfully."**

### 8.3 List assignments — `GET /users/{user}/stores` (permission `user-store-view`)
Target must be `visibleTo`. Response: `{assignments: [{store_id, store_name, role_id, role_name}]}` ordered by store_id ascending (sentinel first when present). The sentinel renders `store_name` as the exact string **"Global (All Stores)"**; a missing/soft-deleted store renders **"—"**; an unresolvable role_id gives `role_name: null`.

---

## 9. The two onboarding doors (both create owner + store together, atomically)

*Deliberately NO invite-email flow (owner rejected it; the password entered at onboarding is final) and no other owner-creating path.*

### 9.1 Public self-registration (`GET/POST /register`, guest-only; the **POST** is throttled 10/min keyed by client IP — the GET page is not)
One form: user fields + store fields. The store half is exactly `name, street, suite, city, state, zip_code`, validated per §12.2; `is_active` is NOT accepted (DB default true) and `country` is absent (hardcoded below).
- **Role selection:** ALWAYS the single role with `is_signup_default = true AND is_global = false AND store_id IS NULL` — **server-chosen; a submitted role id is ignored entirely** (tested). The last two conditions are defensive: a corrupted global-flagged or store-scoped default counts as "no default" and signup closes gracefully rather than handing out a role that belongs elsewhere. *Reason for `store_id IS NULL`: signup creates a BRAND-NEW store, and a role built inside an existing store must never govern it (§6.2); §10.3 already forces the flag off for such roles, so this is the second layer.* *No name-based conditions anywhere on the server ("Store Owner" is found by flag, never by name).*
- **The default-role lookup runs BEFORE field validation.** If none exists: even a fully invalid submission gets the closed-signup response — a validation error on the **`email` field**, exact message **"Registration is not available right now. Please contact the administrator."**, with old input preserved; nothing is created. The GET page receives a `signupOpen` boolean (same query) so the form can be hidden.
- All-or-nothing transaction: user + store + pivot attach. Invalid store half → nothing created.
- Attribution: owner's `created_by = firstSuperAdminId()` (null when no super exists); store's `created_by =` the new owner (so deleting the owner cascades the store).
- `country` is NOT on the form; hardcoded server-side to `'USA'` (**registration only** — see §9.2/§12.2).
- On success: the user is logged in and the session id regenerated BEFORE the log line — so `user.registered`'s actor is **the new owner themself**. Redirect to dashboard.

### 9.2 Admin onboarding (`POST /users/onboard`)
Requires ALL THREE permissions as route middleware: `user-store` AND `store-store` AND `user-store-assign`. A global user holding all three can onboard too. Differences from §9.1:
- The role comes from the request, validated in this order: outside `Role::visibleTo(actor)` → **404**; visible but global → **422 field error on `role_id`: "A global role cannot be used for a store owner."**; visible but **store-scoped** (`store_id` not NULL) → **422 field error on `role_id`: "That role belongs to another store and cannot be used for a new one."** *Reason: this call creates a BRAND-NEW store, so the owner's role must be one that works in any store — handing over a role built inside store A would put the new owner's powers under store A's control, the very thing §6.2 forbids. Consequence to accept: a store user whose only roles are store-scoped cannot onboard until a store-less role exists (super/global admins create those), which is correct — nobody should be able to spawn a store governed by another store's role.*
- **`country` IS a required request field** (string, max 100; the UI pre-fills "USA" but it is editable) — unlike registration's hardcoding.
- `is_active` is forced to `true` (not accepted from the request).
- Owner's `created_by =` acting admin; store's `created_by =` the new owner. Same transaction guarantee. Logged `user.onboarded`.
- UI convenience (client-only): the onboard modal loads `/users/assignable-roles`, filters out global roles **and store-scoped ones** client-side (mirroring both server guards above — the endpoint returns `store_id` for exactly this), and pre-selects the first role whose name matches `/owner/i` (else the first role) — a display nicety; the server validates the submitted id independently.

---

## 9A. Plain user create / update (for completeness)
- `POST /users` (`user-store`): creates an UNASSIGNED user — fields per §18.2, `created_by = actor`, no store/role attached (assignment is a separate §8.1 call); logs `user.created`. No guards beyond validation.
- `PUT /users/{user}` (`user-update`): §14.2's self-check, then §6.4 scoping, then field edits only (blank password = unchanged); logs `user.updated`. No tier guards — see §24.2.

## 10. Role management

### 10.1 Create (`role-store`)
- `name`: required, string, max 255 — **NOT unique** (no per-creator or system-wide uniqueness; `roles.name` has no DB unique index either). The role **id** is the sole identity; every assignment and permission link joins on the id. One owner may legitimately keep several roles all named "Cashier" — one per store — each with its own permission set (one store's Cashier fewer permissions, another's more). *Reason (owner): a role name recurs naturally across stores; forcing uniqueness blocks a real workflow.*
- **Reserved name (the ONE exception):** the name `Super-Admin` is rejected for anyone, on create and rename-to — 422 field error on `name`: **"This role name is reserved."** The guard is deliberately **two-pronged**, and a port needs both: (a) `trim` + case-insensitive compare, which holds on every engine; **and** (b) a comparison run **by the database itself** (`SELECT ? = ?`), which folds exactly what the anchor lookup folds. *Reason: the authorization anchor matches this name with a DB query (§4.1), and a case/accent/pad-insensitive collation like MySQL's `utf8mb4_unicode_ci` / `utf8mb4_0900_ai_ci` treats `super-admin`, `Super-Ádmin` and `Super-Admin ` (trailing space or NBSP) as THE SAME STRING — so a look-alike would resolve as the real role and silently mint super admins. An ASCII-only check in application code cannot fold accents and misses this; the framework's input trimming strips spaces but not accents.* Only the genuine seeded Super-Admin role keeps the name (its own rename-to-itself is exempt).
- **≥ 1 permission required**; `created_by = actor`; `store_id = session.current_store_id` for a store user, **NULL when the role is global** or when the actor has no store context (super/global admins) — see §2.3 and §6.2.

### 10.2 Permission subset rule (create AND update)
Every attached permission must be within the actor's `assignablePermissions()` (§7.1), enforced **server-side**; violation → 422 **field-style** error keyed `permissions`: **"You can only assign permissions that you hold yourself."** (the UI renders it as a red error message directly below the checklist — field-style, not a toast; the checkboxes themselves get no red border). The UI checklist only *displays* the allowed subset — cosmetic. *Reason: privilege escalation — otherwise a user could mint a stronger role and assign it to a puppet user.*

### 10.3 Flags (super-admin-only) — exact semantics
- **Precedence (create AND update):** `is_global` is computed FIRST, then the role's resulting `store_id` (§2.3), then `is_signup_default = (submitted boolean) AND actor-is-super AND NOT is_global AND store_id IS NULL` — a store-scoped role can never be the signup default, since signup creates a brand-new store (§9.1). So submitting both flags true, or setting signup-default on a global role, is NEVER an error — signup-default is **silently forced false** (belt-and-braces: §9.1's registration lookup also excludes global roles). Flipping the current signup-default role to global in one request silently clears its default flag the same way.
- **Create, super actor:** each flag = boolean of the submitted field (absent = false); the single-holder swap runs on create exactly as on update.
- **Create, non-super actor:** the request SUCCEEDS (200) and both flags are silently forced false — never an error for sending them. The role form doesn't even render the checkboxes for non-supers (client mirror).
- **Update, super actor:** each flag becomes the boolean of the submitted field — **an ABSENT field UNSETS it.** Consequence: a super admin editing the signup-default role via raw API without re-sending the flag silently clears it, leaving ZERO signup-default roles (registration closes; the system never auto-elects a new holder). The UI avoids this by pre-filling checkbox state.
- **Update, non-super actor:** both flags silently keep their stored values (submitted values ignored; still 200).
- Setting `is_signup_default` on role X runs a **blanket clear** inside the transaction (`UPDATE roles SET is_signup_default = false` for every other role) before setting X — so even racing swaps converge on a single holder (last commit wins). The UI hides the signup-default checkbox while the global checkbox is ticked. (Recommended in a port: a partial unique index on the flag where the engine supports it; and if the reader ever finds >1 flagged, treat as "no default" — §9.1's closed-signup path.)
- Flipping `is_global` (either direction) is blocked while the role is assigned to any user → 422 **"This role is assigned to one or more users; remove those assignments before changing where it applies."** The check fires only when the submitted value differs from the stored one. (The update path reads the raw `is_global` column here, not the `isGlobal()` name-helper.)
- A role that ends up global has its `store_id` cleared to NULL in the same update (a role that spans every store must not stay pinned to the one it was born in). Turning a global role back into a store role leaves `store_id` NULL — store-less, usable anywhere — because the actor doing the flip is a super admin with no store context.

### 10.4 Update / Delete
- Modify guard (update AND delete): actor must be creator OR super admin → 403 **"You can only modify roles you created."**
- Super-Admin rename lock (§4.1).
- Delete while assigned → 422 **"This role is assigned to one or more users and cannot be deleted. Reassign or remove those users first."**; deletable once unassigned. The assigned-check (for delete AND for the §10.3 global-flip) reads the role's users through `store_user` **joined to users, not stores** — so sentinel (store-0) wearers COUNT for every role; §4.1's Super-Admin note is emphasis, not a different rule. The same is true of cascade step 4 (§15).
- Role mutations run in transactions; their activity rows are written **after the transaction commits** (a rolled-back mutation logs nothing).

### 10.5 Read a role's permissions — `GET /roles/{role}/permissions` (permission `role-view`)
**Deliberately unscoped** (read-only exception to §6.4): any role-view holder can read ANY role id's permission list (global roles included). Response objects: `{id, name, label, display_name}`.

---

## 11. Permission management (`permission-*`)
- Create/update: `name` required, unique (ignoring self on update), max 255; optional `label` (§2.4 regex).
- **Delete is double-guarded:** attached to any role → 422 message-only **"This permission is assigned to one or more roles and cannot be deleted. Remove it from those roles first."**; then the actor must re-enter their CURRENT password in request field `password` — wrong → 422 **field-style** error keyed `password`: **"Password is wrong."** *Reason: deleting a permission silently rewrites what every role can do.*

---

## 12. Store management

### 12.1 Visibility — §6.3.
### 12.2 Create (`store-store`) / Update (`store-update`) — identical rule set
| field | rules |
|---|---|
| name | required, string, max 255 |
| street | required, string, max 255 |
| suite | nullable, string, max 100 |
| city | required, string, max 100 |
| state | required, exactly 2 chars, in the fixed list of the **50 US states — DC and territories are rejected** (a test asserts 'DC' fails; beware stock lists that include it) |
| zip_code | required, string, max 10, digits-only regex — message **"Zip code can only contain numbers."** |
| country | **required, string, max 100 — from the request** on create/update/onboarding; ONLY public registration hardcodes 'USA' (§9.1) |
| is_active | optional boolean — **this toggle is the only way a store is deactivated/reactivated**; absent on create → DB default true; absent on update → unchanged. NOTE: is_active is purely informational (a dashboard/listing badge) — NO guard, visibility rule, or permission check reads it |

Slug auto-generated (§2.2); `created_by = actor` on create.
### 12.3 Update/Delete scoping
Both run the accessibility check: actor assigned to the store OR global/super → else **404**. Permissions alone never cross the store boundary.
### 12.4 Delete semantics
Soft-delete + detach ALL of its `store_user` rows, in one transaction. *Reason: no phantom memberships in a dead store; the store row stays recoverable (unlike users).*

---

## 13. Store switching — `POST /stores/switch`
- **No `can:` permission middleware — deliberately** (auth + throttle only): a store user has ZERO permissions before selecting a store, so any permission gate would deadlock them out of ever switching in.
- Guard order: (1) validate `store_id` required + exists in stores — 0 or unknown id → 422 BEFORE any other guard, even for supers; the exists rule passes soft-deleted ids (the membership guard is the backstop). (2) actor `isSuperAdmin()` → **403 "Super Admins cannot switch into a store directly. Use \"Log in as\" to access a store as one of its assigned users."** — unconditional: even a super who somehow HAS a pivot row for the store is refused (*reason: a super has no role in the store; context would be undefined; the supported path is impersonation*). (3) no pivot row for (actor, store) → **403 "You do not belong to this store."**
- Effect: sets `session.current_store_id` (raw value — see §5), logs `store.switched`, responds redirect-back with flash **"Store switched."**

---

## 14. Impersonation & self-service

### 14.1 Impersonation ("Log in as")
- Start `POST /users/{user}/impersonate`: guards in order — actor not super → **403 "Only Super Admins can impersonate."**; target is super → **403 "Cannot impersonate another Super Admin."** The target is fetched by raw id (missing → 404) with **NO visibility scoping** — a deliberate exception to §6.4 (equivalent in effect: the only users invisible to a super are other supers, who are blocked anyway).
- Session mechanics: `impersonate.started` is logged BEFORE the identity switch (actor = the admin, subject = target). The original admin's id is remembered in the session; auth switches to the target; `current_store_id` is cleared; the session id is **regenerated** (regenerate keeps session data — full invalidation would destroy the remembered admin id and break stop).
- Stop `POST /impersonate/stop`: if the remembered admin still exists AND is still a super admin → log `impersonate.stopped` while still authenticated as the impersonated user (actor = impersonated user, subject null, description "Returned to {admin} from impersonating {user}"), clear store context, switch back, regenerate id. If the admin was deleted or demoted → full logout + session invalidation, **no log row**. Stopping while not impersonating = harmless no-op (redirect, no log).
- The users listing flags super-admin rows so the UI hides the button for them; the backend enforces regardless.

### 14.2 Profile (self-service; admin endpoints refuse self)
- Admin `PUT /users/{self}` → **403 "You cannot edit your own account here."**; admin `DELETE /users/{self}` → **403 "You cannot delete your own account."** Both self-checks run BEFORE visibility scoping (deliberate: `visibleTo` excludes self, so scoping first would produce a 404; the tests pin 403).
- Profile update: own first/last name, phone, email. Email change resets `email_verified_at` to null. Logged `profile.updated` **only when something actually changed**. NOTE: profile email validation differs from every other door — see §18.2.
- Own password change: request fields `current_password`, `password`, `password_confirmation`; wrong current password → redirect-back with a field error on `current_password` (framework-default text "The password is incorrect.") in the error bag named `updatePassword`. Logged `password.changed`.
- Self-delete: requires the current password in field `password` (wrong → field error, bag `userDeletion`); logs `account.deleted` BEFORE logout (correct actor snapshot); then logout; then the full cascade (§15) in a transaction; session invalidated. *Owner rule: self-deletion is not a loophole around the cascade.*

---

## 15. Cascade delete (owner's rule — hard deletes)

Deleting a user (admin `user-destroy`, or self-delete) hard-deletes everything they created, recursively — in ONE transaction:

```
1. Collect the target's entire created_by subtree breadth-first:
     ids=[target]; frontier=[target]
     while frontier: frontier = users where created_by IN frontier; ids += frontier
2. createdRoleIds  = roles where created_by IN ids
   createdStoreIds = LIVE stores where created_by IN ids AND deleted_at IS NULL
   (a store the subtree created that was ALREADY soft-deleted is never touched
    again — not detached, not re-deleted — and is excluded from the counts)
3. Bulk-DELETE all users in ids  (no per-row model events; store_user rows die via FK
   cascade; other users' created_by → NULL via SET NULL; activity_logs keep the
   actor_name snapshot — on MySQL actor_id keeps a stale value because partitioning
   dropped that FK (§16.5); on other drivers it nulls)
4. Of createdRoleIds delete ONLY those with no remaining assignments (a role still
   worn by a survivor is spared); their role_has_permissions rows are deleted first.
5. Every store in createdStoreIds: detach all users, then SOFT-delete.
6. Return counts {users, roles, stores} — users counts DESCENDANTS ONLY (excludes the
   target: deleting a user with no subtree reports users: 0). The user.deleted log
   line includes these counts.
```

*Reasons: no soft-delete graveyard for users (owner); roles spared while worn so survivors don't break; stores soft-deleted for recoverability; users-first order so step 4's "still assigned" checks see the post-delete world.*

---

## 16. Activity logging (audit trail)

### 16.1 Write rule
Every mutation endpoint writes exactly one row: `record(action, subject?, description, actor?)`.
- `actor_id` + snapshot `actor_name` (display always uses the snapshot; survives actor deletion). Unauthenticated flows pass the acting user EXPLICITLY (password reset via email link); truly actor-less writes fall back to `actor_name = 'System'`.
- Logs for transactional mutations are written after the transaction commits (§10.4) — with ONE exception: `account.deleted` is deliberately logged BEFORE the cascade transaction (while the actor is still authenticated, §14.2), so a rolled-back self-delete would leave that log row behind.

### 16.2 Action catalog (complete, with subject + description conventions)
Subject = the acted-on record; **deletions and no-subject events log subject NULL** (`user.deleted`, `store.deleted`, `role.deleted`, `permission.deleted`, `account.deleted`, `auth.login` "Signed in", `auth.logout` "Signed out" — logged just before logout and only if authenticated, `impersonate.stopped`, `activity.maintenance`). Description templates are short human strings, e.g. "Created user {name} ({email})", "Onboarded store owner {name} ({email}) with store {store}", "Updated their own profile", "Deleted their own account ({email})", "Switched into store {name}".

**Contract note:** only strings this doc quotes verbatim are contractual; other descriptions (and all mutation success messages except those quoted in §8/§13) are free-form human text. Subject for `user.assigned`/`user.unassigned` is the target USER (not the store).

Actions: `user.created`, `user.updated`, `user.deleted` (cascade counts in description), `user.assigned`, `user.unassigned`, `user.onboarded`, `user.registered` (actor = the new owner, §9.1), `store.created`, `store.updated`, `store.deleted`, `store.switched`, `role.created`, `role.updated`, `role.deleted`, `permission.created`, `permission.updated`, `permission.deleted`, `auth.login`, `auth.logout`, `password.changed`, `password.reset` (explicit actor), `profile.updated` (only when dirty), `account.deleted`, `impersonate.started`, `impersonate.stopped`, `activity.maintenance` (§16.5 for which runs log).

### 16.3 Deliberate exclusions (owner-accepted; do NOT log)
Failed login attempts (flood risk; the throttle handles abuse), forgot-password link requests (guest noise; the actual reset IS logged), password-confirmation checks (session-only), email-verification resend (unused feature), impersonation stop-failure/no-op paths (§14.1), scheduled maintenance runs that did nothing (§16.5), and profile submits that change nothing (§14.2's dirty-check).

### 16.4 Reading (`GET /activity`, `GET /activity/data` — permission `activity-view`)
- Newest first (id DESC). LIKE search across `action`, `description`, `actor_name`. Pagination per §19.6 (UI requests 25/page).
- **Date-range bounding (timezone-correct):** optional `from`/`to`, accepted as either a full **ISO-8601 instant** (what the UI sends) or a plain `Y-m-d` date (raw/API callers); validated as a date (malformed → 422, never silently ignored). Since `created_at` is stored in UTC, each bound is normalized to a UTC datetime: a date-only value covers the whole day (`from` → 00:00:00, `to` → 23:59:59 of that date in UTC); a full instant is compared as-is. `from` → `created_at >= fromUTC`; `to` → `created_at <= toUTC`. *Reason: partition pruning — the DB only scans the matching year partitions.*
- UI presets: the date `<input>`s hold the viewer's LOCAL date (YYYY-MM-DD) — Today = today..today; Last 7 days = today−7..today; **Last 30 days = today−30..today (default)**; This year = Jan 1..today; Custom = keeps current dates for direct editing (any manual date edit flips the preset to Custom). Every change resets to page 1 and refetches. There is deliberately NO "All time" preset (owner removed it); full history needs an explicit old Custom `from`.
- **Local-day boundaries are sent as UTC instants.** When fetching, the client converts each local date to a real instant — `from` → local 00:00:00 of that date `.toISOString()`, `to` → local 23:59:59.999 `.toISOString()` — so the window is correct in EVERY timezone (Pakistan, US, Canada…) and, crucially, a freshly-created log (stamped in server-UTC) is never hidden: local-end-of-today expressed in UTC is always ≥ "now". (Timestamps in the table are rendered with the browser's `toLocaleString()`, i.e. the viewer's own local time, from the UTC ISO value the API returns.)

### 16.5 Retention & yearly partitions (MySQL; other drivers emulate)
- RANGE partitioned by `YEAR(created_at)`. MySQL constraints that shaped it: partition key must be in every unique key → **PK (id, created_at)**; `YEAR()` over TIMESTAMP rejected → created_at is **DATETIME**; partitioned tables can't hold FKs → actor_id FK dropped.
- Layout: `p{YYYY}` per named year + **`pmax` MAXVALUE catch-all** (safety net: if maintenance never runs, new years land in pmax instead of failing INSERTs — and every action writes a log, so an INSERT failure would down the whole app). **Initial partitioning already opens the same three-named-years window maintenance enforces** (current + next two: p{Y}, p{Y+1}, p{Y+2}, plus pmax).
- **Maintenance (idempotent):** (1) FIRST drop every partition with year < currentYear−1 — partition AND data, instantly (retention = current + previous year); (2) THEN reorganize pmax to open current + next two years, re-homing rows that accumulated in pmax. Drop-before-create is an owner decision (lighten before rebuild). Non-MySQL: plain `DELETE WHERE created_at < Jan 1 of (currentYear−1)`; `created` is always `[]` and `dropped` = one `{year, rows}` entry per calendar year that had rows (counted BEFORE the delete) — so the scheduled run's "something changed" criterion there is simply "dropped non-empty".
- **Triggers & logging:** UI button (panel and button rendered for super admins only; endpoint checks `isSuperAdmin()` → else 403 **"Only a Super Admin can run activity log maintenance."**) — logs on EVERY run, description `Activity log maintenance — partitions created: {years}; dropped (with data): {years}` (lists comma-joined, empty → the word `none`). Monthly scheduler (1st, 00:30; needs a cron entry in deployment) — logs ONLY when something changed, prefix `Scheduled maintenance — …`, actor 'System'.
- Maintain response: `{message, created: [year…], dropped: [{year, rows}…]}` — the UI toasts "opened: …; deleted: … (N rows)" or "nothing to do", then refetches status + listing.
- Status endpoint (`activity-view`): `{driver, partitions: [{name, year, rows}], cutoffYear}` where cutoffYear = currentYear−1, `year` is null for pmax; a named partition's rows = COUNT for that calendar year; pmax's rows = COUNT of created_at ≥ Jan 1 of (max named year + 1). Non-MySQL synthesizes one `p{year}` row per distinct calendar year present in the data (no pmax row). The UI only fetches this panel for super admins.

---

## 17. Dashboard
- Super admin / global user → the global "statistics": the payload is exactly `{isGlobalUser, totalStores, myStores}` where `totalStores` counts live stores (computed ONLY for global users — null otherwise) — **totalStores is the only global figure; there are no other stats**.
- Store user → one card per assigned store: name, city/state, `is_active`, **their role name in that store** (single batched query for all cards; an unresolvable role shows the literal fallback **"No role"**), plus the switch button.
- Empty state (store users only — a global user with zero stores just sees stats): exact text **"You are not assigned to any store yet."**
- Route is auth-only: no permission gate, and OUTSIDE the admin throttle group (§18.5). Query count is bounded regardless of store count (tested).

---

## 18. Validation & auth catalog

### 18.1 Error shapes (the UI depends on both)
- **Field errors:** 422 with `{errors: {field: [messages…]}}` — painted under the matching inputs.
- **Guard errors:** 403/422 with `{message: "…"}` only — surfaced as a toast, never swallowed.

### 18.2 Field rules
| field | rules |
|---|---|
| user.first_name / last_name | required, string, max 255 |
| user.phone | required, numeric AND exactly 10 digits |
| user.email (create/update/onboard/signup) | required, `email` validator + regex `^\S+$` (no whitespace anywhere — rejects even RFC-valid quoted local parts; exact message **"Email cannot contain spaces."**), unique in users; **no max length, no lowercase rule** |
| profile.email (self-update — DIFFERENT) | required, string, **lowercase (uppercase input is rejected, not normalized)**, `email`, max 255, unique ignoring own row; the whitespace regex does NOT apply here |
| user.password | required on create, min 8, confirmed; optional on admin update (blank = unchanged) |
| store.* | see §12.2 (street 255 / suite 100 / city 100 / country 100 / zip 10 digits-only / state 50-states / is_active bool) |
| role.name | required, string, max 255, **NOT unique** (§10.1 — the id is the identity); only exception: never `Super-Admin` case-insensitively (reserved) |
| role.permissions | required array, min 1, ids must exist, subset rule §10.2 |
| permission.name / label | §11 / §2.4 |
| activity from/to | nullable, format `Y-m-d` |

### 18.3 Login
- Guest-only route (an authenticated user requesting it is redirected to the DASHBOARD — likewise register / forgot-password / reset-password).
- Optional boolean `remember`: when true a persistent remember-me cookie is issued, backed by `users.remember_token`.
- Throttle: 5 failed attempts, each hit expiring after 60 s, keyed `transliterate(lowercase(email)) + '|' + client IP` (transliterate = ASCII-fold, é→e; any stable normalization putting equivalent inputs in one bucket is acceptable), checked BEFORE the credential attempt and **cleared on success**. Failure → validation error on the `email` field ("These credentials do not match our records."); while locked → error on `email` from the framework's throttle template ("Too many login attempts. Please try again in :seconds seconds." — framework-default text, non-contractual).

### 18.4 Password reset
- Link request: no explicit route throttle, but the reset-link service refuses a new link within 60 s of the last for that user. The response distinguishes unknown emails (accepted, deliberate user-enumeration trade-off). All reset-flow message texts are framework-default translations — non-contractual.
- Token handling: a random token is generated, stored **HASHED (bcrypt)** in `password_reset_tokens` (one row per email — a new request overwrites the old), and only the emailed link carries the plaintext; the reset compares by hash. Tokens expire after 60 minutes.
- Reset form validates token + email + new password (min 8, with matching `password_confirmation`). Success: `password.reset` logged (explicit actor = the user), `remember_token` rotated to a fresh random 60-char value (kills every outstanding remember-me cookie), redirect to the LOGIN page — **no auto-login**. Failures surface on the `email` field.

### 18.5 Rate limits
- Register: 10/min. Login: §18.3.
- **The entire authenticated admin surface** (users/, stores/, roles/, permissions/, activity/, impersonation) sits behind a shared per-user throttle of **240 requests/minute** (429 beyond) — added deliberately as a security-audit fix. Dashboard and profile routes are auth-only, unthrottled.

---

## 19. Frontend contract (part of the system, not cosmetics)

### 19.1 UI hides, backend enforces
Every action element (buttons, nav links) is wrapped in a permission check MATCHING its route's middleware exactly; gates ship in the same change as the button. Sidebar composite rule: the "Users" nav group appears when the viewer holds ANY of user-view / role-view / permission-view, its header linking to the first permitted page in the fixed order users → roles → permissions, each sub-link individually gated; Stores and Activity Log links are gated by store-view / activity-view.

### 19.2 Assign modal mechanics
- `/users/assignable-stores` (gated `user-store-assign`) is **target-agnostic**: global/super actor → ALL live stores; store actor → only their own stores; `{id, name}` ordered by name. Filtering out the target's already-assigned stores happens **client-side** (the duplicate guard §8.1.6 is the real enforcement).
- `/users/assignable-roles` (gated `user-store-assign`) returns `Role::visibleTo(actor)` (§6.2) ordered by name as `{id, name, is_global, store_id}` — **both flags are load-bearing**: the client detects a global-role selection (`is_global` OR name == 'Super-Admin') to hide the store field and show "This role is global — no store needed."; the onboard modal drops global AND store-scoped roles (`store_id` not null) because it creates a new store (§9.2). Since the scope already limits a store user to their current store's roles, the list a store user sees is exactly what they may hand out there.
- The store dropdown is rendered only for global/super actors; a **store actor always assigns into the CURRENT store** (the client forces `store_id = current_store_id`; the UI path into another store is switching into it first). NOTE the backend nuance a port must preserve: the `can:user-store-assign` gate is evaluated in the actor's CURRENT session store context, and §8.1.5 then requires only MEMBERSHIP of the posted target store — so a raw POST with `store_id = B` from an actor whose session is store A (where they hold assign rights) succeeds if they are merely a member of B; assign rights IN B itself are not required.
- For targets flagged `is_global_user` the assign form is hidden (the global row + its Remove button remain). The Remove button on the global row renders for ANY viewer holding `user-store-unassign` — the supers-only rule for store-0 removal is enforced purely server-side (§8.2.1's 403); a non-super who sees the button gets that 403 on click.

### 19.3 Double-submit protection
Every AJAX action has an in-flight flag disabling its button until the request settles (double-click creates exactly ONE record — browser-tested). Plain full-page forms: one global guard that lets the FIRST natural submit through, flags the form, blocks further submits, disables submit buttons only on the NEXT tick (synchronous disabling would drop the clicked button's name/value from the serialized POST), leaves already-prevented (AJAX) submits alone, and re-arms everything on back/forward-cache restore (pageshow).

### 19.4 Client-side validation mirrors the backend
Same rules client-side before sending (round-trip saver); the backend stays the source of truth (stripping client validation changes no outcomes). A client-side failure sends NO request. Invalid fields get a red border and a message directly below; required labels carry a red `*`. The `data-client-invalid` attribute exists ONLY on the public registration form's track (its standalone validator sets it; a browser test asserts it) — the AJAX modal track instead drives the red border via its error-wrapper class from the shared `formErrors` state, with no such attribute. Exact client messages the browser suite asserts: required → "<Label> is required."; phone → "Phone must be exactly 10 digits."; password mismatch → "Password confirmation does not match."; empty role checklist → "Please select at least one permission.". Input hardening on the user form: the phone field filters keystrokes to digits and caps at 10; the email field blocks the space key and strips whitespace on input.

### 19.5 Listing payloads
- Users rows: selected columns id, first_name, last_name, email, phone, created_by + computed `is_super_admin`, `is_global_user`, and `roles` — the de-duplicated role NAMES across ALL the user's assignments (sentinel included), from batched queries (one roles pluck + one pivot query per page), rendered as badges ("—" when empty).
- Roles rows must include `created_by`, `is_global`, `is_signup_default`; the page also receives the viewer's id + isSuperAdmin, and shows Edit/Delete only when `isSuperAdmin || row.created_by == viewer.id` (client mirror of §10.4's 403). Flag checkboxes render for supers only; signup-default hides while global is ticked.

### 19.6 Shared listing behavior
Params `search`, `page`, `per_page` (server default 10, clamped 1–100). LIKE search columns (**case-insensitive** — the original relies on MySQL's default collation; use ILIKE/LOWER() elsewhere): users → first_name, last_name, email (grouped OR); stores → name, city; roles → name; permissions → name. Response `{<itemsKey>, total, currentPage, lastPage, perPage}` with itemsKey `users` / `stores` / `roles` / `permissions` / `logs`. Ordering: activity is id DESC; the other four listings have NO explicit ORDER BY (the original leans on DB natural order — a port should pick a stable order, e.g. id ASC). Client behavior: 400 ms search debounce resetting to page 1; a request token discards stale out-of-order responses; client page sizes 100 (users), 25 (activity), 10 (others); after a delete empties the current page the UI steps back one page and refetches.

### 19.7 Toasts
One global top-center toast container; success green / error red; auto-dismiss 5 s with manual ✕. Message-only 422/403 responses MUST reach it (a real bug once hid the Super-Admin rename error).

---

## 20. Seeding / bootstrap (idempotent; model events suppressed; safe to re-run)
1. Upsert the 20 permissions (§3) — labels repaired on every run.
2. `firstOrCreate` the `Super-Admin` role on (name = 'Super-Admin' AND created_by IS NULL); sync ALL permissions onto it.
3. `updateOrCreate` the first admin user — exact seeded identity: email **admin@gmail.com**, first_name "Admin", last_name "Momin", phone "0000000000", email_verified_at = now. Password from `SEED_ADMIN_PASSWORD` env; if unset, a random 16-char alphanumeric is generated and printed once: `SEED_ADMIN_PASSWORD is not set — generated admin password: {password}`. **Every re-run resets the password and profile fields.**
4. Sentinel attach via syncWithoutDetaching of `(store_id 0, role_id 1)` — role id **hardcoded 1** (assumes a fresh DB where Super-Admin is the first role; a re-run forces an existing store-0 row's role back to 1).
5. Seed a `Store Owner` role flagged `is_signup_default` — **guarded: only if NO role currently holds the flag** (never steal an owner-chosen default). Its permission set is exactly these **7**: user-view, user-store, user-store-view, user-store-assign, role-view, role-store, store-view (deliberately NO update/destroy/unassign, NO store-store, NO permission-*, NO activity-view — this defines what every self-registered owner can do on day one). Matched by name via firstOrCreate: an existing unflagged "Store Owner" role keeps its flag state but still receives the 7 permissions (merge without detaching extras).
6. **All seeded rows have `created_by = NULL`** (admin user, both roles). Consequences: seeded rows sit outside every visibility chain and every cascade delete; store users never see the seeded Store Owner role in their role lists (global users and supers do).
7. Running the seeder twice must not error or duplicate anything.

---

## 21. Invariants checklist (assert in the port's tests)
1. ≤ 1 role has `is_signup_default`; never a global one. (A super CAN clear it via update — registration then closes gracefully, §10.3/§9.1.)
2. A user's `store_user` rows are store_id=0 XOR store_id>0 — never both kinds.
3. UNIQUE(store_id, user_id) — one role per store per user.
4. App-created users/roles/stores always set `created_by` (seeds are NULL; signup owner may be NULL only when no super admin exists).
5. The role named `Super-Admin` always exists, cannot be renamed, cannot be deleted while held (sentinel included).
6. No endpoint that MUTATES a target record acts outside the actor's visibility (404) — exceptions: the two documented read-side/impersonation cases (§6.4), and store-switch (which mutates only session state and deliberately 403s non-members with a message, §13).
7. No role ever gains a permission its EDITOR (the actor performing that create/update) couldn't assign at that moment (§10.2 — a super admin editing someone else's role may legally attach anything), and no assignment hands out a store role the actor cannot see (§8.1.5).
8. Every state-CHANGING mutation writes exactly one activity row (§16.2); the §16.3 exclusions (no-op profile submits and quiet scheduled maintenance included) write none; guest-flow rows carry an explicit actor.
9. Users are hard-deleted only via the cascade; stores only soft-deleted; roles never deleted while assigned.
10. A store user with no selected store has zero permissions; revocation takes effect next request.
11. The users list never contains the viewer; admin endpoints never act on the actor — the 403-with-message form applies to the two §14.2 endpoints; self as an assign/real-store-unassign target surfaces as 404 (visibility excludes self), and store-0 self-unassign is the §8.2.1 422.
12. Status-code discipline: actor-identity guards → 403; target-state guards → 422; invisible targets → 404 (§8.1).

## 22. Laravel → Python translation notes (framework mechanics made explicit)
- **Authentication transport:** session-cookie based. The cookie holds a session id; the server-side session record holds the authenticated user id. "Log in" = store that id + regenerate the session id; "log out" = remove it / invalidate the session; impersonation swaps WHICH id is stored (remembering the original).
- **Remember-me:** the optional persistent cookie stores the user id + the `remember_token` value, long-lived (~5 years). On a request with NO live session, the server silently re-authenticates when the cookie's token matches `users.remember_token` — which is exactly why rotating the token invalidates every outstanding cookie. Rotation happens on password reset (§18.4) AND on logout.
- **CSRF (never optional):** every state-changing request carries a per-session CSRF token — hidden input on HTML forms, request header on the AJAX/JSON admin surface. A missing/stale token is rejected (the original uses HTTP 419; any 4xx works in a port as long as ALL mutating routes are covered).
- **`confirmed` validation rule:** the request must include a second field named exactly `password_confirmation` equal to `password`; mismatch → error keyed `password`.
- **Flash / redirect-back / "old input preserved":** flash = write-once session data consumed by exactly the next request. HTML-form validation FAILURE is not a 422 status — it is a 302 redirect back with the field errors AND the submitted input flashed, so the re-rendered form shows errors and repopulates. HTML success = 302 with a flashed status. The JSON admin surface instead uses §18.1's literal shapes, and JSON mutation SUCCESS = 200 `{message: "…", …extras}`.
- **"Pivot":** the many-to-many join table (`store_user`); "pivot row" = one row of it; "raw pivot query" = querying it directly instead of joining through `stores` (how sentinel rows stay reachable).
- **attach / detach / sync / syncWithoutDetaching** (SQL semantics): attach = INSERT a join row (with extra columns); detach = DELETE the join row(s); sync(ids) = make the related set EXACTLY ids (insert missing, DELETE extras — load-bearing for seeder idempotency); syncWithoutDetaching = upsert the pair, deleting nothing (a matched row gets its extra columns — e.g. role_id — updated).
- **firstOrCreate(match, extra)** = SELECT by match; INSERT match+extra only if absent; NEVER updates a found row (why an existing "Store Owner" keeps its flag state, §20.5). **updateOrCreate(match, values)** = same lookup but UPDATEs the found row (why every seeder re-run resets the admin password, §20.3).
- **"Model events suppressed" / "no per-row events":** the original registers NO ORM lifecycle observers; the phrases only mean seeding and the bulk cascade run as plain SQL with no per-row side effects. A port without ORM hooks can ignore them.
- **`email` validator:** RFC-style validation of `user@domain`, with the `^\S+$` regex as the tightening layer (§18.2) — calibrate any library so whitespace anywhere (quoted local parts included) is rejected with the pinned message.
- **`can:` middleware / Gate** → decorator/dependency `require_permission('user-view')` running §7 with per-request caching. Register nothing at boot.
- **Scopes** (`visibleTo`) → reusable query filters applied on reads AND write-target lookups.
- **`findOrFail`** → fetch-or-404. Validation errors → 422 `{errors:{field:[…]}}`; guard errors → `{message}` (§18.1).
- **Session** holds `current_store_id` (normalize its type! §5) and the impersonation "original admin id".
- **Soft delete** = `deleted_at` + default filter (stores only). **hashed cast** = bcrypt-on-write.
- **Timezone:** the server stores and compares `created_at` in UTC (framework default). The activity filter is timezone-correct end to end (§16.4): the client turns the viewer's local day boundaries into UTC instants before sending, and timestamps display via `toLocaleString()` in the viewer's own zone — so there is no client-local-vs-UTC skew, in any timezone or at any time of day.
- **Transactions**: signup, onboarding, cascade, role mutations, store destroy, signup-default swap.
- **Scheduler** = cron running the monthly maintenance (1st, 00:30).
- Routes shown are semantic, not sacred — keep the behavior, adapt shapes. HTML-form flows (auth pages, profile) speak redirects + flash; the admin panel speaks JSON.

## 23. Acceptance scenarios (behavioral test suite in prose — all must pass)
1. Signup: atomic owner+store; role always the flagged default (request role ignored); no default → graceful closed-signup error on `email` before field validation; invalid store half → nothing created; new owner logged in; `user.registered` actor = the owner.
2. Onboard: 3 permissions required; invisible role → 404, global role → 422 on `role_id`; country required; atomic; global-user actor allowed.
3. Visibility: supers see everyone except unrelated supers; a promoted super cannot see/edit/demote their promoter; others see only direct children; nobody sees themselves; B never sees A's users even sharing both stores.
4. Assign: exact guard order §8.1 incl. super-target 422 first, forced sentinel, ignored store id, duplicate-last; **an invisible store role posted by raw id → 404, while a global user assigning a super-created store role still succeeds**; status-code discipline holds.
5. Unassign: store-0 rules run before scoping (own-removal → 422, non-super → 403); membership rule for real stores; nonexistent assignment removal = silent success but still logged.
6. Context: one user, two stores, two roles → different powers per store; switch flips the UI; supers can't switch (even if assigned); non-members can't switch; revoked store → no permissions next request.
7. Roles: name NOT unique (one owner can hold several "Cashier" roles with different permission sets, keyed by id); `Super-Admin` reserved case-insensitively on create/rename → 422; subset rule unbreakable via create or update (message on `permissions`); creator-or-super guard 403; rename lock 422; delete-while-assigned 422 (sentinel-aware); ≥1 permission; flags per §10.3 exactly (non-super create silently drops flags with 200; super update absent-field unsets; single-holder swap atomic).
8. Permissions: delete blocked while attached; wrong password → "Password is wrong."
9. Stores: tier visibility; non-member update/delete → 404 despite permissions; delete soft-deletes + detaches; 50-states (DC rejected), zip digits, country required, is_active toggle.
10. Impersonation: super-only, never super→super, unscoped target, store context cleared both ways, demoted/deleted-admin stop → logout without log, listing flags supers.
11. Cascade: whole subtree + unworn roles + soft-deleted stores, counts exclude the target; self-delete cascades identically.
12. Activity: every §16.2 action logs; §16.3 excluded ones don't; actor_name survives actor deletion; range filter bounds and 422s on bad dates; maintenance retention/3-ahead per §16.5, super-only, manual always-logs vs scheduled only-on-change.
13. Listings: bounded query counts; per_page clamp; search columns; stale-response discard.
14. Double-click on any submit = exactly one mutation; client validation blocks bad input with zero requests.

## 24. Known sharp edges (documented REALITY — port them as-is, do not silently "fix"; each is an owner-level decision to revisit)
1. **The last super admin can self-delete.** §8.2's self-lockout guard covers only store-0 unassign; self-delete (§14.2) has no last-super check. Consequences: every self-registered owner has `created_by = firstSuperAdminId()`, so deleting the FIRST super admin cascades away ALL signup-created owners and their subtrees; and a zero-super system has nobody who can assign global roles, set flags, impersonate, or run maintenance. **Sanctioned recovery: re-run the seeder** (idempotent — recreates/repairs the admin, role, permissions).
2. **A creator keeps full power over a child later promoted to Super-Admin/global.** The non-super visibility branch is purely `created_by`, and user-update/user-destroy have no tier guard on the target — so a store-tier creator can still reset the password of, or cascade-delete, a user who has since become a super admin. Deliberate one-level-ownership behavior; only assign (§8.1.2) and store-0 unassign (§8.2.1) special-case super targets.
3. **A lingering `current_store_id` starves a newly promoted global user.** §7 prefers the store context whenever the session key is set; a promoted global user whose session still holds an old store id resolves to zero permissions (they no longer have that pivot row) until they LOG OUT and back in (logout invalidates the session; a fresh login starts with no store selected). Rare in practice: promotion requires zero store rows, so the stale id survives only across an unassign→promote sequence in one session.
4. **Soft-deleted stores can acquire phantom assignments.** Assign validation passes soft-deleted store ids (§8.1.3) and global/super actors skip the membership guard — creating a membership in a dead store; the member can even switch INTO it (a legal zero-permission context; §13's membership guard is the backstop only for actors without such a row). There is NO store-restore endpoint (recoverability is DB-level only), and restoring a row does not revive memberships detached at delete time (§12.4).
5. **The 20 permission names are de-facto system anchors with no rename lock.** Route gates hardwire the names; renaming e.g. `user-view` via permission-update makes every route gated on it deny EVERYONE (supers included — no implicit bypass) until renamed back. Deleting is incidentally blocked while attached to Super-Admin (§11). Likewise the Super-Admin ROLE's permission SET is editable by supers (only its name/deletion are locked) — super powers derive entirely from that set; the seeder re-run restores it.
6. **Duplicate-assign race** → the second attach dies on the DB unique constraint as an unhandled server error (§8.1.6). A port may catch it and return the friendly 422.
7. **Impersonated target deleted mid-session:** the session now references a missing user → the next request is unauthenticated (redirect to login); the remembered admin id dies with the session; nothing is logged. Same net effect as the deleted-admin stop branch.

---
*End of specification. Verified three times against the original codebase before delivery: pass 1 completeness (101 gaps folded in), pass 2 adversarial correctness (11 corrections), pass 3 standalone portability (59 clarifications, including this §24).*
