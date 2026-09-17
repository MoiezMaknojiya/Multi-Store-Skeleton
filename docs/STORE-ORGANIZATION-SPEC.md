# Store as an Organization — People Model Spec

> **Status:** approved direction (owner, 2026-09-16) — "follow the industrial standard, keep the UI/UX
> professional, and clean up everything after building." Built on `ads-feature`, no commits.
> This file is the source of truth for the rebuild; the checklist at the bottom tracks progress.

## 0. Why

The old model gave power through `created_by` ("whoever made you owns you"): people were visible only
to their creator, deleting a person cascaded through everyone they had created, and admins set other
people's passwords. The loophole hunt (2026-09-16) proved two consequences: a store user could reset
the password of a person they created after that person was promoted to Super-Admin, and deleting
that store user deleted the promoted Super-Admin.

The industry model (Slack workspaces, GitHub organizations, Shopify stores) gives power through
**membership**: a person is a member of an organization with a role; the organization owns its
content, its roles and its members list; nobody owns anybody.

**Here, a Store is the organization (tenant).** An account layer above stores (one bill for several
shops) is out of scope; these rules stay the same if it is added later.

## 1. Decisions taken

| Question | Decision | Why |
|---|---|---|
| How do people join a store? | **Email invitation only**; the invitee sets their own password | Industry standard; no admin ever knows a password |
| Custom roles? | **Kept**, as the property of one store, next to the store roles the super admin makes | Already built; standard (platform-defined + custom) |
| Built-in roles? | **None but Super-Admin** (2026-09-17). The super admin makes every other role and says what it is for: a **store role**, offered in every store, or a **platform role** for the team above the stores. Owner, Admin, Staff and Viewer are only the starter store roles — renamed, changed and (Owner aside) deleted like any other | Owner's rule (2026-09-17): "yeh built-in role wala chakar hata do" |
| Does a role's name decide anything? | **No — its permissions do.** Whatever a role is called, its holders do exactly what its permissions allow; nothing is "Owner-only" | Owner's rule (2026-09-17): "role matter nahi karta permission matter karta ha" |
| How does the system know a store's owner? | **The Owner role marker** (`roles.key = owner`): its holders are the store's owners — a store may have **several** (partners) — signup, owner invitations and Create store give it, a store changes hands by giving it on the Members page, "at least one Owner" counts it. Renamable, never deleted; the Stores list has no Owner column | Owner's choices (2026-09-17) |
| Deleting a role somebody holds | **Refused, with the reason** — Delete is offered on every role but Super-Admin and the Owner role, and a held one answers "Please unassign {role} from everyone first" (a toast) | Owner's rule (2026-09-17) |
| Stores that grew copies of one role | **One store role per name**: same-named copies were merged, people and invitations moved, permissions gathered — it gave "No owner" stores their owners back. That migration was squashed away once the one database that needed it had run it (2026-09-17) | Owner's choice (2026-09-17) |
| Store deletion | **Immediate, complete and for good** (no 30-day trash, no soft delete): everything the store owns — the roles made in it included — goes with the store row; only the people's accounts and the activity log stay | Owner's earlier explicit rule (delete media from storage at once) and "A to Z" (2026-09-17) |
| Billing / account layer | Out of scope | Subscription is a separate future branch |
| Can a member create a new store? | **Only when their role carries `store-store`** — the super admin decides; the new store is theirs (Owner), nobody is invited | Owner's rule (2026-09-16); off by default, so free-screen abuse stays closed unless the platform opens it |
| Super admin's access | **Every permission, always** (`Gate::before`), and every role but Super-Admin is theirs to change. Rules that are not permissions still hold | Owner's rules "sab matlab sab" and "super admin per sari permission dikhao" (2026-09-16) |
| The role form's checklist | **Only what that role can hold** — no greyed-out checkboxes; the list follows the role's type | Owner's rule (2026-09-17): "Role K Ander Permission K checkbox dikhao hi nahi" |
| Platform permissions on a store's role? | **Yes, for that store alone**: the Stores tab of Settings, channels, reading the activity log. Never the accounts (a store's people are its Members page — 2026-09-17), yearly log maintenance or the permission catalogue | Owner's rule (2026-09-16): "store k hisab se work kare" |
| Special access for one person in one store | The super admin makes a store role with what that person needs and gives it to them in that store from Users → Stores | Owner's choices (2026-09-16, 2026-09-17) |
| Putting a person in a store from the platform | **Straight in** (Users → Stores): the super admin adds anybody to any store with a role, changes it, or takes them out — no invitation | Owner's rule (2026-09-17): "super admin kisi ko bhi store k ander assign kar sake role deke" |
| Where a store is changed from inside it | **Settings → Stores** — a tab beside Profile, opened from the person's name and shown with View Stores; no sidebar link, and no Stores page for a store's people. The store worked in (details, Delete Store laid out like Delete Account), "Your stores" and Create store, each by its own permission | Owner's rules (2026-09-17): "store k ander ja k store setting mein ja k karna honga" |
| Deleting a store from inside it | The permission **`store-destroy`**, which the Owner starts with — the super admin may take it from Owners or give it to another role | Owner's rule (2026-09-16): "dena naah dena woo super admin per ha" |
| Handing a store over | **On the Members page**: whoever may give the Owner role makes another member Owner, and the new Owner may change the first one's role — no Transfer Ownership screen or permission | Owner's rule (2026-09-17): "change role kar sakta hu toh transfer ownership ki zarurat nahi" |
| Accounts inside a store | **None** — a store's people are its Members page; View Accounts and Delete Accounts work above the stores only | Owner's rule (2026-09-17): "members k tab mein sub araha ha toh accounts k tab ki zarurat nahi" |
| Invite owner on the platform | **Only for a store with no Owner**; more Owners come from the store's Members page or Users → Stores | Owner's choice (2026-09-17) |
| Deleting an account | Its memberships and everything pointing at it go (sessions, reset link, tokens, invitations waiting for its email); what the person made stays with its store | Owner's rule (2026-09-17): "jaha kahi per bhi link ha toh hata dena" |
| Campaigns | Stay a super-admin gate, not a permission row | Owner's choice (2026-09-16) |
| Deleting | **Big deletes ask for the password** (store, account, role, permission, channel, campaign, member, platform role); everyday deletes stay one confirmation | Owner's rule (2026-09-16); a session left open must not destroy with two clicks |
| Super admins among themselves | All super admins see each other; only the **primary** super admin (the first one) may invite a new super admin, remove Super-Admin from someone or delete a super admin account; nobody may act on the primary | Replaces the old `created_by` protection ("a promoted super admin cannot act on who promoted them"); granting sits with whoever can undo it |

