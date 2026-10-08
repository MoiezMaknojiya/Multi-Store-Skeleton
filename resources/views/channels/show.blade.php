{{-- The tab says the channel's name alone (the header may add "Paused"). --}}
<x-app-layout :title="$channel->name">
    <x-slot name="header">
        <div class="flex min-w-0 items-center gap-2">
            <a href="{{ route('channels.view') }}" dusk="back-to-channels" aria-label="Back to channels"
               class="inline-flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-200">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
            </a>
            <h1 class="page-title min-w-0 truncate" title="{{ $channel->name }}">{{ $channel->name }}</h1>
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
            // Above the organizations, the platform's channel may take any organization's files: the pickers ask which library.
            'libraries' => $libraries,
            'uploadsJoin' => $channel->isPlatformChannel() ? "the platform's media library" : 'your media library',
         ]) }})"
         x-on:modal-closing.window="onModalClosing($event)"
         class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

        {{-- The platform's channel seen from inside an organization: there to look at (owner, 2026-09-19). --}}
        @if ($readOnly)
            <p class="alert-info" dusk="channel-read-only-note">
                From the platform. Put it on a screen from that screen's Channels tab.
            </p>
        @endif

        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="text-subheading">Ads</h2>
                    <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-400" dusk="channel-ads-summary" x-text="summary()"></p>
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
                        No ads yet.
                    </p>
                </template>

                <template x-for="(ad, index) in ads" :key="ad.id">
                    {{-- An ad outside its dates is on no screen: its picture is drawn faded and its badge says why —
                         the row's buttons stay as they are, since they still work. --}}
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 p-2 rounded-md border border-gray-200 dark:border-gray-700"
                         x-bind:dusk="'channel-ad-row-' + ad.id">
                        <span class="w-6 text-xs text-gray-500 text-center dark:text-gray-400" x-text="index + 1"></span>

                        <div class="w-20 h-12 rounded overflow-hidden bg-gray-100 dark:bg-gray-700 flex items-center justify-center flex-shrink-0"
                             x-bind:class="ad.status !== 'running' ? 'opacity-50' : ''">
                            {{-- An upright picture is shown whole, not cut to its middle (docs/AD-BUILDER-SPEC.md §12). The
                                 title is beside it, so the picture says nothing more (alt=""). --}}
                            <template x-if="ad.thumbnail_url">
                                <img :src="ad.thumbnail_url" alt="" loading="lazy" class="w-full h-full"
                                     x-bind:class="ad.orientation === 'portrait' ? 'object-contain' : 'object-cover'">
                            </template>
                            <template x-if="!ad.thumbnail_url">
                                <span class="text-[10px] text-gray-500 dark:text-gray-300" x-text="typeLabel(ad.type)"></span>
                            </template>
                        </div>

                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-800 dark:text-white truncate" x-bind:title="ad.title"
                               x-bind:dusk="'channel-ad-title-' + ad.id" x-text="ad.title"></p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
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
                            <span class="text-sm text-gray-500 whitespace-nowrap dark:text-gray-400" x-bind:dusk="'channel-ad-length-' + ad.id"
                                  x-text="lengthLabel(ad)"></span>

                            <span x-bind:dusk="'channel-ad-status-' + ad.id"
                                  x-bind:class="statusBadge(ad)"
                                  x-bind:title="ad.status === 'draft' ? 'Unpublished in the Ad Builder: it plays again once it is published.' : ''"
                                  x-text="statusLabel(ad)"></span>

                            @if (! $readOnly)
                                @can('channel-update')
                                {{-- Each button names the ad it acts on, and the red × stands a little apart. --}}
                                <div class="flex items-center gap-2">
                                    <button @click="openAdModal(ad)" x-bind:dusk="'edit-channel-ad-' + ad.id"
                                        class="btn-row-neutral" x-bind:aria-label="'Edit ' + ad.title">Edit</button>
                                    <button @click="move(index, -1)" x-bind:disabled="index === 0 || reordering"
                                        x-bind:dusk="'channel-ad-up-' + ad.id"
                                        class="btn-row-neutral" title="Move up"
                                        x-bind:aria-label="'Move ' + ad.title + ' up'"><span aria-hidden="true">&uarr;</span></button>
                                    <button @click="move(index, 1)" x-bind:disabled="index === ads.length - 1 || reordering"
                                        x-bind:dusk="'channel-ad-down-' + ad.id"
                                        class="btn-row-neutral" title="Move down"
                                        x-bind:aria-label="'Move ' + ad.title + ' down'"><span aria-hidden="true">&darr;</span></button>
                                    <button @click="confirmRemove(ad)" x-bind:dusk="'remove-channel-ad-' + ad.id"
                                        class="btn-row-danger ml-2" title="Take it out of this channel"
                                        x-bind:aria-label="'Take ' + ad.title + ' out of this channel'"><span aria-hidden="true">&times;</span></button>
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
             joins the library first (docs/CHANNEL-CONTENT-SPEC.md §8c). Two columns from a laptop up (owner, 2026-10-01:
             "sari ads nahi dikhti ... srf 3 show ho rae ha"; of the two pictures shown, 2026-10-02: "doosri wali theek
             ha"): the files on the left, as many as the window's height takes, and what is said of the one chosen on
             the right. On a phone the same, the fields under the files. --}}
        <x-modal name="channel-ad-modal" :show="false" maxWidth="6xl" persistent>
            {{-- novalidate: the seconds field's own min and max would stop the save with the browser's bubble
                 before saveAd could say it under the field, as every form here does (validate.js).
                 From a laptop up the dialog is never taller than the window: the files scroll inside it, and Cancel
                 and Save stay in sight. While a library is open it keeps one height — the window's, up to 60rem —
                 however few files it lists, so it does not jump as they arrive. --}}
            <form @submit.prevent="saveAd" novalidate dusk="channel-ad-form"
                  x-bind:data-picking="source === 'library' || source === 'ads' ? 'true' : 'false'"
                  class="flex flex-col lg:max-h-[calc(100dvh-2rem)] lg:data-[picking=true]:h-[min(calc(100dvh-2rem),60rem)]">
                <h2 class="px-6 pt-6 text-lg font-medium text-gray-900 dark:text-gray-100"
                    x-text="editingAd ? 'Edit Ad' : 'Add Ad'"></h2>

                <div class="mt-4 flex flex-col gap-6 px-6 lg:min-h-0 lg:flex-1 lg:flex-row">
                    {{-- Which file. The four pixels at its left keep a focus ring whole: a column that may scroll
                         cuts whatever is drawn outside it. --}}
                    <div class="flex min-w-0 flex-col gap-3 lg:-ml-1 lg:flex-1 lg:overflow-y-auto lg:border-r lg:border-gray-200 lg:pl-1 lg:pr-6 dark:lg:border-gray-700"
                         dusk="channel-ad-files">
                        {{-- Where the file comes from: a question, then its answers drawn like the panel's tabs — the
                             dialog's one blue button stays Save. --}}
                        <div>
                            <p id="channel-ad-source-label" class="form-label">Where does the ad come from?</p>
                            <div class="mt-1 flex flex-wrap gap-x-6 border-b border-gray-200 dark:border-gray-700" role="group" aria-labelledby="channel-ad-source-label">
                                {{-- The chosen way is pressed, and its colours follow aria-pressed — written on the tab,
                                     never only in a binding (a border with no colour of its own is drawn black). --}}
                                <template x-if="editingAd">
                                    <button type="button" @click="setSource('keep')" dusk="channel-ad-source-keep"
                                            class="tab-link pb-2" x-bind:aria-pressed="source === 'keep' ? 'true' : 'false'">Keep This File</button>
                                </template>
                                <button type="button" @click="setSource('library')" dusk="channel-ad-source-library"
                                        class="tab-link pb-2" x-bind:aria-pressed="source === 'library' ? 'true' : 'false'">Media Library</button>
                                <button type="button" @click="setSource('ads')" dusk="channel-ad-source-ads"
                                        class="tab-link pb-2" x-bind:aria-pressed="source === 'ads' ? 'true' : 'false'">Ad Builder</button>
                                <button type="button" @click="setSource('upload')" dusk="channel-ad-source-upload"
                                        class="tab-link pb-2" x-bind:aria-pressed="source === 'upload' ? 'true' : 'false'">Upload</button>
                            </div>
                        </div>

                        {{-- Keeping the file it has: shown, so nobody has to remember what it was — a row on a phone,
                             the picture itself where there is a column for it. --}}
                        <div x-show="source === 'keep'" x-cloak dusk="channel-ad-kept"
                             class="flex items-center gap-3 rounded-md border border-gray-200 p-2 dark:border-gray-700 lg:max-w-md lg:shrink-0 lg:flex-col lg:items-stretch lg:gap-0 lg:overflow-hidden lg:p-0">
                            <div class="aspect-video w-24 shrink-0 overflow-hidden rounded bg-gray-100 dark:bg-gray-700 lg:w-full lg:rounded-none">
                                <template x-if="editingAd?.thumbnail_url">
                                    <img :src="editingAd?.thumbnail_url" alt="" class="h-full w-full object-contain">
                                </template>
                            </div>
                            <div class="min-w-0 lg:px-3 lg:py-2">
                                <p class="truncate text-sm font-medium text-gray-800 dark:text-gray-100" x-bind:title="editingAd?.title" x-text="editingAd?.title"></p>
                                <p class="text-xs text-gray-500 dark:text-gray-400"
                                   x-text="editingAd ? typeLabel(editingAd.type) + (editingAd.orientation === 'portrait' ? ' · portrait' : '') : ''"></p>
                            </div>
                        </div>

                        {{-- The library, or the Ad Builder's published ads (its pages live in the same library). --}}
                        <div x-show="source === 'library' || source === 'ads'" x-cloak class="flex flex-col gap-3 lg:min-h-0 lg:flex-1">
                            <div class="flex flex-wrap gap-2">
                                {{-- Above the organizations the platform's channel may show any organization's file: which library.
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
                                {{-- Enter here searches at once. Left to the browser it would send the form this box sits in:
                                     the ad was saved with whatever tile was picked, by somebody who only meant to search. --}}
                                <input type="search" x-model="picker.search" @input.debounce.300ms="loadPicker()"
                                       @keydown.enter.prevent="loadPicker()"
                                       dusk="channel-ad-picker-search" class="form-input min-w-0 flex-1" maxlength="255"
                                       x-bind:placeholder="source === 'ads' ? 'Search ads…' : 'Search files…'" aria-label="Search">
                            </div>

                            {{-- The picker leaves them out rather than offering and refusing them (owner, 2026-09-26), and says how
                                 many it left out while others are listed (with none listed, the empty line says it). --}}
                            <p x-show="picker.onPlaylists > 0 && picker.items.length > 0" x-cloak class="text-xs text-muted-soft" dusk="channel-ad-picker-note"
                               x-text="onPlaylistsText() + ', so not listed: nothing plays twice.'"></p>

                            <p x-show="picker.loading && picker.items.length === 0" x-cloak class="py-6 text-center text-sm text-muted-soft">Loading…</p>

                            <p x-show="!picker.loading && picker.items.length === 0" x-cloak dusk="channel-ad-picker-empty"
                               class="py-6 text-center text-sm text-gray-500 dark:text-gray-400" x-text="pickerEmptyText()"></p>

                            {{-- As many tiles across as the column takes (two on a phone), each row as tall as its tiles:
                                 a grid given a height would otherwise squeeze its rows into it. On a phone it scrolls
                                 inside 20rem; from a laptop up it takes what is left of the dialog's height.
                                 Nothing chosen: the picker is outlined in red, as a field with an error is. --}}
                            <div class="grid max-h-80 auto-rows-max grid-cols-2 gap-3 overflow-y-auto pr-1 sm:grid-cols-[repeat(auto-fill,minmax(10rem,1fr))] lg:max-h-none lg:min-h-0 lg:flex-1"
                                 dusk="channel-ad-picker"
                                 x-bind:class="formErrors.media_id ? 'rounded-md ring-2 ring-red-500' : ''">
                                {{-- The chosen tile says so with its own 2 px border and a tick — never a ring, a shadow
                                     drawn outside the tile that the scrolling grid cut off at its edges. The keyboard's
                                     ring is drawn inside (ring-inset) for the same reason. --}}
                                <template x-for="item in picker.items" :key="item.id">
                                    <button type="button" @click="pick(item)" x-bind:dusk="'channel-ad-pick-' + item.id"
                                            x-bind:aria-pressed="chosen?.id === item.id ? 'true' : 'false'"
                                            class="relative overflow-hidden rounded-md border-2 border-gray-200 text-left transition-colors not-aria-pressed:hover:border-gray-400 aria-pressed:border-blue-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500 dark:border-gray-700 dark:not-aria-pressed:hover:border-gray-500 dark:aria-pressed:border-blue-400">
                                        <span x-show="chosen?.id === item.id" x-cloak aria-hidden="true"
                                              class="absolute right-1.5 top-1.5 z-10 flex h-5 w-5 items-center justify-center rounded-full bg-blue-600 text-white shadow-sm">
                                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                        </span>
                                        <div class="aspect-video bg-gray-100 dark:bg-gray-700">
                                            <template x-if="item.thumbnail_url">
                                                <img :src="item.thumbnail_url" alt="" class="h-full w-full" loading="lazy"
                                                     x-bind:class="item.orientation === 'portrait' ? 'object-contain' : 'object-cover'">
                                            </template>
                                        </div>
                                        <div class="px-2 py-1.5">
                                            <p class="truncate text-xs font-medium text-gray-800 dark:text-gray-100" x-bind:title="item.title" x-text="item.title"></p>
                                            <p class="text-[11px] text-gray-500 dark:text-gray-400"
                                               x-text="typeLabel(item.type) + (item.orientation === 'portrait' ? ' · portrait' : '')"></p>
                                        </div>
                                    </button>
                                </template>

                                {{-- Under the last tile, where somebody who has seen them all is looking. --}}
                                <div x-show="picker.page < picker.lastPage" x-cloak class="col-span-full pb-1 text-center">
                                    <button type="button" @click="loadPicker({ more: true })" x-bind:disabled="picker.loading"
                                            dusk="channel-ad-picker-more" class="btn-secondary">Load More</button>
                                </div>
                            </div>

                            <template x-if="formErrors.media_id">
                                <p class="form-error" role="alert" x-text="formErrors.media_id[0]"></p>
                            </template>
                        </div>

                        {{-- A fresh file: it joins the channel's library first, like an upload on the Media page. It goes up in
                             chunks the moment it is chosen (docs/UPLOADS-SPEC.md), and Save waits until it has arrived. The page
                             listens here, not on the box: an expression on the box runs with the box's own `this`. --}}
                        <div x-show="source === 'upload'" x-cloak x-ref="adUpload"
                             x-on:upload-picked="onPicked($event.detail)"
                             x-on:upload-ready="onUploadReady($event.detail)"
                             x-on:upload-cleared="onUploadCleared()"
                             x-on:upload-busy="uploading = $event.detail.busy">
                            <x-crud.form-field label="File" field="file" :required="true">
                                <x-upload-dropzone purpose="channel" mode="form" dusk="channel-ad"
                                    context="{ channel: String(channelId), storage: storage }"
                                    hint="JPG, PNG, GIF, WEBP, MP4 or WEBM — up to 250 MB, a video 5 minutes at most." />
                            </x-crud.form-field>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" dusk="channel-ad-upload-note">
                                Plays muted. It joins <span x-text="uploadsJoin"></span>.
                            </p>
                        </div>
                    </div>

                    {{-- What is said of it. A column of its own from a laptop up, and it scrolls only in a window too
                         short for it; the pixels at its sides keep a field's focus ring whole there. --}}
                    <div class="flex min-w-0 flex-col gap-4 lg:-mx-0.5 lg:w-89 lg:shrink-0 lg:overflow-y-auto lg:px-0.5" dusk="channel-ad-fields">
                        {{-- Which tile is picked: the tile itself may have scrolled away, or a search may have left it
                             out. A row — and, in a window tall enough for it above the fields, the picture itself, which
                             gives its height up first when an error takes a line. --}}
                        <div x-show="source === 'library' || source === 'ads'" x-cloak dusk="channel-ad-chosen"
                             class="flex items-center gap-3 rounded-md border border-gray-200 p-2 dark:border-gray-700 lg:tall:min-h-28 lg:tall:flex-col lg:tall:items-stretch lg:tall:gap-0 lg:tall:overflow-hidden lg:tall:p-0">
                            <div class="aspect-video w-24 shrink-0 overflow-hidden rounded bg-gray-100 dark:bg-gray-700 lg:tall:min-h-0 lg:tall:w-full lg:tall:shrink lg:tall:rounded-none">
                                <template x-if="chosen?.thumbnail_url">
                                    <img :src="chosen?.thumbnail_url" alt="" class="h-full w-full object-contain">
                                </template>
                            </div>
                            <div class="min-w-0 lg:tall:min-h-13 lg:tall:shrink-0 lg:tall:px-3 lg:tall:py-2">
                                <p class="truncate text-sm" x-bind:title="chosen?.title"
                                   x-bind:class="chosen ? 'font-medium text-gray-800 dark:text-gray-100' : 'text-gray-500 dark:text-gray-400'"
                                   x-text="chosenTitle()"></p>
                                <p x-show="chosen" class="text-xs text-gray-500 dark:text-gray-400"
                                   x-text="chosen ? typeLabel(chosen.type) + (chosen.orientation === 'portrait' ? ' · portrait' : '') + ' · chosen' : ''"></p>
                            </div>
                        </div>

                        <x-crud.form-field label="Title" field="title">
                            <x-text-input x-model="form.title" dusk="channel-ad-title"
                                          maxlength="255" autocomplete="off" placeholder="Taken from the file when left blank" />
                        </x-crud.form-field>

                        {{-- Seconds belong to a picture. A video plays to its own end, and an Ad Builder page for the
                             length its design says, so for them there is no field at all rather than one that does
                             nothing. --}}
                        <div x-show="!runsOwnLength()" x-cloak>
                            <x-crud.form-field label="Seconds on screen" field="seconds" :required="true">
                                <x-text-input type="number" min="{{ \App\Models\PlaylistItem::MIN_IMAGE_SECONDS }}" x-bind:max="maxImageSeconds" x-model.number="form.seconds"
                                              dusk="channel-ad-seconds" />
                            </x-crud.form-field>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" x-text="secondsHint()"></p>
                        </div>
                        <p x-show="runsOwnLength()" x-cloak dusk="channel-ad-video-note" class="text-sm text-gray-500 dark:text-gray-400"
                           x-text="ownLengthNote()"></p>

                        {{-- The two dates on one line wherever there is room for both: it is what lets a small laptop
                             show every field without scrolling. --}}
                        <div>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <x-crud.form-field label="Starts on" field="starts_on">
                                    <x-text-input type="date" x-model="form.starts_on" dusk="channel-ad-starts-on" />
                                </x-crud.form-field>
                                <x-crud.form-field label="Ends on" field="ends_on">
                                    <x-text-input type="date" x-model="form.ends_on" dusk="channel-ad-ends-on" />
                                </x-crud.form-field>
                            </div>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Leave both empty to run with no end.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="mt-4 border-t border-gray-200 px-6 pb-6 dark:border-gray-700">
                    {{-- Cancel while a file is still going up asks first: closing gives the upload up. --}}
                    <div x-show="confirmingClose && uploading" x-cloak role="alert" class="alert-warning mt-4 flex flex-wrap items-center justify-between gap-3" dusk="channel-ad-upload-still-going">
                        <span>The file is still uploading. Stop it and close?</span>
                        <span class="flex flex-wrap gap-2">
                            <button type="button" class="btn-secondary" @click="confirmingClose = false">Keep Uploading</button>
                            <button type="button" class="btn-danger" @click="closeAdModal(true)" dusk="channel-ad-stop-and-close">Stop and Close</button>
                        </span>
                    </div>

                    <x-crud.form-actions savingVar="saving || (uploading && source === 'upload')" cancelAction="closeAdModal()"
                                         dusk="channel-ad-save" cancelDusk="channel-ad-cancel" />
                </div>
            </form>
        </x-modal>

        {{-- Take one ad out. Its file stays in its library. --}}
        <x-crud.confirm-delete-modal
            name="confirm-channel-ad-deletion"
            entity="Ad"
            title="Remove from channel"
            question="Take this ad out of the channel:"
            confirmLabel="Remove"
            nameExpression="removingAd?.title"
            deleteAction="removeAd()"
            disabledVar="removing">
            <x-slot name="note">Its file stays in the media library.</x-slot>
        </x-crud.confirm-delete-modal>
        @endif
    </div>
</x-app-layout>
