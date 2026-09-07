<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-white leading-tight">{{ __('Stores') }}</h2>
    </x-slot>

    <div x-data="storesTable({ isGlobalUser: @json(auth()->user()->globalRole() !== null), currentStoreId: @json((int) session('current_store_id')) })" class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="flex items-center justify-between mb-6">
            @can('store-store')
            <x-crud.add-button label="Add Store" @click="openFormModal()" dusk="add-store" />
            @endcan
        </div>

        <x-crud.table-wrapper title="All Stores" searchPlaceholder="Search Stores (name, city...)" :columns="4">
            <x-slot name="head">
                <th class="px-5 py-3 text-left font-semibold">Name</th>
                <th class="px-5 py-3 text-left font-semibold">Location</th>
                <th class="px-5 py-3 text-left font-semibold">Status</th>
                <th class="px-5 py-3 text-right font-semibold">Actions</th>
            </x-slot>

            <x-slot name="body">
                <x-crud.table-empty :columns="4" itemsVar="items" message="No stores found." />

                <template x-for="item in items" :key="item.id">
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                        <td class="px-5 py-4">
                            <p class="font-medium text-gray-800 dark:text-white" x-text="item.name"></p>
                        </td>
                        <td class="px-5 py-4 text-gray-600 dark:text-gray-300">
                            <span x-text="item.city"></span><br>
                            <span class="text-xs text-gray-400" x-text="item.state + ' ' + item.zip_code"></span>
                        </td>
                        <td class="px-5 py-4">
                            <x-crud.status-badge activeExpression="item.is_active" />
                        </td>
                        <td class="px-5 py-4">
                            {{-- Store users may only manage their CURRENT store (permissions are
                                 per-store); global users span every store. rowShow mirrors the
                                 backend guard so the buttons never mislead. --}}
                            <x-crud.table-actions editClick="openFormModal(item)" deleteClick="confirmDelete(item)"
                                editCan="store-update" deleteCan="store-destroy"
                                rowShow="isGlobalUser || item.id === currentStoreId" />
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
            name="confirm-store-deletion"
            entity="Store"
            nameExpression="selectedItem?.name"
            deleteAction="deleteItem()"
            disabledVar="deleting" />

        {{-- Add/Edit Store Form Modal --}}
        <x-modal name="store-form-modal" :show="false" maxWidth="2xl">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100"
                    x-text="editingItem ? 'Edit Store' : 'Add Store'"></h2>
                <form @submit.prevent="saveItem" dusk="store-form" class="mt-4 space-y-4">

                    <x-crud.form-field label="Store Name" field="name" :required="true">
                        <x-text-input x-model="form.name" dusk="store-name" class="block w-full" autocomplete="off" />
                    </x-crud.form-field>

                    {{-- Street & Suite --}}
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <x-crud.form-field label="Street" field="street" :required="true">
                            <x-text-input x-model="form.street" dusk="store-street" class="block w-full" autocomplete="off" />
                        </x-crud.form-field>
                        <x-crud.form-field label="Suite/Unit" field="suite">
                            <x-text-input x-model="form.suite" class="block w-full" autocomplete="off" />
                        </x-crud.form-field>
                    </div>

                    {{-- City, State, Zip --}}
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <x-crud.form-field label="City" field="city" :required="true">
                            <x-text-input x-model="form.city" dusk="store-city" class="block w-full" autocomplete="off" />
                        </x-crud.form-field>
                        <x-crud.form-field label="State" field="state" :required="true">
                            <select x-model="form.state" dusk="store-state" class="form-select block w-full">
                                <option value="">Select State</option>
                                @foreach ($states as $code => $name)
                                    <option value="{{ $code }}">{{ $name }} ({{ $code }})</option>
                                @endforeach
                            </select>
                        </x-crud.form-field>
                        <x-crud.form-field label="Zip Code" field="zip_code" :required="true">
                            <x-text-input type="text" inputmode="numeric" x-model="form.zip_code" dusk="store-zip" class="block w-full" autocomplete="off"
                                @input="restrictZipInput($event)" @keydown="restrictZipInput($event)" />
                        </x-crud.form-field>
                    </div>

                    {{-- Country --}}
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <x-crud.form-field label="Country" field="country" :required="true">
                            <x-text-input x-model="form.country" dusk="store-country" class="block w-full" autocomplete="off" />
                        </x-crud.form-field>
                        <div></div>
                    </div>

                    {{-- Active Checkbox --}}
                    <div class="flex items-center">
                        <input type="checkbox" x-model="form.is_active" id="is_active"
                            class="form-checkbox">
                        <label for="is_active" class="ml-2 block text-sm text-gray-700 dark:text-gray-300">Active</label>
                    </div>

                    <x-crud.form-actions savingVar="saving" dusk="store-save" />
                </form>
            </div>
        </x-modal>
    </div>
</x-app-layout>