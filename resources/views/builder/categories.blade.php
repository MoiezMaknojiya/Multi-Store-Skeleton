<x-app-layout>
    <x-slot name="header">
        <h1 class="page-title">{{ __('Categories') }}</h1>
    </x-slot>

    {{-- The Ad Builder's one list of categories, the platform's (owner, 2026-10-22): an ad is put in them in Create Ad and in
         the editor, and the Ads page and the Premium Templates are filtered by them. --}}
    <div x-data="adCategoriesTable()" class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <x-crud.table-wrapper title="All Categories" description="Put ads in categories when you create or edit them, then filter the Ads page and the Premium Templates by them."
            searchPlaceholder="Search by name" :columns="3">
            @can('ad-category-store')
                <x-slot name="actions">
                    <x-crud.add-button label="Add Category" @click="openFormModal()" dusk="add-category" />
                </x-slot>
            @endcan
            <x-slot name="head">
                <th class="px-5 py-3 text-left font-semibold">Name</th>
                <th class="px-5 py-3 text-left font-semibold">Ads</th>
                <th class="px-5 py-3 text-right font-semibold">Actions</th>
            </x-slot>

            <x-slot name="body">
                <x-crud.table-empty :columns="3" itemsVar="items" message="No categories yet." />

                <template x-for="item in items" :key="item.id">
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                        <td class="px-5 py-4">
                            <span class="badge-neutral" x-text="item.name"></span>
                        </td>
                        <td class="px-5 py-4 whitespace-nowrap text-gray-600 dark:text-gray-300"
                            x-text="item.ads_count === 1 ? '1 ad' : item.ads_count + ' ads'"></td>
                        <td class="px-5 py-4">
                            <x-crud.table-actions editClick="openFormModal(item)" deleteClick="confirmDelete(item)" editCan="ad-category-update" deleteCan="ad-category-destroy" dusk="category" />
                        </td>
                    </tr>
                </template>
            </x-slot>

            <x-slot name="footer">
                <x-crud.pagination itemsVar="items" />
            </x-slot>
        </x-crud.table-wrapper>

        {{-- Deleting a category takes it off its ads and deletes nothing else, so it is one confirmation away. --}}
        <x-crud.confirm-delete-modal
            name="confirm-category-deletion"
            entity="Category"
            nameExpression="selectedItem?.name"
            deleteAction="deleteItem()"
            disabledVar="deleting"
            question="Delete the category">
            <x-slot name="note">It comes off every ad in it. The ads themselves stay as they are.</x-slot>
        </x-crud.confirm-delete-modal>

        <x-modal name="category-form-modal" :show="false" maxWidth="md">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100"
                    x-text="editingItem ? 'Edit Category' : 'Add Category'"></h2>
                <form @submit.prevent="saveItem" novalidate class="mt-4 space-y-4">
                    <x-crud.form-field label="Name" field="name" :required="true">
                        <x-text-input x-model="form.name" maxlength="{{ \App\Models\AdCategory::MAX_NAME }}" autocomplete="off" placeholder="Menu" dusk="category-name" />
                    </x-crud.form-field>
                    <x-crud.form-actions savingVar="saving" dusk="category-save" />
                </form>
            </div>
        </x-modal>
    </div>
</x-app-layout>