## 2. Vocabulary

- **Account** (`users`) — an identity: name, phone, email, password. Owned by the person.
- **Store** — the organization. Owns members, custom roles, invitations, screens, media, playlists, dayparts.
- **Membership** — a `store_user` row `(user_id, store_id > 0, role_id)`. One role per person per store.
- **Platform membership** — a `store_user` row with `store_id = 0` holding a global role (unchanged sentinel).
- **Store role** — a role the super admin makes for the stores: offered in every store, the same everywhere.
- **Owner role** — the store role marked `roles.key = owner`; its holders own their store.
- **Starter roles** — the store roles an installation begins with (`Role::STARTERS`: Owner, Admin, Staff, Viewer,
  keys `owner`/`admin`/`staff`/`viewer`). Only the Owner's key means anything; the others are ordinary store roles.
- **Custom role** — a role created inside one store: `roles.store_id` = that store, `key` NULL.
- **Platform role** — `roles.is_global = true` (Super-Admin and any platform role the super admin creates).

Role invariants (enforced by code, established by the data migrations):

| Kind | `key` | `is_global` | `store_id` |
|---|---|---|---|
| Store role | `owner` on the Owner role; a starter key or NULL otherwise | false | NULL |
| Custom | NULL | false | the store |
| Platform | NULL | true | NULL |

## 3. Permissions

`App\Models\Permission` holds the catalogue lists; RoleController enforces them; the Roles page mirrors them.

