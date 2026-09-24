{{-- Stores, from the platform's side: every store. A store's own people change the store they work in from
     Settings → Stores instead (owner's rule, 2026-09-17). There is no Owner column — a store may have several
     Owners — and Invite owner appears only on a store with none. What each row allows comes from the server
     (`can`). --}}
@php
    // The advertising column and its bulk switch belong to the platform owner alone.
    // The route says the same thing (can:campaign-manage) — this only stops the page
    // from showing a control nobody else may press.
    $manageAds = auth()->user()?->can('campaign-manage') ?? false;
    $columns = $manageAds ? 7 : 5;
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-white leading-tight">{{ __('Stores') }}</h2>
    </x-slot>

    <div x-data="storesTable()"
         class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
            @can('store-store')
            <x-crud.add-button label="Add Store" @click="openFormModal()" dusk="add-store" />
            @endcan

            @if ($manageAds)
            {{-- The bulk switch, worded with the SAME two words as the button inside a
                 shop's screens page — one action must not have two vocabularies. --}}
            <div class="flex flex-wrap items-center gap-2 ml-auto">
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    Network advertising &mdash;
                    <span x-text="selectedStoreIds.length === 0
                        ? 'tick the shops below'
                        : selectedStoreIds.length + (selectedStoreIds.length === 1 ? ' shop' : ' shops') + ' selected'"></span>
                </span>
                <button @click="askStoreAds(true)" dusk="stores-ads-on" class="btn-row-neutral"
                        x-bind:disabled="selectedStoreIds.length === 0 || savingAds"
                        x-bind:class="selectedStoreIds.length === 0 ? 'opacity-40 cursor-not-allowed' : ''">Adverts on</button>
                <button @click="askStoreAds(false)" dusk="stores-ads-off" class="btn-row-neutral"
                        x-bind:disabled="selectedStoreIds.length === 0 || savingAds"
                        x-bind:class="selectedStoreIds.length === 0 ? 'opacity-40 cursor-not-allowed' : ''">Adverts off</button>
            </div>
            @endif
        </div>

        <x-crud.table-wrapper title="All Stores" searchPlaceholder="Search Stores (name, city...)" :columns="$columns">
            <x-slot name="head">
                @if ($manageAds)
                <th class="px-5 py-3 text-left font-semibold w-10">
                    <input type="checkbox" class="form-checkbox" dusk="select-all-stores"
                           x-bind:checked="allOnPageSelected()" @change="toggleSelectAll()"
                           x-bind:disabled="items.length === 0" aria-label="Select every shop on this page">
                </th>
                @endif
                <th class="px-5 py-3 text-left font-semibold">Name</th>
                <th class="px-5 py-3 text-left font-semibold">Location</th>
                <th class="px-5 py-3 text-left font-semibold">Members</th>
                <th class="px-5 py-3 text-left font-semibold">Status</th>
                @if ($manageAds)
                <th class="px-5 py-3 text-left font-semibold">Adverts</th>
                @endif
                <th class="px-5 py-3 text-right font-semibold">Actions</th>
            </x-slot>

            <x-slot name="body">
                <x-crud.table-empty :columns="$columns" itemsVar="items" message="No stores found." />

                <template x-for="item in items" :key="item.id">
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                        @if ($manageAds)
                        <td class="px-5 py-4">
                            <input type="checkbox" class="form-checkbox"
                                   x-bind:checked="isSelected(item.id)" @change="toggleSelect(item.id)"
                                   x-bind:dusk="'select-store-' + item.id"
                                   x-bind:aria-label="'Select ' + item.name">
                        </td>
                        @endif
                        <td class="px-5 py-4">
                            <p class="font-medium text-gray-800 dark:text-white" x-text="item.name"></p>
                        </td>
                        <td class="px-5 py-4 text-gray-600 dark:text-gray-300">
                            <span x-text="item.city"></span><br>
                            <span class="text-xs text-gray-400" x-text="item.state + ' ' + item.zip_code"></span>
                        </td>
                        <td class="px-5 py-4 text-gray-600 dark:text-gray-300" x-text="item.members_count" x-bind:dusk="'store-members-' + item.id"></td>
                        <td class="px-5 py-4">
                            <x-crud.status-badge activeExpression="item.is_active" />
                        </td>
                        @if ($manageAds)
                        <td class="px-5 py-4">
                            <span x-bind:class="adsBadgeClass(item)" x-bind:dusk="'store-ads-' + item.id"
                                  x-text="adsLabel(item)"></span>
                        </td>
                        @endif
                        <td class="px-5 py-4">
                            <div class="flex items-center justify-end gap-2">
                                <button type="button" class="btn-row-success" x-show="item.can.invite_owner"
                                    @click="openInviteOwner(item)" x-bind:dusk="'invite-owner-' + item.id">Invite owner</button>
                                <button type="button" class="btn-row-neutral" x-show="item.can.update"
                                    @click="openFormModal(item)" x-bind:dusk="'edit-store-' + item.id">Edit</button>
                                <button type="button" class="btn-row-danger" x-show="item.can.destroy"
                                    @click="confirmDelete(item)" x-bind:dusk="'delete-store-' + item.id">Delete</button>
                            </div>
                        </td>
                    </tr>
                </template>
            </x-slot>

            <x-slot name="footer">
                <x-crud.pagination itemsVar="items" />
            </x-slot>
        </x-crud.table-wrapper>

        @if ($manageAds)
        {{-- Confirmation, because this reaches past the shops you ticked and into every
             television inside them — including any one that was deliberately set apart.
             The numbers are shown before the press, not reported after it. --}}
        <x-modal name="confirm-store-ads" :show="false" maxWidth="lg" focusable>
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100"
                    x-text="(pendingAdsAccepts ? 'Switch advertising on for ' : 'Switch advertising off for ')
                        + selectedStoreIds.length + (selectedStoreIds.length === 1 ? ' shop?' : ' shops?')"></h2>

                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    This also switches
                    <span class="font-semibold" x-text="selectedScreenCount() + (selectedScreenCount() === 1 ? ' screen' : ' screens')"></span>
                    inside those shops &mdash; a shop on its own shows nothing, so the two travel together.
                </p>

                <p class="mt-2 text-sm text-amber-700 dark:text-amber-400" x-show="selectedScreenCount() > 0" x-cloak>
                    Any screen you had set apart by hand is switched too. Set those again from
                    inside the shop afterwards.
                </p>

                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'confirm-store-ads')"
                                        x-bind:disabled="savingAds">
                        Cancel
                    </x-secondary-button>
                    <x-primary-button x-on:click="applyStoreAds()" x-bind:disabled="savingAds"
                                      dusk="confirm-store-ads"
                                      x-text="pendingAdsAccepts ? 'Adverts on' : 'Adverts off'"></x-primary-button>
                </div>
            </div>
        </x-modal>
        @endif

        {{-- Delete: the store and everything it owns, so the name has to be typed --}}
        <x-modal name="confirm-store-deletion" :show="false" maxWidth="md" focusable>
            <form @submit.prevent="deleteItem()" class="p-6 space-y-4" dusk="delete-store-form">
                <div>
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Delete <span x-text="selectedItem?.name"></span>?</h2>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        This deletes the store and everything in it — screens (they stop playing at once), media files,
                        playlists, dayparts, custom roles, its own channels, its Ad Builder designs and assets, invitations
                        and every member's access.
                        People's accounts are not deleted. <span class="font-semibold text-red-600 dark:text-red-400">This cannot be undone.</span>
                    </p>
                </div>
                <div>
                    <label class="form-label" for="delete-store-name">Type <span class="font-semibold" x-text="selectedItem?.name"></span> to confirm <span class="text-red-500">*</span></label>
                    <div class="mt-1" x-bind:class="deleteErrors.confirm_name ? 'crud-field-error' : ''">
                        <x-text-input id="delete-store-name" x-model="deleteConfirmName" dusk="delete-store-name" class="block w-full" autocomplete="off" />
                    </div>
                    <template x-if="deleteErrors.confirm_name">
                        <p class="form-error" x-text="deleteErrors.confirm_name[0]"></p>
                    </template>
                </div>

                <x-crud.password-confirm id="delete-store-password" error="deleteErrors.password?.[0]" />
                <div class="flex flex-wrap justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'confirm-store-deletion')">Cancel</x-secondary-button>
                    <x-danger-button x-bind:disabled="deleting" dusk="delete-store-confirm">Delete store</x-danger-button>
                </div>
            </form>
        </x-modal>

        {{-- Invite an owner: offered only for a store that has none --}}
        <x-modal name="invite-store-owner" :show="false" maxWidth="md" focusable>
            <form @submit.prevent="sendOwnerInvite()" class="p-6 space-y-4" dusk="invite-owner-form">
                <div>
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Give <span x-text="ownerStore?.name"></span> an owner</h2>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        If this email belongs to someone already in the store, they become its Owner right away. Anyone else gets an
                        invitation by email — sending to the same address again gives them a fresh link, and a different address
                        replaces the earlier owner invitation.
                    </p>
                </div>
                <div>
                    <label class="form-label" for="invite-owner-email">Email <span class="text-red-500">*</span></label>
                    <div class="mt-1" x-bind:class="ownerErrors.email ? 'crud-field-error' : ''">
                        <x-text-input id="invite-owner-email" type="email" x-model="ownerEmail" dusk="invite-owner-email" class="block w-full" autocomplete="off" placeholder="owner@example.com" />
                    </div>
                    <template x-if="ownerErrors.email">
                        <p class="form-error" x-text="ownerErrors.email[0]"></p>
                    </template>
                </div>
                <x-crud.form-actions cancelAction="$dispatch('close-modal', 'invite-store-owner')" savingVar="invitingOwner" saveLabel="Send" dusk="invite-owner-send" />
            </form>
        </x-modal>

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

                    {{-- The owner is invited when the store is created; after that Owners come
                         through "Invite owner", or from inside the store. --}}
                    <div x-show="!editingItem" class="border-t border-gray-200 dark:border-gray-700 pt-4">
                        <x-crud.form-field label="Owner's email" field="owner_email" :required="true">
                            <x-text-input type="email" x-model="form.owner_email" dusk="store-owner-email" class="block w-full" autocomplete="off" placeholder="owner@example.com" />
                        </x-crud.form-field>
                        <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">They'll get an email invitation to own this store. Nobody can use the store until then.</p>
                    </div>

                    {{-- Active Checkbox: switching a store on or off is the platform's alone --}}
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
