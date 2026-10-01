{{-- Roles, from where the person stands (docs/STORE-ORGANIZATION-SPEC.md §2–4 — owner's rules, 2026-09-17).
     On the platform (super admins): Super-Admin, the store roles (offered in every store — the Owner role among
     them), the platform roles, and in a card of their own the custom roles stores made for themselves. Inside a
     store ($store): the store roles to read, and the store's own custom roles. What each row allows comes from
     the server (can_edit, can_delete); the form's checklist from /roles/assignable. --}}
<x-app-layout>
    <x-slot name="header">
        <h1 class="page-title">{{ __('Roles') }}</h1>
    </x-slot>

    <div x-data="rolesPage({{ Js::from([
            'isPlatform' => $store === null,
            'storeName' => $store?->name,
         ]) }})"
         class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        {{-- One heading for the list, a line under it, and Create on the header's line (the list has no search). --}}
        <div class="card" dusk="roles-table">
            <div class="card-header">
                <div class="min-w-0">
                    <h2 class="text-subheading">{{ $store ? 'Roles in '.$store->name : 'All roles' }}</h2>
                    <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">
                        @if ($store)
                            Organization roles come from the platform. Custom roles are {{ $store->name }}'s own.
                        @else
                            Organization roles are offered in every organization. Platform roles are for your team.
                        @endif
                    </p>
                </div>
                @can('role-store')
                    <x-crud.add-button :label="$store ? 'Create Custom Role' : 'Create Role'" @click="openForm()" x-bind:disabled="openingForm" dusk="create-role" />
                @endcan
            </div>
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead>
                        <tr class="table-head-row">
                            <th class="px-5 py-3 text-left font-semibold">Role</th>
                            <th class="px-5 py-3 text-left font-semibold">Type</th>
                            <th class="px-5 py-3 text-left font-semibold">Permissions</th>
                            <th class="px-5 py-3 text-left font-semibold">{{ $store ? 'Members' : 'Holders' }}</th>
                            <th class="px-5 py-3 text-right font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="table-tbody" x-bind:aria-busy="loading ? 'true' : 'false'">
                        <template x-if="loading">
                            <tr><td colspan="5" class="px-5 py-6 text-center text-muted-soft">
                                <span class="inline-flex items-center gap-2"><x-spinner class="text-blue-600 dark:text-blue-400" /> Loading...</span>
                            </td></tr>
                        </template>

                        <template x-if="!loading && mainRoles().length === 0">
                            <tr><td colspan="5" class="px-5 py-6 text-center text-muted-soft">No roles yet.</td></tr>
                        </template>

                        <template x-for="role in mainRoles()" :key="role.id">
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50" x-bind:dusk="'role-row-' + role.id">
                                <td class="cell-prose px-5 py-4">
                                    {{-- The Owner mark is amber, as an Owner is on the Members and Users pages. --}}
                                    <p class="flex flex-wrap items-center gap-x-2 gap-y-1 font-medium text-gray-800 dark:text-white">
                                        <span x-text="role.name" x-bind:dusk="'role-name-' + role.id"></span>
                                        <span x-show="role.is_owner_role" class="badge-warning" dusk="owner-role-badge">Owner</span>
                                    </p>
                                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400" x-text="role.description"></p>
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap">
                                    <span x-bind:class="kindBadgeClass(role)" x-text="kindLabel(role)"></span>
                                </td>
                                <td class="px-5 py-4 text-gray-600 dark:text-gray-300">
                                    <template x-if="role.kind === 'super_admin'">
                                        <span class="whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">Every permission, always</span>
                                    </template>
                                    <template x-if="role.kind !== 'super_admin'">
                                        <span class="badge-neutral" x-text="role.permissions.length"></span>
                                    </template>
                                </td>
                                <td class="px-5 py-4 text-gray-600 dark:text-gray-300" x-text="role.holders_count"></td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center justify-end gap-2">
                                        <button type="button" class="btn-row-neutral" @click="view(role)" x-bind:aria-label="'View ' + role.name" x-bind:dusk="'view-role-' + role.id">View</button>
                                        <button type="button" class="btn-row-neutral" x-show="role.can_edit" @click="openForm(role)" x-bind:aria-label="'Edit ' + role.name"
                                            x-bind:disabled="openingForm" x-bind:dusk="'edit-role-' + role.id">Edit</button>
                                        <button type="button" class="btn-row-danger" x-show="role.can_delete" @click="confirmDelete(role)" x-bind:aria-label="'Delete ' + role.name"
                                            x-bind:dusk="'delete-role-' + role.id">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        @unless ($store)
        {{-- The custom roles stores made for themselves — each store's own, which the super admin looks after too. --}}
        <div class="card" dusk="store-custom-roles" x-show="!loading && storeCustomRoles().length > 0" x-cloak>
            <div class="card-header">
                <div>
                    <h2 class="text-subheading">Custom roles made in organizations</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Each belongs to its own organization and is offered there alone.</p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead>
                        <tr class="table-head-row">
                            <th class="px-5 py-3 text-left font-semibold">Role</th>
                            <th class="px-5 py-3 text-left font-semibold">Organization</th>
                            <th class="px-5 py-3 text-left font-semibold">Permissions</th>
                            <th class="px-5 py-3 text-left font-semibold">Members</th>
                            <th class="px-5 py-3 text-right font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="table-tbody">
                        <template x-for="role in storeCustomRoles()" :key="role.id">
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50" x-bind:dusk="'role-row-' + role.id">
                                <td class="cell-prose px-5 py-4">
                                    <p class="font-medium text-gray-800 dark:text-white" x-text="role.name" x-bind:dusk="'role-name-' + role.id"></p>
                                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400" x-text="role.description"></p>
                                </td>
                                <td class="px-5 py-4 text-gray-600 dark:text-gray-300 whitespace-nowrap" x-text="role.store_name"></td>
                                <td class="px-5 py-4"><span class="badge-neutral" x-text="role.permissions.length"></span></td>
                                <td class="px-5 py-4 text-gray-600 dark:text-gray-300" x-text="role.holders_count"></td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center justify-end gap-2">
                                        <button type="button" class="btn-row-neutral" @click="view(role)" x-bind:aria-label="'View ' + role.name" x-bind:dusk="'view-role-' + role.id">View</button>
                                        <button type="button" class="btn-row-neutral" x-show="role.can_edit" @click="openForm(role)" x-bind:aria-label="'Edit ' + role.name"
                                            x-bind:disabled="openingForm" x-bind:dusk="'edit-role-' + role.id">Edit</button>
                                        <button type="button" class="btn-row-danger" x-show="role.can_delete" @click="confirmDelete(role)" x-bind:aria-label="'Delete ' + role.name"
                                            x-bind:dusk="'delete-role-' + role.id">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
        @endunless

        {{-- Create / edit --}}
        <x-modal name="role-form" :show="false" maxWidth="2xl" focusable persistent>
            <form @submit.prevent="save()" novalidate class="p-6 space-y-5" dusk="role-form">
                <div>
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100"
                        x-text="editingRole ? 'Edit ' + editingRole.name : {{ Js::from($store ? 'Create Custom Role' : 'Create Role') }}"></h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400" x-text="formHint()"></p>
                </div>

                <x-crud.form-field label="Role name" field="name" :required="true">
                    <x-text-input x-model="form.name" dusk="role-name" maxlength="255" autocomplete="off" placeholder="e.g. Shift supervisor" />
                </x-crud.form-field>

                @unless ($store)
                {{-- What a new role is for. Fixed once it exists: its holders are already on one side or the other. Two
                     radios, named as one question by their legend. --}}
                <fieldset x-show="!editingRole" class="min-w-0">
                    <legend class="form-label">What is this role for? <span class="text-red-600 dark:text-red-400" aria-hidden="true">*</span></legend>
                    <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <label class="role-choice">
                            <input type="radio" name="role-type" value="store" class="mt-0.5 accent-blue-600" x-model="form.type" @change="changeType('store')" dusk="role-type-store">
                            <span>
                                <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">Organization role</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">Offered in every organization. Its permissions reach the member's own organization.</span>
                            </span>
                        </label>
                        <label class="role-choice">
                            <input type="radio" name="role-type" value="platform" class="mt-0.5 accent-blue-600" x-model="form.type" @change="changeType('platform')" dusk="role-type-platform">
                            <span>
                                <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">Platform role</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">For your team above the organizations. Its permissions reach every organization.</span>
                            </span>
                        </label>
                    </div>
                    <template x-if="formErrors.type">
                        <p class="form-error" role="alert" x-text="formErrors.type[0]"></p>
                    </template>
                </fieldset>
                @endunless

                {{-- The checklist is a group of checkboxes, named by its legend. --}}
                <fieldset class="min-w-0">
                    <legend class="form-label">Permissions <span class="text-red-600 dark:text-red-400" aria-hidden="true">*</span></legend>
                    <template x-if="loadingAssignable">
                        <p class="mt-2 inline-flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                            <x-spinner class="text-blue-600 dark:text-blue-400" /> Loading permissions...
                        </p>
                    </template>
                    <div class="mt-2 max-h-96 overflow-y-auto grid grid-cols-1 md:grid-cols-2 gap-3 pr-1" x-show="!loadingAssignable">
                        <template x-for="group in grouped(assignable)" :key="group.key">
                            <div class="rounded-md border border-gray-200 dark:border-gray-600 overflow-hidden" x-bind:dusk="'permission-group-' + group.key">
                                <label class="flex items-center gap-2 bg-gray-50 dark:bg-gray-800 px-3 py-2 border-b border-gray-200 dark:border-gray-600 cursor-pointer">
                                    <input type="checkbox" class="form-checkbox"
                                        :checked="groupState(group.permissions) === 'all'"
                                        x-effect="$el.indeterminate = groupState(group.permissions) === 'some'"
                                        @change="toggleGroup(group.permissions)">
                                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-200" x-text="group.title"></span>
                                    <span class="ml-auto text-[11px] font-medium text-blue-700 dark:text-blue-300" x-show="groupHint(group)" x-text="groupHint(group)"></span>
                                </label>
                                <div class="p-2 space-y-0.5">
                                    <template x-for="permission in group.permissions" :key="permission.id">
                                        <label class="flex items-center gap-2 rounded px-2 py-1.5 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800">
                                            <input type="checkbox" class="form-checkbox" :checked="isChecked(permission)" @change="toggle(permission)"
                                                :dusk="'permission-' + permission.name">
                                            <span class="text-sm text-gray-700 dark:text-gray-300" x-text="permission.label ?? permission.name"></span>
                                        </label>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                    <template x-if="formErrors.permissions">
                        <p class="form-error" role="alert" x-text="formErrors.permissions[0]"></p>
                    </template>
                    <p x-show="!loadingAssignable && !openingForm && assignable.length === 0" class="mt-2 text-sm text-gray-500 dark:text-gray-400">You hold no permissions you could give to a role.</p>
                </fieldset>

                {{-- Not while the checklist is still coming: a save then would send the ticks of the list before it. --}}
                <x-crud.form-actions cancelAction="$dispatch('close-modal', 'role-form')" savingVar="saving || loadingAssignable" saveLabel="Save Role" dusk="role-save" />
            </form>
        </x-modal>

        {{-- View permissions --}}
        <x-modal name="role-permissions" :show="false" maxWidth="lg" focusable>
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100" x-text="viewedRole?.name"></h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400" x-text="viewedRole ? kindLabel(viewedRole) + (viewedRole.store_name ? ' · ' + viewedRole.store_name : '') : ''"></p>
                <p x-show="viewedRole?.kind === 'super_admin'" class="alert-info mt-3">
                    A super admin passes every permission check, whatever this list says — a permission added later included.
                </p>
                <div class="mt-4 max-h-96 overflow-y-auto space-y-4">
                    <template x-for="group in grouped(viewedRole?.permissions ?? [])" :key="group.key">
                        <div>
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" x-text="group.title"></h3>
                            <ul class="mt-1.5 flex flex-wrap gap-1.5">
                                <template x-for="permission in group.permissions" :key="permission.id">
                                    <li class="badge-neutral" x-text="permission.label ?? permission.name"></li>
                                </template>
                            </ul>
                        </div>
                    </template>
                </div>
                <div class="mt-6 flex justify-end">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'role-permissions')">Close</x-secondary-button>
                </div>
            </div>
        </x-modal>

        {{-- Delete --}}
        <x-modal name="confirm-role-deletion" :show="false" maxWidth="md" focusable>
            <form @submit.prevent="destroy()" class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Delete <span x-text="selectedRole?.name"></span>?</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    Nobody holds this role, so no one loses access.
                </p>
                <p x-show="selectedRole?.invitations_count > 0" x-cloak class="mt-2 text-sm text-amber-700 dark:text-amber-400"
                   x-text="selectedRole?.invitations_count === 1
                       ? 'Its pending invitation will be revoked too.'
                       : 'Its ' + selectedRole?.invitations_count + ' pending invitations will be revoked too.'"></p>

                <x-crud.password-confirm id="delete-role-password" />

                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'confirm-role-deletion')">Cancel</x-secondary-button>
                    <x-danger-button x-bind:disabled="deleting" dusk="confirm-role-deletion-confirm">
                        <x-spinner x-show="deleting" x-cloak />
                        Delete Role
                    </x-danger-button>
                </div>
            </form>
        </x-modal>
    </div>
</x-app-layout>
