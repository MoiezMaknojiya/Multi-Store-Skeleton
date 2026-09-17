<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('screens.view') }}" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200" dusk="back-to-screens">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
            </a>
            <h2 class="font-semibold text-xl text-gray-800 dark:text-white leading-tight">{{ $screen->name }}</h2>
            <span class="text-sm text-gray-400">{{ $orientations[$screen->orientation] ?? $screen->orientation }}</span>
        </div>
    </x-slot>

    {{-- Js::from, not @json: @json leaves its quotes raw and the first string would
         close this attribute (see .claude/rules/02-project-conventions.md). --}}
    <div x-data="screenPlaylist({{ Js::from([
            'screenId' => $screen->id,
            'canEdit' => auth()->user()->can('screen-playlist'),
            'dayparts' => $dayparts,
            'weekdays' => $weekdays,
            'recurrenceTypes' => $recurrenceTypes,
            'ordinals' => $ordinals,
         ]) }})"
         class="max-w-7xl mx-auto sm:px-6 lg:px-8">

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            {{-- ── Playlist ─────────────────────────────────────────────── --}}
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3 class="text-subheading">Playlist</h3>
                        <p class="text-xs text-gray-400 mt-0.5" dusk="playlist-summary" x-text="summary()"></p>
                    </div>
                    @can('screen-playlist')
                    <div class="flex items-center gap-2">
                        {{-- Copying REPLACES the target's playlist, so it is deliberately
                             the quieter button of the two and asks before it acts. --}}
                        <button @click="openCopyModal()" dusk="playlist-copy-open" class="btn-secondary">
                            Copy to other screens
                        </button>
                        <button @click="save()" x-bind:disabled="!dirty || saving" dusk="playlist-save"
                            class="btn-primary disabled:opacity-50 disabled:cursor-not-allowed">
                            <span x-text="saving ? 'Saving...' : 'Save Changes'"></span>
                        </button>
                    </div>
                    @endcan
                </div>

                <div class="p-4 space-y-2">
                    <template x-if="loading">
                        <p class="text-center text-muted-soft py-6">Loading...</p>
                    </template>

                    <template x-if="!loading && items.length === 0">
                        <p class="text-center text-muted-soft py-10" dusk="playlist-empty">
                            Nothing here yet. Add files from the library on the right &mdash; or a channel, below it.
                        </p>
                    </template>

                    <template x-for="(item, index) in items" :key="item.key">
                        <div class="flex items-center gap-3 p-2 rounded-md border border-gray-200 dark:border-gray-700">
                            <span class="w-6 text-xs text-gray-400 text-center" x-text="index + 1"></span>

                            <div class="w-20 h-12 rounded overflow-hidden bg-gray-100 dark:bg-gray-700 flex items-center justify-center flex-shrink-0">
                                <template x-if="item.thumbnail_url">
                                    <img :src="item.thumbnail_url" :alt="item.title" class="w-full h-full object-cover">
                                </template>
                                <template x-if="!item.thumbnail_url">
                                    <span class="text-[10px] text-gray-400" x-text="item.type"></span>
                                </template>
                            </div>

                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-800 dark:text-white truncate" x-text="item.title"></p>
                                <p class="text-xs text-gray-400">
                                    <span class="capitalize" x-text="item.type"></span>
                                    <span x-show="item.expires_at" class="text-amber-600 dark:text-amber-400"
                                          x-text="' - expires ' + new Date(item.expires_at).toLocaleDateString()"></span>
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

                            {{-- Images are timed; a video runs to its own end; a channel lasts as long
                                 as the ads it plays that day, which nobody sets here. --}}
                            <div class="flex items-center gap-1">
                                <template x-if="item.type === 'image'">
                                    <span class="flex items-center gap-1">
                                        <input type="number" min="1" max="86400" x-model.number="item.duration_seconds"
                                            @input="dirty = true" x-bind:dusk="'playlist-duration-' + index"
                                            x-bind:disabled="!canEdit"
                                            class="form-input w-20 text-sm text-right">
                                        <span class="text-xs text-gray-400">secs</span>
                                    </span>
                                </template>
                                <template x-if="item.type !== 'image'">
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
                    </template>
                </div>
            </div>

            {{-- The right-hand column: the shop's own files, and below them the channels it
                 may carry — the same kind of box, so adding either one reads the same. --}}
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
                                    <img :src="media.thumbnail_url" :alt="media.title" class="w-full h-full object-cover">
                                </template>
                                <template x-if="!media.thumbnail_url">
                                    <span class="text-[10px] text-gray-400" x-text="media.type"></span>
                                </template>
                            </div>

                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-800 dark:text-white truncate" x-text="media.title"></p>
                                <p class="text-xs text-gray-400">
                                    <span class="capitalize" x-text="media.type"></span>
                                    <span x-text="media.orientation ? ' - ' + media.orientation : ''"></span>
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
                                        <img :src="channel.thumbnail_url" :alt="channel.title" class="w-full h-full object-cover">
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
                                                <img :src="ad.thumbnail_url" :alt="ad.title" class="w-full h-full object-cover">
                                            </template>
                                            <template x-if="!ad.thumbnail_url">
                                                <span class="text-[10px] text-gray-400" x-text="ad.type"></span>
                                            </template>
                                        </div>
                                        <p class="mt-1 truncate text-gray-700 dark:text-gray-200" x-text="ad.title"></p>
                                        <p class="text-gray-400" x-text="adLine(ad)"></p>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            </div>
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
                                <label class="form-label mb-0 w-16">Days</label>
                                <select x-model="rule.day_mode" @change="refreshPreview()"
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
                                                <option value="{{ $value }}">{{ $label }}</option>
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

                            {{-- WHAT TIME, on a day the rule covers. --}}
                            <div class="flex flex-wrap items-center gap-2">
                                <label class="form-label mb-0 w-16">Time</label>
                                <select x-model="rule.daypart_id" @change="refreshPreview()"
                                        x-bind:dusk="'rule-daypart-' + ruleIndex" class="form-select w-72">
                                    <option value="">All day</option>
                                    <template x-for="daypart in dayparts" :key="daypart.id">
                                        <option x-bind:value="daypart.id" x-text="daypartLabel(daypart)"></option>
                                    </template>
                                </select>
                                <a href="{{ route('dayparts.view') }}" target="_blank" rel="noopener"
                                   class="text-xs text-blue-600 dark:text-blue-400 hover:underline">New daypart</a>
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

                <div class="flex justify-end gap-3 mt-6">
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

                <div class="flex justify-end gap-3 mt-6">
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
