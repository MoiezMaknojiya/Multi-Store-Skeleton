<x-app-layout>
    <x-slot name="header">
        <h1 class="page-title">{{ __('Permissions') }}</h1>
    </x-slot>

    <div x-data="permissionsTable()" class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        @can('permission-store')
        <div class="flex flex-wrap items-center gap-3">
            <x-crud.add-button label="Add Permission" @click="openFormModal()" />
        </div>
        @endcan

        {{-- The one thing to know before touching this list, said under its title (rule 02, "Platform permissions"):
             the names are written in the code. --}}
        <x-crud.table-wrapper title="All Permissions" searchPlaceholder="Search by name or label" :columns="2"
            description="A permission's name is written in the app's code. Renaming or deleting one a feature uses turns that feature off for everybody but super admins.">
            <x-slot name="head">
                <th class="px-5 py-3 text-left font-semibold">Name</th>
                <th class="px-5 py-3 text-right font-semibold">Actions</th>
            </x-slot>

            <x-slot name="body">
                <x-crud.table-empty :columns="2" itemsVar="items" message="No permissions yet." />

                <template x-for="item in items" :key="item.id">
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                        <td class="px-5 py-4">
                            <p class="font-medium text-gray-800 dark:text-white" x-text="item.display_name"></p>
                            <p x-show="item.label" class="text-xs text-gray-500 dark:text-gray-400" x-text="item.name"></p>
                        </td>
                        <td class="px-5 py-4">
                            <x-crud.table-actions editClick="openFormModal(item)" deleteClick="confirmDelete(item)" editCan="permission-update" deleteCan="permission-destroy" dusk="permission" />
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
                </p>
                <p class="alert-warning mt-3">
                    Every role loses it, and a feature whose code asks for it stops working for everybody but super admins.
                </p>

                <x-crud.password-confirm id="delete-permission-password" />

                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'confirm-permission-deletion')">
                        Cancel
                    </x-secondary-button>
                    <x-danger-button x-bind:disabled="deleting" dusk="confirm-permission-deletion-confirm">
                        <x-spinner x-show="deleting" x-cloak />
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
                {{-- The name first — it is what the code asks for — then the words people read. --}}
                <form @submit.prevent="saveItem" novalidate class="mt-4 space-y-4">
                    <p x-show="editingItem" x-cloak class="alert-warning">
                        The app's code asks for a permission by its name: renaming one a feature uses turns that feature off for
                        everybody but super admins. Change the label instead.
                    </p>
                    <div>
                        <x-crud.form-field label="Name" field="name" :required="true">
                            <x-text-input x-model="form.name" maxlength="255" autocomplete="off" placeholder="screen-view" />
                        </x-crud.form-field>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Lowercase words joined by hyphens, as the code names it.</p>
                    </div>
                    <div>
                        <x-crud.form-field label="Label" field="label">
                            <x-text-input x-model="form.label" placeholder="View Screens" maxlength="255" autocomplete="off"
                                @input="restrictLabelInput($event)" @keydown="restrictLabelInput($event)" />
                        </x-crud.form-field>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Optional: the words shown on the Roles page. Letters, numbers and spaces.</p>
                    </div>
                    <x-crud.form-actions savingVar="saving" />
                </form>
            </div>
        </x-modal>
    </div>
</x-app-layout>
