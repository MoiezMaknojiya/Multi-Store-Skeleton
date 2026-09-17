<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-white leading-tight">{{ __('Advertising') }}</h2>
    </x-slot>

    {{-- Js::from, not @json: @json leaves its quotes raw and the first string would
         close this attribute (see .claude/rules/02-project-conventions.md). --}}
    <div x-data="campaignsTable({{ Js::from([
            'breakEverySeconds' => $breakEverySeconds,
            'maxBreakSeconds' => $maxBreakSeconds,
         ]) }})"
         class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="flex flex-wrap items-center gap-3 mb-6">
            <x-crud.add-button label="Add Campaign" @click="openCampaignModal()" dusk="add-campaign" />

            {{-- The two facts that govern everything on this page, stated once. --}}
            <p class="text-sm text-gray-500 dark:text-gray-400">
                One advert break every <span class="font-medium" x-text="Math.round(breakEverySeconds / 60)"></span> minutes,
                up to <span class="font-medium" x-text="maxBreakSeconds"></span> seconds long.
                The shop's own content pauses and carries on afterwards.
            </p>
        </div>

        <x-crud.table-wrapper title="All Campaigns" searchPlaceholder="Search by campaign or advertiser..." :columns="6">
            <x-slot name="head">
                <th class="px-5 py-3 text-left font-semibold">Campaign</th>
                <th class="px-5 py-3 text-left font-semibold">Runs</th>
                <th class="px-5 py-3 text-left font-semibold">Length</th>
                <th class="px-5 py-3 text-left font-semibold">Screens</th>
                <th class="px-5 py-3 text-left font-semibold">Status</th>
                <th class="px-5 py-3 text-right font-semibold">Actions</th>
            </x-slot>

            <x-slot name="body">
                <x-crud.table-empty :columns="6" itemsVar="items" message="No campaigns yet." />

                <template x-for="item in items" :key="item.id">
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-16 h-10 rounded overflow-hidden bg-gray-100 dark:bg-gray-700 flex items-center justify-center flex-shrink-0">
                                    <template x-if="item.thumbnail_url">
                                        <img :src="item.thumbnail_url" :alt="item.name" class="w-full h-full object-cover">
                                    </template>
                                    <template x-if="!item.thumbnail_url">
                                        <span class="text-[10px] text-gray-400" x-text="item.type"></span>
                                    </template>
                                </div>
                                <div class="min-w-0">
                                    <p class="font-medium text-gray-800 dark:text-white whitespace-nowrap"
                                       x-bind:dusk="'campaign-name-' + item.id" x-text="item.name"></p>
                                    <p class="text-xs text-gray-400 whitespace-nowrap"
                                       x-text="item.advertiser_name || 'No advertiser named'"></p>
                                </div>
                            </div>
                        </td>

                        <td class="px-5 py-4 text-gray-600 dark:text-gray-300 text-xs">
                            <span class="block whitespace-nowrap" x-text="datesLabel(item)"></span>
                            <span class="block text-gray-400 whitespace-nowrap" x-text="windowLabel(item)"></span>
                        </td>

                        <td class="px-5 py-4 text-gray-600 dark:text-gray-300 text-sm whitespace-nowrap">
                            <span x-text="item.play_seconds + 's'"></span>
                            <span class="block text-xs text-gray-400 capitalize" x-text="item.type"></span>
                        </td>

                        <td class="px-5 py-4 text-gray-600 dark:text-gray-300 text-xs">
                            <span x-bind:class="(item.screens_count ?? 0) === 0 ? 'text-amber-600 dark:text-amber-400' : ''"
                                  x-bind:dusk="'campaign-screens-' + item.id"
                                  x-text="screensLabel(item)"></span>
                        </td>

                        <td class="px-5 py-4">
                            <span x-show="item.is_active" x-cloak class="badge-success">Active</span>
                            <span x-show="!item.is_active" x-cloak class="badge-neutral">Paused</span>
                        </td>

                        <td class="px-5 py-4">
                            <div class="flex items-center justify-end gap-2">
                                <button @click="openCampaignModal(item)" x-bind:dusk="'edit-campaign-' + item.id"
                                    class="btn-row-neutral">Edit</button>
                                <button @click="confirmDelete(item)" x-bind:dusk="'delete-campaign-' + item.id"
                                    class="btn-row-danger">Delete</button>
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
            name="confirm-campaign-deletion"
            entity="Campaign"
            nameExpression="selectedItem?.name"
            deleteAction="deleteItem()"
            disabledVar="deleting"
            :password="true" />

        {{-- Add / Edit --}}
        <x-modal name="campaign-form-modal" :show="false" maxWidth="3xl">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100"
                    x-text="editingItem ? 'Edit Campaign' : 'Add Campaign'"></h2>

                <form @submit.prevent="saveCampaign" dusk="campaign-form" class="mt-4 space-y-4">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-crud.form-field label="Campaign name" field="name" :required="true">
                            <x-text-input x-model="form.name" dusk="campaign-name" class="block w-full"
                                          placeholder="Coca-Cola — Ramadan" autocomplete="off" maxlength="120" />
                        </x-crud.form-field>

                        {{-- Who the advert is FOR, kept apart from what this campaign is
                             called — so "never run this brand in that shop" can be added
                             later without moving anything. --}}
                        <x-crud.form-field label="Advertiser" field="advertiser_name">
                            <x-text-input x-model="form.advertiser_name" dusk="campaign-advertiser" class="block w-full"
                                          placeholder="Coca-Cola" autocomplete="off" maxlength="120" />
                        </x-crud.form-field>
                    </div>

                    {{-- The advert itself. Silent by design: the player mutes every
                         video, and a browser will not autoplay an unmuted one anyway. --}}
                    <x-crud.form-field label="Advert" field="file" requiredWhen="!editingItem">
                        <input type="file" x-ref="fileInput" @change="onFileSelected($event)"
                               dusk="campaign-file" class="form-input"
                               accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm">
                    </x-crud.form-field>
                    <p class="text-xs text-gray-500 dark:text-gray-400 -mt-2">
                        JPG, PNG, GIF, WEBP, MP4 or WEBM &mdash; up to 250 MB. Adverts play with no sound.
                        <span x-show="editingItem" x-cloak>Leave empty to keep the current advert.</span>
                        <span x-show="preparing" x-cloak>Reading the video&hellip;</span>
                    </p>

                    {{-- Seconds belong to an IMAGE. A video runs to its own length, so for one the
                         field is not shown at all, rather than shown and then ignored (owner's rule). --}}
                    <div x-show="!isVideoAd()" x-cloak class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <x-crud.form-field label="Seconds on screen" field="duration_seconds" :required="true">
                            <x-text-input type="number" min="1" max="300" x-model.number="form.duration_seconds"
                                          dusk="campaign-seconds" class="block w-full" />
                        </x-crud.form-field>
                    </div>
                    <p x-show="isVideoAd()" x-cloak dusk="campaign-video-note" class="text-sm text-gray-500 dark:text-gray-400">
                        A video plays to its own end &mdash; there is nothing to set.
                    </p>

                    {{-- The contract period. --}}
                    <div class="pt-2 border-t border-gray-200 dark:border-gray-700">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <x-crud.form-field label="Starts on" field="starts_on">
                                <x-text-input type="date" x-model="form.starts_on" dusk="campaign-starts-on" class="block w-full" />
                            </x-crud.form-field>
                            <x-crud.form-field label="Ends on" field="ends_on">
                                <x-text-input type="date" x-model="form.ends_on" dusk="campaign-ends-on" class="block w-full" />
                            </x-crud.form-field>
                        </div>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Leave both blank to run until it is switched off.
                        </p>
                    </div>

                    {{-- The window inside a day, read on each screen's own clock. --}}
                    <div class="pt-2 border-t border-gray-200 dark:border-gray-700">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <x-crud.form-field label="From" field="start_time">
                                <x-text-input type="time" x-model="form.start_time" dusk="campaign-start-time" class="block w-full" />
                            </x-crud.form-field>
                            <x-crud.form-field label="Until" field="end_time">
                                <x-text-input type="time" x-model="form.end_time" dusk="campaign-end-time" class="block w-full" />
                            </x-crud.form-field>
                        </div>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Leave both blank to run all day. Each screen reads these on its own clock, and an
                            end <span class="font-semibold">earlier</span> than the start runs past midnight.
                        </p>
                    </div>

                    {{-- Which screens carry it. --}}
                    <div class="pt-2 border-t border-gray-200 dark:border-gray-700">
                        <label class="form-label">Screens</label>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">
                            Only shops that agreed to advertising can be chosen.
                            <span x-show="loadingScreens" x-cloak>Loading&hellip;</span>
                        </p>

                        <template x-if="!loadingScreens && allScreens.length === 0">
                            <p class="text-sm text-gray-400 dark:text-gray-500" dusk="campaign-no-screens">
                                There are no screens yet.
                            </p>
                        </template>

                        <div class="space-y-3 max-h-72 overflow-y-auto">
                            <template x-for="group in screensByStore()" :key="group.id">
                                <div class="rounded-md border border-gray-200 dark:border-gray-700 p-3">
                                    <div class="flex items-center justify-between mb-2">
                                        <p class="text-sm font-medium text-gray-800 dark:text-white" x-text="group.name"></p>
                                        <button type="button" @click="toggleStore(group)"
                                                x-bind:dusk="'campaign-store-all-' + group.id"
                                                x-show="group.screens.some(s => s.carries_ads)"
                                                class="btn-row-neutral">Select all</button>
                                    </div>

                                    <template x-for="screen in group.screens" :key="screen.id">
                                        <label class="flex items-center gap-3 py-1"
                                               x-bind:class="screen.carries_ads ? 'cursor-pointer' : 'opacity-60'">
                                            <input type="checkbox" class="form-checkbox"
                                                   x-bind:dusk="'campaign-screen-' + screen.id"
                                                   x-bind:disabled="!screen.carries_ads"
                                                   x-bind:checked="isChosen(screen.id)"
                                                   @change="toggleScreen(screen.id)">
                                            <span class="flex-1 text-sm text-gray-700 dark:text-gray-200" x-text="screen.name"></span>

                                            {{-- A greyed row that explains ITSELF beats a screen
                                                 that is silently not in the list. --}}
                                            <template x-if="!screen.carries_ads">
                                                <span class="text-xs text-gray-400" x-text="blockedReason(screen)"></span>
                                            </template>

                                            {{-- And one that can be chosen says what choosing it
                                                 would do to its break. --}}
                                            <template x-if="screen.carries_ads">
                                                <span class="text-xs"
                                                      x-bind:class="isOversold(screen)
                                                            ? 'text-amber-600 dark:text-amber-400 font-medium' : 'text-gray-400'"
                                                      x-text="bookedLabel(screen)"></span>
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div class="flex items-center">
                        <input type="checkbox" x-model="form.is_active" id="campaign_is_active"
                               dusk="campaign-active" class="form-checkbox">
                        <label for="campaign_is_active" class="ml-2 block text-sm text-gray-700 dark:text-gray-300">
                            Active &mdash; running on the screens chosen above
                        </label>
                    </div>

                    <x-crud.form-actions savingVar="saving" dusk="campaign-save" cancelDusk="campaign-cancel" />
                </form>
            </div>
        </x-modal>
    </div>
</x-app-layout>
