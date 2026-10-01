# Organizations — People Model Spec

> **Status:** approved direction (owner, 2026-09-16) — "follow the industrial standard, keep the UI/UX
> professional, and clean up everything after building." Built on `ads-feature` and committed there (176f784);
> the work continues on the `ads-builder` branch.
> This file is the source of truth for the rebuild; the checklist at the bottom tracks progress.

## 0. Why

The old model gave power through `created_by` ("whoever made you owns you"): people were visible only
to their creator, deleting a person cascaded through everyone they had created, and admins set other
people's passwords. The loophole hunt (2026-09-16) proved two consequences: an organization user could reset
the password of a person they created after that person was promoted to Super-Admin, and deleting
that organization user deleted the promoted Super-Admin.

The industry model (Slack workspaces, GitHub organizations, Shopify stores) gives power through
**membership**: a person is a member of an organization with a role; the organization owns its
content, its roles and its members list; nobody owns anybody.

**Here, an Organization is the organization (tenant).** An account layer above organizations (one bill for several
organizations) is out of scope; these rules stay the same if it is added later.

## 1. Decisions taken

| Question | Decision | Why |
|---|---|---|
| How do people join an organization? | **Email invitation only**; the invitee sets their own password | Industry standard; no admin ever knows a password |
| Custom roles? | **Kept**, as the property of one organization, next to the organization roles the super admin makes | Already built; standard (platform-defined + custom) |
| Built-in roles? | **None but Super-Admin** (2026-09-17). The super admin makes every other role and says what it is for: an **organization role**, offered in every organization, or a **platform role** for the team above the organizations. Owner, Admin, Staff and Viewer are only the starter organization roles — renamed, changed and (Owner aside) deleted like any other | Owner's rule (2026-09-17): "yeh built-in role wala chakar hata do" |
| Does a role's name decide anything? | **No — its permissions do.** Whatever a role is called, its holders do exactly what its permissions allow; nothing is "Owner-only" | Owner's rule (2026-09-17): "role matter nahi karta permission matter karta ha" |
| How does the system know an organization's owner? | **The Owner role marker** (`roles.key = owner`): its holders are the organization's owners — an organization may have **several** (partners) — signup, owner invitations and Create organization give it, an organization changes hands by giving it on the Members page, "at least one Owner" counts it. Renamable, never deleted; the Organizations list has no Owner column | Owner's choices (2026-09-17) |
| Deleting a role somebody holds | **Refused, with the reason** — Delete is offered on every role but Super-Admin and the Owner role, and a held one answers "Please unassign {role} from everyone first" (a toast) | Owner's rule (2026-09-17) |
| Organizations that grew copies of one role | **One organization role per name**: same-named copies were merged, people and invitations moved, permissions gathered — it gave "No owner" organizations their owners back. That migration was squashed away once the one database that needed it had run it (2026-09-17) | Owner's choice (2026-09-17) |
| Organization deletion | **Immediate, complete and for good** (no 30-day trash, no soft delete): everything the organization owns — the roles made in it included — goes with the organization row; only the people's accounts and the activity log stay | Owner's earlier explicit rule (delete media from storage at once) and "A to Z" (2026-09-17) |
| Billing / account layer | Out of scope | Subscription is a separate future branch |
| Can a member create a new organization? | **Only when their role carries `organization-store`** — the super admin decides; the new organization is theirs (Owner), nobody is invited | Owner's rule (2026-09-16); off by default, so free-screen abuse stays closed unless the platform opens it |
| Super admin's access | **Every permission, always** (`Gate::before`), and every role but Super-Admin is theirs to change. Rules that are not permissions still hold | Owner's rules "sab matlab sab" and "super admin per sari permission dikhao" (2026-09-16) |
| The role form's checklist | **Only what that role can hold** — no greyed-out checkboxes; the list follows the role's type | Owner's rule (2026-09-17): "Role K Ander Permission K checkbox dikhao hi nahi" |
| Platform permissions on an organization's role? | **Yes, for that organization alone**: the Organizations tab of Settings, channels, reading the activity log. Never the accounts (an organization's people are its Members page — 2026-09-17), yearly log maintenance or the permission catalogue | Owner's rule (2026-09-16): "store k hisab se work kare" |
| Special access for one person in one organization | The super admin makes an organization role with what that person needs and gives it to them in that organization from Users → Organizations | Owner's choices (2026-09-16, 2026-09-17) |
| Putting a person in an organization from the platform | **Straight in** (Users → Organizations): the super admin adds anybody to any organization with a role, changes it, or takes them out — no invitation | Owner's rule (2026-09-17): "super admin kisi ko bhi store k ander assign kar sake role deke" |
| Where an organization is changed from inside it | **Settings → Organizations** — a tab beside Profile, opened from the person's name and shown with View Organizations; no sidebar link, and no Organizations page for an organization's people. The organization worked in (details, Delete Organization laid out like Delete Account), "Your organizations" and Create organization, each by its own permission | Owner's rules (2026-09-17): "store k ander ja k store setting mein ja k karna honga" |
| Deleting an organization from inside it | The permission **`organization-destroy`**, which the Owner starts with — the super admin may take it from Owners or give it to another role | Owner's rule (2026-09-16): "dena naah dena woo super admin per ha" |
| Handing an organization over | **On the Members page**: whoever may give the Owner role makes another member Owner, and the new Owner may change the first one's role — no Transfer Ownership screen or permission | Owner's rule (2026-09-17): "change role kar sakta hu toh transfer ownership ki zarurat nahi" |
| Accounts inside an organization | **None** — an organization's people are its Members page; View Accounts and Delete Accounts work above the organizations only | Owner's rule (2026-09-17): "members k tab mein sub araha ha toh accounts k tab ki zarurat nahi" |
| Invite owner on the platform | **Only for an organization with no Owner**; more Owners come from the organization's Members page or Users → Organizations | Owner's choice (2026-09-17) |
| Deleting an account | Its memberships and everything pointing at it go (sessions, reset link, invitations waiting for its email); what the person made stays with its organization | Owner's rule (2026-09-17): "jaha kahi per bhi link ha toh hata dena" |
| Campaigns | Stay a super-admin gate, not a permission row | Owner's choice (2026-09-16) |
| Deleting | **Big deletes ask for the password** (organization, account, role, permission, channel, campaign, ad design, member, platform role); everyday deletes (an Ad Builder asset among them) stay one confirmation | Owner's rule (2026-09-16); a session left open must not destroy with two clicks |
| Super admins among themselves | All super admins see each other; only the **primary** super admin (the first one) may invite a new super admin, remove Super-Admin from someone or delete a super admin account; nobody may act on the primary | Replaces the old `created_by` protection ("a promoted super admin cannot act on who promoted them"); granting sits with whoever can undo it |

