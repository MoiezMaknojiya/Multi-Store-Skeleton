{{-- Accounts (docs/STORE-ORGANIZATION-SPEC.md §8): every account, from the platform's side — a store's own people
     are its Members page (owner's rule, 2026-09-17). Nobody is created or edited here: stores invite their people,
     and the platform team is invited below. What each row allows comes from the server (`can`). --}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-white leading-tight">{{ __('Users') }}</h2>
    </x-slot>

    <div x-data="usersTable({{ Js::from(['isSuperAdmin' => auth()->user()->isSuperAdmin()]) }})"
         class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div>
                <h1 class="text-lg font-semibold text-gray-900 dark:text-white">All accounts</h1>
                {{-- Stores (putting a person in a store) is the super admin's alone, so only they are told of it. --}}
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    People join a store by invitation from that store.
                    @can('super-admin-tier')
                        You can also put them in one with Stores below.
                    @endcan
                    They manage their own name, email and password.
                </p>
            </div>
            @can('super-admin-tier')
            <x-crud.add-button label="Invite to platform team" @click="openInvite()" dusk="invite-platform-member" />
            @endcan
        </div>

        <x-crud.table-wrapper title="Accounts" searchPlaceholder="Search by name or email" :columns="4">
            <x-slot name="head">
                <th class="px-5 py-3 text-left font-semibold">Account</th>
                <th class="px-5 py-3 text-left font-semibold">Access</th>
                <th class="px-5 py-3 text-left font-semibold">Joined</th>
                <th class="px-5 py-3 text-right font-semibold">Actions</th>
            </x-slot>

            <x-slot name="body">
                <x-crud.table-empty :columns="4" itemsVar="items" message="No accounts found." />

                <template x-for="item in items" :key="item.id">
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50" x-bind:dusk="'account-row-' + item.id">
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-3">
                                <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full bg-blue-50 text-sm font-semibold text-blue-700 dark:bg-blue-900/30 dark:text-blue-300"
                                     x-text="initials(item.name)" aria-hidden="true"></div>
                                <div class="min-w-0">
                                    <p class="font-medium text-gray-800 dark:text-white">
                                        <span x-text="item.name"></span>
                                        <span x-show="item.is_you" class="ml-1 badge-neutral">You</span>
                                        <span x-show="item.is_primary" class="ml-1 badge-info" title="The first super admin — cannot be removed">Primary</span>
                                    </p>
                                    <p class="text-xs text-gray-400 truncate" x-text="item.email"></p>
                                </div>
                            </div>
                        </td>
                        <td class="px-5 py-4">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <template x-if="item.platform_role">
                                    <span class="badge-info" x-text="'Platform · ' + item.platform_role"></span>
                                </template>
                                <template x-for="membership in item.memberships" :key="membership.store_id">
                                    <span x-bind:class="roleBadgeClass(membership.role_key)" x-text="membershipLabel(membership)"></span>
                                </template>
                                <span x-show="!item.platform_role && item.memberships.length === 0" class="text-xs text-gray-400">No access</span>
                            </div>
                        </td>
                        <td class="px-5 py-4 text-gray-600 dark:text-gray-300" x-text="formatDate(item.created_at)"></td>
                        <td class="px-5 py-4">
                            <div class="flex items-center justify-end gap-2">
                                <button type="button" class="btn-row-neutral" x-show="item.can.impersonate"
                                    @click="impersonate(item)" x-bind:disabled="impersonating" x-bind:dusk="'impersonate-' + item.id">Log in as</button>
                                <button type="button" class="btn-row-neutral" x-show="item.can.manage_stores"
                                    @click="openManageStores(item)" x-bind:disabled="loadingAccess" x-bind:dusk="'manage-stores-' + item.id">Stores</button>
                                <button type="button" class="btn-row-neutral" x-show="item.can.remove_platform_role"
                                    @click="confirmRemoveRole(item)" x-bind:dusk="'remove-platform-role-' + item.id">Remove platform role</button>
                                <button type="button" class="btn-row-danger" x-show="item.can.delete"
                                    @click="confirmDelete(item)" x-bind:dusk="'delete-account-' + item.id">Delete</button>
                            </div>
                        </td>
                    </tr>
                </template>
            </x-slot>

            <x-slot name="footer">
                <x-crud.pagination itemsVar="items" />
            </x-slot>
        </x-crud.table-wrapper>

        {{-- The same gate as the routes behind everything in here (can:super-admin-tier). --}}
        @can('super-admin-tier')
        {{-- The platform team's open invitations --}}
        <div class="card" dusk="platform-invitations">
            <div class="card-header">
                <h3 class="text-subheading">Platform team invitations</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400">Links are valid for {{ \App\Models\Invitation::LIFETIME_DAYS }} days. Resending sends a new link.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead>
                        <tr class="table-head-row">
                            <th class="px-5 py-3 text-left font-semibold">Email</th>
                            <th class="px-5 py-3 text-left font-semibold">Role</th>
                            <th class="px-5 py-3 text-left font-semibold">Invited by</th>
                            <th class="px-5 py-3 text-left font-semibold">Status</th>
                            <th class="px-5 py-3 text-right font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="table-tbody">
                        <tr x-show="invitations.length === 0">
                            <td colspan="5" class="px-5 py-6 text-center text-muted-soft">No invitations are waiting.</td>
                        </tr>
                        <template x-for="invitation in invitations" :key="invitation.id">
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                <td class="px-5 py-4 font-medium text-gray-800 dark:text-white" x-text="invitation.email"></td>
                                <td class="px-5 py-4"><span class="badge-info" x-text="invitation.role ?? '—'"></span></td>
                                <td class="px-5 py-4 text-gray-600 dark:text-gray-300" x-text="invitation.invited_by ?? '—'"></td>
                                <td class="px-5 py-4"><span x-bind:class="invitation.is_expired ? 'badge-danger' : 'badge-info'" x-text="expiresText(invitation)"></span></td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center justify-end gap-2">
                                        <button type="button" class="btn-row-neutral" @click="resendInvitation(invitation)" x-bind:disabled="busyInvitationId !== null"
                                            x-bind:dusk="'resend-platform-invitation-' + invitation.id">Resend</button>
                                        <button type="button" class="btn-row-danger" @click="confirmRevoke(invitation)"
                                            x-bind:dusk="'revoke-platform-invitation-' + invitation.id">Revoke</button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Invite to the platform team --}}
        <x-modal name="invite-platform-member" :show="false" maxWidth="md" focusable>
            <form @submit.prevent="sendInvite()" class="p-6 space-y-4" dusk="invite-platform-form">
                <div>
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Invite to the platform team</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Platform staff work above the stores. A store member's email cannot be invited.
                    </p>
                </div>

                <x-crud.form-field label="Email" field="email" :required="true">
                    <x-text-input type="email" x-model="inviteForm.email" dusk="invite-platform-email" class="block w-full" autocomplete="off" placeholder="name@example.com" />
                </x-crud.form-field>

                <x-crud.form-field label="Platform role" field="role_id" :required="true">
                    <select x-model="inviteForm.role_id" dusk="invite-platform-role" class="form-select">
                        <option value="">Choose a role</option>
                        @foreach ($platformRoles as $role)
                            <option value="{{ $role->id }}">{{ $role->name }}</option>
                        @endforeach
                    </select>
                </x-crud.form-field>

                <x-crud.form-actions cancelAction="$dispatch('close-modal', 'invite-platform-member')" savingVar="inviting" saveLabel="Send invitation" dusk="invite-platform-send" />
            </form>
        </x-modal>

        {{-- Manage stores: put a person in a store with a role, change that role, take it away — from the platform.
             Straight in, no invitation (owner's rule, 2026-09-17). --}}
        <x-modal name="manage-stores" :show="false" maxWidth="2xl" focusable>
            <div class="p-6 space-y-6" dusk="manage-stores">
                <div>
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100"><span x-text="accessTarget?.name"></span>'s stores</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Add them to any store with a role, change the role they hold, or take them out. The change is immediate — nobody is invited.
                    </p>
                </div>

                {{-- The stores they are in --}}
                <section>
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Member of</h3>
                    <p x-show="access.memberships.length === 0" class="mt-2 text-sm text-gray-500 dark:text-gray-400">Not in any store yet.</p>
                    <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
                        <template x-for="membership in access.memberships" :key="membership.store_id">
                            <li class="py-3" x-bind:dusk="'membership-' + membership.store_id">
                                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                                    <p class="sm:w-44 text-sm font-medium text-gray-800 dark:text-gray-100 truncate" x-text="membership.store_name"></p>
                                    <div class="flex-1 min-w-0">
                                        <select class="form-select" x-model.number="membership.selected_role_id" x-bind:dusk="'membership-role-' + membership.store_id"
                                                aria-label="Role in this store">
                                            <template x-for="role in rolesFor(membership.store_id)" :key="role.id">
                                                <option x-bind:value="role.id" x-text="role.name" x-bind:selected="role.id === membership.selected_role_id"></option>
                                            </template>
                                        </select>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <button type="button" class="btn-row-neutral" @click="saveMembershipRole(membership)"
                                                x-bind:disabled="membership.busy || membership.selected_role_id === membership.role_id"
                                                x-bind:dusk="'membership-save-' + membership.store_id">Save</button>
                                        <button type="button" class="btn-row-danger" @click="askRemove(membership)"
                                                x-bind:disabled="membership.busy" x-bind:dusk="'membership-remove-' + membership.store_id">Remove</button>
                                    </div>
                                </div>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" x-text="roleDescription(membership.store_id, membership.selected_role_id)"></p>
                                <p class="form-error" x-show="membership.error" x-text="membership.error"></p>
                            </li>
                        </template>
                    </ul>

                    {{-- Taking them out of a store is a big delete: the password, in a real form. --}}
                    <form x-show="removing" x-cloak @submit.prevent="removeMembership()" dusk="remove-membership-form"
                          class="mt-3 rounded-md border border-red-200 dark:border-red-900/60 bg-red-50 dark:bg-red-900/20 p-4">
                        <p class="text-sm text-red-800 dark:text-red-300">
                            Take <span class="font-semibold" x-text="accessTarget?.name"></span> out of
                            <span class="font-semibold" x-text="removing?.store_name"></span>? What they made there stays with the store.
                        </p>
                        <x-crud.password-confirm id="remove-membership-password" model="removePassword" error="removePasswordError" />
                        <div class="mt-4 flex justify-end gap-3">
                            <x-secondary-button x-on:click="removing = null">Cancel</x-secondary-button>
                            <x-danger-button x-bind:disabled="removingBusy" dusk="remove-membership-confirm">Remove from store</x-danger-button>
                        </div>
                    </form>
                </section>

                {{-- Add them to another store --}}
                <section class="border-t border-gray-200 dark:border-gray-700 pt-5">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Add to a store</h3>
                    <p x-show="access.stores.length === 0" class="mt-2 text-sm text-gray-500 dark:text-gray-400">They are already in every store.</p>
                    <form x-show="access.stores.length > 0" @submit.prevent="assignToStore()" class="mt-3 grid grid-cols-1 sm:grid-cols-[1fr_1fr_auto] gap-3 items-start" dusk="assign-store-form">
                        <div x-bind:class="assignErrors.store_id ? 'crud-field-error' : ''">
                            <select class="form-select" x-model="assignForm.store_id" @change="assignForm.role_id = ''" dusk="assign-store" aria-label="Store">
                                <option value="">Choose a store</option>
                                <template x-for="option in access.stores" :key="option.id">
                                    <option x-bind:value="option.id" x-text="option.name + (option.city ? ' — ' + option.city : '')"></option>
                                </template>
                            </select>
                            <template x-if="assignErrors.store_id"><p class="form-error" x-text="assignErrors.store_id[0]"></p></template>
                        </div>
                        <div x-bind:class="assignErrors.role_id ? 'crud-field-error' : ''">
                            <select class="form-select" x-model="assignForm.role_id" x-bind:disabled="!assignForm.store_id" dusk="assign-role" aria-label="Role">
                                <option value="">Choose a role</option>
                                <template x-for="role in rolesFor(assignForm.store_id)" :key="role.id">
                                    <option x-bind:value="role.id" x-text="role.name"></option>
                                </template>
                            </select>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" x-show="assignForm.role_id" x-text="roleDescription(assignForm.store_id, assignForm.role_id)"></p>
                            <template x-if="assignErrors.role_id"><p class="form-error" x-text="assignErrors.role_id[0]"></p></template>
                        </div>
                        <x-primary-button x-bind:disabled="assigning" dusk="assign-store-save">Add</x-primary-button>
                    </form>
                </section>

                <div class="flex justify-end">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'manage-stores')">Close</x-secondary-button>
                </div>
            </div>
        </x-modal>

        {{-- Remove platform role --}}
        <x-modal name="confirm-remove-platform-role" :show="false" maxWidth="md" focusable>
            <form @submit.prevent="removeRole()" class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Remove <span x-text="selectedForRole?.platform_role"></span> from <span x-text="selectedForRole?.name"></span>?</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    They leave the platform team at once. Their account stays, with no access to anything.
                </p>

                <x-crud.password-confirm id="remove-platform-role-password" model="rolePassword" error="rolePasswordError" />

                <div class="mt-6 flex justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'confirm-remove-platform-role')">Cancel</x-secondary-button>
                    <x-danger-button x-bind:disabled="removingRole" dusk="remove-platform-role-confirm">Remove role</x-danger-button>
                </div>
            </form>
        </x-modal>

        {{-- Revoke a platform invitation --}}
        <x-modal name="confirm-revoke-platform-invitation" :show="false" maxWidth="md" focusable>
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Revoke this invitation?</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    The link sent to <span class="font-medium" x-text="selectedInvitation?.email"></span> stops working at once.
                </p>
                <div class="mt-6 flex justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'confirm-revoke-platform-invitation')">Cancel</x-secondary-button>
                    <x-danger-button x-on:click="revokeInvitation()" x-bind:disabled="revoking" dusk="revoke-platform-invitation-confirm">Revoke invitation</x-danger-button>
                </div>
            </div>
        </x-modal>
        @endcan

        {{-- Delete an account --}}
        <x-modal name="confirm-account-deletion" :show="false" maxWidth="md" focusable>
            <form @submit.prevent="deleteItem()" class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Delete <span x-text="selectedItem?.name"></span>'s account?</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    The account, its access to every store, its sign-ins everywhere and any invitation waiting for its email are deleted. What they added to stores stays with those stores.
                    <span class="font-semibold text-red-600 dark:text-red-400">This cannot be undone.</span>
                </p>
                <div x-show="selectedItem?.sole_owner_of?.length" x-cloak
                     class="mt-4 rounded-md border border-amber-200 dark:border-amber-900/50 bg-amber-50 dark:bg-amber-900/20 px-4 py-3 text-sm text-amber-800 dark:text-amber-300">
                    They are the only Owner of <span class="font-semibold" x-text="(selectedItem?.sole_owner_of ?? []).join(', ')"></span>.
                    <span x-text="(selectedItem?.sole_owner_of?.length ?? 0) === 1 ? 'That store' : 'Those stores'"></span>
                    {{-- Only the ways back this viewer actually has: Stores here is the super admin's alone, and
                         Invite owner on the Stores page needs View Stores and Create Stores (routes/web.php). --}}
                    @can('super-admin-tier')
                        will have no owner until you give it one — Invite owner on Stores, or Stores here on Users.
                    @elsecan(['store-view', 'store-store'])
                        will have no owner until you give it one — Invite owner on Stores.
                    @else
                        will have no owner until one is given.
                    @endcan
                </div>

                <x-crud.password-confirm id="delete-account-password" />

                <div class="mt-6 flex justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'confirm-account-deletion')">Cancel</x-secondary-button>
                    <x-danger-button x-bind:disabled="deleting" dusk="delete-account-confirm">Delete account</x-danger-button>
                </div>
            </form>
        </x-modal>
    </div>
</x-app-layout>
