<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-white leading-tight">{{ __('Roles') }}</h2>
    </x-slot>

    <div x-data="rolesTable({
        isSuperAdmin: @json(auth()->user()->isSuperAdmin()),
        currentUserId: {{ auth()->id() }}
    })" class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="flex items-center justify-between mb-6">
            @can('role-store')
            <x-crud.add-button label="Add Role" @click="openFormModal()" dusk="add-role" />
            @endcan
        </div>

        <x-crud.table-wrapper title="All Roles" searchPlaceholder="Search Roles" :columns="2">
            <x-slot name="head">
                <th class="px-5 py-3 text-left font-semibold">Name</th>
                <th class="px-5 py-3 text-right font-semibold">Actions</th>
            </x-slot>

            <x-slot name="body">
                <x-crud.table-empty :columns="2" itemsVar="items" message="No roles found." />

                <template x-for="item in items" :key="item.id">
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                        <td class="px-5 py-4">
                            <p class="font-medium text-gray-800 dark:text-white" x-text="item.name"></p>
                        </td>
                        <td class="px-5 py-4">
                            <x-crud.table-actions editClick="openFormModal(item)" deleteClick="confirmDelete(item)" editCan="role-update" deleteCan="role-destroy" rowShow="canManageRole(item)" />
                        </td>
                    </tr>
                </template>
            </x-slot>

            <x-slot name="footer">
                <x-crud.pagination itemsVar="items" />
            </x-slot>
        </x-crud.table-wrapper>

        {{-- Delete Confirmation --}}
        <x-crud.confirm-delete-modal
            name="confirm-role-deletion"
            entity="Role"
            nameExpression="selectedItem?.name"
            deleteAction="deleteItem()"
            disabledVar="deleting" />

        {{-- Add/Edit Role Modal with Grouped Permissions --}}
        <x-modal name="role-form-modal" :show="false" maxWidth="2xl">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100"
                    x-text="editingItem ? 'Edit Role' : 'Add Role'"></h2>
                <form @submit.prevent="saveItem" dusk="role-form" class="mt-4 space-y-4">
                    <x-crud.form-field label="Role Name" field="name" :required="true">
                        <x-text-input x-model="form.name" dusk="role-name" class="block w-full" autocomplete="off" />
                    </x-crud.form-field>

                    @if (auth()->user()->isSuperAdmin())
                    {{-- Super-admin-only concepts; store users never see these options --}}
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" x-model="form.is_global" dusk="role-global" class="form-checkbox">
                            <span class="text-sm text-gray-700 dark:text-gray-300">
                                Global role — assigned without a store, applies system-wide
                            </span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer" x-show="!form.is_global">
                            <input type="checkbox" x-model="form.is_signup_default" dusk="role-signup-default" class="form-checkbox">
                            <span class="text-sm text-gray-700 dark:text-gray-300">
                                Default role for new sign-ups — public registration assigns this role (only one role can hold this)
                            </span>
                        </label>
                    </div>
                    @endif

                    {{-- Permissions grouped by category (unique to roles) --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Permissions <span class="text-red-500">*</span></label>
                        <div class="max-h-100 overflow-y-auto space-y-5">
                            <template x-if="permissionsList.length === 0">
                                <p class="text-sm text-gray-500 dark:text-gray-400">No permissions available.</p>
                            </template>

                            <template x-for="(perms, groupKey) in groupedPermissions" :key="groupKey">
                                <div class="border border-gray-200 dark:border-gray-600 rounded-xs overflow-hidden">
                                    <label class="bg-gray-100 dark:bg-gray-800 px-3 py-2 border-b border-gray-200 dark:border-gray-600 flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" class="form-checkbox"
                                            :checked="isGroupFullySelected(perms)"
                                            x-effect="$el.indeterminate = isGroupPartiallySelected(perms)"
                                            @change="toggleGroupSelection(perms)">
                                        <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300" x-text="getGroupDisplayName(groupKey)"></h4>
                                    </label>
                                    <div class="p-2 space-y-1">
                                        <template x-for="perm in perms" :key="perm.id">
                                            <label class="flex items-center gap-2 py-1.5 px-2 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-800 rounded-md">
                                                <input type="checkbox" x-model="form.permissions" :value="perm.id"
                                                    class="form-checkbox">
                                                <span class="text-sm text-gray-700 dark:text-gray-300">
                                                    <span x-text="perm.display_name ?? perm.name"></span>
                                                    <span x-show="perm.label" class="text-xs text-gray-400 dark:text-gray-500" x-text="'(' + perm.name + ')'"></span>
                                                </span>
                                            </label>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <template x-if="formErrors.permissions">
                            <p class="mt-1 text-xs text-red-500" x-text="Array.isArray(formErrors.permissions) ? formErrors.permissions.join(', ') : formErrors.permissions"></p>
                        </template>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Select at least one permission.</p>
                    </div>

                    <x-crud.form-actions savingVar="saving" dusk="role-save" />
                </form>
            </div>
        </x-modal>
    </div>
</x-app-layout>