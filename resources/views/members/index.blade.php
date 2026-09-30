{{-- The current store's team (docs/STORE-ORGANIZATION-SPEC.md §8). Buttons are gated by the
     route permissions here; what may be done to each ROW comes from the server (can_manage). --}}
<x-app-layout>
    <x-slot name="header">
        <h1 class="page-title">{{ __('Members') }}</h1>
    </x-slot>

    <div x-data="membersPage({{ Js::from(['storeName' => $store->name]) }})"
         class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        {{-- Invite stands on each list's header line (beside the members' search); leaving — the person's own step —
             is at the foot of the page. --}}
        {{-- Tabs: a tab list with its two panels, so a screen reader says "tab, 1 of 2" and which list is open. --}}
        <div class="border-b border-gray-200 dark:border-gray-700">
            <div class="-mb-px flex gap-6" role="tablist" aria-label="Team">
                {{-- The open tab is the one with aria-selected, written as the page opens (Members) so the row is
                     right before Alpine starts; tab-link colours it (app.css). --}}
                <button type="button" role="tab" id="tab-members" aria-controls="panel-members" dusk="tab-members" class="tab-link"
                    x-on:click="tab = 'members'" aria-selected="true" :aria-selected="tab === 'members' ? 'true' : 'false'">
                    Members <span class="ml-1 badge-neutral" x-text="members.length"></span>
                </button>
                <button type="button" role="tab" id="tab-invitations" aria-controls="panel-invitations" dusk="tab-invitations" class="tab-link"
                    x-on:click="tab = 'invitations'" aria-selected="false" :aria-selected="tab === 'invitations' ? 'true' : 'false'">
                    Pending invitations <span class="ml-1 badge-neutral" x-text="invitations.length"></span>
                </button>
            </div>
        </div>

        {{-- Members --}}
        <div x-show="tab === 'members'" class="card" id="panel-members" role="tabpanel" aria-labelledby="tab-members" dusk="members-table">
            <div class="card-header">
                <h2 class="text-subheading min-w-0">Team of {{ $store->name }}</h2>
                <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto sm:flex-nowrap sm:justify-end">
                    <x-crud.search-input placeholder="Search by name or email" />
                    @can('member-invite')
                        <x-crud.add-button label="Invite member" @click="openInvite()" dusk="invite-member" />
                    @endcan
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead>
                        <tr class="table-head-row">
                            <th class="px-5 py-3 text-left font-semibold">Member</th>
                            <th class="px-5 py-3 text-left font-semibold">Role</th>
                            <th class="px-5 py-3 text-left font-semibold">Joined</th>
                            <th class="px-5 py-3 text-right font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="table-tbody" x-bind:aria-busy="loading ? 'true' : 'false'">
                        <template x-if="loading">
                            <tr><td colspan="4" class="px-5 py-6 text-center text-muted-soft">
                                <span class="inline-flex items-center gap-2"><x-spinner class="text-blue-600 dark:text-blue-400" /> Loading...</span>
                            </td></tr>
                        </template>
                        <template x-if="!loading && filteredMembers().length === 0">
                            <tr><td colspan="4" class="px-5 py-10 text-center">
                                <div class="mx-auto max-w-md space-y-3" dusk="table-no-match">
                                    <p class="text-sm text-gray-700 dark:text-gray-200">Nobody matches &ldquo;<span class="font-medium" x-text="search"></span>&rdquo;.</p>
                                    <button type="button" class="btn-row-neutral" @click="search = ''" dusk="table-clear-search">Clear search</button>
                                </div>
                            </td></tr>
                        </template>

                        <template x-for="member in filteredMembers()" :key="member.id">
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50" :dusk="'member-row-' + member.id">
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full bg-blue-50 text-sm font-semibold text-blue-700 dark:bg-blue-900/30 dark:text-blue-300"
                                             x-text="initials(member.name)" aria-hidden="true"></div>
                                        <div class="min-w-0">
                                            {{-- The name is one line; its pill drops below it, whole, when the column is narrow. --}}
                                            <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 font-medium text-gray-800 dark:text-white">
                                                <span x-text="member.name"></span>
                                                <span x-show="member.is_you" class="badge-neutral">You</span>
                                            </p>
                                            <p class="text-xs text-gray-500 truncate dark:text-gray-400" x-text="member.email"></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4">
                                    <span :class="roleBadgeClass(member.role)" x-text="member.role?.name ?? '—'" :dusk="'member-role-' + member.id"></span>
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap text-gray-600 dark:text-gray-300" x-text="formatDate(member.joined_at)"></td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center justify-end gap-2">
                                        @can('member-update')
                                        <button type="button" class="btn-row-neutral" x-show="member.can_manage"
                                            x-bind:aria-label="'Change the role of ' + member.name"
                                            x-on:click="openChangeRole(member)" :dusk="'change-role-' + member.id">Change role</button>
                                        @endcan
                                        @can('member-remove')
                                        <button type="button" class="btn-row-danger" x-show="member.can_manage"
                                            x-bind:aria-label="'Remove ' + member.name"
                                            x-on:click="confirmRemove(member)" :dusk="'remove-member-' + member.id">Remove</button>
                                        @endcan
                                        <span x-show="!member.can_manage" class="relative text-xs text-gray-500 dark:text-gray-400"><span aria-hidden="true">—</span><span class="sr-only">Nothing to change</span></span>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Pending invitations --}}
        <div x-show="tab === 'invitations'" x-cloak class="card" id="panel-invitations" role="tabpanel" aria-labelledby="tab-invitations" dusk="invitations-table">
            <div class="card-header">
                <div class="min-w-0">
                    <h2 class="text-subheading">Pending invitations</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Links last {{ \App\Models\Invitation::LIFETIME_DAYS }} days.</p>
                </div>
                @can('member-invite')
                    <x-crud.add-button label="Invite member" @click="openInvite()" dusk="invite-member-invitations" />
                @endcan
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
                    <tbody class="table-tbody" x-bind:aria-busy="loading ? 'true' : 'false'">
                        <template x-if="loading">
                            <tr><td colspan="5" class="px-5 py-6 text-center text-muted-soft">
                                <span class="inline-flex items-center gap-2"><x-spinner class="text-blue-600 dark:text-blue-400" /> Loading...</span>
                            </td></tr>
                        </template>
                        <template x-if="!loading && invitations.length === 0">
                            <tr>
                                <td colspan="5" class="px-5 py-10 text-center">
                                    <p class="text-sm font-medium text-gray-700 dark:text-gray-200" dusk="invitations-empty">No invitations are waiting.</p>
                                </td>
                            </tr>
                        </template>

                        <template x-for="invitation in invitations" :key="invitation.id">
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50" :dusk="'invitation-row-' + invitation.id">
                                <td class="px-5 py-4 font-medium text-gray-800 dark:text-white" x-text="invitation.email"></td>
                                <td class="px-5 py-4"><span :class="roleBadgeClass(invitation.role)" x-text="invitation.role?.name ?? '—'"></span></td>
                                <td class="px-5 py-4 text-gray-600 dark:text-gray-300" x-text="invitation.invited_by ?? '—'"></td>
                                <td class="px-5 py-4 whitespace-nowrap">
                                    <span :class="invitation.is_expired ? 'badge-danger' : 'badge-info'" x-text="expiresText(invitation)"></span>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center justify-end gap-2" x-show="invitation.can_manage">
                                        @can('member-invite')
                                        <button type="button" class="btn-row-neutral" x-on:click="resend(invitation)"
                                            x-bind:aria-label="'Resend the invitation to ' + invitation.email"
                                            :disabled="busyInvitationId !== null" :dusk="'resend-invitation-' + invitation.id">
                                            <x-spinner x-show="busyInvitationId === invitation.id" x-cloak class="h-3 w-3" />
                                            Resend
                                        </button>
                                        <button type="button" class="btn-row-danger" x-on:click="confirmRevoke(invitation)"
                                            x-bind:aria-label="'Revoke the invitation to ' + invitation.email"
                                            :dusk="'revoke-invitation-' + invitation.id">Revoke</button>
                                        @endcan
                                    </div>
                                    <span x-show="!invitation.can_manage" class="relative block text-right text-xs text-gray-500 dark:text-gray-400"><span aria-hidden="true">—</span><span class="sr-only">Nothing to change</span></span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Invite --}}
        <x-modal name="invite-member" :show="false" maxWidth="md" focusable>
            <form @submit.prevent="sendInvite()" novalidate class="p-6 space-y-4" dusk="invite-form">
                <div>
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Invite a member</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        They'll get an email with a link to join {{ $store->name }}.
                    </p>
                </div>

                <x-crud.form-field label="Email" field="email" :required="true">
                    <x-text-input type="email" x-model="inviteForm.email" dusk="invite-email" maxlength="255" autocomplete="off" placeholder="name@example.com" />
                </x-crud.form-field>

                <x-crud.form-field label="Role" field="role_id" :required="true">
                    <select x-model="inviteForm.role_id" dusk="invite-role" class="form-select">
                        <option value="">Choose a role</option>
                        <template x-for="role in assignableRoles" :key="role.id">
                            <option :value="role.id" x-text="role.name"></option>
                        </template>
                    </select>
                    <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400" x-show="inviteForm.role_id" x-text="roleDescription(inviteForm.role_id)"></p>
                </x-crud.form-field>

                <x-crud.form-actions cancelAction="$dispatch('close-modal', 'invite-member')" savingVar="inviting" saveLabel="Send invitation" dusk="invite-send" />
            </form>
        </x-modal>

        {{-- Change role --}}
        <x-modal name="change-member-role" :show="false" maxWidth="md" focusable>
            <form @submit.prevent="saveRole()" novalidate class="p-6 space-y-4" dusk="change-role-form">
                <div>
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        Change role for <span x-text="selectedMember?.name"></span>
                    </h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">The new role takes effect on their next page load.</p>
                </div>

                <x-crud.form-field label="Role" field="role_id" :required="true">
                    <select x-model="roleForm.role_id" dusk="change-role-select" class="form-select">
                        <template x-for="role in assignableRoles" :key="role.id">
                            <option :value="role.id" x-text="role.name" :selected="String(role.id) === String(roleForm.role_id)"></option>
                        </template>
                    </select>
                    <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400" x-text="roleDescription(roleForm.role_id)"></p>
                </x-crud.form-field>

                <x-crud.form-actions cancelAction="$dispatch('close-modal', 'change-member-role')" savingVar="changingRole" saveLabel="Save role" dusk="change-role-save" />
            </form>
        </x-modal>

        {{-- Remove member --}}
        <x-modal name="remove-member" :show="false" maxWidth="md" focusable>
            <form @submit.prevent="removeMember()" class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Remove <span x-text="selectedMember?.name"></span>?</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    They lose access to {{ $store->name }} at once. Their account and what they added stay.
                </p>

                {{-- They can be invited back, so it asks only that it is you. --}}
                <x-crud.password-confirm id="remove-member-password" model="removePassword" error="removePasswordError" hint="Confirm it is you." />

                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'remove-member')">Cancel</x-secondary-button>
                    <x-danger-button x-bind:disabled="removing" dusk="remove-member-confirm">
                        <x-spinner x-show="removing" x-cloak />
                        Remove member
                    </x-danger-button>
                </div>
            </form>
        </x-modal>

        {{-- Revoke invitation --}}
        <x-modal name="revoke-invitation" :show="false" maxWidth="md" focusable>
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Revoke this invitation?</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    The link sent to <span class="font-medium" x-text="selectedInvitation?.email"></span> stops working at once.
                    You can invite them again later.
                </p>
                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'revoke-invitation')">Cancel</x-secondary-button>
                    <x-danger-button x-on:click="revoke()" x-bind:disabled="revoking" dusk="revoke-invitation-confirm">
                        <x-spinner x-show="revoking" x-cloak />
                        Revoke invitation
                    </x-danger-button>
                </div>
            </div>
        </x-modal>

        {{-- Leave store --}}
        <x-modal name="leave-store" :show="false" maxWidth="md" focusable>
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Leave {{ $store->name }}?</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    You lose access at once. To come back, someone must invite you again.
                </p>
                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'leave-store')">Cancel</x-secondary-button>
                    <x-danger-button x-on:click="leave()" x-bind:disabled="leaving" dusk="leave-store-confirm">
                        <x-spinner x-show="leaving" x-cloak />
                        Leave store
                    </x-danger-button>
                </div>
            </div>
        </x-modal>

        {{-- Leaving: the person's own step, at the foot of the page like Delete Account on the profile. The only
             Owner is told at once why not (the server refuses it too). --}}
        <div class="card p-5 sm:p-6" dusk="leave-store-card">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="min-w-0">
                    <h2 class="text-subheading">Leave {{ $store->name }}</h2>
                    <p class="mt-1 max-w-2xl text-sm text-gray-500 dark:text-gray-400">
                        You lose access at once.
                    </p>
                </div>
                <button type="button" class="btn-secondary" dusk="leave-store"
                    x-on:click="isSoleOwner() ? window.toast('You are the only Owner of ' + storeName + '. Make someone else an Owner before you leave.') : $dispatch('open-modal', 'leave-store')">
                    Leave store
                </button>
            </div>
        </div>
    </div>
</x-app-layout>
