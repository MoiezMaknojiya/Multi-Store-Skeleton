<x-app-layout>
    <x-slot name="header">
        <div class="flex min-w-0 items-center gap-3">
            <a href="{{ route('channels.view') }}" class="flex-shrink-0 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200" dusk="back-to-channels"
               aria-label="Back to channels">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
            </a>
            <h2 class="min-w-0 truncate font-semibold text-xl text-gray-800 dark:text-white leading-tight" title="{{ $channel->name }}">{{ $channel->name }}</h2>
            @unless ($channel->is_active)
                <span class="badge-neutral flex-shrink-0">Paused</span>
            @endunless
        </div>
    </x-slot>

    {{-- Js::from, not @json: @json leaves its quotes raw and the first string would
         close this attribute (see .claude/rules/02-project-conventions.md). --}}
    <div x-data="channelAds({{ Js::from([
            'channelId' => $channel->id,
            'maxImageSeconds' => $maxImageSeconds,
            'adsPerPass' => $channel->ads_per_pass,
            // Above the stores, the platform's channel may take any shop's files: the pickers ask which library.
            'libraries' => $libraries,
            'uploadsJoin' => $channel->isPlatformChannel() ? "the platform's media library" : 'your media library',
         ]) }})"
         class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

        {{-- The platform's channel seen from inside a shop: there to look at (owner, 2026-09-19). --}}
        @if ($readOnly)
            <p class="rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-200"
               dusk="channel-read-only-note">
                This channel comes from the platform. You can add it to any of your screens from that screen's Channels box.
            </p>
        @endif

        <div class="card">
            <div class="card-header">
                <div>
                    <h3 class="text-subheading">Ads</h3>
                    <p class="text-xs text-gray-400 mt-0.5" dusk="channel-ads-summary" x-text="summary()"></p>
                </div>
                @if (! $readOnly)
                    @can('channel-update')
                    <x-crud.add-button label="Add Ad" @click="openAdModal()" dusk="add-channel-ad" />
                    @endcan
                @endif
            </div>

            {{-- A container, so a row's controls move under its title when the card is too narrow for
                 both — on a phone they ran off the edge of the card, and the arrows and × with them. The
                 same pattern as a screen's playlist lines. --}}
            <div class="@container p-4 space-y-2">
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
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 p-2 rounded-md border border-gray-200 dark:border-gray-700"
                         x-bind:class="ad.status !== 'running' ? 'opacity-60' : ''"
                         x-bind:dusk="'channel-ad-row-' + ad.id">
                        <span class="w-6 text-xs text-gray-400 text-center" x-text="index + 1"></span>

                        <div class="w-20 h-12 rounded overflow-hidden bg-gray-100 dark:bg-gray-700 flex items-center justify-center flex-shrink-0">
                            <template x-if="ad.thumbnail_url">
                                <img :src="ad.thumbnail_url" :alt="ad.title" class="w-full h-full object-cover">
                            </template>
                            <template x-if="!ad.thumbnail_url">
                                <span class="text-[10px] text-gray-400" x-text="typeLabel(ad.type)"></span>
                            </template>
                        </div>

                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-800 dark:text-white truncate"
                               x-bind:dusk="'channel-ad-title-' + ad.id" x-text="ad.title"></p>
                            <p class="text-xs text-gray-400">
                                <span x-text="typeLabel(ad.type)"></span>
                                {{-- A portrait file on a channel plays with bars on a landscape screen (§12): said here. --}}
                                <span x-show="ad.orientation === 'portrait'" x-cloak x-bind:dusk="'channel-ad-orientation-' + ad.id">&middot; portrait</span>
                                {{-- Whose library the file lives in. --}}
                                <span x-show="ad.library" x-bind:dusk="'channel-ad-library-' + ad.id" x-text="' · ' + ad.library"></span>
                                <span x-text="' · ' + datesLabel(ad)"></span>
                            </p>
                        </div>

                        {{-- The ad's length, state and controls: beside the title when there is room, on a
                             line of their own under it when there is not. --}}
                        <div class="flex w-full flex-wrap items-center justify-end gap-x-3 gap-y-2 @xl:w-auto">
                            <span class="text-sm text-gray-500 whitespace-nowrap" x-bind:dusk="'channel-ad-length-' + ad.id"
                                  x-text="lengthLabel(ad)"></span>

                            <span x-bind:dusk="'channel-ad-status-' + ad.id"
                                  x-bind:class="ad.status === 'running' ? 'badge-success' : 'badge-neutral'"
                                  x-bind:title="ad.status === 'draft' ? 'Unpublished in the Ad Builder: it plays again once it is published.' : ''"
                                  x-text="statusLabel(ad)"></span>

                            @if (! $readOnly)
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
                                        class="btn-row-danger" title="Take it out of this channel">&times;</button>
                                </div>
                                @endcan
                            @endif
                        </div>
                    </div>
                </template>
            </div>
        </div>

        @if (! $readOnly)
        {{-- Add / edit one ad: its file comes from the library, from the Ad Builder, or a fresh upload that
             joins the library first (docs/CHANNEL-CONTENT-SPEC.md). --}}
        <x-modal name="channel-ad-modal" :show="false" maxWidth="2xl">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100"
                    x-text="editingAd ? 'Edit Ad' : 'Add Ad'"></h2>

                <form @submit.prevent="saveAd" dusk="channel-ad-form" class="mt-4 space-y-4">
                    {{-- Where the file comes from. --}}
                    <div class="flex flex-wrap gap-2" role="group" aria-label="Where the ad comes from">
                        <template x-if="editingAd">
                            <button type="button" @click="setSource('keep')" dusk="channel-ad-source-keep"
                                    x-bind:class="source === 'keep' ? 'btn-primary' : 'btn-secondary'"
                                    x-bind:aria-pressed="source === 'keep'">Keep this file</button>
                        </template>
                        <button type="button" @click="setSource('library')" dusk="channel-ad-source-library"
                                x-bind:class="source === 'library' ? 'btn-primary' : 'btn-secondary'"
                                x-bind:aria-pressed="source === 'library'">Media library</button>
                        <button type="button" @click="setSource('ads')" dusk="channel-ad-source-ads"
                                x-bind:class="source === 'ads' ? 'btn-primary' : 'btn-secondary'"
                                x-bind:aria-pressed="source === 'ads'">Ad Builder</button>
                        <button type="button" @click="setSource('upload')" dusk="channel-ad-source-upload"
                                x-bind:class="source === 'upload' ? 'btn-primary' : 'btn-secondary'"
                                x-bind:aria-pressed="source === 'upload'">Upload</button>
                    </div>

                    {{-- Keeping the file it has: shown, so nobody has to remember what it was. --}}
                    <div x-show="source === 'keep'" x-cloak class="flex items-center gap-3 rounded-md border border-gray-200 p-2 dark:border-gray-700">
                        <div class="h-12 w-20 flex-shrink-0 overflow-hidden rounded bg-gray-100 dark:bg-gray-700">
                            <template x-if="editingAd?.thumbnail_url">
                                <img :src="editingAd?.thumbnail_url" alt="" class="h-full w-full object-cover">
                            </template>
                        </div>
                        <p class="min-w-0 truncate text-sm text-gray-700 dark:text-gray-200" x-text="editingAd?.title"></p>
                    </div>

                    {{-- The library, or the Ad Builder's published ads (its pages live in the same library). --}}
                    <div x-show="source === 'library' || source === 'ads'" x-cloak class="space-y-3">
                        <div class="flex flex-wrap gap-2">
                            {{-- Above the stores the platform's channel may show any shop's file: which library.
                                 The width sits on a wrapper — .form-select's own width outranks a utility. --}}
                            <template x-if="libraries.length > 0">
                                <div class="w-full sm:w-52">
                                    <select x-model="picker.library" @change="loadPicker()" dusk="channel-ad-library"
                                            class="form-select" aria-label="Library">
                                        <option value="platform">Platform library</option>
                                        <template x-for="library in libraries" :key="library.id">
                                            <option :value="String(library.id)" x-text="library.name"></option>
                                        </template>
                                    </select>
                                </div>
                            </template>
                            <input type="search" x-model="picker.search" @input.debounce.300ms="loadPicker()"
                                   dusk="channel-ad-picker-search" class="form-input min-w-0 flex-1" maxlength="255"
                                   x-bind:placeholder="source === 'ads' ? 'Search ads…' : 'Search files…'" aria-label="Search">
                        </div>

                        <p x-show="picker.loading && picker.items.length === 0" x-cloak class="py-6 text-center text-sm text-muted-soft">Loading…</p>

                        <p x-show="!picker.loading && picker.items.length === 0" x-cloak dusk="channel-ad-picker-empty"
                           class="py-6 text-center text-sm text-gray-500 dark:text-gray-400" x-text="pickerEmptyText()"></p>

                        <div class="grid max-h-80 grid-cols-2 gap-3 overflow-y-auto pr-1 sm:grid-cols-3" dusk="channel-ad-picker">
                            <template x-for="item in picker.items" :key="item.id">
                                <button type="button" @click="pick(item)" x-bind:dusk="'channel-ad-pick-' + item.id"
                                        x-bind:aria-pressed="chosen?.id === item.id"
                                        class="overflow-hidden rounded-md border text-left transition"
                                        x-bind:class="chosen?.id === item.id
                                            ? 'border-blue-500 ring-2 ring-blue-500'
                                            : 'border-gray-200 hover:border-gray-400 dark:border-gray-700'">
                                    <div class="aspect-video bg-gray-100 dark:bg-gray-700">
                                        <template x-if="item.thumbnail_url">
                                            <img :src="item.thumbnail_url" alt="" class="h-full w-full object-cover" loading="lazy">
                                        </template>
                                    </div>
                                    <div class="px-2 py-1.5">
                                        <p class="truncate text-xs font-medium text-gray-800 dark:text-gray-100" x-text="item.title"></p>
                                        <p class="text-[11px] text-gray-400"
                                           x-text="typeLabel(item.type) + (item.orientation === 'portrait' ? ' · portrait' : '')"></p>
                                    </div>
                                </button>
                            </template>
                        </div>

                        <div x-show="picker.page < picker.lastPage" x-cloak class="text-center">
                            <button type="button" @click="loadPicker({ more: true })" x-bind:disabled="picker.loading"
                                    dusk="channel-ad-picker-more" class="btn-secondary">Load more</button>
                        </div>

                        <template x-if="formErrors.media_id">
                            <p class="form-error" x-text="formErrors.media_id[0]"></p>
                        </template>
                    </div>

                    {{-- A fresh file: it joins the channel's library first, like an upload on the Media page. --}}
                    <div x-show="source === 'upload'" x-cloak>
                        <x-crud.form-field label="File" field="file" :required="true">
                            <input type="file" x-ref="fileInput" @change="onFileSelected($event)"
                                   dusk="channel-ad-file" class="form-input"
                                   accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm">
                        </x-crud.form-field>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" dusk="channel-ad-upload-note">
                            JPG, PNG, GIF, WEBP, MP4 or WEBM &mdash; up to 250 MB. Ads play with no sound.
                            It joins <span x-text="uploadsJoin"></span>.
                            <span x-show="preparing" x-cloak>Reading the video&hellip;</span>
                        </p>
                    </div>

                    <x-crud.form-field label="Title" field="title">
                        <x-text-input x-model="form.title" dusk="channel-ad-title" class="block w-full"
                                      maxlength="255" autocomplete="off" placeholder="Taken from the file when left blank" />
                    </x-crud.form-field>

                    {{-- Seconds belong to an image or an ad page. A video plays to its own end, so for one
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

        {{-- Take one ad out. Its file stays in its library. --}}
        <x-crud.confirm-delete-modal
            name="confirm-channel-ad-deletion"
            entity="Ad"
            nameExpression="removingAd?.title"
            deleteAction="removeAd()"
            disabledVar="removing" />
        @endif
    </div>
</x-app-layout>
