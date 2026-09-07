<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-white leading-tight">{{ __('Users') }}</h2>
    </x-slot>

    <div x-data="usersTable({
        isSuperAdmin: @json(auth()->user()->isSuperAdmin()),
        isGlobalUser: @json(auth()->user()->globalRole() !== null),
        currentStoreId: {{ session('current_store_id') ?? 'null' }}
    })" class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        {{-- Create actions --}}
        <div class="flex items-center gap-3 mb-6">
            @can('user-store')
            <x-crud.add-button label="Add User" @click="openFormModal()" dusk="add-user" />
            @endcan

            {{-- Onboarding needs all three capabilities: create user + create store + assign --}}
            @if (auth()->user()->can('user-store') && auth()->user()->can('store-store') && auth()->user()->can('user-store-assign'))
            <button @click="openOnboardModal()" dusk="onboard-button" class="btn-secondary">
                Onboard Store Owner
            </button>
            @endif
        </div>

        {{-- Users Data Table --}}
        <x-crud.table-wrapper title="All Users" searchPlaceholder="Search Users" :columns="4">
            <x-slot name="head">
                <th class="px-5 py-3 text-left font-semibold">Name</th>
                <th class="px-5 py-3 text-left font-semibold">Phone</th>
                <th class="px-5 py-3 text-left font-semibold">Roles</th>
                <th class="px-5 py-3 text-right font-semibold">Actions</th>
            </x-slot>

            <x-slot name="body">
                <x-crud.table-empty :columns="4" itemsVar="items" message="No users found." />

                <template x-for="item in items" :key="item.id">
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-3">
                                <div>
                                    <p class="font-medium text-gray-800 dark:text-white" x-text="item.first_name + ' ' + item.last_name"></p>
                                    <p class="text-xs text-gray-400" x-text="item.email"></p>
                                </div>
                            </div>
                        </td>
                        <td class="px-5 py-4 text-gray-600 dark:text-gray-300" x-text="item.phone || '—'"></td>
                        <td class="px-5 py-4">
                            <div class="flex flex-wrap items-center gap-1">
                                <template x-for="role in item.roles" :key="role">
                                    <span class="badge-info" x-text="role"></span>
                                </template>
                                <span x-show="!item.roles || item.roles.length === 0" class="text-gray-400">—</span>
                            </div>
                        </td>
                        <td class="px-5 py-4">
                            <div class="flex items-center justify-end gap-2">
                                @can('user-update')
                                <button @click="openFormModal(item)" x-bind:dusk="'edit-user-' + item.id" class="btn-row-neutral">Edit</button>
                                @endcan

                                @can('user-destroy')
                                <button @click="confirmDelete(item)"
                                    x-show="item.id !== {{ auth()->id() }}"
                                    x-bind:dusk="'delete-user-' + item.id"
                                    class="btn-row-danger">Delete</button>
                                @endcan

                                @can('user-store-view')
                                <button @click="openStoreAssignmentModal(item)"
                                    x-bind:disabled="openingAssignments"
                                    x-bind:dusk="'stores-user-' + item.id"
                                    class="btn-row-success">
                                    Stores
                                </button>
                                @endcan

                                <button @click="impersonate(item)"
                                    x-show="isSuperAdmin && !item.is_super_admin"
                                    x-bind:disabled="impersonating"
                                    class="btn-row-neutral">Log in as</button>
                            </div>
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
            name="confirm-user-deletion"
            entity="User"
            nameExpression="(selectedItem?.first_name ?? '') + ' ' + (selectedItem?.last_name ?? '')"
            deleteAction="deleteItem()"
            disabledVar="deleting" />

        {{-- Add/Edit User Form Modal --}}
        <x-modal name="user-form-modal" :show="false" maxWidth="md">
            <div class="p-4">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100"
                    x-text="editingItem ? 'Edit User' : 'Add User'"></h2>
                <form @submit.prevent="saveItem" dusk="user-form" class="mt-4 space-y-4">
                    <x-crud.form-field label="First Name" field="first_name" :required="true">
                        <x-text-input x-model="form.first_name" dusk="user-first-name" class="block w-full" autocomplete="off" />
                    </x-crud.form-field>
                    <x-crud.form-field label="Last Name" field="last_name" :required="true">
                        <x-text-input x-model="form.last_name" dusk="user-last-name" class="block w-full" autocomplete="off" />
                    </x-crud.form-field>
                    <x-crud.form-field label="Phone (10 digits)" field="phone" :required="true">
                        <x-text-input type="tel" x-model="form.phone" dusk="user-phone"
                            @input="restrictPhoneInput($event)" @keydown="restrictPhoneInput($event)"
                            class="block w-full" placeholder="1234567890" autocomplete="off" />
                    </x-crud.form-field>
                    <x-crud.form-field label="Email" field="email" :required="true">
                        <x-text-input type="email" x-model="form.email" dusk="user-email" class="block w-full" autocomplete="off"
                            @input="restrictEmailInput($event)" @keydown="restrictEmailInput($event)" />
                    </x-crud.form-field>
                    <x-crud.form-field label="Password" field="password" :required="true">
                        <x-text-input type="password" x-model="form.password" dusk="user-password" class="block w-full" autocomplete="new-password" />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" x-show="!editingItem">Required for new user.</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" x-show="editingItem">Leave blank to keep current password.</p>
                    </x-crud.form-field>
                    <x-crud.form-field label="Confirm Password" field="password_confirmation" :required="true">
                        <x-text-input type="password" x-model="form.password_confirmation" dusk="user-password-confirm" class="block w-full" autocomplete="new-password" />
                    </x-crud.form-field>

                    <x-crud.form-actions savingVar="saving" dusk="user-save" />
                </form>
            </div>
        </x-modal>

        {{-- Onboard Store Owner Modal: owner + their store in one step --}}
        <x-modal name="onboard-modal" :show="false" maxWidth="2xl">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Onboard Store Owner</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Creates the owner's account and their store together, and assigns the selected role.
                </p>

                <form @submit.prevent="saveOnboard" dusk="onboard-form" class="mt-5 space-y-6">
                    {{-- Section 1: the owner --}}
                    <div>
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-3">Owner Details</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <x-crud.form-field label="First Name" field="first_name" :required="true">
                                <x-text-input x-model="onboardForm.first_name" dusk="onboard-first-name" class="block w-full" autocomplete="off" />
                            </x-crud.form-field>
                            <x-crud.form-field label="Last Name" field="last_name" :required="true">
                                <x-text-input x-model="onboardForm.last_name" dusk="onboard-last-name" class="block w-full" autocomplete="off" />
                            </x-crud.form-field>
                            <x-crud.form-field label="Phone (10 digits)" field="phone" :required="true">
                                <x-text-input type="tel" x-model="onboardForm.phone" dusk="onboard-phone" class="block w-full" placeholder="1234567890" autocomplete="off" />
                            </x-crud.form-field>
                            <x-crud.form-field label="Email" field="email" :required="true">
                                <x-text-input type="email" x-model="onboardForm.email" dusk="onboard-email" class="block w-full" autocomplete="off" />
                            </x-crud.form-field>
                            <x-crud.form-field label="Password" field="password" :required="true">
                                <x-text-input type="password" x-model="onboardForm.password" dusk="onboard-password" class="block w-full" autocomplete="new-password" />
                            </x-crud.form-field>
                            <x-crud.form-field label="Confirm Password" field="password_confirmation" :required="true">
                                <x-text-input type="password" x-model="onboardForm.password_confirmation" dusk="onboard-password-confirm" class="block w-full" autocomplete="new-password" />
                            </x-crud.form-field>
                        </div>
                    </div>

                    {{-- Section 2: their store --}}
                    <div class="border-t border-gray-200 dark:border-gray-700 pt-5">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-3">Store Details</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <x-crud.form-field label="Store Name" field="store_name" :required="true">
                                <x-text-input x-model="onboardForm.store_name" dusk="onboard-store-name" class="block w-full" autocomplete="off" />
                            </x-crud.form-field>
                            <x-crud.form-field label="Street" field="street" :required="true">
                                <x-text-input x-model="onboardForm.street" dusk="onboard-street" class="block w-full" autocomplete="off" />
                            </x-crud.form-field>
                            <x-crud.form-field label="Suite/Unit" field="suite">
                                <x-text-input x-model="onboardForm.suite" class="block w-full" autocomplete="off" />
                            </x-crud.form-field>
                            <x-crud.form-field label="City" field="city" :required="true">
                                <x-text-input x-model="onboardForm.city" dusk="onboard-city" class="block w-full" autocomplete="off" />
                            </x-crud.form-field>
                            <x-crud.form-field label="State" field="state" :required="true">
                                <select x-model="onboardForm.state" dusk="onboard-state" class="form-select block w-full">
                                    <option value="">Select State</option>
                                    @foreach ($states as $code => $name)
                                        <option value="{{ $code }}">{{ $name }} ({{ $code }})</option>
                                    @endforeach
                                </select>
                            </x-crud.form-field>
                            <x-crud.form-field label="Zip Code" field="zip_code" :required="true">
                                <x-text-input type="text" inputmode="numeric" x-model="onboardForm.zip_code" dusk="onboard-zip" class="block w-full" autocomplete="off" />
                            </x-crud.form-field>
                            <x-crud.form-field label="Country" field="country" :required="true">
                                <x-text-input x-model="onboardForm.country" dusk="onboard-country" class="block w-full" autocomplete="off" />
                            </x-crud.form-field>
                        </div>
                    </div>

                    {{-- Section 3: role (pre-selected to the owner role) --}}
                    <div class="border-t border-gray-200 dark:border-gray-700 pt-5">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <x-crud.form-field label="Role" field="role_id" :required="true">
                                <select x-model="onboardForm.role_id" dusk="onboard-role" class="form-select block w-full">
                                    <option value="">Select Role</option>
                                    <template x-for="role in onboardRoles" :key="role.id">
                                        <option :value="role.id" x-text="role.name"></option>
                                    </template>
                                </select>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Pre-selected to the store-owner role.</p>
                            </x-crud.form-field>
                        </div>
                    </div>

                    <x-crud.form-actions savingVar="onboarding" dusk="onboard-save" cancelAction="$dispatch('close-modal', 'onboard-modal')" saveLabel="Onboard" />
                </form>
            </div>
        </x-modal>

        {{-- Manage Store Assignments Modal (unique to users) --}}
        <x-modal name="user-stores-modal" :show="false" maxWidth="2xl">
            <div class="p-4">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                    Manage Stores for <span x-text="(selectedUserForStores?.first_name ?? '') + ' ' + (selectedUserForStores?.last_name ?? '')"></span>
                </h2>

                {{-- Backend error --}}
                <div x-show="assignErrors.general" x-text="assignErrors.general" class="mb-4 text-sm text-red-600 dark:text-red-400"></div>

                {{-- Global users stay global: no store assignment for them --}}
                <div x-show="selectedUserForStores?.is_global_user" x-cloak
                    class="mb-8 rounded-xs border border-blue-100 dark:border-blue-900/50 bg-blue-50 dark:bg-blue-900/20 px-4 py-3 text-sm text-blue-700 dark:text-blue-300">
                    This user holds a global role and works across every store — store assignments don't apply.
                    Remove the global role below first if you want to make them a store user.
                </div>

                {{-- Assign to New Store --}}
                <div class="mb-8" x-show="!selectedUserForStores?.is_global_user">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">Assign to Store</h3>
                    <div class="flex flex-col sm:flex-row gap-3">
                        <div class="flex-1 min-w-0" x-show="isGlobalUser && !isGlobalRoleSelected()" x-cloak>
                            <select x-model="assignForm.store_id" dusk="assign-store" class="form-select">
                                <option value="">Select Store</option>
                                <template x-for="store in availableStores" :key="store.id">
                                    <option :value="store.id" x-text="store.name"></option>
                                </template>
                            </select>
                        </div>
                        <div class="flex-1 min-w-0 flex items-center text-sm text-gray-500 dark:text-gray-400" x-show="isGlobalRoleSelected()" x-cloak>
                            This role is global — no store needed.
                        </div>
                        <div class="flex-1 min-w-0 w-full">
                            <select x-model="assignForm.role_id" dusk="assign-role" class="form-select">
                                <option value="">Select Role</option>
                                <template x-for="role in availableRoles" :key="role.id">
                                    <option :value="role.id" x-text="role.name"></option>
                                </template>
                            </select>
                        </div>
                        @can('user-store-assign')
                        <button @click="assignStore()" x-bind:disabled="assigning" dusk="assign-button" class="btn-primary">
                            Assign
                        </button>
                        @endcan
                    </div>
                    <div class="mt-2 text-xs text-red-500">
                        <p x-show="assignErrors.store_id" x-text="assignErrors.store_id"></p>
                        <p x-show="!assignErrors.store_id && assignErrors.role_id" x-text="assignErrors.role_id"></p>
                    </div>
                </div>

                {{-- Current Assignments Table --}}
                <div>
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">Current Assignments</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-100 dark:border-gray-700 text-gray-500 dark:text-gray-400 bg-gray-50/50 dark:bg-gray-800/80">
                                    <th class="px-4 sm:px-5 py-3 text-left font-semibold">Store</th>
                                    <th class="px-4 sm:px-5 py-3 text-left font-semibold">Role</th>
                                    <th class="px-4 sm:px-5 py-3 text-right font-semibold">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <template x-for="ass in assignments" :key="ass.store_id">
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                        <td class="px-4 sm:px-5 py-3 sm:py-4">
                                            <span class="text-gray-800 dark:text-gray-200" x-text="ass.store_name"></span>
                                        </td>
                                        <td class="px-4 sm:px-5 py-3 sm:py-4">
                                            <span class="text-gray-800 dark:text-gray-200" x-text="ass.role_name"></span>
                                        </td>
                                        <td class="px-4 sm:px-5 py-3 sm:py-4 text-right">
                                            @can('user-store-unassign')
                                            {{-- Per-store: a store user may only remove an assignment for
                                                 the store they are CURRENTLY in (mirrors the backend guard);
                                                 global users manage every store, incl. the store-0 sentinel. --}}
                                            <button x-show="isGlobalUser || ass.store_id === currentStoreId"
                                                @click="confirmRemoveAssignment(ass.store_id, ass.store_name)"
                                                class="btn-row-danger whitespace-nowrap">Remove</button>
                                            @endcan
                                        </td>
                                    </tr>
                                </template>
                                <tr x-show="assignments.length === 0">
                                    <td colspan="3" class="px-4 sm:px-5 py-6 text-center text-gray-500 dark:text-gray-400">
                                        No stores assigned.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </x-modal>

        {{-- Confirm Store Removal Modal --}}
        <x-crud.confirm-delete-modal
            name="confirm-store-removal"
            entity="Store Assignment"
            nameExpression="storeToRemove?.name"
            deleteAction="removeAssignment()"
            disabledVar="removing" />
    </div>
</x-app-layout>