## 2. Vocabulary

- **Account** (`users`) — an identity: name, phone, email, password. Owned by the person.
- **Organization** — the tenant. Owns members, custom roles, invitations, screens, media, playlists, dayparts,
  its own channels, and its Ad Builder designs and assets. It was called a **store** (and on some pages a shop) until
  2026-10-01, when the owner made it an organization everywhere — the screens, the code and the database — because the
  app is sold to hospitals, schools and other institutes as well as shops (see `.claude/rules/02-project-conventions.md`,
  "Organization, everywhere": the databases that existed were renamed in place that day, and the migrations say
  organization from their first line since).
- **Membership** — an `organization_user` row `(user_id, organization_id > 0, role_id)`. One role per person per organization.
- **Platform membership** — an `organization_user` row with `organization_id = 0` holding a global role (unchanged sentinel).
- **Organization role** — a role the super admin makes for the organizations: offered in every organization, the same everywhere.
- **Owner role** — the organization role marked `roles.key = owner`; its holders own their organization.
- **Starter roles** — the organization roles an installation begins with (`Role::STARTERS`: Owner, Admin, Staff, Viewer,
  keys `owner`/`admin`/`staff`/`viewer`). Only the Owner's key means anything; the others are ordinary organization roles.
- **Custom role** — a role created inside one organization: `roles.organization_id` = that organization, `key` NULL.
- **Platform role** — `roles.is_global = true` (Super-Admin and any platform role the super admin creates).

Role invariants (enforced by code, established by the data migrations):

| Kind | `key` | `is_global` | `organization_id` |
|---|---|---|---|
| Organization role | `owner` on the Owner role; a starter key or NULL otherwise | false | NULL |
| Custom | NULL | false | the organization |
| Platform | NULL | true | NULL |

## 3. Permissions

`App\Models\Permission` holds the catalogue lists; RoleController enforces them; the Roles page mirrors them.

