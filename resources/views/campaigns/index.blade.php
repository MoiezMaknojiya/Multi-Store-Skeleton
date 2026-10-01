<x-app-layout>
    <x-slot name="header">
        <h1 class="page-title">{{ __('Advertising') }}</h1>
    </x-slot>

    {{-- Js::from, not @json: @json leaves its quotes raw and the first string would
         close this attribute (see .claude/rules/02-project-conventions.md). --}}
    <div x-data="campaignsTable({{ Js::from([
            'maxBreakSeconds' => $maxBreakSeconds,
         ]) }})"
         x-on:modal-closing.window="onModalClosing($event)"
         class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        {{-- The two facts that govern the page, in one line under its title (in seconds below a minute — a browser
             test turns it right down — in minutes above); Add Campaign beside the search. --}}
        <x-crud.table-wrapper title="All Campaigns" searchPlaceholder="Search campaigns..." :columns="6"
            :description="'One advert break every '.\App\Rules\VideoLength::inWords($breakEverySeconds).', up to '.$maxBreakSeconds.' seconds long.'">
            <x-slot name="actions">
                <x-crud.add-button label="Add Campaign" @click="openCampaignModal()" dusk="add-campaign" />
            </x-slot>
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
                                    {{-- An upright advert is shown whole, not cut to its middle. The name is beside it, so
                                         the picture says nothing more (alt=""). --}}
                                    <template x-if="item.thumbnail_url">
                                        <img :src="item.thumbnail_url" alt="" loading="lazy" class="w-full h-full"
                                             x-bind:class="Number(item.height) > Number(item.width) ? 'object-contain' : 'object-cover'">
                                    </template>
                                    <template x-if="!item.thumbnail_url">
                                        <span class="text-[10px] text-gray-500 dark:text-gray-300" x-text="item.type"></span>
                                    </template>
                                </div>
                                <div class="min-w-0">
                                    <p class="font-medium text-gray-800 dark:text-white whitespace-nowrap"
                                       x-bind:dusk="'campaign-name-' + item.id" x-text="item.name"></p>
                                    <p class="text-xs text-gray-500 whitespace-nowrap dark:text-gray-400"
                                       x-text="item.advertiser_name || 'No advertiser named'"></p>
                                </div>
                            </div>
                        </td>

                        <td class="px-5 py-4 text-gray-600 dark:text-gray-300 text-xs">
                            <span class="block whitespace-nowrap" x-text="datesLabel(item)"></span>
                            <span class="block text-gray-500 whitespace-nowrap dark:text-gray-400" x-text="windowLabel(item)"></span>
                        </td>

                        <td class="px-5 py-4 text-gray-600 dark:text-gray-300 text-sm whitespace-nowrap">
                            <span class="tabular-nums" x-text="item.play_seconds + ' secs'"></span>
                            <span class="block text-xs text-gray-500 capitalize dark:text-gray-400" x-text="item.type"></span>
                        </td>

                        <td class="px-5 py-4 text-gray-600 dark:text-gray-300 text-xs">
                            <span x-bind:class="(item.screens_count ?? 0) === 0 ? 'text-amber-700 dark:text-amber-400' : ''"
                                  x-bind:dusk="'campaign-screens-' + item.id"
                                  x-text="screensLabel(item)"></span>
                        </td>

                        {{-- Where it stands today: paused, over, not yet, on no screen, or running. --}}
                        <td class="px-5 py-4">
                            <span x-bind:class="campaignStatus(item).badge" x-bind:dusk="'campaign-status-' + item.id"
                                  x-text="campaignStatus(item).label"></span>
                        </td>

                        <td class="px-5 py-4">
                            {{-- No permission names: the whole page is campaign-manage, a hand-written gate. --}}
                            <x-crud.table-actions editClick="openCampaignModal(item)" deleteClick="confirmDelete(item)" dusk="campaign" />
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
        <x-modal name="campaign-form-modal" :show="false" maxWidth="3xl" persistent>
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100"
                    x-text="editingItem ? 'Edit Campaign' : 'Add Campaign'"></h2>

                {{-- novalidate: the seconds field's own min and max would stop the save with the browser's bubble
                     before saveCampaign could say it under the field, as every form here does (validate.js). --}}
                <form @submit.prevent="saveCampaign" novalidate dusk="campaign-form" class="mt-4 space-y-4">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-crud.form-field label="Campaign name" field="name" :required="true">
                            <x-text-input x-model="form.name" dusk="campaign-name"
                                          placeholder="Coca-Cola — Ramadan" autocomplete="off" maxlength="120" />
                        </x-crud.form-field>

                        {{-- Who the advert is FOR, kept apart from what this campaign is
                             called — so "never run this brand in that shop" can be added
                             later without moving anything. --}}
                        <x-crud.form-field label="Advertiser" field="advertiser_name">
                            <x-text-input x-model="form.advertiser_name" dusk="campaign-advertiser"
                                          placeholder="Coca-Cola" autocomplete="off" maxlength="120" />
                        </x-crud.form-field>
                    </div>

                    {{-- The advert itself. Silent by design: the player mutes every
                         video, and a browser will not autoplay an unmuted one anyway. --}}
                    {{-- It goes up in chunks the moment it is chosen (docs/UPLOADS-SPEC.md), and Save waits until it has
                         arrived. The page listens here, not on the box: an expression on the box runs with the box's
                         own `this`. --}}
                    <div x-ref="advertUpload"
                         x-on:upload-picked="onPicked($event.detail)"
                         x-on:upload-ready="onUploadReady($event.detail)"
                         x-on:upload-cleared="onUploadCleared()"
                         x-on:upload-busy="uploading = $event.detail.busy">
                        <x-crud.form-field label="Advert" field="file" requiredWhen="!editingItem">
                            <x-upload-dropzone purpose="campaign" mode="form" dusk="campaign"
                                :max-video-seconds="\App\Models\Campaign::MAX_AD_SECONDS" video-noun="An advert"
                                hint="JPG, PNG, GIF, WEBP, MP4 or WEBM — up to 250 MB, 60 seconds at most." />
                        </x-crud.form-field>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Adverts play muted.</p>

                        {{-- Editing: the advert it plays now, which stays unless another file is chosen. --}}
                        <div x-show="editingItem && !picked" x-cloak class="mt-3 flex items-center gap-3" dusk="campaign-current-advert">
                            <div class="flex h-10 w-16 flex-shrink-0 items-center justify-center overflow-hidden rounded bg-gray-100 dark:bg-gray-700">
                                <template x-if="editingItem?.thumbnail_url">
                                    <img :src="editingItem.thumbnail_url" alt="" class="h-full w-full object-contain">
                                </template>
                            </div>
                            <p class="text-sm text-gray-600 dark:text-gray-300"
                               x-text="'Current ' + (editingItem?.type === 'video' ? 'video' : 'picture') + '. Choose a file to replace it.'"></p>
                        </div>
                    </div>

                    {{-- Seconds belong to an IMAGE. A video runs to its own length, so for one the
                         field is not shown at all, rather than shown and then ignored (owner's rule). --}}
                    <div x-show="!isVideoAd()" x-cloak>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <x-crud.form-field label="Seconds on screen" field="duration_seconds" :required="true">
                                <x-text-input type="number" min="{{ \App\Models\PlaylistItem::MIN_IMAGE_SECONDS }}" x-bind:max="maxBreakSeconds" step="1" x-model.number="form.duration_seconds"
                                              dusk="campaign-seconds" />
                            </x-crud.form-field>
                        </div>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" x-text="secondsHint()"></p>
                    </div>
                    <p x-show="isVideoAd()" x-cloak dusk="campaign-video-note" class="text-sm text-gray-500 dark:text-gray-400">
                        A video plays to its own end &mdash; there is nothing to set.
                    </p>

                    {{-- The contract period. --}}
                    <div class="pt-2 border-t border-gray-200 dark:border-gray-700">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <x-crud.form-field label="Starts on" field="starts_on">
                                <x-text-input type="date" x-model="form.starts_on" dusk="campaign-starts-on" />
                            </x-crud.form-field>
                            <x-crud.form-field label="Ends on" field="ends_on">
                                <x-text-input type="date" x-model="form.ends_on" dusk="campaign-ends-on" />
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
                                <x-text-input type="time" x-model="form.start_time" dusk="campaign-start-time" />
                            </x-crud.form-field>
                            <x-crud.form-field label="Until" field="end_time">
                                <x-text-input type="time" x-model="form.end_time" dusk="campaign-end-time" />
                            </x-crud.form-field>
                        </div>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Leave both empty to run all day. An end before the start runs past midnight.
                        </p>
                    </div>

                    {{-- Which screens carry it: a set of checkboxes, named as one by its legend. --}}
                    <fieldset class="min-w-0 pt-2 border-t border-gray-200 dark:border-gray-700">
                        <legend class="form-label float-left w-full">Screens</legend>
                        <p class="clear-left text-xs text-gray-500 dark:text-gray-400 mb-2">
                            Only organizations that agreed to advertising can be chosen.
                            <span x-show="loadingScreens" x-cloak class="inline-flex items-center gap-1">
                                <x-spinner class="h-3 w-3" /> Loading&hellip;
                            </span>
                        </p>

                        <template x-if="!loadingScreens && allScreens.length === 0">
                            <p class="text-sm text-gray-500 dark:text-gray-400" dusk="campaign-no-screens">
                                There are no screens yet.
                            </p>
                        </template>

                        <div class="space-y-3 max-h-72 overflow-y-auto">
                            <template x-for="group in screensByStore()" :key="group.id">
                                <div class="rounded-md border border-gray-200 dark:border-gray-700 p-3">
                                    {{-- One press chooses every screen of the shop that can carry adverts, and the same
                                         press, once they all are, clears them — the words say which it will do. --}}
                                    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                                        <p class="text-sm font-medium text-gray-800 dark:text-white" x-text="group.name"></p>
                                        <button type="button" @click="toggleStore(group)"
                                                x-bind:dusk="'campaign-store-all-' + group.id"
                                                x-show="group.screens.some(s => s.carries_ads)"
                                                x-bind:aria-label="(storeAllChosen(group) ? 'Clear every screen of ' : 'Choose every screen of ') + group.name"
                                                class="btn-row-neutral" x-text="storeAllChosen(group) ? 'Clear All' : 'Select All'"></button>
                                    </div>

                                    {{-- A screen that cannot carry adverts says why beside it, in words that stay readable:
                                         only its checkbox is disabled. --}}
                                    <template x-for="screen in group.screens" :key="screen.id">
                                        <label class="flex flex-wrap items-center gap-x-3 gap-y-1 py-1"
                                               x-bind:class="screen.carries_ads ? 'cursor-pointer' : 'cursor-not-allowed'">
                                            <input type="checkbox" class="form-checkbox"
                                                   x-bind:dusk="'campaign-screen-' + screen.id"
                                                   x-bind:disabled="!screen.carries_ads"
                                                   x-bind:checked="isChosen(screen.id)"
                                                   @change="toggleScreen(screen.id)">
                                            <span class="flex-1 text-sm"
                                                  x-bind:class="screen.carries_ads ? 'text-gray-700 dark:text-gray-200' : 'text-gray-500 dark:text-gray-400'"
                                                  x-text="screen.name"></span>

                                            {{-- A greyed row that explains ITSELF beats a screen
                                                 that is silently not in the list. --}}
                                            <template x-if="!screen.carries_ads">
                                                <span class="text-xs text-gray-500 dark:text-gray-400" x-text="blockedReason(screen)"></span>
                                            </template>

                                            {{-- And one that can be chosen says what choosing it
                                                 would do to its break. --}}
                                            <template x-if="screen.carries_ads">
                                                <span class="text-xs"
                                                      x-bind:class="isOversold(screen)
                                                            ? 'text-amber-700 dark:text-amber-400 font-medium' : 'text-gray-500 dark:text-gray-400'"
                                                      x-text="bookedLabel(screen)"></span>
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </template>
                        </div>
                        <template x-if="screensError()">
                            <p class="form-error" role="alert" x-text="screensError()" dusk="campaign-screens-error"></p>
                        </template>
                    </fieldset>

                    <div class="flex items-start gap-2">
                        <input type="checkbox" x-model="form.is_active" id="campaign_is_active"
                               dusk="campaign-active" class="form-checkbox mt-0.5">
                        <label for="campaign_is_active" class="text-sm text-gray-700 dark:text-gray-300">
                            Active &mdash; running on the screens chosen above
                        </label>
                    </div>

                    {{-- Cancel while the advert is still going up asks first: closing gives the upload up. --}}
                    <div x-show="confirmingClose && uploading" x-cloak role="alert" class="alert-warning flex flex-wrap items-center justify-between gap-3" dusk="campaign-upload-still-going">
                        <span>The advert is still uploading. Stop it and close?</span>
                        <span class="flex flex-wrap gap-2">
                            <button type="button" class="btn-secondary" @click="confirmingClose = false">Keep Uploading</button>
                            <button type="button" class="btn-danger" @click="closeCampaignModal(true)" dusk="campaign-stop-and-close">Stop and Close</button>
                        </span>
                    </div>

                    {{-- Save waits while a video is still being measured, or it would go without its length and poster. --}}
                    <x-crud.form-actions savingVar="saving || uploading" cancelAction="closeCampaignModal()" dusk="campaign-save" cancelDusk="campaign-cancel" />
                </form>
            </div>
        </x-modal>
    </div>
</x-app-layout>
