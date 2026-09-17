{{-- The current store's team (docs/STORE-ORGANIZATION-SPEC.md §8). Buttons are gated by the
     route permissions here; what may be done to each ROW comes from the server (can_manage). --}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-white leading-tight">{{ __('Members') }}</h2>
    </x-slot>

    <div x-data="membersPage({{ Js::from(['storeName' => $store->name]) }})"
         class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        {{-- Page intro + actions --}}
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div>
                <h1 class="text-lg font-semibold text-gray-900 dark:text-white">Team of {{ $store->name }}</h1>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Everyone who can work in this store, and the invitations still waiting for an answer.
                </p>
            </div>
            <div class="flex items-center gap-3">
                <button type="button" class="btn-secondary-add" dusk="leave-store"
                    x-on:click="isSoleOwner() ? window.toast('You are the only Owner of ' + storeName + '. Make someone else an Owner before you leave.') : $dispatch('open-modal', 'leave-store')">
                    Leave store
                </button>
                @can('member-invite')
                <x-crud.add-button label="Invite member" @click="openInvite()" dusk="invite-member" />
                @endcan
            </div>
        </div>

        {{-- Tabs --}}
        <div class="border-b border-gray-200 dark:border-gray-700" role="tablist">
            <nav class="-mb-px flex gap-6">
                <button type="button" role="tab" dusk="tab-members"
                    x-on:click="tab = 'members'" :aria-selected="tab === 'members'"
                    :class="tab === 'members' ? 'border-blue-600 text-blue-600 dark:text-blue-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                    class="whitespace-nowrap border-b-2 px-1 pb-3 text-sm font-medium">
                    Members <span class="ml-1 badge-neutral" x-text="members.length"></span>
                </button>
                <button type="button" role="tab" dusk="tab-invitations"
                    x-on:click="tab = 'invitations'" :aria-selected="tab === 'invitations'"
                    :class="tab === 'invitations' ? 'border-blue-600 text-blue-600 dark:text-blue-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                    class="whitespace-nowrap border-b-2 px-1 pb-3 text-sm font-medium">
                    Pending invitations <span class="ml-1 badge-neutral" x-text="invitations.length"></span>
                </button>
            </nav>
        </div>

        {{-- Members --}}
        <div x-show="tab === 'members'" class="card" dusk="members-table">
            <div class="card-header">
                <h3 class="text-subheading">Members</h3>
                <x-crud.search-input placeholder="Search by name or email" />
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
                    <tbody class="table-tbody">
                        <template x-if="loading">
                            <tr><td colspan="4" class="px-5 py-6 text-center text-muted-soft">Loading...</td></tr>
                        </template>
                        <template x-if="!loading && filteredMembers().length === 0">
                            <tr><td colspan="4" class="px-5 py-6 text-center text-muted-soft">No members match your search.</td></tr>
                        </template>

                        <template x-for="member in filteredMembers()" :key="member.id">
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50" :dusk="'member-row-' + member.id">
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full bg-blue-50 text-sm font-semibold text-blue-700 dark:bg-blue-900/30 dark:text-blue-300"
                                             x-text="initials(member.name)" aria-hidden="true"></div>
                                        <div class="min-w-0">
                                            <p class="font-medium text-gray-800 dark:text-white">
                                                <span x-text="member.name"></span>
                                                <span x-show="member.is_you" class="ml-1 badge-neutral">You</span>
                                            </p>
                                            <p class="text-xs text-gray-400 truncate" x-text="member.email"></p>
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
                                            x-on:click="openChangeRole(member)" :dusk="'change-role-' + member.id">Change role</button>
                                        @endcan
                                        @can('member-remove')
                                        <button type="button" class="btn-row-danger" x-show="member.can_manage"
                                            x-on:click="confirmRemove(member)" :dusk="'remove-member-' + member.id">Remove</button>
                                        @endcan
                                        <span x-show="!member.can_manage" class="text-xs text-gray-400">—</span>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Pending invitations --}}
        <div x-show="tab === 'invitations'" x-cloak class="card" dusk="invitations-table">
            <div class="card-header">
                <h3 class="text-subheading">Pending invitations</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400">Links are valid for 7 days. Resending sends a new link.</p>
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
                        <template x-if="!loading && invitations.length === 0">
                            <tr>
                                <td colspan="5" class="px-5 py-8 text-center">
                                    <p class="text-sm text-gray-500 dark:text-gray-400">No invitations are waiting.</p>
                                    @can('member-invite')
                                    <button type="button" class="mt-2 text-sm font-medium text-blue-600 hover:underline dark:text-blue-400" x-on:click="openInvite()" dusk="invite-member-empty">Invite someone</button>
                                    @endcan
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
                                            :disabled="busyInvitationId !== null" :dusk="'resend-invitation-' + invitation.id">Resend</button>
                                        <button type="button" class="btn-row-danger" x-on:click="confirmRevoke(invitation)"
                                            :dusk="'revoke-invitation-' + invitation.id">Revoke</button>
                                        @endcan
                                    </div>
                                    <span x-show="!invitation.can_manage" class="block text-right text-xs text-gray-400">—</span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Invite --}}
        <x-modal name="invite-member" :show="false" maxWidth="md" focusable>
            <form @submit.prevent="sendInvite()" class="p-6 space-y-4" dusk="invite-form">
                <div>
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Invite a member</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        They'll get an email with a link to join {{ $store->name }}. The link works for 7 days.
                    </p>
                </div>

                <x-crud.form-field label="Email" field="email" :required="true">
                    <x-text-input type="email" x-model="inviteForm.email" dusk="invite-email" class="block w-full" autocomplete="off" placeholder="name@example.com" />
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
            <form @submit.prevent="saveRole()" class="p-6 space-y-4" dusk="change-role-form">
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
                    They lose access to {{ $store->name }} straight away. Their account is not deleted, and everything they
                    added here — media, screens, playlists — stays with the store.
                </p>

                <x-crud.password-confirm id="remove-member-password" model="removePassword" error="removePasswordError" />

                <div class="mt-6 flex justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'remove-member')">Cancel</x-secondary-button>
                    <x-danger-button x-bind:disabled="removing" dusk="remove-member-confirm">Remove member</x-danger-button>
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
                <div class="mt-6 flex justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'revoke-invitation')">Cancel</x-secondary-button>
                    <x-danger-button x-on:click="revoke()" x-bind:disabled="revoking" dusk="revoke-invitation-confirm">Revoke invitation</x-danger-button>
                </div>
            </div>
        </x-modal>

        {{-- Leave store --}}
        <x-modal name="leave-store" :show="false" maxWidth="md" focusable>
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Leave {{ $store->name }}?</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    You lose access to this store straight away. To come back, someone in the store has to invite you again.
                </p>
                <div class="mt-6 flex justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'leave-store')">Cancel</x-secondary-button>
                    <x-danger-button x-on:click="leave()" x-bind:disabled="leaving" dusk="leave-store-confirm">Leave store</x-danger-button>
                </div>
            </div>
        </x-modal>
    </div>
</x-app-layout>
