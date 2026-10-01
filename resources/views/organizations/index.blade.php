{{-- Organizations, from the platform's side: every organization. An organization's own people change the organization they work in from
     Settings → Organizations instead (owner's rule, 2026-09-17). There is no Owner column — an organization may have several
     Owners — and Invite owner appears only on an organization with none. What each row allows comes from the server
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
        <h1 class="page-title">{{ __('Organizations') }}</h1>
    </x-slot>

    <div x-data="organizationsTable()"
         class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        @if ($manageAds)
        <div class="flex flex-wrap items-center justify-end gap-3">
            {{-- The bulk switch, worded with the SAME two words as the button inside an
                 organization's screens page — one action must not have two vocabularies. --}}
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    Network advertising &mdash;
                    <span x-text="selectedOrganizationIds.length === 0
                        ? 'tick the organizations below'
                        : selectedOrganizationIds.length + (selectedOrganizationIds.length === 1 ? ' organization' : ' organizations') + ' selected'">tick the organizations below</span>
                </span>
                {{-- Page actions on the ticked organizations: a disabled button looks disabled by itself (btn-secondary). --}}
                <button type="button" @click="askOrganizationAds(true)" dusk="organizations-ads-on" class="btn-secondary"
                        x-bind:disabled="selectedOrganizationIds.length === 0 || savingAds">Adverts On</button>
                <button type="button" @click="askOrganizationAds(false)" dusk="organizations-ads-off" class="btn-secondary"
                        x-bind:disabled="selectedOrganizationIds.length === 0 || savingAds">Adverts Off</button>
            </div>
        </div>
        @endif

        <x-crud.table-wrapper title="All Organizations" searchPlaceholder="Search organizations..." :columns="$columns">
            @can('organization-store')
                <x-slot name="actions">
                    <x-crud.add-button label="Add Organization" @click="openFormModal()" dusk="add-organization" />
                </x-slot>
            @endcan
            <x-slot name="head">
                @if ($manageAds)
                <th class="px-5 py-3 text-left font-semibold w-10">
                    <input type="checkbox" class="form-checkbox" dusk="select-all-organizations"
                           x-bind:checked="allOnPageSelected()" @change="toggleSelectAll()"
                           x-bind:disabled="items.length === 0" aria-label="Select every organization on this page">
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
                <x-crud.table-empty :columns="$columns" itemsVar="items" message="No organizations yet." />

                <template x-for="item in items" :key="item.id">
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                        @if ($manageAds)
                        <td class="px-5 py-4">
                            <input type="checkbox" class="form-checkbox"
                                   x-bind:checked="isSelected(item.id)" @change="toggleSelect(item.id)"
                                   x-bind:dusk="'select-organization-' + item.id"
                                   x-bind:aria-label="'Select ' + item.name">
                        </td>
                        @endif
                        <td class="px-5 py-4">
                            <p class="font-medium text-gray-800 dark:text-white" x-text="item.name"></p>
                        </td>
                        <td class="px-5 py-4 text-gray-600 dark:text-gray-300">
                            <span x-text="item.city"></span><br>
                            <span class="text-xs text-gray-500 dark:text-gray-400" x-text="item.state + ' ' + item.zip_code"></span>
                        </td>
                        <td class="px-5 py-4 text-gray-600 dark:text-gray-300" x-text="item.members_count" x-bind:dusk="'organization-members-' + item.id"></td>
                        <td class="px-5 py-4">
                            <x-crud.status-badge activeExpression="item.is_active" inactive="Paused" />
                        </td>
                        @if ($manageAds)
                        <td class="px-5 py-4">
                            <span x-bind:class="adsBadgeClass(item)" x-bind:dusk="'organization-ads-' + item.id"
                                  x-text="adsLabel(item)"></span>
                        </td>
                        @endif
                        <td class="px-5 py-4">
                            <div class="flex items-center justify-end gap-2">
                                <button type="button" class="btn-row-success" x-show="item.can.invite_owner"
                                    x-bind:aria-label="'Invite an owner for ' + item.name"
                                    @click="openInviteOwner(item)" x-bind:dusk="'invite-owner-' + item.id">Invite Owner</button>
                                <button type="button" class="btn-row-neutral" x-show="item.can.update"
                                    x-bind:aria-label="'Edit ' + item.name"
                                    @click="openFormModal(item)" x-bind:dusk="'edit-organization-' + item.id">Edit</button>
                                <button type="button" class="btn-row-danger" x-show="item.can.destroy"
                                    x-bind:aria-label="'Delete ' + item.name"
                                    @click="confirmDelete(item)" x-bind:dusk="'delete-organization-' + item.id">Delete</button>
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
        {{-- Confirmation, because this reaches past the organizations you ticked and into every
             television inside them — including any one that was deliberately set apart.
             The numbers are shown before the press, not reported after it. --}}
        <x-modal name="confirm-organization-ads" :show="false" maxWidth="lg" focusable>
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100"
                    x-text="(pendingAdsAccepts ? 'Switch advertising on for ' : 'Switch advertising off for ')
                        + selectedOrganizationIds.length + (selectedOrganizationIds.length === 1 ? ' organization?' : ' organizations?')"></h2>

                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    This also switches the
                    <span class="font-semibold" x-text="selectedScreenCount() + (selectedScreenCount() === 1 ? ' screen' : ' screens')"></span>
                    inside those organizations.
                </p>

                <p class="alert-warning mt-3" x-show="selectedScreenCount() > 0" x-cloak>
                    Any screen you had set apart by hand is switched too.
                </p>

                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'confirm-organization-ads')"
                                        x-bind:disabled="savingAds">
                        Cancel
                    </x-secondary-button>
                    <x-primary-button x-on:click="applyOrganizationAds()" x-bind:disabled="savingAds" dusk="confirm-organization-ads">
                        <x-spinner x-show="savingAds" x-cloak />
                        <span x-text="pendingAdsAccepts ? 'Adverts On' : 'Adverts Off'"></span>
                    </x-primary-button>
                </div>
            </div>
        </x-modal>
        @endif

        {{-- Delete: the organization and everything it owns, so the name has to be typed --}}
        <x-modal name="confirm-organization-deletion" :show="false" maxWidth="md" focusable>
            <form @submit.prevent="deleteItem()" class="p-6 space-y-4" dusk="delete-organization-form">
                <div>
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Delete <span x-text="selectedItem?.name"></span>?</h2>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        Everything in it goes: screens, files, playlists, channels, ads and every member's access.
                        People's accounts stay. <span class="font-semibold text-red-600 dark:text-red-400">This cannot be undone.</span>
                    </p>
                </div>
                <div>
                    <label class="form-label" for="delete-organization-name">Type <span class="confirm-name" x-text="selectedItem?.name" dusk="delete-organization-typed-name"></span> to confirm <span class="text-red-600 dark:text-red-400" aria-hidden="true">*</span></label>
                    <div class="mt-1" x-bind:class="deleteErrors.confirm_name ? 'crud-field-error' : ''">
                        <x-text-input id="delete-organization-name" x-model="deleteConfirmName" dusk="delete-organization-name" maxlength="255" autocomplete="off"
                            aria-required="true" x-bind:aria-invalid="deleteErrors.confirm_name ? 'true' : null"
                            x-bind:aria-describedby="deleteErrors.confirm_name ? 'delete-organization-name-error' : null" />
                    </div>
                    <template x-if="deleteErrors.confirm_name">
                        <p id="delete-organization-name-error" class="form-error" role="alert" x-text="deleteErrors.confirm_name[0]"></p>
                    </template>
                </div>

                <x-crud.password-confirm id="delete-organization-password" error="deleteErrors.password?.[0]" />
                <div class="flex flex-wrap justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'confirm-organization-deletion')">Cancel</x-secondary-button>
                    <x-danger-button x-bind:disabled="deleting" dusk="delete-organization-confirm">
                        <x-spinner x-show="deleting" x-cloak />
                        Delete Organization
                    </x-danger-button>
                </div>
            </form>
        </x-modal>

        {{-- Invite an owner: offered only for an organization that has none --}}
        <x-modal name="invite-organization-owner" :show="false" maxWidth="md" focusable>
            <form @submit.prevent="sendOwnerInvite()" novalidate class="p-6 space-y-4" dusk="invite-owner-form">
                <div>
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Give <span x-text="ownerOrganization?.name"></span> an owner</h2>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        Someone already in the organization becomes its Owner at once. Anyone else gets an email invitation.
                    </p>
                </div>
                <div>
                    <label class="form-label" for="invite-owner-email">Email <span class="text-red-600 dark:text-red-400" aria-hidden="true">*</span></label>
                    <div class="mt-1" x-bind:class="ownerErrors.email ? 'crud-field-error' : ''">
                        <x-text-input id="invite-owner-email" type="email" x-model="ownerEmail" dusk="invite-owner-email" maxlength="255" autocomplete="off" placeholder="owner@example.com"
                            aria-required="true" x-bind:aria-invalid="ownerErrors.email ? 'true' : null"
                            x-bind:aria-describedby="ownerErrors.email ? 'invite-owner-email-error' : null" />
                    </div>
                    <template x-if="ownerErrors.email">
                        <p id="invite-owner-email-error" class="form-error" role="alert" x-text="ownerErrors.email[0]"></p>
                    </template>
                </div>
                <x-crud.form-actions cancelAction="$dispatch('close-modal', 'invite-organization-owner')" savingVar="invitingOwner" saveLabel="Send" dusk="invite-owner-send" />
            </form>
        </x-modal>

        {{-- Add/Edit Organization Form Modal --}}
        <x-modal name="organization-form-modal" :show="false" maxWidth="2xl" persistent>
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100"
                    x-text="editingItem ? 'Edit Organization' : 'Add Organization'"></h2>
                <form @submit.prevent="saveItem" novalidate dusk="organization-form" class="mt-4 space-y-4">

                    <x-crud.form-field label="Organization Name" field="name" :required="true">
                        <x-text-input x-model="form.name" dusk="organization-name" maxlength="255" autocomplete="off" />
                    </x-crud.form-field>

                    {{-- Street & Suite --}}
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <x-crud.form-field label="Street" field="street" :required="true">
                            <x-text-input x-model="form.street" dusk="organization-street" maxlength="255" autocomplete="off" />
                        </x-crud.form-field>
                        <x-crud.form-field label="Suite/Unit" field="suite">
                            <x-text-input x-model="form.suite" maxlength="100" autocomplete="off" />
                        </x-crud.form-field>
                    </div>

                    {{-- City, State, Zip --}}
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <x-crud.form-field label="City" field="city" :required="true">
                            <x-text-input x-model="form.city" dusk="organization-city" maxlength="100" autocomplete="off" />
                        </x-crud.form-field>
                        <x-crud.form-field label="State" field="state" :required="true">
                            <select x-model="form.state" dusk="organization-state" class="form-select">
                                <option value="">Select State</option>
                                @foreach ($states as $code => $name)
                                    <option value="{{ $code }}">{{ $name }} ({{ $code }})</option>
                                @endforeach
                            </select>
                        </x-crud.form-field>
                        <x-crud.form-field label="Zip Code" field="zip_code" :required="true">
                            <x-text-input type="text" inputmode="numeric" data-digits="10" x-model="form.zip_code" dusk="organization-zip" autocomplete="off" />
                        </x-crud.form-field>
                    </div>

                    {{-- Country: half the row, like the street beside its suite. --}}
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <x-crud.form-field label="Country" field="country" :required="true">
                            <x-text-input x-model="form.country" dusk="organization-country" maxlength="100" autocomplete="off" />
                        </x-crud.form-field>
                    </div>

                    {{-- The owner is invited when the organization is created; after that Owners come
                         through "Invite owner", or from inside the organization. --}}
                    <div x-show="!editingItem" class="border-t border-gray-200 dark:border-gray-700 pt-4">
                        <x-crud.form-field label="Owner's email" field="owner_email" :required="true">
                            <x-text-input type="email" x-model="form.owner_email" dusk="organization-owner-email" maxlength="255" autocomplete="off" placeholder="owner@example.com" />
                        </x-crud.form-field>
                        <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">They'll get an email invitation to own this organization.</p>
                    </div>

                    {{-- Active Checkbox: switching an organization on or off is the platform's alone. Off, the organization is paused
                         (EnsureOrganizationIsActive): closed to its own people, its screens still playing. --}}
                    <div>
                        <div class="flex items-center gap-2">
                            <input type="checkbox" x-model="form.is_active" id="is_active" dusk="organization-active"
                                aria-describedby="organization-active-hint" class="form-checkbox">
                            <label for="is_active" class="text-sm text-gray-700 dark:text-gray-300">Active</label>
                        </div>
                        <p id="organization-active-hint" class="mt-1 text-xs text-gray-500 dark:text-gray-400">Off pauses the organization: its people cannot open it, and its screens keep playing.</p>
                    </div>

                    <x-crud.form-actions savingVar="saving" dusk="organization-save" />
                </form>
            </div>
        </x-modal>
    </div>
</x-app-layout>
