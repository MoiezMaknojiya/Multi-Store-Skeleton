{{-- Accounts (docs/ORGANIZATION-SPEC.md §8): every account, from the platform's side — an organization's own people
     are its Members page (owner's rule, 2026-09-17). Nobody is created or edited here: organizations invite their people,
     and the platform team is invited below. What each row allows comes from the server (`can`). --}}
<x-app-layout>
    <x-slot name="header">
        <h1 class="page-title">{{ __('Users') }}</h1>
    </x-slot>

    <div x-data="usersTable({{ Js::from(['isSuperAdmin' => auth()->user()->isSuperAdmin()]) }})"
         class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        @can('super-admin-tier')
            {{-- The super admin's two sides of the accounts (owner, 2026-10-07 — "Platform Team aur Organization Members bana
                 do"): one list, shown whole or one side of it, each saying how many it holds. The open one is aria-pressed,
                 written as the page opens so the row is right before Alpine starts; tab-link colours it (app.css). --}}
            <div class="border-b border-gray-200 dark:border-gray-700">
                <div class="-mb-px flex flex-wrap gap-x-6 gap-y-2" role="group" aria-label="Show accounts">
                    @foreach (['all' => 'All', 'platform' => 'Platform Team', 'organization' => 'Organization Members'] as $group => $label)
                        <button type="button" class="tab-link" dusk="accounts-tab-{{ $group }}" @click="showGroup('{{ $group === 'all' ? '' : $group }}')"
                            aria-pressed="{{ $group === 'all' ? 'true' : 'false' }}" x-bind:aria-pressed="(group || 'all') === '{{ $group }}' ? 'true' : 'false'">
                            {{ $label }} <span class="ml-1 badge-neutral" x-show="counts" x-cloak x-text="counts?.{{ $group }}" dusk="accounts-count-{{ $group }}"></span>
                        </button>
                    @endforeach
                </div>
            </div>
        @endcan

        {{-- Inviting to the platform team (the super admin's alone) stands beside the search. --}}
        <x-crud.table-wrapper title="Accounts" searchPlaceholder="Search by name or email" :columns="4">
            @can('super-admin-tier')
                <x-slot name="actions">
                    <x-crud.add-button label="Invite to Platform Team" @click="openInvite()" dusk="invite-platform-member" />
                </x-slot>
            @endcan
            {{-- Account and Joined sort the list (owner, 2026-10-07): the newest accounts first until another is pressed. --}}
            <x-slot name="head">
                <x-crud.sort-header column="name" label="Account" />
                <th class="px-5 py-3 text-left font-semibold">Access</th>
                <x-crud.sort-header column="joined" label="Joined" first="desc" />
                <th class="px-5 py-3 text-right font-semibold">Actions</th>
            </x-slot>

            <x-slot name="body">
                <x-crud.table-empty :columns="4" itemsVar="items" message="No accounts yet."
                    filtered="group !== ''" clearFilters="showGroup('')" />

                <template x-for="item in items" :key="item.id">
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50" x-bind:dusk="'account-row-' + item.id">
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-3">
                                <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full bg-blue-50 text-sm font-semibold text-blue-700 dark:bg-blue-900/30 dark:text-blue-300"
                                     x-text="initials(item.name)" aria-hidden="true"></div>
                                <div class="min-w-0">
                                    {{-- The name is one line; its pills drop below it, whole, when the column is narrow. --}}
                                    <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 font-medium text-gray-800 dark:text-white">
                                        <span x-text="item.name"></span>
                                        <span x-show="item.is_you" class="badge-neutral">You</span>
                                        <span x-show="item.is_primary" class="badge-info relative" title="The first super admin — cannot be removed">Primary<span class="sr-only">: the first super admin, who cannot be removed</span></span>
                                    </p>
                                    <p class="text-xs text-gray-500 truncate dark:text-gray-400" x-text="item.email"></p>
                                </div>
                            </div>
                        </td>
                        <td class="px-5 py-4">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <template x-if="item.platform_role">
                                    <span class="badge-info" x-text="'Platform · ' + item.platform_role"></span>
                                </template>
                                <template x-for="membership in item.memberships" :key="membership.organization_id">
                                    <span x-bind:class="roleBadgeClass(membership.role_key)" x-text="membershipLabel(membership)"></span>
                                </template>
                                <span x-show="!item.platform_role && item.memberships.length === 0" class="text-xs text-gray-500 dark:text-gray-400">No access</span>
                            </div>
                        </td>
                        <td class="px-5 py-4 text-gray-600 dark:text-gray-300 whitespace-nowrap" x-text="formatDate(item.created_at)"></td>
                        <td class="px-5 py-4">
                            {{-- Each names the account it acts on; the two that take something away are red. --}}
                            <div class="flex items-center justify-end gap-2">
                                <button type="button" class="btn-row-neutral" x-show="item.can.impersonate"
                                    x-bind:aria-label="'Log in as ' + item.name"
                                    @click="impersonate(item)" x-bind:disabled="impersonating" x-bind:dusk="'impersonate-' + item.id">Log In As</button>
                                <button type="button" class="btn-row-neutral" x-show="item.can.manage_organizations"
                                    x-bind:aria-label="item.name + '’s organizations'"
                                    @click="openManageOrganizations(item)" x-bind:disabled="loadingAccess" x-bind:dusk="'manage-organizations-' + item.id">Organizations</button>
                                <button type="button" class="btn-row-danger" x-show="item.can.remove_platform_role"
                                    x-bind:aria-label="'Remove the platform role of ' + item.name"
                                    @click="confirmRemoveRole(item)" x-bind:dusk="'remove-platform-role-' + item.id">Remove Platform Role</button>
                                <button type="button" class="btn-row-danger" x-show="item.can.delete"
                                    x-bind:aria-label="'Delete ' + item.name"
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
                <h2 class="text-subheading">Platform team invitations</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">Links last {{ \App\Models\Invitation::LIFETIME_DAYS }} days.</p>
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
                                            x-bind:aria-label="'Resend the invitation to ' + invitation.email"
                                            x-bind:dusk="'resend-platform-invitation-' + invitation.id">
                                            <x-spinner x-show="busyInvitationId === invitation.id" x-cloak class="h-3 w-3" />
                                            Resend
                                        </button>
                                        <button type="button" class="btn-row-danger" @click="confirmRevoke(invitation)"
                                            x-bind:aria-label="'Revoke the invitation to ' + invitation.email"
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
            <form @submit.prevent="sendInvite()" novalidate class="p-6 space-y-4" dusk="invite-platform-form">
                <div>
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Invite to the platform team</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Platform staff work above the organizations.
                    </p>
                </div>

                <x-crud.form-field label="Email" field="email" :required="true">
                    <x-text-input type="email" x-model="inviteForm.email" dusk="invite-platform-email" maxlength="255" autocomplete="off" placeholder="name@example.com" />
                </x-crud.form-field>

                <x-crud.form-field label="Platform role" field="role_id" :required="true">
                    <select x-model="inviteForm.role_id" dusk="invite-platform-role" class="form-select">
                        <option value="">Choose a role</option>
                        @foreach ($platformRoles as $role)
                            <option value="{{ $role->id }}">{{ $role->name }}</option>
                        @endforeach
                    </select>
                </x-crud.form-field>

                <x-crud.form-actions cancelAction="$dispatch('close-modal', 'invite-platform-member')" savingVar="inviting" saveLabel="Send Invitation" dusk="invite-platform-send" />
            </form>
        </x-modal>

        {{-- Manage organizations: put a person in an organization with a role, change that role, take it away — from the platform.
             Straight in, no invitation (owner's rule, 2026-09-17). --}}
        <x-modal name="manage-organizations" :show="false" maxWidth="2xl" focusable>
            <div class="p-6 space-y-6" dusk="manage-organizations">
                <div>
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100"><span x-text="accessTarget?.name"></span>'s organizations</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Add them to an organization, change their role or take them out. It happens at once.
                    </p>
                </div>

                {{-- The organizations they are in --}}
                <section>
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Member of</h3>
                    <p x-show="access.memberships.length === 0" class="mt-2 text-sm text-gray-500 dark:text-gray-400">Not in any organization yet.</p>
                    <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
                        <template x-for="membership in access.memberships" :key="membership.organization_id">
                            <li class="py-3" x-bind:dusk="'membership-' + membership.organization_id">
                                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                                    <p class="sm:w-44 text-sm font-medium text-gray-800 dark:text-gray-100 truncate" x-text="membership.organization_name"></p>
                                    <div class="flex-1 min-w-0">
                                        <select class="form-select" x-model.number="membership.selected_role_id" x-bind:dusk="'membership-role-' + membership.organization_id"
                                                aria-label="Role in this organization">
                                            <template x-for="role in rolesFor(membership.organization_id)" :key="role.id">
                                                <option x-bind:value="role.id" x-text="role.name" x-bind:selected="role.id === membership.selected_role_id"></option>
                                            </template>
                                        </select>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <button type="button" class="btn-row-neutral" @click="saveMembershipRole(membership)"
                                                x-bind:disabled="membership.busy || membership.selected_role_id === membership.role_id"
                                                x-bind:aria-label="'Save the role in ' + membership.organization_name"
                                                x-bind:dusk="'membership-save-' + membership.organization_id">Save</button>
                                        <button type="button" class="btn-row-danger" @click="askRemove(membership)"
                                                x-bind:aria-label="'Take them out of ' + membership.organization_name"
                                                x-bind:disabled="membership.busy" x-bind:dusk="'membership-remove-' + membership.organization_id">Remove</button>
                                    </div>
                                </div>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" x-text="roleDescription(membership.organization_id, membership.selected_role_id)"></p>
                                <p class="form-error" role="alert" x-show="membership.error" x-text="membership.error"></p>
                            </li>
                        </template>
                    </ul>

                    {{-- Taking them out of an organization is a big delete: the password, in a real form. --}}
                    <form x-show="removing" x-cloak @submit.prevent="removeMembership()" dusk="remove-membership-form"
                          class="alert-error mt-3">
                        <p>
                            Take <span class="font-semibold" x-text="accessTarget?.name"></span> out of
                            <span class="font-semibold" x-text="removing?.organization_name"></span>? What they made there stays with the organization.
                        </p>
                        {{-- They can be put back in, so it asks only that it is you. --}}
                        <x-crud.password-confirm id="remove-membership-password" model="removePassword" error="removePasswordError" hint="Confirm it is you." />
                        <div class="mt-4 flex flex-wrap justify-end gap-3">
                            <x-secondary-button x-on:click="removing = null">Cancel</x-secondary-button>
                            <x-danger-button x-bind:disabled="removingBusy" dusk="remove-membership-confirm">
                                <x-spinner x-show="removingBusy" x-cloak />
                                Remove from Organization
                            </x-danger-button>
                        </div>
                    </form>
                </section>

                {{-- Add them to another organization --}}
                <section class="border-t border-gray-200 dark:border-gray-700 pt-5">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Add to an organization</h3>
                    <p x-show="access.organizations.length === 0" class="mt-2 text-sm text-gray-500 dark:text-gray-400">They are already in every organization.</p>
                    <form x-show="access.organizations.length > 0" @submit.prevent="assignToOrganization()" class="mt-3 grid grid-cols-1 sm:grid-cols-[1fr_1fr_auto] gap-3 items-start" dusk="assign-organization-form">
                        <div x-bind:class="assignErrors.organization_id ? 'crud-field-error' : ''">
                            <select class="form-select" x-model="assignForm.organization_id" @change="assignForm.role_id = ''" dusk="assign-organization" aria-label="Organization">
                                <option value="">Choose an organization</option>
                                <template x-for="option in access.organizations" :key="option.id">
                                    <option x-bind:value="option.id" x-text="option.name + (option.city ? ' — ' + option.city : '')"></option>
                                </template>
                            </select>
                            <template x-if="assignErrors.organization_id"><p class="form-error" role="alert" x-text="assignErrors.organization_id[0]"></p></template>
                        </div>
                        <div x-bind:class="assignErrors.role_id ? 'crud-field-error' : ''">
                            <select class="form-select" x-model="assignForm.role_id" x-bind:disabled="!assignForm.organization_id" dusk="assign-role" aria-label="Role">
                                <option value="">Choose a role</option>
                                <template x-for="role in rolesFor(assignForm.organization_id)" :key="role.id">
                                    <option x-bind:value="role.id" x-text="role.name"></option>
                                </template>
                            </select>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" x-show="assignForm.role_id" x-text="roleDescription(assignForm.organization_id, assignForm.role_id)"></p>
                            <template x-if="assignErrors.role_id"><p class="form-error" role="alert" x-text="assignErrors.role_id[0]"></p></template>
                        </div>
                        <x-primary-button x-bind:disabled="assigning" dusk="assign-organization-save">
                            <x-spinner x-show="assigning" x-cloak />
                            Add
                        </x-primary-button>
                    </form>
                </section>

                <div class="flex justify-end">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'manage-organizations')">Close</x-secondary-button>
                </div>
            </div>
        </x-modal>

        {{-- Remove platform role --}}
        <x-modal name="confirm-remove-platform-role" :show="false" maxWidth="md" focusable>
            <form @submit.prevent="removeRole()" class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Remove <span x-text="selectedForRole?.platform_role"></span> from <span x-text="selectedForRole?.name"></span>?</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    They leave the platform team at once. Their account stays.
                </p>

                {{-- They can be invited back, so it asks only that it is you. --}}
                <x-crud.password-confirm id="remove-platform-role-password" model="rolePassword" error="rolePasswordError" hint="Confirm it is you." />

                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'confirm-remove-platform-role')">Cancel</x-secondary-button>
                    <x-danger-button x-bind:disabled="removingRole" dusk="remove-platform-role-confirm">
                        <x-spinner x-show="removingRole" x-cloak />
                        Remove Role
                    </x-danger-button>
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
                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'confirm-revoke-platform-invitation')">Cancel</x-secondary-button>
                    <x-danger-button x-on:click="revokeInvitation()" x-bind:disabled="revoking" dusk="revoke-platform-invitation-confirm">
                        <x-spinner x-show="revoking" x-cloak />
                        Revoke Invitation
                    </x-danger-button>
                </div>
            </div>
        </x-modal>
        @endcan

        {{-- Delete an account --}}
        <x-modal name="confirm-account-deletion" :show="false" maxWidth="md" focusable>
            <form @submit.prevent="deleteItem()" class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Delete <span x-text="selectedItem?.name"></span>'s account?</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    The account and its access to every organization are deleted. What they added stays with the organizations.
                    <span class="font-semibold text-red-600 dark:text-red-400">This cannot be undone.</span>
                </p>
                <div x-show="selectedItem?.sole_owner_of?.length" x-cloak class="alert-warning mt-4">
                    They are the only Owner of <span class="font-semibold" x-text="(selectedItem?.sole_owner_of ?? []).join(', ')"></span>.
                    <span x-text="(selectedItem?.sole_owner_of?.length ?? 0) === 1 ? 'That organization' : 'Those organizations'"></span>
                    {{-- Only the ways back this viewer actually has: Organizations here is the super admin's alone, and
                         Invite owner on the Organizations page needs View Organizations and Create Organizations (routes/web.php). --}}
                    @can('super-admin-tier')
                        will have no owner until you give it one — Invite owner on Organizations, or Organizations here on Users.
                    @elsecan(['organization-view', 'organization-store'])
                        will have no owner until you give it one — Invite owner on Organizations.
                    @else
                        will have no owner until one is given.
                    @endcan
                </div>

                <x-crud.password-confirm id="delete-account-password" />

                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'confirm-account-deletion')">Cancel</x-secondary-button>
                    <x-danger-button x-bind:disabled="deleting" dusk="delete-account-confirm">
                        <x-spinner x-show="deleting" x-cloak />
                        Delete Account
                    </x-danger-button>
                </div>
            </form>
        </x-modal>
    </div>
</x-app-layout>
