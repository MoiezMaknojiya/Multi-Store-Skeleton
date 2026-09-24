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

<x-app-layout>
    <x-slot name="header">
        <div class="flex min-w-0 items-center gap-3">
            <a href="{{ route('screens.view') }}" class="flex-shrink-0 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200" dusk="back-to-screens"
               aria-label="Back to screens">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
            </a>
            <h2 class="min-w-0 truncate font-semibold text-xl text-gray-800 dark:text-white leading-tight" title="{{ $screen->name }}">{{ $screen->name }}</h2>
            <span class="hidden flex-shrink-0 text-sm text-gray-400 sm:inline">{{ $orientations[$screen->orientation] ?? $screen->orientation }}</span>
        </div>
    </x-slot>

    {{-- Js::from, not @json: @json leaves its quotes raw and the first string would
         close this attribute (see .claude/rules/02-project-conventions.md). --}}
    <div x-data="screenPlaylist({{ Js::from([
            'screenId' => $screen->id,
            // Which way the panel is mounted, so a line or a picker row the other way round can say it
            // will play with bars (docs/AD-BUILDER-SPEC.md §12) — the shop's to notice, not a refusal.
            'screenOrientation' => str_starts_with($screen->orientation, 'portrait') ? 'portrait' : 'landscape',
            'canEdit' => auth()->user()->can('screen-playlist'),
            'dayparts' => $dayparts,
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
                        <h3 class="text-subheading">Playlist</h3>
                        <p class="text-xs text-gray-400 mt-0.5" dusk="playlist-summary" x-text="summary()"></p>
                    </div>
                    @can('screen-playlist')
                    <div class="flex flex-wrap items-center gap-2">
                        {{-- Copying REPLACES the target's playlist, so it is deliberately
                             the quieter button of the two and asks before it acts. It copies the
                             SAVED playlist, so it waits until what is on the page has been saved. --}}
                        <button @click="openCopyModal()" dusk="playlist-copy-open" class="btn-secondary"
                            x-bind:disabled="dirty || saving"
                            x-bind:title="dirty ? 'Save your changes first: copying sends the saved playlist' : ''">
                            Copy to other screens
                        </button>
                        <button @click="save()" x-bind:disabled="!dirty || saving" dusk="playlist-save"
                            class="btn-primary disabled:opacity-50 disabled:cursor-not-allowed">
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
                                Add files from the library on the right &mdash; or a channel, below it.
                            @endcan
                        </p>
                    </template>

                    <template x-for="(item, index) in items" :key="item.key">
                        {{-- A line that is not playing — an Ad Builder page taken off the screens (unpublished) — is drawn faded. --}}
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 p-2 rounded-md border border-gray-200 dark:border-gray-700"
                             x-bind:class="item.is_draft ? 'opacity-60' : ''">
                            <span class="w-6 text-xs text-gray-400 text-center" x-text="index + 1"></span>

                            <div class="w-20 h-12 rounded overflow-hidden bg-gray-100 dark:bg-gray-700 flex items-center justify-center flex-shrink-0">
                                {{-- An upright picture is shown whole, not cut to its middle (docs/AD-BUILDER-SPEC.md §12). --}}
                                <template x-if="item.thumbnail_url">
                                    <img :src="item.thumbnail_url" :alt="item.title" class="w-full h-full"
                                         x-bind:class="(item.thumbnail_orientation ?? item.orientation) === 'portrait' ? 'object-contain' : 'object-cover'">
                                </template>
                                <template x-if="!item.thumbnail_url">
                                    <span class="text-[10px] text-gray-400" x-text="typeLabel(item)"></span>
                                </template>
                            </div>

                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-800 dark:text-white truncate" x-text="item.title"></p>
                                <p class="text-xs text-gray-400">
                                    <span x-text="typeLabel(item)"></span>
                                    <span x-show="item.expires_at" class="text-amber-600 dark:text-amber-400"
                                          x-text="' - expires ' + new Date(item.expires_at).toLocaleDateString()"></span>
                                    {{-- It keeps its place, and plays again once the ad is published. --}}
                                    <span x-show="item.is_draft" x-cloak class="text-amber-600 dark:text-amber-400"
                                          x-bind:dusk="'playlist-draft-' + index">&middot; Draft &mdash; not playing until it is published in the Ad Builder</span>
                                    {{-- A file the other way round from the screen plays with bars: said, not refused. --}}
                                    <span x-show="orientationNote(item)" x-cloak class="text-amber-600 dark:text-amber-400"
                                          x-bind:dusk="'playlist-orientation-' + index" x-text="' · ' + orientationNote(item)"></span>
                                    {{-- A channel line says what it will actually play — and says so
                                         plainly when that is nothing: paused, or no ads running. --}}
                                    <template x-if="item.type === 'channel'">
                                        <span x-bind:dusk="'playlist-channel-info-' + index"
                                              x-bind:class="channelWarning(item) ? 'text-amber-600 dark:text-amber-400' : ''"
                                              x-text="' · ' + channelInfo(item)"></span>
                                    </template>
                                </p>
                                {{-- Only shown when the item HAS a schedule. An item
                                     with none plays whenever the screen is on, and
                                     saying so on every row would be noise. --}}
                                <p x-show="(item.rules ?? []).length > 0" x-cloak
                                   class="text-xs text-blue-600 dark:text-blue-400 truncate"
                                   x-bind:dusk="'playlist-schedule-badge-' + index"
                                   x-bind:title="scheduleBadge(item)">
                                    &#9201; <span x-text="scheduleBadge(item)"></span>
                                </p>
                            </div>

                            {{-- The line's own controls: beside the title when there is room, on a
                                 line of their own under it when there is not. --}}
                            <div class="flex w-full flex-wrap items-center justify-end gap-x-3 gap-y-2 @xl:w-auto">
                                {{-- Images and ad pages are timed; a video runs to its own end; a channel
                                     lasts as long as the ads it plays that day, which nobody sets here. --}}
                                <div class="flex items-center gap-1">
                                    <template x-if="isTimed(item)">
                                        <span class="flex items-center gap-1">
                                            <input type="number" min="1" max="86400" x-model.number="item.duration_seconds"
                                                @input="dirty = true" x-bind:dusk="'playlist-duration-' + index"
                                                x-bind:disabled="!canEdit"
                                                class="form-input w-20 text-sm text-right">
                                            <span class="text-xs text-gray-400">secs</span>
                                        </span>
                                    </template>
                                    <template x-if="!isTimed(item)">
                                        <span class="text-sm text-right text-gray-500 whitespace-nowrap"
                                              x-bind:dusk="'playlist-length-' + index"
                                              x-text="(item.type === 'channel' ? '~' : '') + formatDuration(lineSeconds(item))"></span>
                                    </template>
                                </div>

                                @can('screen-playlist')
                                <div class="flex items-center gap-1">
                                    <button @click="openSchedule(index)" x-bind:dusk="'playlist-schedule-' + index"
                                        class="btn-row-neutral whitespace-nowrap" title="When this item plays">Schedule</button>
                                    <button @click="moveUp(index)" x-bind:disabled="index === 0"
                                        x-bind:dusk="'playlist-up-' + index"
                                        class="btn-row-neutral disabled:opacity-30" title="Move up">&uarr;</button>
                                    <button @click="moveDown(index)" x-bind:disabled="index === items.length - 1"
                                        x-bind:dusk="'playlist-down-' + index"
                                        class="btn-row-neutral disabled:opacity-30" title="Move down">&darr;</button>
                                    <button @click="removeItem(index)" x-bind:dusk="'playlist-remove-' + index"
                                        class="btn-row-danger" title="Remove">&times;</button>
                                </div>
                                @endcan
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            {{-- The right-hand column: the shop's own files, and below them the channels it
                 may carry — the same kind of box, so adding either one reads the same. Both are
                 for adding to the playlist, and their lists answer screen-playlist alone, so
                 somebody who may only look at the playlist is not shown two boxes that stay empty. --}}
            @can('screen-playlist')
            <div class="space-y-6">

            {{-- ── Library picker ───────────────────────────────────────── --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="text-subheading">Content library</h3>
                    <div class="relative w-full max-w-xs">
                        <input x-model="search" type="text" placeholder="Search files..." autocomplete="new-password"
                            dusk="media-picker-search" class="form-input" />
                    </div>
                </div>

                <div class="p-4 space-y-2" dusk="media-picker">
                    <template x-if="available.length === 0">
                        <p class="text-center text-muted-soft py-10" dusk="picker-empty">
                            Nothing to show here. Upload files to the library first, or clear the search.
                        </p>
                    </template>

                    <template x-for="media in available" :key="media.id">
                        <div class="flex items-center gap-3 p-2 rounded-md border border-gray-200 dark:border-gray-700">
                            <div class="w-20 h-12 rounded overflow-hidden bg-gray-100 dark:bg-gray-700 flex items-center justify-center flex-shrink-0">
                                <template x-if="media.thumbnail_url">
                                    <img :src="media.thumbnail_url" :alt="media.title" class="w-full h-full"
                                         x-bind:class="media.orientation === 'portrait' ? 'object-contain' : 'object-cover'">
                                </template>
                                <template x-if="!media.thumbnail_url">
                                    <span class="text-[10px] text-gray-400" x-text="typeLabel(media)"></span>
                                </template>
                            </div>

                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-800 dark:text-white truncate" x-text="media.title"></p>
                                <p class="text-xs text-gray-400">
                                    <span x-text="typeLabel(media)"></span>
                                    {{-- Which way the file is — or, when that is not the screen's way, why it matters:
                                         it would play with bars (§12). One or the other, never "portrait · Portrait". --}}
                                    <span x-show="media.orientation && !orientationNote(media)" x-text="' · ' + media.orientation"></span>
                                    <span x-show="orientationNote(media)" x-cloak class="text-amber-600 dark:text-amber-400"
                                          x-bind:dusk="'picker-orientation-' + media.id" x-text="' · ' + orientationNote(media)"></span>
                                </p>
                            </div>

                            @can('screen-playlist')
                            <button @click="addItem(media)" x-bind:dusk="'playlist-add-' + media.id"
                                class="btn-row-neutral">Add</button>
                            @endcan
                        </div>
                    </template>
                </div>
            </div>

            {{-- ── Channels ─────────────────────────────────────────────────
                 Ads the platform offers every shop, plus this store's own channels — a wholesaler's
                 promotions, a season — that this shop may choose to carry. Adding one puts ONE line on the
                 playlist, and that line plays whatever the channel is running that day,
                 exactly where it stands. Not shown at all while there are no channels. --}}
            <div class="card" x-show="channels.length > 0" x-cloak dusk="channel-picker">
                <div class="card-header">
                    <h3 class="text-subheading">Channels</h3>
                    <p class="text-xs text-gray-400">Add one once &mdash; its new ads arrive on their own.</p>
                </div>

                <div class="p-4 space-y-2">
                    <template x-for="channel in channels" :key="channel.id">
                        <div class="rounded-md border border-gray-200 dark:border-gray-700">
                            <div class="flex items-center gap-3 p-2">
                                <div class="w-20 h-12 rounded overflow-hidden bg-gray-100 dark:bg-gray-700 flex items-center justify-center flex-shrink-0">
                                    <template x-if="channel.thumbnail_url">
                                        <img :src="channel.thumbnail_url" :alt="channel.title" class="w-full h-full"
                                             x-bind:class="channel.thumbnail_orientation === 'portrait' ? 'object-contain' : 'object-cover'">
                                    </template>
                                    <template x-if="!channel.thumbnail_url">
                                        <span class="text-[10px] text-gray-400">channel</span>
                                    </template>
                                </div>

                                <div class="flex-1 min-w-0">
                                    <p class="flex items-center gap-1.5 min-w-0 text-sm font-medium text-gray-800 dark:text-white">
                                        <span class="truncate" x-text="channel.title"></span>
                                        {{-- A channel the store made for itself, told apart from the platform's; kept out of
                                             the truncated name so a long name never hides it. --}}
                                        <span x-show="channel.is_store_channel" class="badge-neutral shrink-0"
                                              x-bind:dusk="'channel-picker-own-' + channel.id">This store</span>
                                    </p>
                                    <p class="text-xs"
                                       x-bind:class="channelWarning(channel) ? 'text-amber-600 dark:text-amber-400' : 'text-gray-400'"
                                       x-bind:dusk="'channel-picker-info-' + channel.id"
                                       x-text="channelInfo(channel)"></p>
                                </div>

                                <button type="button" @click="toggleChannelAds(channel.id)" x-show="channel.ads.length > 0"
                                        x-bind:dusk="'channel-preview-' + channel.id"
                                        class="btn-row-neutral whitespace-nowrap"
                                        x-text="openChannelId === channel.id ? 'Hide ads' : 'Show ads'"></button>

                                @can('screen-playlist')
                                <button @click="addChannel(channel)" x-bind:dusk="'playlist-add-channel-' + channel.id"
                                    class="btn-row-neutral">Add</button>
                                @endcan
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
                                                <span class="text-[10px] text-gray-400" x-text="ad.type"></span>
                                            </template>
                                        </div>
                                        <p class="mt-1 truncate text-gray-700 dark:text-gray-200" x-text="ad.title"></p>
                                        <p class="text-gray-400" x-text="adLine(ad)"></p>
                                        {{-- The other way round from this screen: it plays with bars, as a file on the list would. --}}
                                        <p x-show="orientationNote(ad)" x-cloak class="text-amber-600 dark:text-amber-400"
                                           x-bind:dusk="'channel-ad-orientation-' + ad.id" x-text="orientationNote(ad)"></p>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            </div>
            @endcan
        </div>

        {{-- ── When one item plays ──────────────────────────────────────── --}}
        <x-modal name="playlist-schedule-modal" :show="false" maxWidth="3xl">
            <div class="p-6" dusk="schedule-modal">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Schedule</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    <span class="font-semibold" x-text="scheduleIndex !== null ? items[scheduleIndex]?.title : ''"></span>
                    &mdash; leave this empty and it plays whenever the screen is on.
                </p>

                <div class="mt-5 space-y-4">
                    <template x-if="scheduleRules.length === 0">
                        <p class="text-sm text-gray-400 dark:text-gray-500" dusk="schedule-always">
                            Always &mdash; no schedule set.
                        </p>
                    </template>

                    <template x-for="(rule, ruleIndex) in scheduleRules" :key="ruleIndex">
                        <div class="rounded-md border border-gray-200 dark:border-gray-700 p-4 space-y-3">

                            {{-- WHICH DAYS. Deliberately separate from the time below:
                                 "every Friday" and "11:00-15:00" are two independent
                                 facts, and folding them together is what forces the
                                 competing product into two different modals. --}}
                            <div class="flex flex-wrap items-center gap-2">
                                <label class="form-label mb-0 w-16" x-bind:for="'rule-day-mode-' + ruleIndex">Days</label>
                                <select x-model="rule.day_mode" @change="refreshPreview()" x-bind:id="'rule-day-mode-' + ruleIndex"
                                        x-bind:dusk="'rule-day-mode-' + ruleIndex" class="form-select w-44">
                                    <option value="always">Every day</option>
                                    <option value="range">Between dates</option>
                                    <option value="repeat">Repeat&hellip;</option>
                                </select>

                                <template x-if="rule.day_mode === 'range'">
                                    <div class="flex items-center gap-2">
                                        <input type="date" x-model="rule.starts_on" @change="refreshPreview()"
                                               x-bind:dusk="'rule-starts-on-' + ruleIndex" class="form-input w-40">
                                        <span class="text-gray-400">&rarr;</span>
                                        <input type="date" x-model="rule.ends_on" @change="refreshPreview()"
                                               x-bind:dusk="'rule-ends-on-' + ruleIndex" class="form-input w-40">
                                    </div>
                                </template>
                            </div>

                            {{-- The full repeat builder, kept behind "Repeat..." so the
                                 nine shops out of ten that only want a date range never
                                 have to look at it. --}}
                            <template x-if="rule.day_mode === 'repeat'">
                                <div class="pl-16 space-y-3">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm text-gray-500 dark:text-gray-400">Repeat every</span>
                                        <input type="number" min="1" max="52" x-model.number="rule.recurrence_interval"
                                               @input="refreshPreview()" x-bind:dusk="'rule-interval-' + ruleIndex"
                                               class="form-input w-20 text-right">
                                        <select x-model="rule.recurrence_type" @change="refreshPreview()"
                                                x-bind:dusk="'rule-type-' + ruleIndex" class="form-select w-56">
                                            @foreach ($recurrenceTypes as $value => $label)
                                                <option value="{{ $value }}">{{ $repeatUnits[$value] ?? $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <template x-if="rule.recurrence_type === 'weekly'">
                                        <div class="flex flex-wrap items-center gap-1">
                                            @foreach ($weekdays as $value => $label)
                                                <button type="button" @click="toggleWeekday(rule, {{ $value }})"
                                                        x-bind:dusk="'rule-weekday-{{ $value }}-' + ruleIndex"
                                                        x-bind:class="hasWeekday(rule, {{ $value }})
                                                            ? 'bg-blue-600 text-white border-blue-600'
                                                            : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 border-gray-300 dark:border-gray-600'"
                                                        class="px-2.5 py-1 rounded border text-xs font-medium">
                                                    {{ substr($label, 0, 3) }}
                                                </button>
                                            @endforeach
                                        </div>
                                    </template>

                                    <template x-if="rule.recurrence_type === 'monthly_day'">
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm text-gray-500 dark:text-gray-400">On day</span>
                                            <input type="number" min="1" max="31" x-model.number="rule.recurrence_monthday"
                                                   @input="refreshPreview()" x-bind:dusk="'rule-monthday-' + ruleIndex"
                                                   class="form-input w-20 text-right">
                                            <span class="text-xs text-gray-400">
                                                A 31st simply does not occur in a 30-day month.
                                            </span>
                                        </div>
                                    </template>

                                    <template x-if="rule.recurrence_type === 'monthly_weekday'">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="text-sm text-gray-500 dark:text-gray-400">On the</span>
                                            <select x-model.number="rule.recurrence_ordinal" @change="refreshPreview()"
                                                    x-bind:dusk="'rule-ordinal-' + ruleIndex" class="form-select w-32">
                                                @foreach ($ordinals as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            <select x-model.number="rule.recurrence_weekday" @change="refreshPreview()"
                                                    x-bind:dusk="'rule-month-weekday-' + ruleIndex" class="form-select w-40">
                                                @foreach ($weekdays as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </template>

                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm text-gray-500 dark:text-gray-400 w-20">Starting</span>
                                        <input type="date" x-model="rule.starts_on" @change="refreshPreview()"
                                               x-bind:dusk="'rule-repeat-start-' + ruleIndex" class="form-input w-40">
                                        <span class="text-sm text-gray-500 dark:text-gray-400">until</span>
                                        <input type="date" x-model="rule.recurrence_until" @change="refreshPreview()"
                                               x-bind:dusk="'rule-until-' + ruleIndex" class="form-input w-40">
                                        <span class="text-xs text-gray-400">Leave the end blank to repeat forever.</span>
                                    </div>
                                </div>
                            </template>

                            {{-- WHAT TIME, on a day the rule covers. The store's live dayparts, and a
                                 retired one only on the rule that already uses it (daypartsFor). --}}
                            <div class="flex flex-wrap items-center gap-2">
                                <label class="form-label mb-0 w-16" x-bind:for="'rule-daypart-' + ruleIndex">Time</label>
                                <select x-model="rule.daypart_id" @change="refreshPreview()" x-bind:id="'rule-daypart-' + ruleIndex"
                                        x-bind:dusk="'rule-daypart-' + ruleIndex" class="form-select w-72">
                                    <option value="">All day</option>
                                    <template x-for="daypart in daypartsFor(rule)" :key="daypart.id">
                                        <option x-bind:value="daypart.id" x-text="daypartLabel(daypart)"
                                                x-bind:selected="String(daypart.id) === String(rule.daypart_id)"></option>
                                    </template>
                                </select>
                                {{-- It opens the Dayparts page (daypart-view) to make one there (daypart-store),
                                     so it is offered to somebody who holds both. --}}
                                @can(['daypart-view', 'daypart-store'])
                                <a href="{{ route('dayparts.view') }}" target="_blank" rel="noopener"
                                   class="text-xs text-blue-600 dark:text-blue-400 hover:underline">New daypart</a>
                                @endcan
                            </div>

                            <div class="flex items-start justify-between gap-3 pt-1">
                                <p class="text-xs text-gray-500 dark:text-gray-400"
                                   x-bind:dusk="'rule-summary-' + ruleIndex" x-text="ruleSummary(rule)"></p>
                                <button type="button" @click="removeRule(ruleIndex)"
                                        x-bind:dusk="'rule-remove-' + ruleIndex" class="btn-row-danger">Remove</button>
                            </div>
                        </div>
                    </template>

                    <button type="button" @click="addRule()" dusk="schedule-add-rule" class="btn-secondary-add">
                        + Add a schedule
                    </button>

                    {{-- Built by the server, through the very same code the television
                         is answered with — a preview from a second implementation
                         would be worse than none at all. --}}
                    <div class="rounded-md bg-gray-50 dark:bg-gray-700/40 px-4 py-3">
                        <p class="text-xs uppercase tracking-wide text-gray-400">
                            Next 7 days <span x-show="previewing" x-cloak class="normal-case">&mdash; working&hellip;</span>
                        </p>
                        <p class="text-sm text-gray-700 dark:text-gray-200 mt-1" dusk="schedule-preview">
                            <template x-if="scheduleRules.length === 0">
                                <span>Plays whenever the screen is on.</span>
                            </template>
                            <template x-if="scheduleRules.length > 0 && preview.length === 0">
                                <span class="text-amber-600 dark:text-amber-400">
                                    Nothing in the next 7 days.
                                </span>
                            </template>
                            <template x-for="(slot, i) in preview" :key="i">
                                <span class="inline-block mr-3 whitespace-nowrap">
                                    <span x-text="new Date(slot.date + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })"></span>
                                    <span class="text-gray-400" x-show="slot.start" x-text="slotLabel(slot)"></span>
                                </span>
                            </template>
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap justify-end gap-3 mt-6">
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
                    Everything on this screen &mdash; the files and channels, their durations and their schedules &mdash;
                    <span class="font-semibold">replaces</span> whatever the chosen screens are playing now.
                </p>

                <div class="mt-4 space-y-2 max-h-80 overflow-y-auto">
                    <template x-if="copyTargets.length === 0">
                        <p class="text-sm text-gray-400 dark:text-gray-500" dusk="copy-no-targets">
                            There are no other screens in this store.
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
                                        ? 'text-amber-600 dark:text-amber-400' : 'text-gray-400'"
                                  x-text="target.playlist_items_count > 0
                                        ? target.playlist_items_count + ' item' + (target.playlist_items_count === 1 ? '' : 's') + ' will be replaced'
                                        : 'empty'"></span>
                        </label>
                    </template>
                </div>

                <p x-show="copySelected.length > 0 && copyWillReplace() > 0" x-cloak dusk="copy-warning"
                   class="mt-4 rounded-md bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
                    <span x-text="copyWillReplace()"></span> existing item(s) will be permanently replaced.
                </p>

                <div class="flex flex-wrap justify-end gap-3 mt-6">
                    <button type="button" @click="$dispatch('close-modal', 'playlist-copy-modal')"
                            dusk="copy-cancel" class="btn-secondary">Cancel</button>
                    <button type="button" @click="doCopy()" dusk="copy-confirm" class="btn-primary"
                            x-bind:disabled="copying || copySelected.length === 0">
                        <span x-text="copying ? 'Copying...' : 'Copy and replace'"></span>
                    </button>
                </div>
            </div>
        </x-modal>
    </div>
</x-app-layout>
