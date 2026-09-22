<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-white leading-tight">{{ __('Channels') }}</h2>
    </x-slot>

    {{-- Js::from, not @json: @json leaves its quotes raw and the first string would
         close this attribute (see .claude/rules/02-project-conventions.md). --}}
    <div x-data="channelsTable({{ Js::from(['maxAdsPerPass' => $maxAdsPerPass]) }})"
         class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="flex flex-wrap items-center gap-3 mb-6">
            @can('channel-store')
            <x-crud.add-button label="Add Channel" @click="openFormModal()" dusk="add-channel" />
            @endcan

            {{-- The one fact that governs this page, stated once. --}}
            <p class="text-sm text-gray-500 dark:text-gray-400" dusk="channels-scope-note">
                @if ($store)
                    Channels made here belong to {{ $store->name }} and play only on its own screens. The platform's channels are
                    listed too, to look at — add one to a screen from that screen's Channels box.
                @else
                    A channel made here is offered to every shop, and each shop decides whether to put it on a screen. A channel a
                    store made for itself is marked with that store.
                @endif
            </p>
        </div>

        {{-- A bound :title — the wrapper echoes it itself, and an echo here as well escaped a store's name twice. --}}
        <x-crud.table-wrapper :title="$store ? 'Channels of '.$store->name : 'All Channels'" searchPlaceholder="Search channels..." :columns="6">
            <x-slot name="head">
                <th class="px-5 py-3 text-left font-semibold">Channel</th>
                <th class="px-5 py-3 text-left font-semibold">Ads</th>
                <th class="px-5 py-3 text-left font-semibold">Each time</th>
                <th class="px-5 py-3 text-left font-semibold">On screens</th>
                <th class="px-5 py-3 text-left font-semibold">Status</th>
                <th class="px-5 py-3 text-right font-semibold">Actions</th>
            </x-slot>

            <x-slot name="body">
                <x-crud.table-empty :columns="6" itemsVar="items" message="No channels yet." />

                <template x-for="item in items" :key="item.id">
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                        <td class="px-5 py-4">
                            <a x-bind:href="'/channels/' + item.id" x-bind:dusk="'channel-name-' + item.id"
                               class="font-medium text-gray-800 dark:text-white hover:underline" x-text="item.name"></a>
                            @unless ($store)
                            {{-- Above the stores, whose screens a channel reaches: every shop's, or one store's own. --}}
                            <div class="mt-1">
                                <span class="whitespace-nowrap" x-bind:class="item.store_name ? 'badge-neutral' : 'badge-info'"
                                      x-bind:dusk="'channel-reach-' + item.id"
                                      x-text="item.store_name ? item.store_name + ' only' : 'Every shop'"></span>
                            </div>
                            @else
                            {{-- Inside a store, the platform's channels are there to look at, never to change. --}}
                            <div class="mt-1" x-show="item.read_only" x-cloak>
                                <span class="badge-info whitespace-nowrap" x-bind:dusk="'channel-from-platform-' + item.id">From the platform</span>
                            </div>
                            @endunless
                            <p class="text-xs text-gray-400" x-text="item.created_by_name ? 'by ' + item.created_by_name : ''"></p>
                        </td>

                        <td class="px-5 py-4 text-sm text-gray-600 dark:text-gray-300">
                            <span x-bind:dusk="'channel-ads-' + item.id" x-text="adsLabel(item)"></span>
                        </td>

                        <td class="px-5 py-4 text-sm text-gray-600 dark:text-gray-300" x-text="perPassLabel(item)"></td>

                        <td class="px-5 py-4 text-sm text-gray-600 dark:text-gray-300">
                            <span x-bind:dusk="'channel-usage-' + item.id" x-text="usageLabel(item)"></span>
                        </td>

                        <td class="px-5 py-4">
                            <span x-show="item.is_active" x-cloak class="badge-success">Active</span>
                            <span x-show="!item.is_active" x-cloak class="badge-neutral">Paused</span>
                        </td>

                        <td class="px-5 py-4">
                            <div class="flex items-center justify-end gap-2">
                                <a x-bind:href="'/channels/' + item.id" x-bind:dusk="'channel-open-' + item.id"
                                   class="btn-row-success">Ads</a>
                                {{-- A platform channel seen from inside a shop offers nothing to change; the server
                                     refuses it anyway (404). --}}
                                @can('channel-update')
                                <button x-show="!item.read_only" @click="openFormModal(item)" x-bind:dusk="'edit-channel-' + item.id"
                                        class="btn-row-neutral">Edit</button>
                                @endcan
                                @can('channel-destroy')
                                <button x-show="!item.read_only" @click="confirmDelete(item)" x-bind:dusk="'delete-channel-' + item.id"
                                        class="btn-row-danger">Delete</button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                </template>
            </x-slot>

            <x-slot name="footer">
                <x-crud.pagination itemsVar="items" />
            </x-slot>
        </x-crud.table-wrapper>

        {{-- Delete. Says how far the channel has spread BEFORE the press, because the
             press takes it off every one of those playlists — which no shop can undo. --}}
        <x-modal name="confirm-channel-deletion" :show="false" maxWidth="md" focusable>
            <form @submit.prevent="deleteItem()" class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Delete Channel</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    Delete <span class="font-semibold" x-text="selectedItem?.name"></span> and every ad in it?
                    This cannot be undone. The files stay in their media libraries.
                </p>

                <p x-show="(selectedItem?.screens_count ?? 0) > 0" x-cloak dusk="channel-delete-usage"
                   class="mt-3 rounded-md bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
                    It is on <span class="font-semibold" x-text="usageLabel(selectedItem)"></span> and will be
                    taken off every one of those playlists. To take it off the air without that, pause it instead.
                </p>

                <x-crud.password-confirm id="confirm-channel-deletion-password" />

                <div class="mt-6 flex justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'confirm-channel-deletion')">
                        Cancel
                    </x-secondary-button>
                    <x-danger-button x-bind:disabled="deleting" dusk="confirm-channel-deletion-confirm">
                        Delete Channel
                    </x-danger-button>
                </div>
            </form>
        </x-modal>

        {{-- Add / Edit --}}
        <x-modal name="channel-form-modal" :show="false" maxWidth="lg">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100"
                    x-text="editingItem ? 'Edit Channel' : 'Add Channel'"></h2>

                <form @submit.prevent="saveItem" dusk="channel-form" class="mt-4 space-y-4">
                    <x-crud.form-field label="Channel name" field="name" :required="true">
                        <x-text-input x-model="form.name" dusk="channel-name" class="block w-full"
                                      placeholder="GAMA Wholesale" autocomplete="off" maxlength="120" />
                    </x-crud.form-field>

                    <x-crud.form-field label="Ads each time" field="ads_per_pass">
                        <x-text-input type="number" min="1" x-bind:max="maxAdsPerPass" x-model="form.ads_per_pass"
                                      dusk="channel-ads-per-pass" class="block w-full" placeholder="All" />
                    </x-crud.form-field>
                    <p class="text-xs text-gray-500 dark:text-gray-400 -mt-2">
                        How many of its ads play each time a screen's loop reaches this channel. Leave it blank to
                        play every one; a smaller number plays the next ones the time after.
                    </p>

                    <div class="flex items-center">
                        <input type="checkbox" x-model="form.is_active" id="channel_is_active"
                               dusk="channel-active" class="form-checkbox">
                        <label for="channel_is_active" class="ml-2 block text-sm text-gray-700 dark:text-gray-300">
                            Active &mdash; playing on every screen that carries it
                        </label>
                    </div>

                    <x-crud.form-actions savingVar="saving" dusk="channel-save" />
                </form>
            </div>
        </x-modal>
    </div>
</x-app-layout>