**Where a permission reaches is where the role sits** (owner's rules, 2026-09-16): on a platform role,
every organization; on an organization role or a custom role, the one organization the member holds it in.

**Organization permissions (26)** — an organization's own work:

- `organization-update` — edit this organization's details
- `member-view`, `member-invite`, `member-update` (change role), `member-remove`
- `role-view`, `role-store`, `role-update`, `role-destroy` — this organization's custom roles
- `screen-view`, `screen-store`, `screen-update`, `screen-destroy`, `screen-playlist`
- `media-view`, `media-store`, `media-update`, `media-destroy`
- `daypart-view`, `daypart-store`, `daypart-update`, `daypart-destroy`
- `ad-view`, `ad-store`, `ad-update`, `ad-destroy` — the Ad Builder (`docs/AD-BUILDER-SPEC.md`), added with a
  migration of their own

**Platform permissions (11)** — above the organizations on a platform role; `Permission::ORGANIZATION_SCOPED` marks the
eight that an organization's role may carry too, and what they mean there:

| Permission | On a platform role | On an organization's role (this organization alone) |
|---|---|---|
| `organization-view` | the Organizations page: every organization | the Organizations tab of Settings, for the organization they work in (turning it off hides the tab) |
| `organization-store` | create an organization + invite its owner; Invite owner (an organization with no Owner) | Create organization on that tab: an organization they own at once (no invitation; active stays the platform's switch) |
| `organization-destroy` | delete any organization | Delete Organization on that tab, for the organization they work in — the Owner's by default |
| `user-view` | every account (support: organization accounts only) | **never** — an organization's people are its Members page (2026-09-17) |
| `user-destroy` | delete an account | **never** — deleting accounts is the platform's |
| `channel-view`, `-organization`, `-update`, `-destroy` | every channel; one made here is offered to every organization | the organization's own channels, offered to its own screens |
| `activity-view` | every entry | this organization's entries |
| `activity-destroy` | yearly maintenance | **never** — it drops every organization's history at once |

**Super-Admin role only (4):** `permission-view`, `permission-store`, `permission-update`, `permission-destroy`.

Rules:
- An organization role or a custom role carries organization permissions and the organization-scoped platform ones (`Permission::belongsToOrganizations`).
- A platform role (not Super-Admin) may carry organization + platform permissions (a support person with
  `media-view` reads every organization's library, and the platform's own).
- The Super-Admin role carries everything.
- A permission in none of the lists (created on the Permissions page) counts as platform-only.
- The role form lists only what the role in it can hold — nothing greyed out (2026-09-17): for an organization role what
  works inside an organization, for a platform role everything but the catalogue; it follows the type chosen for a new role.
  A member inside an organization is offered what they hold there.

**Removed permissions** (mapped by the data migration, then deleted): `user-organization`, `user-update`,
`user-organization-view`, `user-organization-assign`, `user-organization-unassign`.

**No Owner-only abilities** (owner's rule, 2026-09-17: a role does not matter, its permissions do). Deleting the organization
is `organization-destroy`, which the Owner role starts with and nobody else does; handing it over is giving the Owner role on
the Members page (rule 11) — Transfer Organization Ownership (`organization-transfer`) was removed the same day. Giving or
managing the Owner role follows the same reach as any role (rules 7–8).

## 4. Organization roles (owner's rules, 2026-09-17)

No role is built in but Super-Admin. The super admin makes a role on the Roles page and says what it is for — **Organization
role** (offered in every organization; what it allows reaches the member's own organization) or **Platform role** (the team above
the organizations) — and renames it, changes what it allows (an organization role: in every organization at once) and deletes it. Delete is
offered on every role but Super-Admin and the Owner role; one somebody still holds is refused — the page says so at once
as a toast, **"Please unassign {role} from everyone first: {n} person still holds it."**, and the server refuses it again.
What a role is for is fixed once it exists. Inside an organization the organization roles are read-only (their permissions
viewable) and the organization makes its own custom roles beside them.

**The Owner role** is the organization role marked `key = owner` (`Role::owner()`): its holders are the organization's owners — an organization
may have several — public signup, owner invitations and Create organization give it, rule 9 counts it, and an organization changes
hands by giving it (rule 11).
It is renamed and changed like any organization role, and never deleted. What an Owner may do is what the role's permissions
allow, like any role.

An installation starts with these organization roles (`Role::STARTERS`) — a starting point only, the super admin's to change:

| Role | Permissions to start with |
|---|---|
| **Owner** | every organization permission, `organization-view` and `organization-destroy`; a migration adding an organization permission grants it here |
| **Admin** | every organization permission and `organization-view` — the difference is deleting the organization (and being an Owner, rule 9) |
| **Staff** | `screen-view`, `screen-playlist`, `media-view`, `media-store`, `media-update`, `media-destroy`, `daypart-view` |
| **Viewer** | `screen-view`, `media-view`, `daypart-view` |

One migration inserts the permission catalogue and all four roles (`2026_10_01_202300_insert_permissions_and_starter_roles`);
the seeder repairs the labels, makes the Super-Admin role and the admin account, and only puts the Owner role back
should it be missing.
Neither touches an existing role, so a re-seed never undoes the super admin's changes or brings back a role they deleted.

**Names** are compared the way a person reads them (accents, case, spacing and punctuation folded away) against every
list the role is shown in: an organization role against every organization role and every organization's custom roles, a custom role against
its organization's list (the organization roles and its own), a platform role against the platform roles. Nothing is named like
Super-Admin.

**Upgrading** is history: the migrations that carried the owner's own database from the old people model to this one —
the people conversion, the merge of same-named organization roles (which is what gave "No owner" organizations their owners back),
the organization-transfer permission and its removal, and the purge of soft-deleted organizations — were squashed away on 2026-09-17,
once that one database had run them all and no other installation existed. `database/migrations` now builds the final
schema and the starter data directly.

## 5. Rules

### A. Accounts
1. Nobody owns an account. Name, phone, email and password are changed only by the person (Profile) or
   by "forgot password".
2. One email = one account; one account may be a member of many organizations with a different role in each.
3. Platform and organization tiers stay exclusive: a platform account is never an organization member, and vice versa.

### B. Membership & roles
4. Power inside an organization comes only from the membership in that organization (current-organization permission check, unchanged).
5. Custom roles belong to the organization: every member holding `role-view` sees them; `role-update` /
   `role-destroy` holders manage them regardless of who created them; a custom role survives its creator.
6. A custom role cannot be deleted while a member holds it (unchanged).

### C. Hierarchy
7. **Assign** (invite or change role): the target role must be available in the organization (an organization role or this
   organization's custom role), and every permission of it must be held by the actor in this organization — the Owner role
   like any other (owner's choice, 2026-09-17: "apni permissions tak").
8. **Manage an existing member** (change role / remove): not yourself; every permission of the member's
   current role must be held by the actor — an Owner's included.
9. **At least one Owner, always.** The last Owner cannot be demoted, removed, leave, or delete their account —
   whoever asks: reach is permissions only (rules 7–8), so every change or removal of a member checks this on its own,
   and the Members page offers no Change role or Remove on an organization's only Owner.
   Every change that could break this is decided under a lock on the organization row (`OrganizationTeam::changeTeam`),
   so two co-owners acting at the same moment cannot both pass.
10. **Leave organization:** any member, except the last Owner — from the Members page, from "Your organizations" on Settings →
    Organizations, or — for whoever has no Organizations tab (no View Organizations where they work, like Staff and Viewer) — from
    "Your organizations" on the profile. Leaving returns to the page it came from; leaving the organization being worked in goes
    to the profile.
11. **An organization changes hands on the Members page** (owner's rule, 2026-09-17 — there is no Transfer Ownership screen or
    permission): whoever may give the Owner role (rule 7) changes another member's role to Owner, and the new Owner may
    then change the first one's role (rule 8). Rule 9 keeps the organization from being left without an Owner in between.

### D. Visibility
12. Organization context: `member-view` shows **every** member of the current organization (the actor included, marked
    "You") and the pending invitations. Never people outside the organization, never platform accounts. An organization's people
    have no Accounts page: the accounts pages are the platform's alone (2026-09-17).
13. Platform: a super admin sees every account; a platform user holding `user-view` sees organization accounts
    only (not platform staff).
14. Out-of-scope ids answer 404 (unchanged).

### E. Joining
15. **Invite** (`member-invite`): email + role (rule 7). Refused when the email is already a member, a
    pending invitation for that email exists (use Resend), or the email belongs to a platform account.
    Link valid **7 days**. Resend rotates the token and resets the expiry; Revoke deletes it.
16. **Accept:** the link shows organization, role and inviter.
    - Signed out, account exists → "Sign in to accept" (returns to the link).
    - Signed out, no account → create account (email fixed from the invitation, marked verified).
    - Signed in with the same email → Accept / Decline.
    - Signed in with another email → explanation + sign out.
    - Accepting creates the membership, deletes the invitation, and switches the session to that organization.
      The invitation row is taken under a lock, so a double submit or a second tab never uses a link twice.
    - Accepting an invitation to an organization you are already in only uses it up (logged).
    - The email is sent through `Invitation::sendLink()`: a mail server that refuses is reported, the
      invitation stands, and the response says the email was not sent (shown as an error, not a 500).
17. **Public signup:** creates the account (email lowercased), the organization, and an **Owner** membership (no
    signup-default flag any more).
18. **Platform organization creation** (`organization-store`): organization details + owner email → the organization is created and an
    Owner invitation is sent. **Invite owner** is offered only for an organization with **no Owner** (owner's rule,
    2026-09-17 — "{organization} already has an Owner." otherwise); more Owners come from the organization's own Members page, and
    the super admin can also put somebody in as Owner from Users → Organizations (rule 32).
    **Invite owner:** an email that belongs to a member of the organization makes them Owner at once
    (`organization.owner_assigned`); the same address as an open invitation gets a fresh Owner link, even when it
    had expired; a different address — or a member made Owner — **replaces** the earlier owner invitations,
    so a mistyped email stops working.
    A member whose role carries `organization-store` opens an organization from inside theirs (Settings → Organizations → Create organization) and
    becomes its Owner at once; nobody is invited.
19. **Platform staff** (super admin only): invite an email with a platform role; accepting creates the
    `organization_id = 0` membership. Only the **primary** super admin may invite a new super admin — the one who
    can also take that role away (rule 24).

### F. Leaving & deleting
20. **Remove member / leave:** the membership row only. Uploads, paired screens and custom roles stay
    with the organization (`created_by` stays as history).
21. **Delete account** (Profile, or platform `user-destroy`): the account and its memberships — nothing
    else of anybody's — and nothing is left pointing at it (owner's rule, 2026-09-17): its sessions on every device,
    its password-reset link and every invitation waiting for its email (to an organization or the platform)
    go too, and `created_by`, `invited_by` and the log's actor empty (what the person made — invitations they sent
    included — stays with its organization; the log keeps their name). Self-deletion is refused while the person is the
    last Owner of any organization (the message names the organizations). A platform deletion is allowed; the response names the organizations left without an Owner, and the super
    admin gives them one (Invite owner, or Users → Organizations).
    - Super admins cannot delete themselves (unchanged).
    - Deleting a platform account needs a super admin; deleting a super admin needs the primary one.
    - An organization's people never delete accounts: that is the platform's (2026-09-17).
22. **Delete organization** (`organization-destroy` — inside an organization the Owner's by default, for the organization worked in, from Settings →
    Organizations; on the platform any organization, from the Organizations page — always the typed name and the password): everything the organization owns goes in one transaction — memberships, invitations, custom roles,
    screens (devices lose their token), playlists and schedule rules, dayparts, the organization's own channels (their
    ads and the lines carrying them), media rows (and any ad of the platform's channel that showed one of them),
    the Ad Builder's designs and assets, and media and Ad Builder files after commit — a channel's files are
    library rows (docs/CHANNEL-CONTENT-SPEC.md). **The organization row goes
    too, for good** (owner's rule, 2026-09-17: "A to Z") — no soft delete, and `roles.organization_id` cascades, so a custom
    role never outlives its organization. Accounts stay, and so does the activity log (its entries keep the organization's id and
    name).
23. **No `created_by` cascade anywhere.** `users.created_by` is dropped; `created_by` on content is history only.

### G. Platform
24. Remove a platform role: super admin; not yourself; removing Super-Admin needs the primary super
    admin; nobody touches the primary.
25. "Log in as": super admin only, audited, onto any account that is not a super admin (platform staff
    included). The way back lives in the session only while the impersonated account is the one signed in;
    every sign-in clears it. Campaigns, activity log maintenance and permissions stay platform-only; channels, the
    activity log and the Organizations tab answer an impersonated member for their own organization (rule 29).
26. **Super-Admin is recognised by its exact name, compared in PHP** (`Role::superAdminId()`), never by a
    SQL `WHERE name =`: MySQL's accent-insensitive collation would let "Súper-Admin" pass for it. A role name
    that folds to Super-Admin, or to a name already in a list the role is shown in (§4), is refused.
27. **A super admin holds every permission** — a `Gate::before` answers every permission check, whatever the
    Super-Admin role's rows say. Rules that are not permissions still stand (rules 9, 21, 24, 26; Super-Admin never
    changed, the Owner role never deleted).
28. **Big deletes re-confirm the password:** an organization, an account, a role, a permission, a channel, a campaign, an
    ad design, removing a member, taking a platform role. Asked after every other refusal; five wrong passwords a
    minute per person, then a pause. Everyday deletes — an Ad Builder asset among them — stay one confirmation.

### H. Platform permissions inside an organization (owner's rules, 2026-09-16)
29. **An organization's role may carry the organization-scoped platform permissions** (§3 table), and there they reach that organization
    alone. The organization permissions act on the organization the person works in and nothing else: an organization is changed from inside
    it, on Settings → Organizations (rule 33) — an organization's people never reach the platform's Organizations page. What only works above
    the organizations keeps the `global-tier` lock: the Organizations page and its writes, the accounts pages, activity log maintenance and its storage
    panel, Invite owner, "Log in as", the platform team, and putting people in organizations from the platform (rule 32, also
    `super-admin-tier`).
30. **An organization's own channels:** made inside the organization (stamped with it), listed, opened and changed only there;
    offered to that organization's screens alone. The platform's channels stay offered to every organization and out of an organization's
    reach for any change — inside an organization they are listed and opened to READ only (2026-09-19: "From the platform",
    no Edit, Delete or Add ad; `Channel::listableIn` for looks, `Channel::visibleTo` for changes); another organization's
    channels are never found. A name stands apart within one organization's list (the platform's channels and its own).
    The platform lists every channel with whose screens it reaches. A channel's ads are rows of a media library
    (docs/CHANNEL-CONTENT-SPEC.md): an organization's channel takes its organization's library, the platform's channel the
    platform's own library (`media.organization_id` NULL) or any organization's.
31. **An organization's own history:** every entry carries the organization it belongs to (its subject's, or the organization named for a
    delete or a person); an organization's role carrying `activity-view` reads those entries alone. Entries logged before
    the column existed, personal account actions and the platform's own work belong to no organization.
32. **The super admin puts people in organizations from above** (owner's rule, 2026-09-17): Users → **Organizations** on an organization
    account lists the organizations they are in, each with its role to change or a Remove, and adds them to any other organization
    with any role that organization has (the organization roles and its own custom roles) — straight in, nobody invited. A platform
    account is refused (the tiers stay exclusive), somebody already in the organization is told to change their role there,
    and every change keeps at least one Owner under the organization's lock; taking someone out asks for the password and
    leaves what they made with the organization. The Roles page lists every organization's custom roles in a card of their own for
    the super admin to edit or delete.

### I. Settings (owner's rules, 2026-09-17)
33. **Settings** is opened from the person's name (the foot of the sidebar, the header's menu) and has tabs: Profile,
    and **Organizations** for an organization member working in an organization whose role there holds View Organizations (`organization-view` — turning it
    off hides the tab, whatever the role is called). The sidebar has no link to it. Each card is its own permission:
    Organization Details (editable with `organization-update`, read-only without), **Your organizations** — every organization the person belongs
    to with their role and Leave, plus **Create organization** (`organization-store`) — and Delete Organization (`organization-destroy`, laid out
    like Delete Account: the organization's name typed, then the password). Whoever has no Organizations tab finds "Your organizations" on
    the profile. There is no handover here: an organization changes hands on the Members page (rule 11).

## 6. Endpoints

**Organization context** (`auth`, `throttle:admin`):

| Method & path | Gate | Action |
|---|---|---|
| GET `/members` | `member-view` | Members page |
| GET `/members/data` | `member-view` | members (with `can_manage`), invitations, assignable roles |
| PUT `/members/{user}` | `member-update` | change role (rules 7–9) |
| DELETE `/members/{user}` | `member-remove` + password | remove (rules 8–9) |
| POST `/members/leave` | auth | leave the current organization (rule 10) |
| DELETE `/profile/organizations/{organization}` | auth (member of that organization) | leave an organization from "Your organizations" (rule 10) |
| POST `/members/invitations` | `member-invite` + `throttle:invitations` | invite (rule 15) |
| POST `/members/invitations/{invitation}/resend` | `member-invite` + `throttle:invitations` | resend |
| DELETE `/members/invitations/{invitation}` | `member-invite` | revoke |
| GET `/settings/organization` | `organization-view` | Settings → Organizations (rule 33) |
| PUT `/settings/organization` | `organization-update` | update details |
| POST `/settings/organization/open` | `organization-store` | Create organization: an organization the person owns at once (plain form, `organization_` fields) |
| DELETE `/settings/organization` | `organization-destroy` + password | delete organization (rule 22) |

**Organizations, accounts, channels, activity** (`auth`, `throttle:admin`) — above the organizations for a platform role; channels
and activity also inside an organization for an organization's role carrying the permission, for that organization alone (rules 29–31):

| Method & path | Gate | Action |
|---|---|---|
| GET `/organizations`, `/organizations/data` | `global-tier` + `organization-view` | every organization with its members count; `can` (update, destroy, invite_owner) per row |
| POST `/organizations` | `global-tier` + `organization-store` | create organization + owner invitation |
| PUT `/organizations/{organization}` | `global-tier` + `organization-update` | edit details and the active switch |
| DELETE `/organizations/{organization}` | `global-tier` + `organization-destroy` + password | delete organization (typed name) |
| POST `/organizations/{organization}/owner-invitation` | `global-tier` + `organization-store` | give an organization with no Owner one: promote a member, or invite (the same address re-sent, a different one replacing the earlier invitation); 422 when it has an Owner (rule 18) |
| POST `/organizations/switch` | auth | switch the current organization (members) |
| GET `/users`, `/users/data` | `global-tier` + `user-view` | every account with its memberships / platform role (Super-Admin offered in the invite modal to the primary super admin only) |
| DELETE `/users/{user}` | `global-tier` + `user-destroy` + password | delete account (rule 21) |
| GET `/users/{user}/organizations` | `global-tier` + `super-admin-tier` | the person's memberships, the organizations they are not in, the organization roles and each organization's custom roles (rule 32) |
| POST `/users/{user}/organizations` | `global-tier` + `super-admin-tier` | put the person in an organization with a role — no invitation (rule 32) |
| PUT `/users/{user}/organizations/{organization}/role` | `global-tier` + `super-admin-tier` | change the person's role in that organization (rule 32) |
| DELETE `/users/{user}/organizations/{organization}` | `global-tier` + `super-admin-tier` + password | take the person out of that organization; the organization keeps an Owner (rule 32) |
| DELETE `/users/{user}/platform-role` | `global-tier` + `super-admin-tier` + password | remove platform role (rule 24) |
| `/channels/*` | `channel-*` | channels within reach (`Channel::listableIn` to look, `Channel::visibleTo` to change, rule 30); `/channels/{channel}/library` feeds the Add-ad pickers under `channel-update` |
| GET `/activity`, `/activity/data` | `activity-view` | entries within reach (rule 31) |
| GET `/activity/partitions`, POST `/activity/partitions/maintain` | `global-tier` + `activity-view` / `activity-destroy` | yearly storage |
| GET `/roles`, `/roles/data` | `role-view` | platform (super admin): every role, organizations' custom roles included · organization: the organization roles and this organization's custom roles |
| POST `/roles` | `role-store` | platform: an organization role or a platform role (`type` = `organization` / `platform`) · organization: a custom role of this organization |
| PUT `/roles/{role}` | `role-update` | name + permissions; platform: any role but Super-Admin · organization: its own custom roles within reach |
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

**Removed:** POST `/users`, PUT `/users/{user}`, `/users/onboard`, `/users/assignable-organizations`,
`/users/assignable-roles`, the old `/users/{user}/organizations` endpoints of the `created_by` model (the paths now serve
rule 32, behind the super-admin tier); the `is_global` / `is_signup_default` flags on the role form (a new role's
type replaced them); `/users/{user}/organization-roles` and the Roles page's `?organization=` picker (2026-09-17); Transfer ownership
(`POST /settings/organization/transfer-ownership`, the `organization-transfer` permission) and the Accounts page inside an organization
(2026-09-17, third round).

## 7. Data model

**`roles`:** add `key` (string 32, nullable, unique); drop `is_signup_default`.

**`invitations` (new):**
- `id`
- `organization_id` — nullable FK → organizations, cascade; NULL = platform invitation
- `email` — lowercased
- `role_id` — FK → roles, cascade
- `token_hash` — char 64, unique, sha256 of a random 64-char token that only ever lives in the email link
- `invited_by` — nullable FK → users, null on delete
- `expires_at`, timestamps

**`users`:** drop `created_by`.

**`organizations`:** no `deleted_at` — a deleted organization goes for good. **`roles.organization_id`:** cascades on delete, so a custom
role never outlives its organization.

**What a fresh install gets** (26 migrations, one per table since 2026-10-01 — the conversion migrations that upgraded
the owner's own database went on 2026-09-17, and every later change was folded into its table's file on 2026-10-01,
so there is no upgrade path from the old model): the tables above in their final shape, plus one data migration. The
baseline (`2026_10_01_202300_insert_permissions_and_starter_roles`) inserts the 41 permissions of `Permission::LABELS`
with their labels and the four starter organization roles — Owner (every organization permission, `organization-view`,
`organization-destroy`), Admin (every organization permission, `organization-view`), Staff (7) and Viewer (3). `db:seed`
then adds the Super-Admin role holding the whole catalogue and the admin account. A permission added later ships a
migration of its own; that baseline file is never edited — a database that has its catalogue skips it.

## 8. UI

Existing design system only: `x-app-layout`, `card`, `btn-*`, `badge-*`, `x-crud.*`, `x-modal`, toasts.
Two form tracks: AJAX modals for lists, plain POST for settings and guest pages. Dark mode, mobile-safe
tables, `dusk` selectors on every interactive element.

**Sidebar**
- Organization context: Dashboard · Screens · Dayparts · Media Library · Ad Builder (ad-view) · **Team** (Members, Roles) ·
  Channels (channel-view) · Activity Log (activity-view) — each link only when the role there carries it. No Organizations
  link: the organization is changed from Settings → Organizations (rule 33).
- Platform: Dashboard · Organizations · Users · Roles · Permissions · Advertising · Channels · Activity Log
  (plus content pages a platform role holds).
- At the foot: the person's name and "Settings", opening Settings (the header's user menu says "Settings" too).

**Members page** (organization)
- Header with organization name + member count.
- Actions: **Invite member** (primary), **Leave organization** (secondary).
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
- **Organizations** tab (rule 33, organization-view): "Organization Details" (the form for organization-update — its address rows sit side by side
  only when the card is wide enough; read-only otherwise) beside "Your organizations" (every membership with its role and
  Leave; a Create organization button for organization-store opening a modal with the organization's details), then "Delete Organization"
  (organization-destroy — laid out like Delete Account: what goes, the roles made in the organization included, then a modal with the
  typed name + password).

**Invitation page** (guest layout): organization, role, inviter; the state-specific form of rule 16; expired or
invalid state.

**Platform Organizations page** (platform accounts only)
- Name, Location, Members, Status (and Adverts for the super admin) — no Owner column: an organization may have several Owners.
- Create organization modal gains the owner email.
- Row action "Invite owner" only on an organization with no Owner (same address → a fresh link; a new address, or a member
  made Owner, replaces the earlier owner invitation).
- Delete asks for the typed organization name and the password.

**Platform Users page**
- Account · Access (platform role badge, or per-organization "Role · Organization" badges) · Joined · actions
  (Log in as, Organizations, Remove platform role, Delete).
- **Organizations** (super admins, organization accounts — rule 32): "Member of" lists each organization with a role select, Save and
  Remove (password), the chosen role's description under it; "Add to an organization" picks an organization they are not in, then a
  role that organization has, and Add.
- Invite platform staff modal + pending platform invitations.
- No create-with-password, no edit of an account.


**Channels page**: above the organizations every channel, badged "Every organization" or "{organization} only"; inside an organization "Channels of
{organization}", its own — and the platform's, badged "From the platform", with Ads but no Edit or Delete. The playlist's
Channels box marks an organization's own channel "This organization".

**Media Library page**: inside an organization, the organization's library. Above the organizations one library at a time, chosen in the
Library list — "Platform library" (the default) or an organization — which is also where an upload lands.

**Activity Log page**: above the organizations every entry and — for activity-destroy — the yearly storage panel; inside an organization
"Activity in {organization}", its entries alone, no storage panel.

**Profile page**
- "Your organizations" (organization accounts without an Organizations tab): every membership with its role and a Leave button behind a
  confirmation; the last Owner of an organization sees why they cannot leave instead.

**Roles page** — one list with a Type column (Super admin · Organization role · Platform role · Custom role)
- Organization: the organization roles (read-only, permissions viewable) then the organization's custom roles (CRUD, what the member holds).
  Deleting a role asks for the password and revokes its pending invitations; the confirmation says so. A role
  somebody holds answers Delete with the toast "Please unassign {role} from everyone first…" and no dialog.
- Platform (super admin): Super-Admin (view only — every permission, always), the Owner role (badged Owner; Edit, no
  Delete), the other organization roles and the platform roles (Edit, Delete); below, a card "Custom roles made in organizations"
  with each role's organization (Edit, Delete).
- **Create role** (platform): name, "What is this role for?" — Organization role / Platform role — and the checklist.
  Editing never changes the type; the name is editable on every role but Super-Admin.
- The checklist lists only what the role can hold, nothing greyed out; on an organization or custom role the Organizations,
  Channels and Activity log groups say "This organization only".

**Dashboard**
- A signed-in person with no organization sees "You're not a member of any organization yet" and is told to ask an
  organization's owner to invite their email, then open the link in that email.
- Deliberately **no** list of pending invitations with Accept buttons. Public signup does not
  verify the email, so anyone could register somebody else's address and accept that person's
  invitations from the list. Only the emailed link — which proves the inbox — accepts.

**Email:** `InvitationNotification` (markdown mail): "{Inviter} invited you to join {Organization} as {Role}" ·
Accept button · expiry line.

## 9. Activity log

New actions:
- `member.invited`, `invitation.resent`, `invitation.revoked`, `invitation.declined`, `invitation.accepted`
- `member.role_changed`, `member.removed`, `member.left`, `member.assigned` (put in an organization from the platform)
- `platform.role_removed`, `organization.owner_invited`, `organization.owner_assigned`,
  `platform.invited`

Existing kept: `organization.created`, `organization.updated`, `organization.deleted`, `user.deleted`, `account.deleted`,
`user.registered`, impersonation.

Removed: `user.created`, `user.updated`, `user.assigned`, `user.unassigned`, `user.onboarded`, and
`organization.ownership_transferred` with Transfer ownership (2026-09-17 — earlier entries stay in the log).

## 10. Tests

Feature (as built):
- `MemberDirectoryTest`; `MemberManagementTest` (change role, remove, leave, hierarchy, last Owner);
  `InvitationTest` (create / resend / revoke / accept / register / decline / expiry / throttle / notification)
- `OrganizationSettingsTest` (details, an organization changing hands on the Members page, full-purge delete), `OrganizationDeletionPurgesMediaTest`, `OrganizationCrudTest`
- `PlatformOrganizationsTest`, `PlatformUsersTest`, `PlatformPermissionTiersTest`, `ChannelPermissionRoleTest`
- `AccountDeletionTest`, `RoleScopesTest`, `Auth/RegistrationTest` (signup → Owner),
  `DatabaseSeederTest` (catalogue lists + starter roles), `DatabaseSchemaTest` (what a fresh migrate leaves: the
  catalogue, the starter roles, the roles→organizations cascade, no `deleted_at`)
- `OrganizationOwnerLoopholeTest` — the adversarial suite, rewritten against the new model
- Organization-scoped platform permissions (2026-09-16): `OrganizationScopedPermissionsTest` (checklist, refusals, the delete on
  Settings → Organizations, Organizations / Activity inside an organization, sidebar), `OrganizationChannelsTest`, `PlatformOrganizationRolesTest`
  (rewritten 2026-09-17 for Users → Organizations)
- Roles made by the super admin (2026-09-17): `RoleScopesTest` (organization / platform / custom roles, names, the Owner
  role never deleted, the checklist by type), `PlatformOrganizationRolesTest` (Users → Organizations: add, change, remove, refusals),
  `OrganizationSettingsTest` (Settings tabs)
- Permissions, not roles (2026-09-17, second round): `OrganizationSettingsTest` (leaving from the tab),
  `OrganizationScopedPermissionsTest` (the Organizations tab by View Organizations, each card by its permission, Your organizations, Create organization,
  the sidebar), `PlatformOrganizationsTest` (no owners, the Organizations page closed to organization members), `RoleScopesTest` +
  `DeletePasswordTest` (a held role's Delete)
- Third round (2026-09-17): `OrganizationSettingsTest` (an organization changes hands on the Members page; the organization, its roles and
  its own channels deleted for good, the people and the history kept), `PlatformOrganizationsTest` (Invite owner only without
  an Owner, a member made Owner retiring the earlier invitation), `OrganizationScopedPermissionsTest` + `RoleScopesTest` +
  `OrganizationOwnerLoopholeTest` + `DatabaseSeederTest` (the accounts never on an organization's role, the pages shut to it),
  `AccountDeletionTest` (its sign-ins, its reset link and the invitations waiting for its email go)
- Updated: `ImpersonateTest`, `ActivityLogTest`, `MediaCrudTest`, `NetworkAdsBulkOrganizationsTest`, `GuestAccessTest`
- Removed with the old model: `UserCrudTest`, `RoleCrudTest`, `OnboardOwnerTest`, `DeletionBehaviourTest`

Browser (as built): `EveryPageRendersTest` (every page above the organizations and every page inside one, opened by
somebody allowed to open it: Alpine really initialised, the listing really answered — no table left on
"Loading..." — and the console stayed clean, which is the only way the Blade/Alpine gotchas show themselves,
because each of them shipped as a 200 with a dead table), `OrganizationScopedAccessFlowTest` (Roles → an organization role with platform permissions → Users → Organizations →
the member works with Channels, the Activity Log and the Organizations tab inside their organization alone),
`TeamInvitationFlowTest` (invite → join from the emailed link → change role → remove),
`InvitationAcceptFlowTest` (the other half: somebody who already has an account is asked to sign in first, then
Accepts — and another Declines, which leaves no membership and no row), `OrganizationSettingsFlowTest` (the plain forms
of Settings → Organizations: the details saved, an organization opened from the tab by a member holding Create organization who owns it
at once, and Leave from the Members page), `PlatformButtonsFlowTest` (an ownerless organization given an owner — and the
button gone once it has one; a platform role taken with the password, the wrong one changing nothing; an invitation
revoked from the Members page), `AdvertisingButtonsFlowTest` (a campaign saved from the Campaigns page with its
advert uploaded and its organization chosen; the organization's advertising switch, which an organization member never sees and which only
exists inside an impersonated session, turned on and off),
`OrganizationOwnershipFlowTest` (the Owner role given on the Members page, the first Owner's role changed by the new one,
typed-name delete for good), `PlatformConsoleFlowTest` (organization with an Owner invitation → Settings → Organizations → no Invite
owner once it has an Owner, platform and organization roles made, a held role's Delete refused with
the toast and an organization role renamed, a person put in an organization as Owner, platform staff invitation, big deletes, empty
dashboard), `ProfilePageTest` (leaving from the profile without an Organizations tab; the sole Owner on the tab), `MultiOrganizationUserTest` (one person,
two organizations, two roles), `FormGuardTest` (invite and role form guards); `RegistrationFlowTest` and
`ZeroToHeroTest` drive public signup → Owner. Invitation links are followed out of the real email in the
mail log (`DuskTestCase::tokenFromMailLog`), never faked. Removed: `OnboardFlowTest`,
`CrossOrganizationSymmetricTest`, `MultiLevelJourneyTest`, `DeepChainMultiOrganizationTest`.

The migration tests went with the migrations they exercised (2026-09-17): what the schema and the starting data must
look like is now `DatabaseSchemaTest`.

Every button is pressed by a browser test (2026-09-17, owner's request: "sub buttons, every single
functionality"). Counted with a script over every `dusk="…"` action selector in the views — 94 of them, and after
`SecondaryButtonsTest` **none is left unpressed**: the Cancel of the schedule and copy modals, the copy box's two
warnings (`copy-warning` after a target is chosen, `copy-no-targets` in a one-screen organization), the empty-state twin of
Invite member, the optional Suite field of Create organization, Users → Organizations' assign form with that organization's own roles,
revoking a platform invitation, deleting a permission with the password, and the activity log's yearly maintenance
(pressed for real; partitions only exist on MySQL, so what it answers against `dusk.sqlite` is beside the point —
that the page survives it is not). The count is a script, not a claim: re-run it when adding a page.
`x-crud.table-actions` gained optional `dusk`/`idExpr` props in the same pass, because its Edit and Delete buttons
had no selector at all and the Permissions page was therefore unreachable from a browser test.

Adversarial (`tests/Feature/Security/`, 2026-09-17 — the owner asked for the app to be attacked, not demonstrated):
`OrganizationWallAttackTest` (a member of Alpha holding every organization permission goes after Beta by id; a stale, foreign,
sentinel or invented `current_organization_id` opens nothing, because a permission read through a membership that is not
there is no permission), `PrivilegeEscalationAttackTest`, `InputAbuseAttackTest`, `FileUploadAttackTest` (real bytes
under a lying filename), `DeviceApiAttackTest`, `InvitationAttackTest`, `SessionAndPasswordAttackTest`,
`TransportAndMiddlewareAttackTest` and `HttpSurfaceSweepTest` (every route, five kinds of person: nothing answers
500 and a guest is offered only the public doors) — and, with the Ad Builder, `AdBuilderAttackTest`. 96 attacks in
those 10 files today; the model held on all of them. Five defects they found — `?search[]=x` 500ing every listing,
`?device_uuid[]=x` 500ing the open device limiter, a name posted as an array 500ing role and channel creation,
`password[]=x` 500ing every big delete, and a schedule rule that was not an array 500ing the playlist preview — are
fixed and described in `ISSUES.md`.

## 11. Progress

- [x] P1 Schema + catalogue + starter roles + data migration + seeder (legacy column drops move to P6)
- [x] P2 Team rules service + members API + invitations API + accept flow + notification
- [x] P3 Organization settings (details, transfer, delete with full purge) + account deletion rules
- [x] P4 Platform Organizations / Users / Roles backends (+ platform invitations, signup → Owner)
- [x] P5 UI: sidebar, Members, Organization settings, Invitation pages, Organizations, Users, Roles, Dashboard
- [x] P6 Remove old code, permissions, routes, views, JS, tests (feature tests rewritten; browser tests in P7)
- [x] P7 Tests (feature + browser) green, Pint, docs (conventions, AUTH-SYSTEM-SPEC), memory, live check
- [x] P8 Verification pass on the finished build: exact-name Super-Admin anchor (MySQL collation), impersonation
  session keys, owner-invariant row locks, one-use invitation links, `sendLink`, owner re-invite/replace,
  leaving from Profile, primary-only super admin invites
- [x] P9 Organization-scoped platform permissions (owner's rules, 2026-09-16): whole catalogue on the role form, `organization-destroy`
  as the Owner's permission, Organizations / Accounts / Channels / Activity inside an organization, an organization's roles and Change role from
  the platform; migrations `2026_09_16_120000`–`120200`
- [x] P10 No built-in roles (owner's rules, 2026-09-17): the super admin makes organization and platform roles, the Owner role
  marker, names editable, the checklist without greyed-out permissions, Users → Organizations (add / change / remove, no
  invitation), Settings tabs with Organization settings and Delete Organization, the handover role; migration `2026_09_17_100000`
  (applied to the owner's local MySQL after a JSON backup under `storage/app/private/backups/`)
- [x] P11 Permissions, not roles (owner's rules, 2026-09-17, second round): no Owner-only rule (reach by permissions
  alone), Transfer Organization Ownership as a permission with Owner offered as the role kept, Settings → Organizations (View Organizations,
  Your organizations, Create organization) replacing the in-organization Organizations page, no Owner column and Invite owner on every organization, a
  held role's Delete refused with a toast; migration `2026_09_17_110000`
- [x] P12 Third round (owner's rules, 2026-09-17): Transfer Organization Ownership removed (an organization changes hands on the Members
  page), no Accounts inside an organization (`user-view`/`user-destroy` above the organizations only), Invite owner only for an organization
  with no Owner, a deleted account leaves nothing pointing at it (sessions, reset link, and — added with P13 — the
  invitations waiting for its email), a deleted organization goes for good with its roles (no soft delete,
  `roles.organization_id` cascades); applied to the owner's local MySQL after a JSON backup
- [x] P13 Total cleanup (owner's request, 2026-09-17): the migrations squashed to 21 files (the conversion/upgrade
  migrations and their tests gone, one baseline migration for the catalogue and the starter roles); `laravel/sanctum`,
  the Breeze email-verification and confirm-password flows, `DaypartSeeder`, `BackfillDaypartPermissionsSeeder`,
  `GET /roles/{role}/permissions`, dead model/controller/JS/CSS code, three npm packages and the guest layout's
  template marketing text all removed; account deletion also withdraws the invitations waiting for that email;
  `items.*.rules.*.daypart_id` gained `min:1`; the `local` disk is no longer served over HTTP; the owner's MySQL
  cleaned (the leftover `users.organization_id`, orphan `migrations` rows, expired cache/session/pairing rows,
  `personal_access_tokens`) after a JSON backup
- [x] P14 Naming, layout and the attack suite (owner's request, 2026-09-17): every controller, request, JS module and
  feature test moved into a folder named after what it is for (`Platform/`, `Organization/`, `Signage/`, `Advertising/`,
  `Device/`, `Auth/`, `Concerns/`; `core/`, `tables/`, `pages/`; `Auth/`, `Platform/`, `Organization/`, `Signage/`,
  `Advertising/`, `System/`, `Security/`) with the naming rules written down in `.claude/rules/02-project-conventions.md`,
  and nine adversarial test files added under `tests/Feature/Security/` (a tenth, `AdBuilderAttackTest`, came with the
  Ad Builder: 96 attacks in 10 files today). The authorization model held on every attack; the five shape-of-input
  defects they found (`?search[]=x` on every listing, `?device_uuid[]=x` on the open device limiter, a name posted as an
  array on role and channel creation, `password[]=x` on every big delete, a rule that was not an array on the playlist
  preview) are fixed, each with the convention that prevents the next one