**Where a permission reaches is where the role sits** (owner's rules, 2026-09-16): on a platform role,
every store; on a store role or a custom role, the one store the member holds it in.

**Store permissions (22)** — a store's own work:

- `store-update` — edit this store's details
- `member-view`, `member-invite`, `member-update` (change role), `member-remove`
- `role-view`, `role-store`, `role-update`, `role-destroy` — this store's custom roles
- `screen-view`, `screen-store`, `screen-update`, `screen-destroy`, `screen-playlist`
- `media-view`, `media-store`, `media-update`, `media-destroy`
- `daypart-view`, `daypart-store`, `daypart-update`, `daypart-destroy`

**Platform permissions (11)** — above the stores on a platform role; `Permission::STORE_SCOPED` marks the
eight that a store's role may carry too, and what they mean there:

| Permission | On a platform role | On a store's role (this store alone) |
|---|---|---|
| `store-view` | the Stores page: every store | the Stores tab of Settings, for the store they work in (turning it off hides the tab) |
| `store-store` | create a store + invite its owner; Invite owner (a store with no Owner) | Create store on that tab: a store they own at once (no invitation; active stays the platform's switch) |
| `store-destroy` | delete any store | Delete Store on that tab, for the store they work in — the Owner's by default |
| `user-view` | every account (support: store accounts only) | **never** — a store's people are its Members page (2026-09-17) |
| `user-destroy` | delete an account | **never** — deleting accounts is the platform's |
| `channel-view`, `-store`, `-update`, `-destroy` | every channel; one made here is offered to every shop | the store's own channels, offered to its own screens |
| `activity-view` | every entry | this store's entries |
| `activity-destroy` | yearly maintenance | **never** — it drops every store's history at once |

**Super-Admin role only (4):** `permission-view`, `permission-store`, `permission-update`, `permission-destroy`.

Rules:
- A store role or a custom role carries store permissions and the store-scoped platform ones (`Permission::belongsToStores`).
- A platform role (not Super-Admin) may carry store + platform permissions (a support person with
  `media-view` reads every store's library, as today).
- The Super-Admin role carries everything.
- A permission in none of the lists (created on the Permissions page) counts as platform-only.
- The role form lists only what the role in it can hold — nothing greyed out (2026-09-17): for a store role what
  works inside a store, for a platform role everything but the catalogue; it follows the type chosen for a new role.
  A member inside a store is offered what they hold there.

**Removed permissions** (mapped by the data migration, then deleted): `user-store`, `user-update`,
`user-store-view`, `user-store-assign`, `user-store-unassign`.

**No Owner-only abilities** (owner's rule, 2026-09-17: a role does not matter, its permissions do). Deleting the store
is `store-destroy`, which the Owner role starts with and nobody else does; handing it over is giving the Owner role on
the Members page (rule 11) — Transfer Store Ownership (`store-transfer`) was removed the same day. Giving or
managing the Owner role follows the same reach as any role (rules 7–8).

## 4. Store roles (owner's rules, 2026-09-17)

No role is built in but Super-Admin. The super admin makes a role on the Roles page and says what it is for — **Store
role** (offered in every store; what it allows reaches the member's own store) or **Platform role** (the team above
the stores) — and renames it, changes what it allows (a store role: in every store at once) and deletes it. Delete is
offered on every role but Super-Admin and the Owner role; one somebody still holds is refused — the page says so at once
as a toast, **"Please unassign {role} from everyone first: {n} person still holds it."**, and the server refuses it again.
What a role is for is fixed once it exists. Inside a store the store roles are read-only (their permissions
viewable) and the store makes its own custom roles beside them.

**The Owner role** is the store role marked `key = owner` (`Role::owner()`): its holders are the store's owners — a store
may have several — public signup, owner invitations and Create store give it, rule 9 counts it, and a store changes
hands by giving it (rule 11).
It is renamed and changed like any store role, and never deleted. What an Owner may do is what the role's permissions
allow, like any role.

An installation starts with these store roles (`Role::STARTERS`) — a starting point only, the super admin's to change:

| Role | Permissions to start with |
|---|---|
| **Owner** | every store permission, `store-view` and `store-destroy`; a migration adding a store permission grants it here |
| **Admin** | every store permission and `store-view` — the difference is deleting the store (and being an Owner, rule 9) |
| **Staff** | `screen-view`, `screen-playlist`, `media-view`, `media-store`, `media-update`, `media-destroy`, `daypart-view` |
| **Viewer** | `screen-view`, `media-view`, `daypart-view` |

One migration inserts the permission catalogue and all four roles (`2026_09_16_110200_insert_permissions_and_starter_roles`);
the seeder repairs the labels, makes the Super-Admin role and the admin account, and only puts the Owner role back
should it be missing.
Neither touches an existing role, so a re-seed never undoes the super admin's changes or brings back a role they deleted.

**Names** are compared the way a person reads them (accents, case, spacing and punctuation folded away) against every
list the role is shown in: a store role against every store role and every store's custom roles, a custom role against
its store's list (the store roles and its own), a platform role against the platform roles. Nothing is named like
Super-Admin.

**Upgrading** is history: the migrations that carried the owner's own database from the old people model to this one —
the people conversion, the merge of same-named store roles (which is what gave "No owner" stores their owners back),
the store-transfer permission and its removal, and the purge of soft-deleted stores — were squashed away on 2026-09-17,
once that one database had run them all and no other installation existed. `database/migrations` now builds the final
schema and the starter data directly.

## 5. Rules

### A. Accounts
1. Nobody owns an account. Name, phone, email and password are changed only by the person (Profile) or
   by "forgot password".
2. One email = one account; one account may be a member of many stores with a different role in each.
3. Platform and store tiers stay exclusive: a platform account is never a store member, and vice versa.

### B. Membership & roles
4. Power inside a store comes only from the membership in that store (current-store permission check, unchanged).
5. Custom roles belong to the store: every member holding `role-view` sees them; `role-update` /
   `role-destroy` holders manage them regardless of who created them; a custom role survives its creator.
6. A custom role cannot be deleted while a member holds it (unchanged).

### C. Hierarchy
7. **Assign** (invite or change role): the target role must be available in the store (a store role or this
   store's custom role), and every permission of it must be held by the actor in this store — the Owner role
   like any other (owner's choice, 2026-09-17: "apni permissions tak").
8. **Manage an existing member** (change role / remove): not yourself; every permission of the member's
   current role must be held by the actor — an Owner's included.
9. **At least one Owner, always.** The last Owner cannot be demoted, removed, leave, or delete their account —
   whoever asks: reach is permissions only (rules 7–8), so every change or removal of a member checks this on its own,
   and the Members page offers no Change role or Remove on a store's only Owner.
   Every change that could break this is decided under a lock on the store row (`StoreTeam::changeTeam`),
   so two co-owners acting at the same moment cannot both pass.
10. **Leave store:** any member, except the last Owner — from the Members page, from "Your stores" on Settings →
    Stores, or — for whoever has no Stores tab (no View Stores where they work, like Staff and Viewer) — from
    "Your stores" on the profile. Leaving returns to the page it came from; leaving the store being worked in goes
    to the profile.
11. **A store changes hands on the Members page** (owner's rule, 2026-09-17 — there is no Transfer Ownership screen or
    permission): whoever may give the Owner role (rule 7) changes another member's role to Owner, and the new Owner may
    then change the first one's role (rule 8). Rule 9 keeps the store from being left without an Owner in between.

### D. Visibility
12. Store context: `member-view` shows **every** member of the current store (the actor included, marked
    "You") and the pending invitations. Never people outside the store, never platform accounts. A store's people
    have no Accounts page: the accounts pages are the platform's alone (2026-09-17).
13. Platform: a super admin sees every account; a platform user holding `user-view` sees store accounts
    only (not platform staff).
14. Out-of-scope ids answer 404 (unchanged).

### E. Joining
15. **Invite** (`member-invite`): email + role (rule 7). Refused when the email is already a member, a
    pending invitation for that email exists (use Resend), or the email belongs to a platform account.
    Link valid **7 days**. Resend rotates the token and resets the expiry; Revoke deletes it.
16. **Accept:** the link shows store, role and inviter.
    - Signed out, account exists → "Sign in to accept" (returns to the link).
    - Signed out, no account → create account (email fixed from the invitation, marked verified).
    - Signed in with the same email → Accept / Decline.
    - Signed in with another email → explanation + sign out.
    - Accepting creates the membership, deletes the invitation, and switches the session to that store.
      The invitation row is taken under a lock, so a double submit or a second tab never uses a link twice.
    - Accepting an invitation to a store you are already in only uses it up (logged).
    - The email is sent through `Invitation::sendLink()`: a mail server that refuses is reported, the
      invitation stands, and the response says the email was not sent (shown as an error, not a 500).
17. **Public signup:** creates the account (email lowercased), the store, and an **Owner** membership (no
    signup-default flag any more).
18. **Platform store creation** (`store-store`): store details + owner email → the store is created and an
    Owner invitation is sent. **Invite owner** is offered only for a store with **no Owner** (owner's rule,
    2026-09-17 — "{store} already has an Owner." otherwise); more Owners come from the store's own Members page, and
    the super admin can also put somebody in as Owner from Users → Stores (rule 32).
    **Invite owner:** an email that belongs to a member of the store makes them Owner at once
    (`store.owner_assigned`); the same address as an open invitation gets a fresh Owner link, even when it
    had expired; a different address — or a member made Owner — **replaces** the earlier owner invitations,
    so a mistyped email stops working.
    A member whose role carries `store-store` opens a store from inside theirs (Settings → Stores → Create store) and
    becomes its Owner at once; nobody is invited.
19. **Platform staff** (super admin only): invite an email with a platform role; accepting creates the
    `store_id = 0` membership. Only the **primary** super admin may invite a new super admin — the one who
    can also take that role away (rule 24).

### F. Leaving & deleting
20. **Remove member / leave:** the membership row only. Uploads, paired screens and custom roles stay
    with the store (`created_by` stays as history).
21. **Delete account** (Profile, or platform `user-destroy`): the account and its memberships — nothing
    else of anybody's — and nothing is left pointing at it (owner's rule, 2026-09-17): its sessions on every device,
    its password-reset link, any API token and every invitation waiting for its email (to a store or the platform)
    go too, and `created_by`, `invited_by` and the log's actor empty (what the person made — invitations they sent
    included — stays with its store; the log keeps their name). Self-deletion is refused while the person is the
    last Owner of any store (the message names the stores). A platform deletion is allowed; the response names the stores left without an Owner, and the super
    admin gives them one (Invite owner, or Users → Stores).
    - Super admins cannot delete themselves (unchanged).
    - Deleting a platform account needs a super admin; deleting a super admin needs the primary one.
    - A store's people never delete accounts: that is the platform's (2026-09-17).
22. **Delete store** (`store-destroy` — inside a store the Owner's by default, for the store worked in, from Settings →
    Stores; on the platform any store, from the Stores page — always the typed name and the password): everything the store owns goes in one transaction — memberships, invitations, custom roles,
    screens (devices lose their token), playlists and schedule rules, dayparts, the store's own channels (their
    ads and the lines carrying them), media rows, and media and channel files after commit. **The store row goes
    too, for good** (owner's rule, 2026-09-17: "A to Z") — no soft delete, and `roles.store_id` cascades, so a custom
    role never outlives its store. Accounts stay, and so does the activity log (its entries keep the store's id and
    name).
23. **No `created_by` cascade anywhere.** `users.created_by` is dropped; `created_by` on content is history only.

### G. Platform
24. Remove a platform role: super admin; not yourself; removing Super-Admin needs the primary super
    admin; nobody touches the primary.
25. "Log in as": super admin only, audited, onto any account that is not a super admin (platform staff
    included). The way back lives in the session only while the impersonated account is the one signed in;
    every sign-in clears it. Campaigns, activity log maintenance and permissions stay platform-only; channels, the
    activity log and the Stores tab answer an impersonated member for their own store (rule 29).
26. **Super-Admin is recognised by its exact name, compared in PHP** (`Role::superAdminId()`), never by a
    SQL `WHERE name =`: MySQL's accent-insensitive collation would let "Súper-Admin" pass for it. A role name
    that folds to Super-Admin, or to a name already in a list the role is shown in (§4), is refused.
27. **A super admin holds every permission** — a `Gate::before` answers every permission check, whatever the
    Super-Admin role's rows say. Rules that are not permissions still stand (rules 9, 21, 24, 26; Super-Admin never
    changed, the Owner role never deleted).
28. **Big deletes re-confirm the password:** a store, an account, a role, a permission, a channel, a campaign,
    removing a member, taking a platform role. Asked after every other refusal; five wrong passwords a minute per
    person, then a pause.

### H. Platform permissions inside a store (owner's rules, 2026-09-16)
29. **A store's role may carry the store-scoped platform permissions** (§3 table), and there they reach that store
    alone. The store permissions act on the store the person works in and nothing else: a store is changed from inside
    it, on Settings → Stores (rule 33) — a store's people never reach the platform's Stores page. What only works above
    the stores keeps the `global-tier` lock: the Stores page and its writes, the accounts pages, activity log maintenance and its storage
    panel, Invite owner, "Log in as", the platform team, and putting people in stores from the platform (rule 32, also
    `super-admin-tier`).
30. **A store's own channels:** made inside the store (stamped with it), listed, opened and changed only there;
    offered to that store's screens alone. The platform's channels stay offered to every shop and out of a store's
    reach; another store's channels are never found. A name stands apart within one store's list (the platform's
    channels and its own). The platform lists every channel with whose screens it reaches.
31. **A store's own history:** every entry carries the store it belongs to (its subject's, or the store named for a
    delete or a person); a store's role carrying `activity-view` reads those entries alone. Entries logged before
    the column existed, personal account actions and the platform's own work belong to no store.
32. **The super admin puts people in stores from above** (owner's rule, 2026-09-17): Users → **Stores** on a store
    account lists the stores they are in, each with its role to change or a Remove, and adds them to any other store
    with any role that store has (the store roles and its own custom roles) — straight in, nobody invited. A platform
    account is refused (the tiers stay exclusive), somebody already in the store is told to change their role there,
    and every change keeps at least one Owner under the store's lock; taking someone out asks for the password and
    leaves what they made with the store. The Roles page lists every store's custom roles in a card of their own for
    the super admin to edit or delete.

### I. Settings (owner's rules, 2026-09-17)
33. **Settings** is opened from the person's name (the foot of the sidebar, the header's menu) and has tabs: Profile,
    and **Stores** for a store member working in a store whose role there holds View Stores (`store-view` — turning it
    off hides the tab, whatever the role is called). The sidebar has no link to it. Each card is its own permission:
    Store Details (editable with `store-update`, read-only without), **Your stores** — every store the person belongs
    to with their role and Leave, plus **Create store** (`store-store`) — and Delete Store (`store-destroy`, laid out
    like Delete Account: the store's name typed, then the password). Whoever has no Stores tab finds "Your stores" on
    the profile. There is no handover here: a store changes hands on the Members page (rule 11).

## 6. Endpoints

**Store context** (`auth`, `throttle:admin`):

| Method & path | Gate | Action |
|---|---|---|
| GET `/members` | `member-view` | Members page |
| GET `/members/data` | `member-view` | members (with `can_manage`), invitations, assignable roles |
| PUT `/members/{user}` | `member-update` | change role (rules 7–9) |
| DELETE `/members/{user}` | `member-remove` + password | remove (rules 8–9) |
| POST `/members/leave` | auth | leave the current store (rule 10) |
| DELETE `/profile/stores/{store}` | auth (member of that store) | leave a store from "Your stores" (rule 10) |
| POST `/members/invitations` | `member-invite` + `throttle:invitations` | invite (rule 15) |
| POST `/members/invitations/{invitation}/resend` | `member-invite` + `throttle:invitations` | resend |
| DELETE `/members/invitations/{invitation}` | `member-invite` | revoke |
| GET `/settings/store` | `store-view` | Settings → Stores (rule 33) |
| PUT `/settings/store` | `store-update` | update details |
| POST `/settings/store/open` | `store-store` | Create store: a store the person owns at once (plain form, `store_` fields) |
| DELETE `/settings/store` | `store-destroy` + password | delete store (rule 22) |

**Stores, accounts, channels, activity** (`auth`, `throttle:admin`) — above the stores for a platform role; channels
and activity also inside a store for a store's role carrying the permission, for that store alone (rules 29–31):

| Method & path | Gate | Action |
|---|---|---|
| GET `/stores`, `/stores/data` | `global-tier` + `store-view` | every store with its members count; `can` (update, destroy, invite_owner) per row |
| POST `/stores` | `global-tier` + `store-store` | create store + owner invitation |
| PUT `/stores/{store}` | `global-tier` + `store-update` | edit details and the active switch |
| DELETE `/stores/{store}` | `global-tier` + `store-destroy` + password | delete store (typed name) |
| POST `/stores/{store}/owner-invitation` | `global-tier` + `store-store` | give a store with no Owner one: promote a member, or invite (the same address re-sent, a different one replacing the earlier invitation); 422 when it has an Owner (rule 18) |
| POST `/stores/switch` | auth | switch the current store (members) |
| GET `/users`, `/users/data` | `global-tier` + `user-view` | every account with its memberships / platform role (Super-Admin offered in the invite modal to the primary super admin only) |
| DELETE `/users/{user}` | `global-tier` + `user-destroy` + password | delete account (rule 21) |
| GET `/users/{user}/stores` | `global-tier` + `super-admin-tier` | the person's memberships, the stores they are not in, the store roles and each store's custom roles (rule 32) |
| POST `/users/{user}/stores` | `global-tier` + `super-admin-tier` | put the person in a store with a role — no invitation (rule 32) |
| PUT `/users/{user}/stores/{store}/role` | `global-tier` + `super-admin-tier` | change the person's role in that store (rule 32) |
| DELETE `/users/{user}/stores/{store}` | `global-tier` + `super-admin-tier` + password | take the person out of that store; the store keeps an Owner (rule 32) |
| DELETE `/users/{user}/platform-role` | `global-tier` + `super-admin-tier` + password | remove platform role (rule 24) |
| `/channels/*` | `channel-*` | channels within reach (`Channel::visibleTo`, rule 30) |
| GET `/activity`, `/activity/data` | `activity-view` | entries within reach (rule 31) |
| GET `/activity/partitions`, POST `/activity/partitions/maintain` | `global-tier` + `activity-view` / `activity-destroy` | yearly storage |
| GET `/roles`, `/roles/data` | `role-view` | platform (super admin): every role, stores' custom roles included · store: the store roles and this store's custom roles |
| POST `/roles` | `role-store` | platform: a store role or a platform role (`type` = `store` / `platform`) · store: a custom role of this store |
| PUT `/roles/{role}` | `role-update` | name + permissions; platform: any role but Super-Admin · store: its own custom roles within reach |
| DELETE `/roles/{role}` | `role-destroy` + password | nobody may hold it; never Super-Admin or the Owner role; revokes its pending invitations |
| GET `/roles/assignable` | `role-view` | the checklist: only what the role can hold (`?type=` for a new role on the platform, `?role=` when editing) |
| GET `/users/invitations` | `super-admin-tier` | pending platform invitations |
| POST `/users/invitations` | `super-admin-tier` | invite platform staff (Super-Admin: primary super admin only) |
| POST `/users/invitations/{invitation}/resend`, DELETE `/users/invitations/{invitation}` | `super-admin-tier` | resend / revoke |
| POST `/users/{user}/impersonate` | `global-tier` + super admin (controller) | Log in as |

**Public** (`throttle:invitation-response`):

| Method & path | Action |
|---|---|
| GET `/invitations/{token}` | invitation page (all states of rule 16) |
| POST `/invitations/{token}/accept` | auth, email must match |
| POST `/invitations/{token}/register` | guest, create account + membership |
| POST `/invitations/{token}/decline` | delete the invitation |

**Removed:** POST `/users`, PUT `/users/{user}`, `/users/onboard`, `/users/assignable-stores`,
`/users/assignable-roles`, the old `/users/{user}/stores` endpoints of the `created_by` model (the paths now serve
rule 32, behind the super-admin tier); the `is_global` / `is_signup_default` flags on the role form (a new role's
type replaced them); `/users/{user}/store-roles` and the Roles page's `?store=` picker (2026-09-17); Transfer ownership
(`POST /settings/store/transfer-ownership`, the `store-transfer` permission) and the Accounts page inside a store
(2026-09-17, third round).

## 7. Data model

**`roles`:** add `key` (string 32, nullable, unique); drop `is_signup_default`.

**`invitations` (new):**
- `id`
- `store_id` — nullable FK → stores, cascade; NULL = platform invitation
- `email` — lowercased
- `role_id` — FK → roles, cascade
- `token_hash` — char 64, unique, sha256 of a random 64-char token that only ever lives in the email link
- `invited_by` — nullable FK → users, null on delete
- `expires_at`, timestamps

**`users`:** drop `created_by`.

**`stores`:** no `deleted_at` — a deleted store goes for good. **`roles.store_id`:** cascades on delete, so a custom
role never outlives its store.

**What a fresh install gets** (21 migrations, squashed on 2026-09-17 — the conversion migrations that upgraded the
owner's own database are gone, and with them the only upgrade path from the old model): the tables above in their final
shape, plus one data migration (`2026_09_16_110200_insert_permissions_and_starter_roles`) that inserts the 37
permissions of `Permission::LABELS` with their labels and the four starter store roles — Owner (every store permission,
`store-view`, `store-destroy`), Admin (every store permission, `store-view`), Staff (7) and Viewer (3). `db:seed` then
adds the Super-Admin role holding the whole catalogue and the admin account. A permission added later ships a migration
of its own; that baseline file is never edited.

## 8. UI

Existing design system only: `x-app-layout`, `card`, `btn-*`, `badge-*`, `x-crud.*`, `x-modal`, toasts.
Two form tracks: AJAX modals for lists, plain POST for settings and guest pages. Dark mode, mobile-safe
tables, `dusk` selectors on every interactive element.

**Sidebar**
- Store context: Dashboard · Screens · Dayparts · Media Library · **Team** (Members, Roles) ·
  Channels (channel-view) · Activity Log (activity-view) — each link only when the role there carries it. No Stores
  link: the store is changed from Settings → Stores (rule 33).
- Platform: Dashboard · Stores · Users · Roles · Permissions · Advertising · Channels · Activity Log
  (plus content pages a platform role holds).
- At the foot: the person's name and "Settings", opening Settings (the header's user menu says "Settings" too).

**Members page** (store)
- Header with store name + member count.
- Actions: **Invite member** (primary), **Leave store** (secondary).
- Tabs: *Members* · *Pending invitations (n)*.
- Members table: avatar initials + name + email + "You" badge · role badge (Owner = amber, Admin = blue,
  others = neutral) · Joined date · Change role / Remove (only when `can_manage`).
- Invitations table: email · role · invited by · expires ("in 6 days" / "Expired") · Resend / Revoke.
- Modals:
  - Invite: email, role select with one-line role descriptions, "link expires in 7 days".
  - Change role.
  - Confirm remove: states that the content stays.
  - Confirm leave.

**Settings** (plain forms; tabs `x-settings-tabs`, one page each)
- **Profile** tab: see Profile page below.
- **Stores** tab (rule 33, store-view): "Store Details" (the form for store-update — its address rows sit side by side
  only when the card is wide enough; read-only otherwise) beside "Your stores" (every membership with its role and
  Leave; a Create store button for store-store opening a modal with the store's details), then "Delete Store"
  (store-destroy — laid out like Delete Account: what goes, the roles made in the store included, then a modal with the
  typed name + password).

**Invitation page** (guest layout): store, role, inviter; the state-specific form of rule 16; expired or
invalid state.

**Platform Stores page** (platform accounts only)
- Name, Location, Members, Status (and Adverts for the super admin) — no Owner column: a store may have several Owners.
- Create store modal gains the owner email.
- Row action "Invite owner" only on a store with no Owner (same address → a fresh link; a new address, or a member
  made Owner, replaces the earlier owner invitation).
- Delete asks for the typed store name and the password.

**Platform Users page**
- Account · Access (platform role badge, or per-store "Role · Store" badges) · Joined · actions
  (Log in as, Stores, Remove platform role, Delete).
- **Stores** (super admins, store accounts — rule 32): "Member of" lists each store with a role select, Save and
  Remove (password), the chosen role's description under it; "Add to a store" picks a store they are not in, then a
  role that store has, and Add.
- Invite platform staff modal + pending platform invitations.
- No create-with-password, no edit of an account.


**Channels page**: above the stores every channel, badged "Every shop" or "{store} only"; inside a store "Channels of
{store}", its own alone. The playlist's Channels box marks a store's own channel "This store".

**Activity Log page**: above the stores every entry and — for activity-destroy — the yearly storage panel; inside a store
"Activity in {store}", its entries alone, no storage panel.

**Profile page**
- "Your stores" (store accounts without a Stores tab): every membership with its role and a Leave button behind a
  confirmation; the last Owner of a store sees why they cannot leave instead.

**Roles page** — one list with a Type column (Super admin · Store role · Platform role · Custom role)
- Store: the store roles (read-only, permissions viewable) then the store's custom roles (CRUD, what the member holds).
  Deleting a role asks for the password and revokes its pending invitations; the confirmation says so. A role
  somebody holds answers Delete with the toast "Please unassign {role} from everyone first…" and no dialog.
- Platform (super admin): Super-Admin (view only — every permission, always), the Owner role (badged Owner; Edit, no
  Delete), the other store roles and the platform roles (Edit, Delete); below, a card "Custom roles made in stores"
  with each role's store (Edit, Delete).
- **Create role** (platform): name, "What is this role for?" — Store role / Platform role — and the checklist.
  Editing never changes the type; the name is editable on every role but Super-Admin.
- The checklist lists only what the role can hold, nothing greyed out; on a store or custom role the Stores,
  Channels and Activity log groups say "This store only".

**Dashboard**
- A signed-in person with no store sees "You're not a member of any store yet" and is told to ask a
  store's owner to invite their email, then open the link in that email.
- Deliberately **no** list of pending invitations with Accept buttons. Public signup does not
  verify the email, so anyone could register somebody else's address and accept that person's
  invitations from the list. Only the emailed link — which proves the inbox — accepts.

**Email:** `InvitationNotification` (markdown mail): "{Inviter} invited you to join {Store} as {Role}" ·
Accept button · expiry line.

## 9. Activity log

New actions:
- `member.invited`, `invitation.resent`, `invitation.revoked`, `invitation.declined`, `invitation.accepted`
- `member.role_changed`, `member.removed`, `member.left`, `member.assigned` (put in a store from the platform)
- `platform.role_removed`, `store.owner_invited`, `store.owner_assigned`,
  `platform.invited`

Existing kept: `store.created`, `store.updated`, `store.deleted`, `user.deleted`, `account.deleted`,
`user.registered`, impersonation.

Removed: `user.created`, `user.updated`, `user.assigned`, `user.unassigned`, `user.onboarded`, and
`store.ownership_transferred` with Transfer ownership (2026-09-17 — earlier entries stay in the log).

## 10. Tests

Feature (as built):
- `MemberDirectoryTest`; `MemberManagementTest` (change role, remove, leave, hierarchy, last Owner);
  `InvitationTest` (create / resend / revoke / accept / register / decline / expiry / throttle / notification)
- `StoreSettingsTest` (details, a store changing hands on the Members page, full-purge delete), `StoreDeletionPurgesMediaTest`, `StoreCrudTest`
- `PlatformStoresTest`, `PlatformUsersTest`, `PlatformPermissionTiersTest`, `ChannelPermissionRoleTest`
- `AccountDeletionTest`, `RoleScopesTest`, `Auth/RegistrationTest` (signup → Owner),
  `DatabaseSeederTest` (catalogue lists + starter roles), `DatabaseSchemaTest` (what a fresh migrate leaves: the
  catalogue, the starter roles, the roles→stores cascade, no `deleted_at`)
- `StoreOwnerLoopholeTest` — the adversarial suite, rewritten against the new model
- Store-scoped platform permissions (2026-09-16): `StoreScopedPermissionsTest` (checklist, refusals, the delete on
  Settings → Stores, Stores / Activity inside a store, sidebar), `StoreChannelsTest`, `PlatformStoreRolesTest`
  (rewritten 2026-09-17 for Users → Stores)
- Roles made by the super admin (2026-09-17): `RoleScopesTest` (store / platform / custom roles, names, the Owner
  role never deleted, the checklist by type), `PlatformStoreRolesTest` (Users → Stores: add, change, remove, refusals),
  `StoreSettingsTest` (Settings tabs)
- Permissions, not roles (2026-09-17, second round): `StoreSettingsTest` (leaving from the tab),
  `StoreScopedPermissionsTest` (the Stores tab by View Stores, each card by its permission, Your stores, Create store,
  the sidebar), `PlatformStoresTest` (no owners, the Stores page closed to store members), `RoleScopesTest` +
  `DeletePasswordTest` (a held role's Delete)
- Third round (2026-09-17): `StoreSettingsTest` (a store changes hands on the Members page; the store, its roles and
  its own channels deleted for good, the people and the history kept), `PlatformStoresTest` (Invite owner only without
  an Owner, a member made Owner retiring the earlier invitation), `StoreScopedPermissionsTest` + `RoleScopesTest` +
  `StoreOwnerLoopholeTest` + `DatabaseSeederTest` (the accounts never on a store's role, the pages shut to it),
  `AccountDeletionTest` (its sign-ins, its reset link and the invitations waiting for its email go)
- Updated: `ImpersonateTest`, `ActivityLogTest`, `MediaCrudTest`, `NetworkAdsBulkStoresTest`, `GuestAccessTest`
- Removed with the old model: `UserCrudTest`, `RoleCrudTest`, `OnboardOwnerTest`, `DeletionBehaviourTest`

Browser (as built): `EveryPageRendersTest` (every page above the stores and every page inside one, opened by
somebody allowed to open it: Alpine really initialised, the listing really answered — no table left on
"Loading..." — and the console stayed clean, which is the only way the Blade/Alpine gotchas show themselves,
because each of them shipped as a 200 with a dead table), `StoreScopedAccessFlowTest` (Roles → a store role with platform permissions → Users → Stores →
the member works with Channels, the Activity Log and the Stores tab inside their store alone),
`TeamInvitationFlowTest` (invite → join from the emailed link → change role → remove),
`InvitationAcceptFlowTest` (the other half: somebody who already has an account is asked to sign in first, then
Accepts — and another Declines, which leaves no membership and no row), `StoreSettingsFlowTest` (the plain forms
of Settings → Stores: the details saved, a store opened from the tab by a member holding Create store who owns it
at once, and Leave from the Members page), `PlatformButtonsFlowTest` (an ownerless store given an owner — and the
button gone once it has one; a platform role taken with the password, the wrong one changing nothing; an invitation
revoked from the Members page), `AdvertisingButtonsFlowTest` (a campaign saved from the Campaigns page with its
advert uploaded and its shop chosen; the shop's advertising switch, which a shopkeeper never sees and which only
exists inside an impersonated session, turned on and off),
`StoreOwnershipFlowTest` (the Owner role given on the Members page, the first Owner's role changed by the new one,
typed-name delete for good), `PlatformConsoleFlowTest` (store with an Owner invitation → Settings → Stores → no Invite
owner once it has an Owner, platform and store roles made, a held role's Delete refused with
the toast and a store role renamed, a person put in a store as Owner, platform staff invitation, big deletes, empty
dashboard), `ProfilePageTest` (leaving from the profile without a Stores tab; the sole Owner on the tab), `MultiStoreUserTest` (one person,
two stores, two roles), `FormGuardTest` (invite and role form guards); `RegistrationFlowTest` and
`ZeroToHeroTest` drive public signup → Owner. Invitation links are followed out of the real email in the
mail log (`DuskTestCase::tokenFromMailLog`), never faked. Removed: `OnboardFlowTest`,
`CrossStoreSymmetricTest`, `MultiLevelJourneyTest`, `DeepChainMultiStoreTest`.

The migration tests went with the migrations they exercised (2026-09-17): what the schema and the starting data must
look like is now `DatabaseSchemaTest`.

Every button is pressed by a browser test (2026-09-17, owner's request: "sub buttons, every single
functionality"). Counted with a script over every `dusk="…"` action selector in the views — 94 of them, and after
`SecondaryButtonsTest` **none is left unpressed**: the Cancel of the schedule and copy modals, the copy box's two
warnings (`copy-warning` after a target is chosen, `copy-no-targets` in a one-screen shop), the empty-state twin of
Invite member, the optional Suite field of Create store, Users → Stores' assign form with that store's own roles,
revoking a platform invitation, deleting a permission with the password, and the activity log's yearly maintenance
(pressed for real; partitions only exist on MySQL, so what it answers against `dusk.sqlite` is beside the point —
that the page survives it is not). The count is a script, not a claim: re-run it when adding a page.
`x-crud.table-actions` gained optional `dusk`/`idExpr` props in the same pass, because its Edit and Delete buttons
had no selector at all and the Permissions page was therefore unreachable from a browser test.

Adversarial (`tests/Feature/Security/`, 2026-09-17 — the owner asked for the app to be attacked, not demonstrated):
`StoreWallAttackTest` (a member of Alpha holding every store permission goes after Beta by id; a stale, foreign,
sentinel or invented `current_store_id` opens nothing, because a permission read through a membership that is not
there is no permission), `PrivilegeEscalationAttackTest`, `InputAbuseAttackTest`, `FileUploadAttackTest` (real bytes
under a lying filename), `DeviceApiAttackTest`, `InvitationAttackTest`, `SessionAndPasswordAttackTest`,
`TransportAndMiddlewareAttackTest` and `HttpSurfaceSweepTest` (every route, five kinds of person: nothing answers
500 and a guest is offered only the public doors). 65 attacks; the model held on all of them. Three defects they
found — `?search[]=x` 500ing every listing, `?device_uuid[]=x` 500ing the open device limiter, and a name posted as
an array 500ing role and channel creation — are fixed and described in `ISSUES.md`.

## 11. Progress

- [x] P1 Schema + catalogue + starter roles + data migration + seeder (legacy column drops move to P6)
- [x] P2 Team rules service + members API + invitations API + accept flow + notification
- [x] P3 Store settings (details, transfer, delete with full purge) + account deletion rules
- [x] P4 Platform Stores / Users / Roles backends (+ platform invitations, signup → Owner)
- [x] P5 UI: sidebar, Members, Store settings, Invitation pages, Stores, Users, Roles, Dashboard
- [x] P6 Remove old code, permissions, routes, views, JS, tests (feature tests rewritten; browser tests in P7)
- [x] P7 Tests (feature + browser) green, Pint, docs (conventions, AUTH-SYSTEM-SPEC), memory, live check
- [x] P8 Verification pass on the finished build: exact-name Super-Admin anchor (MySQL collation), impersonation
  session keys, owner-invariant row locks, one-use invitation links, `sendLink`, owner re-invite/replace,
  leaving from Profile, primary-only super admin invites
- [x] P9 Store-scoped platform permissions (owner's rules, 2026-09-16): whole catalogue on the role form, `store-destroy`
  as the Owner's permission, Stores / Accounts / Channels / Activity inside a store, a store's roles and Change role from
  the platform; migrations `2026_09_16_120000`–`120200`
- [x] P10 No built-in roles (owner's rules, 2026-09-17): the super admin makes store and platform roles, the Owner role
  marker, names editable, the checklist without greyed-out permissions, Users → Stores (add / change / remove, no
  invitation), Settings tabs with Store settings and Delete Store, the handover role; migration `2026_09_17_100000`
  (applied to the owner's local MySQL after a JSON backup under `storage/app/private/backups/`)
- [x] P11 Permissions, not roles (owner's rules, 2026-09-17, second round): no Owner-only rule (reach by permissions
  alone), Transfer Store Ownership as a permission with Owner offered as the role kept, Settings → Stores (View Stores,
  Your stores, Create store) replacing the in-store Stores page, no Owner column and Invite owner on every store, a
  held role's Delete refused with a toast; migration `2026_09_17_110000`
- [x] P12 Third round (owner's rules, 2026-09-17): Transfer Store Ownership removed (a store changes hands on the Members
  page), no Accounts inside a store (`user-view`/`user-destroy` above the stores only), Invite owner only for a store
  with no Owner, a deleted account leaves nothing pointing at it (sessions, reset link, and — added with P13 — the
  invitations waiting for its email), a deleted store goes for good with its roles (no soft delete,
  `roles.store_id` cascades); applied to the owner's local MySQL after a JSON backup
- [x] P13 Total cleanup (owner's request, 2026-09-17): the migrations squashed to 21 files (the conversion/upgrade
  migrations and their tests gone, one baseline migration for the catalogue and the starter roles); `laravel/sanctum`,
  the Breeze email-verification and confirm-password flows, `DaypartSeeder`, `BackfillDaypartPermissionsSeeder`,
  `GET /roles/{role}/permissions`, dead model/controller/JS/CSS code, three npm packages and the guest layout's
  template marketing text all removed; account deletion also withdraws the invitations waiting for that email;
  `items.*.rules.*.daypart_id` gained `min:1`; the `local` disk is no longer served over HTTP; the owner's MySQL
  cleaned (the leftover `users.store_id`, orphan `migrations` rows, expired cache/session/pairing rows,
  `personal_access_tokens`) after a JSON backup
- [x] P14 Naming, layout and the attack suite (owner's request, 2026-09-17): every controller, request, JS module and
  feature test moved into a folder named after what it is for (`Platform/`, `Store/`, `Signage/`, `Advertising/`,
  `Device/`, `Auth/`, `Concerns/`; `core/`, `tables/`, `pages/`; `Auth/`, `Platform/`, `Store/`, `Signage/`,
  `Advertising/`, `System/`, `Security/`) with the naming rules written down in `.claude/rules/02-project-conventions.md`,
  and nine adversarial test files added under `tests/Feature/Security/` (65 attacks). The authorization model held on
  every attack; the three shape-of-input defects they found (`?search[]=x` on every listing, `?device_uuid[]=x` on the
  open device limiter, a name posted as an array on role and channel creation) are fixed, each with the convention that
  prevents the next one
