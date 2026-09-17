<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('channels.view') }}" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200" dusk="back-to-channels">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
            </a>
            <h2 class="font-semibold text-xl text-gray-800 dark:text-white leading-tight">{{ $channel->name }}</h2>
            @unless ($channel->is_active)
                <span class="badge-neutral">Paused</span>
            @endunless
        </div>
    </x-slot>

    {{-- Js::from, not @json: @json leaves its quotes raw and the first string would
         close this attribute (see .claude/rules/02-project-conventions.md). --}}
    <div x-data="channelAds({{ Js::from([
            'channelId' => $channel->id,
            'maxImageSeconds' => $maxImageSeconds,
            'adsPerPass' => $channel->ads_per_pass,
         ]) }})"
         class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="card">
            <div class="card-header">
                <div>
                    <h3 class="text-subheading">Ads</h3>
                    <p class="text-xs text-gray-400 mt-0.5" dusk="channel-ads-summary" x-text="summary()"></p>
                </div>
                @can('channel-update')
                <x-crud.add-button label="Add Ad" @click="openAdModal()" dusk="add-channel-ad" />
                @endcan
            </div>

            <div class="p-4 space-y-2">
                <template x-if="loading">
                    <p class="text-center text-muted-soft py-6">Loading...</p>
                </template>

                <template x-if="!loading && ads.length === 0">
                    <p class="text-center text-muted-soft py-10" dusk="channel-ads-empty">
                        No ads yet. Every screen carrying this channel will start showing an ad the moment it is added here.
                    </p>
                </template>

                <template x-for="(ad, index) in ads" :key="ad.id">
                    {{-- An ad outside its dates is on no screen, and is drawn faded to say so. --}}
                    <div class="flex items-center gap-3 p-2 rounded-md border border-gray-200 dark:border-gray-700"
                         x-bind:class="ad.status !== 'running' ? 'opacity-60' : ''"
                         x-bind:dusk="'channel-ad-row-' + ad.id">
                        <span class="w-6 text-xs text-gray-400 text-center" x-text="index + 1"></span>

                        <div class="w-20 h-12 rounded overflow-hidden bg-gray-100 dark:bg-gray-700 flex items-center justify-center flex-shrink-0">
                            <template x-if="ad.thumbnail_url">
                                <img :src="ad.thumbnail_url" :alt="ad.title" class="w-full h-full object-cover">
                            </template>
                            <template x-if="!ad.thumbnail_url">
                                <span class="text-[10px] text-gray-400" x-text="ad.type"></span>
                            </template>
                        </div>

                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-800 dark:text-white truncate"
                               x-bind:dusk="'channel-ad-title-' + ad.id" x-text="ad.title"></p>
                            <p class="text-xs text-gray-400">
                                <span class="capitalize" x-text="ad.type"></span>
                                <span x-text="' · ' + datesLabel(ad)"></span>
                            </p>
                        </div>

                        <span class="text-sm text-gray-500 whitespace-nowrap" x-bind:dusk="'channel-ad-length-' + ad.id"
                              x-text="lengthLabel(ad)"></span>

                        <span x-bind:dusk="'channel-ad-status-' + ad.id"
                              x-bind:class="ad.status === 'running' ? 'badge-success' : 'badge-neutral'"
                              x-text="statusLabel(ad)"></span>

                        @can('channel-update')
                        <div class="flex items-center gap-1">
                            <button @click="openAdModal(ad)" x-bind:dusk="'edit-channel-ad-' + ad.id"
                                class="btn-row-neutral">Edit</button>
                            <button @click="move(index, -1)" x-bind:disabled="index === 0 || reordering"
                                x-bind:dusk="'channel-ad-up-' + ad.id"
                                class="btn-row-neutral disabled:opacity-30" title="Move up">&uarr;</button>
                            <button @click="move(index, 1)" x-bind:disabled="index === ads.length - 1 || reordering"
                                x-bind:dusk="'channel-ad-down-' + ad.id"
                                class="btn-row-neutral disabled:opacity-30" title="Move down">&darr;</button>
                            <button @click="confirmRemove(ad)" x-bind:dusk="'remove-channel-ad-' + ad.id"
                                class="btn-row-danger" title="Remove">&times;</button>
                        </div>
                        @endcan
                    </div>
                </template>
            </div>
        </div>

        {{-- Add / edit one ad --}}
        <x-modal name="channel-ad-modal" :show="false" maxWidth="lg">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100"
                    x-text="editingAd ? 'Edit Ad' : 'Add Ad'"></h2>

                <form @submit.prevent="saveAd" dusk="channel-ad-form" class="mt-4 space-y-4">
                    <x-crud.form-field label="File" field="file">
                        <input type="file" x-ref="fileInput" @change="onFileSelected($event)"
                               dusk="channel-ad-file" class="form-input"
                               accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm">
                    </x-crud.form-field>
                    <p class="text-xs text-gray-500 dark:text-gray-400 -mt-2">
                        JPG, PNG, GIF, WEBP, MP4 or WEBM &mdash; up to 250 MB. Ads play with no sound.
                        <span x-show="editingAd" x-cloak>Leave it empty to keep the current file.</span>
                        <span x-show="preparing" x-cloak>Reading the video&hellip;</span>
                    </p>

                    <x-crud.form-field label="Title" field="title">
                        <x-text-input x-model="form.title" dusk="channel-ad-title" class="block w-full"
                                      maxlength="255" autocomplete="off" placeholder="Taken from the file name when left blank" />
                    </x-crud.form-field>

                    {{-- Seconds belong to an IMAGE. A video plays to its own end, so for one
                         there is no field at all rather than one that does nothing. --}}
                    <div x-show="!isVideo()" x-cloak>
                        <x-crud.form-field label="Seconds on screen" field="seconds" :required="true">
                            <x-text-input type="number" min="1" x-bind:max="maxImageSeconds" x-model.number="form.seconds"
                                          dusk="channel-ad-seconds" class="block w-full" />
                        </x-crud.form-field>
                    </div>
                    <p x-show="isVideo()" x-cloak dusk="channel-ad-video-note" class="text-sm text-gray-500 dark:text-gray-400">
                        A video plays to its own end &mdash; there is nothing to set.
                    </p>

                    <div class="pt-2 border-t border-gray-200 dark:border-gray-700">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <x-crud.form-field label="Starts on" field="starts_on">
                                <x-text-input type="date" x-model="form.starts_on" dusk="channel-ad-starts-on" class="block w-full" />
                            </x-crud.form-field>
                            <x-crud.form-field label="Ends on" field="ends_on">
                                <x-text-input type="date" x-model="form.ends_on" dusk="channel-ad-ends-on" class="block w-full" />
                            </x-crud.form-field>
                        </div>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Both days included, on each screen's own calendar. Leave both blank to run until it is taken out.
                        </p>
                    </div>

                    <x-crud.form-actions savingVar="saving || preparing" cancelAction="closeAdModal()"
                                         dusk="channel-ad-save" cancelDusk="channel-ad-cancel" />
                </form>
            </div>
        </x-modal>

        {{-- Remove one ad --}}
        <x-crud.confirm-delete-modal
            name="confirm-channel-ad-deletion"
            entity="Ad"
            nameExpression="removingAd?.title"
            deleteAction="removeAd()"
            disabledVar="removing" />
    </div>
</x-app-layout>
