@php
    // The repeat picker reads "Repeat every [2] [week(s)]", so it names the UNIT. The model's own labels
    // (ScheduleRule::TYPES) begin with "Every" and would say it twice. A type added there later still
    // shows, under its own label, until it is given a unit here.
    $repeatUnits = [
        'daily' => 'day(s)',
        'weekly' => 'week(s)',
        'monthly_day' => 'month(s), on a date',
        'monthly_weekday' => 'month(s), on a weekday',
        'yearly' => 'year(s)',
    ];
@endphp

{{-- The tab says the screen's name alone (the header also shows which way it is mounted). --}}
<x-app-layout :title="$screen->name">
    <x-slot name="header">
        <div class="flex min-w-0 items-center gap-2">
            <a href="{{ route('screens.view') }}" dusk="back-to-screens" aria-label="Back to screens"
               class="inline-flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-200">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
            </a>
            <h1 class="page-title min-w-0 truncate" title="{{ $screen->name }}">{{ $screen->name }}</h1>
            <span class="hidden flex-shrink-0 text-sm text-gray-500 sm:inline dark:text-gray-400">{{ $orientations[$screen->orientation] ?? $screen->orientation }}</span>
        </div>
    </x-slot>

    {{-- Js::from, not @json: @json leaves its quotes raw and the first string would
         close this attribute (see .claude/rules/02-project-conventions.md). --}}
    <div x-data="screenPlaylist({{ Js::from([
            'screenId' => $screen->id,
            // Which way the panel is mounted, so a line or a picker row the other way round can say it
            // will play with bars (docs/AD-BUILDER-SPEC.md §12) — the organization's to notice, not a refusal.
            'screenOrientation' => str_starts_with($screen->orientation, 'portrait') ? 'portrait' : 'landscape',
            'canEdit' => auth()->user()->can('screen-playlist'),
            'weekdays' => $weekdays,
            'ordinals' => $ordinals,
         ]) }})"
         class="max-w-7xl mx-auto sm:px-6 lg:px-8">

        {{-- Two columns only when there is a second one: the library and the channels are drawn for
             someone who may change the playlist, and anybody else gets the playlist at full width. --}}
        <div @class(['grid grid-cols-1 gap-6', 'lg:grid-cols-2' => auth()->user()->can('screen-playlist')])>

            {{-- ── Playlist ─────────────────────────────────────────────── --}}
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2 class="text-subheading">Playlist</h2>
                        <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-400" dusk="playlist-summary" x-text="summary()"></p>
                    </div>
                    @can('screen-playlist')
                    <div class="flex flex-wrap items-center gap-2">
                        {{-- Edits stay on this page until Save Changes: said beside the button, and asked about
                             before the page is left (the browser's own "Leave site?"). --}}
                        <span x-show="dirty && !saving" x-cloak role="status" dusk="playlist-unsaved"
                              class="text-xs font-medium text-amber-700 dark:text-amber-400">Unsaved changes</span>
                        {{-- Copying REPLACES the target's playlist, so it is deliberately
                             the quieter button of the two and asks before it acts. It copies the
                             SAVED playlist, so it waits until what is on the page has been saved. --}}
                        <button @click="openCopyModal()" dusk="playlist-copy-open" class="btn-secondary"
                            x-bind:disabled="dirty || saving"
                            x-bind:title="dirty ? 'Save your changes first: copying sends the saved playlist' : ''">
                            Copy to Other Screens
                        </button>
                        <button @click="save()" x-bind:disabled="!dirty || saving" dusk="playlist-save" class="btn-primary">
                            <x-spinner x-show="saving" x-cloak />
                            <span x-text="saving ? 'Saving...' : 'Save Changes'"></span>
                        </button>
                    </div>
                    @endcan
                </div>

                {{-- A container, so a line's controls move under its title when the column is too
                     narrow for both — at 1280 px they once squeezed every title to nothing. --}}
                <div class="@container p-4 space-y-2">
                    <template x-if="loading">
                        <p class="text-center text-muted-soft py-6">Loading...</p>
                    </template>

                    <template x-if="!loading && items.length === 0">
                        <p class="text-center text-muted-soft py-10" dusk="playlist-empty">
                            Nothing here yet.
                            @can('screen-playlist')
                                Add files or a channel from the lists.
                            @endcan
                        </p>
                    </template>

                    {{-- Platform Channels locked (docs/BILLING-SPEC.md §6, owner 2026-10-08): their lines stay, and play nothing until
                         unlocked again — said once, above the lines. --}}
                    <div x-show="lockedLines().length > 0" x-cloak class="alert-warning flex items-start gap-3" dusk="playlist-locked">
                        <x-icon name="lock-closed" class="mt-0.5 h-4 w-4 shrink-0" />
                        <div>
                            <p class="font-semibold"><span x-text="lockedNames()"></span> not playing: Platform Channels are locked for {{ $screen->organization?->name }}.</p>
                            <p class="mt-0.5" x-text="lockedLines().length === 1
                                ? 'Its line stays here. Unlock Platform Channels and it plays again by itself{{ auth()->user()->can('screen-playlist') ? ', or take it out' : '' }}.'
                                : 'Their lines stay here. Unlock Platform Channels and they play again by themselves{{ auth()->user()->can('screen-playlist') ? ', or take them out' : '' }}.'"></p>
                        </div>
                    </div>

                    <template x-for="(item, index) in items" :key="item.key">
                        {{-- A line that is not playing — an Ad Builder page taken off the screens (unpublished) — is drawn faded; a
                             locked platform channel's in the lock's amber. --}}
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 p-2 rounded-md border"
                             x-bind:class="[item.is_draft ? 'opacity-60' : '', item.locked ? 'border-amber-200 bg-amber-50 dark:border-amber-500/40 dark:bg-amber-500/10' : 'border-gray-200 dark:border-gray-700']"
                             x-bind:dusk="'playlist-line-' + index">
                            <span class="w-6 text-xs text-gray-500 text-center dark:text-gray-400" x-text="index + 1"></span>

                            <div class="w-20 h-12 rounded overflow-hidden bg-gray-100 dark:bg-gray-700 flex items-center justify-center flex-shrink-0"
                                 x-bind:class="item.locked ? 'opacity-60' : ''">
                                {{-- An upright picture is shown whole, not cut to its middle (docs/AD-BUILDER-SPEC.md §12). --}}
                                <template x-if="item.thumbnail_url">
                                    <img :src="item.thumbnail_url" :alt="item.title" class="w-full h-full"
                                         x-bind:class="(item.thumbnail_orientation ?? item.orientation) === 'portrait' ? 'object-contain' : 'object-cover'">
                                </template>
                                <template x-if="!item.thumbnail_url">
                                    <span class="text-[10px] text-gray-500 dark:text-gray-400" x-text="typeLabel(item)"></span>
                                </template>
                            </div>

                            <div class="flex-1 min-w-0">
                                <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 min-w-0 text-sm font-medium text-gray-800 dark:text-white">
                                    <span class="truncate" x-text="item.title"></span>
                                    {{-- A platform file from before the Content Library became the organization's own (docs/BILLING-SPEC.md §5),
                                         left where no copy could be made: said, so the save's refusal names something the page shows. --}}
                                    <span x-show="item.from_platform" x-cloak class="badge-neutral shrink-0"
                                          x-bind:dusk="'playlist-platform-' + index">From the platform</span>
                                    <span x-show="item.locked" x-cloak class="badge-warning inline-flex shrink-0 items-center gap-1"
                                          x-bind:dusk="'playlist-premium-' + index"><x-icon name="lock-closed" class="h-3 w-3" /> Premium</span>
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    <span x-text="typeLabel(item)"></span>
                                    {{-- It keeps its place, and plays again once the ad is published. --}}
                                    <span x-show="item.is_draft" x-cloak class="text-amber-700 dark:text-amber-400"
                                          x-bind:dusk="'playlist-draft-' + index">&middot; Draft &mdash; not playing until it is published in the Ad Builder</span>
                                    {{-- A file the other way round from the screen plays with bars: said, not refused. --}}
                                    <span x-show="orientationNote(item)" x-cloak class="text-amber-700 dark:text-amber-400"
                                          x-bind:dusk="'playlist-orientation-' + index" x-text="' · ' + orientationNote(item)"></span>
                                    {{-- A channel line says what it will actually play — and says so
                                         plainly when that is nothing: paused, or no ads running. --}}
                                    <template x-if="item.type === 'channel'">
                                        <span x-bind:dusk="'playlist-channel-info-' + index"
                                              x-bind:class="item.locked || channelWarning(item) ? 'text-amber-700 dark:text-amber-400' : ''"
                                              x-text="' · ' + (item.locked ? 'locked: not playing on the screen' : channelInfo(item))"></span>
                                    </template>
                                </p>
                                {{-- Only shown when the item HAS a schedule. An item
                                     with none plays whenever the screen is on, and
                                     saying so on every row would be noise. --}}
                                <p x-show="(item.rules ?? []).length > 0" x-cloak
                                   class="flex items-center gap-1 text-xs text-blue-600 dark:text-blue-400 truncate"
                                   x-bind:dusk="'playlist-schedule-badge-' + index"
                                   x-bind:title="scheduleBadge(item)">
                                    <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <span class="sr-only">Schedule:</span>
                                    <span class="truncate" x-text="scheduleBadge(item)"></span>
                                </p>
                                {{-- Every schedule of the line is over, by the screen's own calendar: it plays no more. --}}
                                <p x-show="scheduleEnded(item)" x-cloak class="mt-0.5">
                                    <span class="badge-warning" x-bind:dusk="'playlist-ended-' + index"
                                          title="Its schedule is over, so it no longer plays">Ended</span>
                                </p>
                            </div>

                            {{-- The line's own controls: beside the title when there is room, on a
                                 line of their own under it when there is not. --}}
                            <div class="flex w-full flex-wrap items-center justify-end gap-x-3 gap-y-2 @xl:w-auto">
                                {{-- Images and ad pages are timed; a video runs to its own end; a channel
                                     lasts as long as the ads it plays that day, which nobody sets here. --}}
                                <div class="flex items-center gap-1">
                                    <template x-if="isTimed(item)">
                                        {{-- A refused number paints this line's box red (crud-field-error), and the toast
                                             says why, with the line's title. --}}
                                        <span class="flex items-center gap-1" x-bind:class="{ 'crud-field-error': item.secondsError }">
                                            <input type="number" min="{{ \App\Models\PlaylistItem::MIN_IMAGE_SECONDS }}" max="{{ \App\Models\PlaylistItem::MAX_IMAGE_SECONDS }}" step="1" x-model.number="item.duration_seconds"
                                                @input="dirty = true; item.secondsError = null" x-bind:dusk="'playlist-duration-' + index"
                                                x-bind:aria-label="'Seconds on screen for ' + item.title"
                                                x-bind:disabled="!canEdit || saving" x-bind:title="item.secondsError || ''"
                                                class="form-input w-20 text-sm text-right">
                                            <span class="text-xs text-gray-500 dark:text-gray-400">secs</span>
                                        </span>
                                    </template>
                                    <template x-if="!isTimed(item) && !item.locked">
                                        <span class="text-sm text-gray-500 whitespace-nowrap dark:text-gray-400"
                                              x-bind:dusk="'playlist-length-' + index"
                                              x-text="(item.type === 'channel' ? '~' : '') + formatDuration(lineSeconds(item))"></span>
                                    </template>
                                </div>

                                @can('screen-playlist')
                                {{-- Each button names the line it acts on, and the red × stands a little apart. --}}
                                <div class="flex items-center gap-2">
                                    {{-- Still while a save is on its way: its answer replaces the list. A locked line offers Unlock and its
                                         removal alone: it plays nothing to schedule or move. --}}
                                    <button @click="openSchedule(index)" x-bind:dusk="'playlist-schedule-' + index" x-bind:disabled="saving"
                                        x-show="!item.locked"
                                        class="btn-row-neutral" title="When this item plays"
                                        x-bind:aria-label="'Schedule for ' + item.title">Schedule</button>
                                    <button @click="moveUp(index, $event)" x-bind:disabled="index === 0 || saving"
                                        x-show="!item.locked"
                                        x-bind:dusk="'playlist-up-' + index"
                                        class="btn-row-neutral" title="Move up"
                                        x-bind:aria-label="'Move ' + item.title + ' up'"><span aria-hidden="true">&uarr;</span></button>
                                    <button @click="moveDown(index, $event)" x-bind:disabled="index === items.length - 1 || saving"
                                        x-show="!item.locked"
                                        x-bind:dusk="'playlist-down-' + index"
                                        class="btn-row-neutral" title="Move down"
                                        x-bind:aria-label="'Move ' + item.title + ' down'"><span aria-hidden="true">&darr;</span></button>
                                    <button type="button" x-show="item.locked" x-cloak @click="$dispatch('open-modal', 'unlock-platform-channels')"
                                        x-bind:dusk="'playlist-unlock-' + index"
                                        class="btn-row-neutral inline-flex items-center gap-1" x-bind:aria-label="'Unlock ' + item.title">
                                        <x-icon name="lock-closed" class="h-3 w-3" /> Unlock</button>
                                    <button @click="removeItem(index, $event)" x-bind:dusk="'playlist-remove-' + index" x-bind:disabled="saving"
                                        class="btn-row-danger ml-2" title="Remove"
                                        x-bind:aria-label="'Remove ' + item.title + ' from the playlist'"><span aria-hidden="true">&times;</span></button>
                                </div>
                                @endcan
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            {{-- The right-hand column: ONE card with two tabs (owner, 2026-10-07 — "Content library aur channel us ek
                 card kar k tab bana du"): the organization's own files and the platform's, and the channels it may
                 carry — the same kind of row, so adding either one reads the same. Both are for adding to the
                 playlist, and their lists answer screen-playlist alone, so somebody who may only look at the
                 playlist is not shown a card that stays empty. The Channels tab is there only while the screen has
                 a channel to carry (the platform's or the organization's own), and the card opens on the library. --}}
            @can('screen-playlist')
            <div class="card self-start" dusk="playlist-picker">
                {{-- A tab list with its two panels, so a screen reader says "tab, 1 of 2" and which list is open; the
                     open tab is the one with aria-selected, written as the page opens (the library) so the row is
                     right before Alpine starts, and tab-link colours it (app.css). --}}
                <div class="border-b border-gray-100 px-5 pt-4 dark:border-gray-700">
                    <div class="-mb-px flex flex-wrap gap-x-6" role="tablist" aria-label="Add to the playlist">
                        <button type="button" role="tab" id="picker-tab-library" aria-controls="picker-panel-library" dusk="picker-tab-library"
                            class="tab-link" @click="pickerTab = 'library'"
                            aria-selected="true" :aria-selected="openPickerTab() === 'library' ? 'true' : 'false'">
                            Content Library <span class="ml-1 badge-neutral" x-show="availableTotal !== null" x-cloak x-text="availableTotal" dusk="picker-count-library"></span>
                        </button>
                        <button type="button" role="tab" id="picker-tab-channels" aria-controls="picker-panel-channels" dusk="picker-tab-channels"
                            class="tab-link" @click="pickerTab = 'channels'" x-show="channels.length > 0" x-cloak
                            aria-selected="false" :aria-selected="openPickerTab() === 'channels' ? 'true' : 'false'">
                            Channels <span class="ml-1 badge-neutral" x-text="channels.length" dusk="picker-count-channels"></span>
                        </button>
                    </div>
                </div>

            {{-- ── Content Library ──────────────────────────────────────── --}}
                <div x-show="openPickerTab() === 'library'" id="picker-panel-library" role="tabpanel" aria-labelledby="picker-tab-library"
                     class="p-4 space-y-2" dusk="media-picker">
                    <input x-model="search" type="search" placeholder="Search files..." aria-label="Search files" autocomplete="new-password" maxlength="255"
                        dusk="media-picker-search" class="form-input" />

                    {{-- The picker leaves them out rather than offering and refusing them (owner, 2026-09-26). --}}
                    <p class="text-xs text-muted-soft" dusk="picker-channel-note">
                        Files in a channel are not listed, so nothing plays twice.
                    </p>

                    {{-- Loading, a search that found nothing (and the way back), or a library with nothing to add yet
                         (and, for somebody who may upload, where to do it) — three different things, said apart. --}}
                    <template x-if="!availableLoaded">
                        <p class="text-center text-muted-soft py-10">Loading...</p>
                    </template>
                    <template x-if="availableLoaded && available.length === 0">
                        <div class="text-center py-10 space-y-3" dusk="picker-empty">
                            <p class="text-sm text-muted-soft" x-show="search">
                                Nothing matches &ldquo;<span x-text="search"></span>&rdquo;.
                                <button type="button" class="ml-1 font-medium text-blue-600 hover:underline dark:text-blue-400" @click="search = ''">Clear Search</button>
                            </p>
                            <p class="text-sm text-muted-soft" x-show="!search">No files to add yet.</p>
                            {{-- The Media page opens with View Media and uploads with Upload Media: offered to somebody with both. --}}
                            @can(['media-view', 'media-store'])
                                <a x-show="!search" href="{{ route('media.view') }}" class="btn-secondary" dusk="picker-upload">Upload Files</a>
                            @endcan
                        </div>
                    </template>

                    <template x-for="media in available" :key="media.id">
                        <div class="flex items-center gap-3 p-2 rounded-md border border-gray-200 dark:border-gray-700">
                            <div class="w-20 h-12 rounded overflow-hidden bg-gray-100 dark:bg-gray-700 flex items-center justify-center flex-shrink-0">
                                <template x-if="media.thumbnail_url">
                                    <img :src="media.thumbnail_url" :alt="media.title" class="w-full h-full"
                                         x-bind:class="media.orientation === 'portrait' ? 'object-contain' : 'object-cover'">
                                </template>
                                <template x-if="!media.thumbnail_url">
                                    <span class="text-[10px] text-gray-500 dark:text-gray-400" x-text="typeLabel(media)"></span>
                                </template>
                            </div>

                            <div class="flex-1 min-w-0">
                                <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 min-w-0 text-sm font-medium text-gray-800 dark:text-white">
                                    <span class="truncate" x-text="media.title"></span>
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    <span x-text="typeLabel(media)"></span>
                                    {{-- Which way the file is — or, when that is not the screen's way, why it matters:
                                         it would play with bars (§12). One or the other, never "portrait · Portrait". --}}
                                    <span x-show="media.orientation && !orientationNote(media)" x-text="' · ' + media.orientation"></span>
                                    <span x-show="orientationNote(media)" x-cloak class="text-amber-700 dark:text-amber-400"
                                          x-bind:dusk="'picker-orientation-' + media.id" x-text="' · ' + orientationNote(media)"></span>
                                </p>
                            </div>

                            <button @click="addItem(media, $event)" x-bind:dusk="'playlist-add-' + media.id" x-bind:disabled="saving"
                                class="btn-row-neutral" x-bind:aria-label="'Add ' + media.title + ' to the playlist'">Add</button>
                        </div>
                    </template>
                </div>

            {{-- ── Channels ─────────────────────────────────────────────────
                 Ads the platform offers every organization, plus this organization's own channels — a wholesaler's
                 promotions, a season — that this organization may choose to carry. Adding one puts ONE line on the
                 playlist, and that line plays whatever the channel is running that day,
                 exactly where it stands. Its tab is not shown at all while there are no channels. --}}
                <div x-show="openPickerTab() === 'channels'" x-cloak id="picker-panel-channels" role="tabpanel" aria-labelledby="picker-tab-channels"
                     class="p-4 space-y-2" dusk="channel-picker">
                    <p class="text-xs text-muted-soft">Add one once &mdash; its new ads arrive on their own.</p>

                    {{-- Locked (docs/BILLING-SPEC.md §6): the platform's channels are shown, with Unlock in place of Add. --}}
                    <div x-show="channelsLocked && channels.some((channel) => channel.locked)" x-cloak class="alert-warning flex items-start gap-3" dusk="channels-locked">
                        <x-icon name="lock-closed" class="mt-0.5 h-4 w-4 shrink-0" />
                        <div>
                            <p class="font-semibold">Platform channels are locked for {{ $screen->organization?->name }}.</p>
                            <p class="mt-0.5">See what they play. To put them on your screens, unlock every platform channel for ${{ \App\Services\BillingSummary::PLATFORM_CHANNELS_PRICE }} a month: contact us.</p>
                        </div>
                    </div>

                    <template x-for="channel in channels" :key="channel.id">
                        <div class="rounded-md border border-gray-200 dark:border-gray-700">
                            <div class="flex items-center gap-3 p-2">
                                <div class="w-20 h-12 rounded overflow-hidden bg-gray-100 dark:bg-gray-700 flex items-center justify-center flex-shrink-0">
                                    <template x-if="channel.thumbnail_url">
                                        <img :src="channel.thumbnail_url" :alt="channel.title" class="w-full h-full"
                                             x-bind:class="channel.thumbnail_orientation === 'portrait' ? 'object-contain' : 'object-cover'">
                                    </template>
                                    <template x-if="!channel.thumbnail_url">
                                        <span class="text-[10px] text-gray-500 dark:text-gray-400">Channel</span>
                                    </template>
                                </div>

                                <div class="flex-1 min-w-0">
                                    <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 min-w-0 text-sm font-medium text-gray-800 dark:text-white">
                                        <span class="truncate" x-text="channel.title"></span>
                                        {{-- A channel the organization made for itself, told apart from the platform's; below
                                             the name when there is no room beside it, so neither is cut short. --}}
                                        <span x-show="channel.is_organization_channel" class="badge-neutral shrink-0"
                                              x-bind:dusk="'channel-picker-own-' + channel.id">This organization</span>
                                        <span x-show="channel.locked" x-cloak class="badge-warning inline-flex shrink-0 items-center gap-1"
                                              x-bind:dusk="'channel-picker-premium-' + channel.id"><x-icon name="lock-closed" class="h-3 w-3" /> Premium</span>
                                    </p>
                                    <p class="text-xs"
                                       x-bind:class="channelWarning(channel) ? 'text-amber-700 dark:text-amber-400' : 'text-gray-500 dark:text-gray-400'"
                                       x-bind:dusk="'channel-picker-info-' + channel.id"
                                       x-text="channelInfo(channel)"></p>
                                </div>

                                <button type="button" @click="toggleChannelAds(channel.id)" x-show="channel.ads.length > 0"
                                        x-bind:dusk="'channel-preview-' + channel.id"
                                        class="btn-row-neutral"
                                        x-bind:aria-expanded="(openChannelId === channel.id).toString()"
                                        x-text="openChannelId === channel.id ? 'Hide Ads' : 'Show Ads'"></button>

                                <button @click="addChannel(channel, $event)" x-bind:dusk="'playlist-add-channel-' + channel.id" x-bind:disabled="saving"
                                    x-show="!channel.locked"
                                    class="btn-row-neutral" x-bind:aria-label="'Add the channel ' + channel.title + ' to the playlist'">Add</button>
                                <button type="button" x-show="channel.locked" x-cloak @click="$dispatch('open-modal', 'unlock-platform-channels')"
                                    x-bind:dusk="'unlock-channel-' + channel.id"
                                    class="btn-row-neutral inline-flex items-center gap-1" x-bind:aria-label="'Unlock the channel ' + channel.title">
                                    <x-icon name="lock-closed" class="h-3 w-3" /> Unlock</button>
                            </div>

                            {{-- What adding it would actually play, today. --}}
                            <div x-show="openChannelId === channel.id" x-cloak
                                 x-bind:dusk="'channel-ads-preview-' + channel.id"
                                 class="border-t border-gray-200 dark:border-gray-700 p-2 grid grid-cols-2 sm:grid-cols-3 gap-2">
                                <template x-for="ad in channel.ads" :key="ad.id">
                                    <div class="text-xs min-w-0">
                                        <div class="aspect-video rounded overflow-hidden bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                                            <template x-if="ad.thumbnail_url">
                                                <img :src="ad.thumbnail_url" :alt="ad.title" class="w-full h-full"
                                                     x-bind:class="ad.orientation === 'portrait' ? 'object-contain' : 'object-cover'">
                                            </template>
                                            <template x-if="!ad.thumbnail_url">
                                                <span class="text-[10px] text-gray-500 dark:text-gray-400" x-text="typeLabel(ad)"></span>
                                            </template>
                                        </div>
                                        <p class="mt-1 truncate text-gray-700 dark:text-gray-200" x-text="ad.title"></p>
                                        <p class="text-gray-500 dark:text-gray-400" x-text="adLine(ad)"></p>
                                        {{-- The other way round from this screen: it plays with bars, as a file on the list would. --}}
                                        <p x-show="orientationNote(ad)" x-cloak class="text-amber-700 dark:text-amber-400"
                                           x-bind:dusk="'channel-ad-orientation-' + ad.id" x-text="orientationNote(ad)"></p>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
            @endcan
        </div>

        @can('screen-playlist')
            <x-billing.unlock-dialog name="unlock-platform-channels" feature="platform_channels" :organization="$screen->organization?->name" />
        @endcan

        {{-- ── When one item plays ──────────────────────────────────────── --}}
        <x-modal name="playlist-schedule-modal" :show="false" maxWidth="3xl" persistent>
            <div class="p-6" dusk="schedule-modal">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Schedule</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    <span class="font-semibold" x-text="scheduleIndex !== null ? items[scheduleIndex]?.title : ''"></span>
                    &mdash; leave this empty and it plays whenever the screen is on.
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" dusk="schedule-clock">
                    Times are this screen's own clock: {{ $screen->timezone }}.
                </p>

                <div class="mt-5 space-y-4">
                    <template x-if="scheduleRules.length === 0">
                        <p class="text-sm text-gray-500 dark:text-gray-400" dusk="schedule-always">
                            Always &mdash; no schedule set.
                        </p>
                    </template>

                    <template x-for="(rule, ruleIndex) in scheduleRules" :key="ruleIndex">
                        <div class="rounded-md border border-gray-200 dark:border-gray-700 p-4 space-y-3">

                            {{-- WHICH DAYS. Deliberately separate from the time below:
                                 "every Friday" and "11:00-15:00" are two independent
                                 facts, and folding them together is what forces the
                                 competing product into two different modals. --}}
                            {{-- On a phone every row wraps and each box takes the width it is given; from sm up they
                                 sit side by side at their own widths. Every box has a name a screen reader says. --}}
                            <div class="flex flex-wrap items-center gap-2">
                                <label class="form-label w-16" x-bind:for="'rule-day-mode-' + ruleIndex">Days</label>
                                <select x-model="rule.day_mode" @change="refreshPreview()" x-bind:id="'rule-day-mode-' + ruleIndex"
                                        x-bind:dusk="'rule-day-mode-' + ruleIndex" class="form-select sm:w-44">
                                    <option value="always">Every day</option>
                                    <option value="range">Between dates</option>
                                    <option value="repeat">Repeat&hellip;</option>
                                </select>

                                <template x-if="rule.day_mode === 'range'">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <input type="date" x-model="rule.starts_on" @change="refreshPreview()" aria-label="From"
                                               x-bind:dusk="'rule-starts-on-' + ruleIndex" class="form-input sm:w-40"
                                               x-bind:class="scheduleErrors[ruleIndex]?.starts_on ? '!border-red-500' : ''">
                                        <span class="text-gray-500 dark:text-gray-400" aria-hidden="true">&rarr;</span>
                                        <input type="date" x-model="rule.ends_on" @change="refreshPreview()" aria-label="To"
                                               x-bind:dusk="'rule-ends-on-' + ruleIndex" class="form-input sm:w-40"
                                               x-bind:class="scheduleErrors[ruleIndex]?.ends_on ? '!border-red-500' : ''">
                                    </div>
                                </template>
                            </div>

                            {{-- The full repeat builder, kept behind "Repeat..." so the
                                 nine organizations out of ten that only want a date range never
                                 have to look at it. --}}
                            <template x-if="rule.day_mode === 'repeat'">
                                <div class="sm:pl-16 space-y-3">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm text-gray-500 dark:text-gray-400" aria-hidden="true">Repeat every</span>
                                        <input type="number" min="1" max="52" step="1" x-model.number="rule.recurrence_interval"
                                               @input="refreshPreview()" x-bind:dusk="'rule-interval-' + ruleIndex" aria-label="Repeat every"
                                               class="form-input w-20 text-right"
                                               x-bind:class="scheduleErrors[ruleIndex]?.recurrence_interval ? '!border-red-500' : ''">
                                        <select x-model="rule.recurrence_type" @change="refreshPreview()" aria-label="Repeat unit"
                                                x-bind:dusk="'rule-type-' + ruleIndex" class="form-select sm:w-56">
                                            @foreach ($recurrenceTypes as $value => $label)
                                                <option value="{{ $value }}">{{ $repeatUnits[$value] ?? $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <template x-if="rule.recurrence_type === 'weekly'">
                                        {{-- A picked day is said, not only coloured: aria-pressed. The two quick picks
                                             after the days tick the usual sets at once. --}}
                                        <div class="flex flex-wrap items-center gap-1" role="group" aria-label="Days of the week">
                                            @foreach ($weekdays as $value => $label)
                                                <button type="button" @click="toggleWeekday(rule, {{ $value }})"
                                                        aria-label="{{ $label }}"
                                                        x-bind:aria-pressed="hasWeekday(rule, {{ $value }}) ? 'true' : 'false'"
                                                        x-bind:dusk="'rule-weekday-{{ $value }}-' + ruleIndex"
                                                        x-bind:class="hasWeekday(rule, {{ $value }})
                                                            ? 'bg-blue-600 text-white border-blue-600'
                                                            : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 border-gray-300 dark:border-gray-600'"
                                                        class="px-2.5 py-1 rounded border text-xs font-medium">
                                                    {{ substr($label, 0, 3) }}
                                                </button>
                                            @endforeach
                                            <button type="button" @click="setWeekdays(rule, [1, 2, 3, 4, 5])"
                                                    x-bind:dusk="'rule-weekdays-' + ruleIndex" class="btn-row-neutral ml-2">Weekdays</button>
                                            <button type="button" @click="setWeekdays(rule, [6, 7])"
                                                    x-bind:dusk="'rule-weekends-' + ruleIndex" class="btn-row-neutral">Weekends</button>
                                        </div>
                                    </template>

                                    <template x-if="rule.recurrence_type === 'monthly_day'">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="text-sm text-gray-500 dark:text-gray-400" aria-hidden="true">On day</span>
                                            <input type="number" min="1" max="31" step="1" x-model.number="rule.recurrence_monthday"
                                                   @input="refreshPreview()" x-bind:dusk="'rule-monthday-' + ruleIndex" aria-label="Day of the month"
                                                   class="form-input w-20 text-right"
                                                   x-bind:class="scheduleErrors[ruleIndex]?.recurrence_monthday ? '!border-red-500' : ''">
                                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                                A 31st simply does not occur in a 30-day month.
                                            </span>
                                        </div>
                                    </template>

                                    <template x-if="rule.recurrence_type === 'monthly_weekday'">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="text-sm text-gray-500 dark:text-gray-400" aria-hidden="true">On the</span>
                                            <select x-model.number="rule.recurrence_ordinal" @change="refreshPreview()" aria-label="Which week"
                                                    x-bind:dusk="'rule-ordinal-' + ruleIndex" class="form-select sm:w-32">
                                                @foreach ($ordinals as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            <select x-model.number="rule.recurrence_weekday" @change="refreshPreview()" aria-label="Weekday"
                                                    x-bind:dusk="'rule-month-weekday-' + ruleIndex" class="form-select sm:w-40">
                                                @foreach ($weekdays as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </template>

                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm text-gray-500 dark:text-gray-400 w-20" aria-hidden="true">Starting</span>
                                        <input type="date" x-model="rule.starts_on" @change="refreshPreview()" aria-label="Starting"
                                               x-bind:dusk="'rule-repeat-start-' + ruleIndex" class="form-input sm:w-40"
                                               x-bind:class="scheduleErrors[ruleIndex]?.starts_on ? '!border-red-500' : ''">
                                        <span class="text-sm text-gray-500 dark:text-gray-400" aria-hidden="true">until</span>
                                        <input type="date" x-model="rule.recurrence_until" @change="refreshPreview()" aria-label="Until"
                                               x-bind:dusk="'rule-until-' + ruleIndex" class="form-input sm:w-40"
                                               x-bind:class="scheduleErrors[ruleIndex]?.recurrence_until ? '!border-red-500' : ''">
                                        <span class="text-xs text-gray-500 dark:text-gray-400">Leave the end blank to repeat forever.</span>
                                    </div>
                                </div>
                            </template>

                            {{-- WHAT TIME, on a day the rule covers: the whole day, or from one clock time to another,
                                 typed here (owner, 2026-10-01) — laid out as the Days row above is. --}}
                            <div class="flex flex-wrap items-center gap-2">
                                <label class="form-label w-16" x-bind:for="'rule-time-mode-' + ruleIndex">Time</label>
                                <select x-model="rule.time_mode" @change="refreshPreview()" x-bind:id="'rule-time-mode-' + ruleIndex"
                                        x-bind:dusk="'rule-time-mode-' + ruleIndex" class="form-select sm:w-44">
                                    <option value="all">All day</option>
                                    <option value="times">Between times</option>
                                </select>

                                <template x-if="rule.time_mode === 'times'">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <input type="time" x-model="rule.start_time" @change="refreshPreview()" aria-label="From"
                                               x-bind:dusk="'rule-start-time-' + ruleIndex" class="form-input sm:w-36"
                                               x-bind:class="scheduleErrors[ruleIndex]?.start_time ? '!border-red-500' : ''">
                                        <span class="text-gray-500 dark:text-gray-400" aria-hidden="true">&rarr;</span>
                                        <input type="time" x-model="rule.end_time" @change="refreshPreview()" aria-label="To"
                                               x-bind:dusk="'rule-end-time-' + ruleIndex" class="form-input sm:w-36"
                                               x-bind:class="scheduleErrors[ruleIndex]?.end_time ? '!border-red-500' : ''">
                                        <span x-show="crossesMidnight(rule)" x-cloak class="text-xs text-gray-500 dark:text-gray-400"
                                              x-bind:dusk="'rule-past-midnight-' + ruleIndex">Runs past midnight.</span>
                                    </div>
                                </template>
                            </div>

                            {{-- What OK found wrong with this rule, said under it (ruleProblems). --}}
                            <template x-if="ruleError(ruleIndex)">
                                <p class="form-error" role="alert" x-bind:dusk="'rule-error-' + ruleIndex" x-text="ruleError(ruleIndex)"></p>
                            </template>

                            <div class="flex items-start justify-between gap-3 pt-1">
                                <p class="text-xs text-gray-500 dark:text-gray-400"
                                   x-bind:dusk="'rule-summary-' + ruleIndex" x-text="ruleSummary(rule)"></p>
                                <button type="button" @click="removeRule(ruleIndex)"
                                        x-bind:dusk="'rule-remove-' + ruleIndex" class="btn-row-danger">Remove</button>
                            </div>
                        </div>
                    </template>

                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" @click="addRule()" dusk="schedule-add-rule" class="btn-secondary-add">
                            + Add a Schedule
                        </button>
                        {{-- The same schedule on other lines too, without typing it again (owner, 2026-10-01). Only
                             where there is another line to give it to. --}}
                        <button type="button" x-show="otherLines().length > 0" x-cloak @click="copyOpen = !copyOpen"
                                x-bind:aria-expanded="copyOpen.toString()" dusk="schedule-copy-open" class="btn-secondary">
                            Copy to Other Lines
                        </button>
                    </div>

                    <div x-show="copyOpen && otherLines().length > 0" x-cloak dusk="schedule-copy-panel"
                         class="rounded-md border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-sm text-gray-600 dark:text-gray-300">OK gives this schedule to the lines you tick, in place of theirs.</p>
                            <button type="button" @click="toggleEveryCopyLine()" dusk="schedule-copy-all" class="btn-row-neutral"
                                    x-text="copyToKeys.length === otherLines().length ? 'Clear All' : 'Select All'"></button>
                        </div>
                        <div class="max-h-48 space-y-2 overflow-y-auto">
                            <template x-for="line in otherLines()" :key="line.key">
                                <label class="flex items-center gap-3 rounded-md border border-gray-200 p-2 cursor-pointer dark:border-gray-700">
                                    <input type="checkbox" class="form-checkbox"
                                           x-bind:dusk="'schedule-copy-line-' + line.number"
                                           x-bind:checked="copyToKeys.includes(line.key)"
                                           @change="toggleCopyLine(line.key)">
                                    <span class="w-6 text-center text-xs text-gray-500 dark:text-gray-400" x-text="line.number"></span>
                                    <span class="min-w-0 flex-1 truncate text-sm text-gray-800 dark:text-white" x-text="line.title"></span>
                                </label>
                            </template>
                        </div>
                    </div>

                    {{-- Built by the server, through the very same code the television
                         is answered with — a preview from a second implementation
                         would be worse than none at all. --}}
                    <div class="rounded-md bg-gray-50 dark:bg-gray-700/40 px-4 py-3">
                        <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            Next 7 days <span x-show="previewing" x-cloak class="normal-case">&mdash; working&hellip;</span>
                        </p>
                        <p class="text-sm text-gray-700 dark:text-gray-200 mt-1" dusk="schedule-preview">
                            <template x-if="scheduleRules.length === 0">
                                <span>Plays whenever the screen is on.</span>
                            </template>
                            {{-- Said once the answer is in: while it is on its way the heading says "working…". --}}
                            <template x-if="scheduleRules.length > 0 && preview.length === 0 && !previewing">
                                <span class="text-amber-700 dark:text-amber-400" dusk="schedule-preview-note"
                                      x-text="previewError || 'Nothing in the next 7 days.'"></span>
                            </template>
                            <template x-for="(slot, i) in preview" :key="i">
                                <span class="inline-block mr-3 whitespace-nowrap">
                                    <span x-text="new Date(slot.date + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })"></span>
                                    <span class="text-gray-500 dark:text-gray-400" x-show="slot.start" x-text="slotLabel(slot)"></span>
                                </span>
                            </template>
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-3 mt-6">
                    <p class="mr-auto text-xs text-gray-500 dark:text-gray-400">Kept on this page until you press Save Changes.</p>
                    <button type="button" @click="closeSchedule()" dusk="schedule-cancel" class="btn-secondary">Cancel</button>
                    <button type="button" @click="applySchedule()" dusk="schedule-ok" class="btn-primary">OK</button>
                </div>
            </div>
        </x-modal>

        {{-- ── Copy this playlist onto other screens ────────────────────── --}}
        <x-modal name="playlist-copy-modal" :show="false" maxWidth="lg">
            <div class="p-6" dusk="copy-modal">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Copy playlist to other screens</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    This playlist, with its schedules, <span class="font-semibold">replaces</span> what the chosen screens play now.
                </p>

                <div class="mt-4 space-y-2 max-h-80 overflow-y-auto">
                    <template x-if="copyLoading">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Loading the other screens&hellip;</p>
                    </template>
                    <template x-if="!copyLoading && copyTargets.length === 0">
                        <p class="text-sm text-gray-500 dark:text-gray-400" dusk="copy-no-targets">
                            There are no other screens in this organization.
                        </p>
                    </template>

                    <template x-for="target in copyTargets" :key="target.id">
                        <label class="flex items-center gap-3 p-2 rounded-md border border-gray-200 dark:border-gray-700 cursor-pointer">
                            <input type="checkbox" class="form-checkbox"
                                   x-bind:dusk="'copy-target-' + target.id"
                                   x-bind:checked="copySelected.includes(target.id)"
                                   @change="toggleCopyTarget(target.id)">
                            <span class="flex-1 text-sm text-gray-800 dark:text-white" x-text="target.name"></span>
                            {{-- The count is the point: "replace" is destructive, and
                                 the person should see what they are about to lose
                                 BEFORE they press the button. --}}
                            <span class="text-xs"
                                  x-bind:class="target.playlist_items_count > 0
                                        ? 'text-amber-700 dark:text-amber-400' : 'text-gray-500 dark:text-gray-400'"
                                  x-text="target.playlist_items_count > 0
                                        ? target.playlist_items_count + ' item' + (target.playlist_items_count === 1 ? '' : 's') + ' will be replaced'
                                        : 'empty'"></span>
                        </label>
                    </template>
                </div>

                <p x-show="copySelected.length > 0 && copyWillReplace() > 0" x-cloak dusk="copy-warning" class="alert-warning mt-4">
                    <span x-text="copyWillReplace()"></span> existing item(s) will be permanently replaced.
                </p>

                <div class="flex flex-wrap justify-end gap-3 mt-6">
                    <button type="button" @click="$dispatch('close-modal', 'playlist-copy-modal')"
                            dusk="copy-cancel" class="btn-secondary">Cancel</button>
                    {{-- Red while it would overwrite something: the colour says what the words do. --}}
                    <button type="button" @click="doCopy()" dusk="copy-confirm"
                            x-bind:class="copyWillReplace() > 0 ? 'btn-danger' : 'btn-primary'"
                            x-bind:disabled="copying || copySelected.length === 0">
                        <x-spinner x-show="copying" x-cloak />
                        <span x-text="copying ? 'Copying...' : 'Copy and Replace'"></span>
                    </button>
                </div>
            </div>
        </x-modal>
    </div>
</x-app-layout>
