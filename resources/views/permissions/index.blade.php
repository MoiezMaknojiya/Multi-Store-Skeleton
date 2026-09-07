<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-white leading-tight">{{ __('Permissions') }}</h2>
    </x-slot>

    <div x-data="permissionsTable()" class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        {{-- Add button --}}
        <div class="flex items-center justify-between mb-6">
            @can('permission-store')
            <x-crud.add-button label="Add Permission" @click="openFormModal()" />
            @endcan
        </div>

        {{-- Data table --}}
        <x-crud.table-wrapper title="All Permissions" searchPlaceholder="Search Permissions" :columns="2">
            <x-slot name="head">
                <th class="px-5 py-3 text-left font-semibold">Name</th>
                <th class="px-5 py-3 text-right font-semibold">Actions</th>
            </x-slot>

            <x-slot name="body">
                <x-crud.table-empty :columns="2" itemsVar="items" message="No permissions found." />

                <template x-for="item in items" :key="item.id">
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                        <td class="px-5 py-4">
                            <p class="font-medium text-gray-800 dark:text-white" x-text="item.display_name"></p>
                            <p x-show="item.label" class="text-xs text-gray-400 dark:text-gray-500" x-text="item.name"></p>
                        </td>
                        <td class="px-5 py-4">
                            <x-crud.table-actions editClick="openFormModal(item)" deleteClick="confirmDelete(item)" editCan="permission-update" deleteCan="permission-destroy" />
                        </td>
                    </tr>
                </template>
            </x-slot>

            <x-slot name="footer">
                <x-crud.pagination itemsVar="items" />
            </x-slot>
        </x-crud.table-wrapper>

        {{-- Delete Confirmation: requires the acting user's password.
             Wrapped in a real <form> so the password field has a proper form boundary — without one,
             Chrome treats it as an "unowned" password field and autofills the nearest plain text input
             on the page (the search box) as its guessed username companion. --}}
        <x-modal name="confirm-permission-deletion" :show="false" maxWidth="md" focusable>
            <form @submit.prevent="deleteItem()" class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Delete Permission</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    Are you sure you want to delete
                    <span x-text="selectedItem?.name" class="font-semibold"></span>?
                    This action cannot be undone.
                </p>

                <div class="mt-4">
                    <x-input-label for="delete-permission-password" value="Confirm your password" />
                    <x-text-input
                        id="delete-permission-password"
                        type="password"
                        class="mt-1 block w-full"
                        x-model="deletePassword"
                        autocomplete="current-password"
                    />
                    <p x-show="deleteError" x-text="deleteError" class="mt-2 text-sm text-red-600 dark:text-red-400"></p>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'confirm-permission-deletion')">
                        Cancel
                    </x-secondary-button>
                    <x-danger-button x-bind:disabled="deleting">
                        Delete Permission
                    </x-danger-button>
                </div>
            </form>
        </x-modal>

        {{-- Add/Edit Form Modal --}}
        <x-modal name="permission-form-modal" :show="false" maxWidth="md">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100"
                    x-text="editingItem ? 'Edit Permission' : 'Add Permission'"></h2>
                <form @submit.prevent="saveItem" class="mt-4 space-y-4">
                    <x-crud.form-field label="Label (optional)" field="label">
                        <x-text-input x-model="form.label" class="block w-full" placeholder="Human-readable name shown in the UI" autocomplete="off"
                            @input="restrictLabelInput($event)" @keydown="restrictLabelInput($event)" />
                    </x-crud.form-field>
                    <x-crud.form-field label="Permission Name" field="name" :required="true">
                        <x-text-input x-model="form.name" class="block w-full" autocomplete="off" />
                    </x-crud.form-field>
                    <x-crud.form-actions savingVar="saving" />
                </form>
            </div>
        </x-modal>
    </div>
</x-app-layout>
