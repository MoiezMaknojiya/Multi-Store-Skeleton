<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-white leading-tight">{{ __('Screens') }}</h2>
    </x-slot>

    {{-- Js::from, not @json: @json leaves its quotes raw and the first string would
         close this attribute (see .claude/rules/02-project-conventions.md). --}}
    <div x-data="screensTable({{ Js::from([
            'hasStore' => (bool) session('current_store_id'),
            'defaultTimezone' => \App\Models\Screen::DEFAULT_TIMEZONE,
            'storeAcceptsAds' => $storeAcceptsAds,
         ]) }})"
         class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="flex flex-wrap items-center gap-3 mb-6">
            @can('screen-store')
            <x-crud.add-button label="Add Screen" @click="openPairModal()" dusk="add-screen" />
            @endcan

            {{-- The TV's own page, which is where the pairing code that "Add Screen"
                 asks for comes from — so the two belong next to each other. Opens in
                 a new tab: the player is a kiosk page and must not replace the panel.
                 Deliberately ungated, because /player is an open route (a TV has no
                 login), so hiding the link would guard nothing. --}}
            <a href="{{ route('player') }}" target="_blank" rel="noopener"
               dusk="open-player" class="btn-secondary-add">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                </svg>
                Open Player
            </a>
        </div>

        {{-- Network advertising. Rendered only inside an impersonated super-admin
             session — whether a shop carries advertising is the platform owner's
             setting, agreed in the deal, and a shopkeeper never sees this at all.
             The routes carry the same gate, so hiding it is not what protects it:
             this is simply the one place the owner can reach it. --}}
        @can('network-ads-toggle')
        <div class="card p-5 border-l-4 border-l-blue-500" dusk="network-ads-panel">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <h3 class="text-subheading">Network advertising</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-xl">
                        Only you can see this. Turn it on once the shop has agreed &mdash; then choose which of
                        their televisions carry adverts. Their own content pauses for the break and carries on
                        from where it stopped.
                    </p>
                </div>

                <button @click="toggleStoreAds()" x-bind:disabled="savingAds" dusk="store-ads-toggle"
                        x-bind:class="storeAcceptsAds ? 'btn-row-danger' : 'btn-primary'"
                        class="whitespace-nowrap">
                    <span x-text="storeAcceptsAds ? 'Turn off for this shop' : 'This shop has agreed'"></span>
                </button>
            </div>

            {{-- The bulk switch. Deliberately worded with the SAME two words as the
                 button on every row — one action should not have two vocabularies,
                 or nobody can tell that they do the same thing. --}}
            <div x-show="storeAcceptsAds" x-cloak class="mt-4 flex flex-wrap items-center gap-2">
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    Every screen in this shop at once:
                </span>
                <button @click="allScreenAds(true)" x-bind:disabled="savingAds"
                        dusk="all-screens-ads-on" class="btn-row-neutral">Adverts on</button>
                <button @click="allScreenAds(false)" x-bind:disabled="savingAds"
                        dusk="all-screens-ads-off" class="btn-row-neutral">Adverts off</button>
                <span class="text-xs text-gray-400">
                    &mdash; or set them one at a time in the list below
                </span>
            </div>
        </div>
        @endcan

        <x-crud.table-wrapper title="All Screens" searchPlaceholder="Search by name, device id or date..." :columns="5">
            <x-slot name="head">
                <th class="px-5 py-3 text-left font-semibold">Name</th>
                <th class="px-5 py-3 text-left font-semibold">Status</th>
                <th class="px-5 py-3 text-left font-semibold">Orientation</th>
                <th class="px-5 py-3 text-left font-semibold">Paired</th>
                <th class="px-5 py-3 text-right font-semibold">Actions</th>
            </x-slot>

            <x-slot name="body">
                <x-crud.table-empty :columns="5" itemsVar="items" message="No screens found." />

                <template x-for="item in items" :key="item.id">
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                        <td class="px-5 py-4">
                            {{-- Names do not wrap. Auto table layout was handing the
                                 Paired column far more room than it needed and squeezing
                                 this one until "Entrance Display" broke over two lines,
                                 which pushed every row taller for nothing. --}}
                            <a x-bind:href="'/screens/' + item.id" x-bind:dusk="'open-screen-' + item.id"
                               class="font-medium text-blue-600 hover:underline dark:text-blue-400 whitespace-nowrap"
                               x-text="item.name"></a>
                            <p class="text-xs text-gray-400" x-text="playlistLabel(item)"></p>
                        </td>
                        <td class="px-5 py-4">
                            <span x-show="item.is_online" x-cloak
                                  class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300">
                                <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span> Online
                            </span>
                            <span x-show="!item.is_online" x-cloak
                                  class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span> Offline
                            </span>
                            {{-- "Seen 2 min ago" broke over two lines and made that one
                                 row taller than its neighbours — a list of screens should
                                 have rows of one height. --}}
                            <span class="block text-xs text-gray-400 mt-1 whitespace-nowrap" x-text="lastSeenLabel(item)"></span>
                        </td>
                        <td class="px-5 py-4 text-gray-600 dark:text-gray-300 text-sm">
                            <span class="whitespace-nowrap" x-text="orientationLabel(item.orientation)"></span>
                            {{-- The clock this screen keeps. It is what every schedule
                                 on its playlist is read against, so it belongs where
                                 the screen's other physical facts are. --}}
                            <span class="block text-xs text-gray-400 mt-1 whitespace-nowrap"
                                  x-bind:dusk="'screen-timezone-' + item.id"
                                  x-text="item.timezone"></span>
                        </td>
                        {{-- When it was paired, and WHICH device is paired. The id
                             belongs beside the date rather than under the name: both are
                             facts about this screen's pairing, and the name column stays
                             about the name.

                             Only the last block of the uuid — twelve characters tell a
                             shop's handful of sets apart, and the player prints the same
                             block, so the two can be matched at a glance. The whole value
                             is on the tooltip for anyone who needs it, and a click selects
                             just the id. A screen no device has claimed shows nothing here
                             rather than an empty dash. --}}
                        <td class="px-5 py-4 text-gray-600 dark:text-gray-300 text-xs">
                            <span class="whitespace-nowrap" x-text="item.paired_at ? new Date(item.paired_at).toLocaleString() : 'Not paired'"></span>
                            {{-- Plain text in the app's informational blue — the same
                                 pair used for the role chip elsewhere, so it reads clearly
                                 on both the light and the dark table. --}}
                            <span x-show="item.device_uuid" x-cloak
                                  class="block mt-1 font-mono text-[11px] text-blue-600 dark:text-blue-400 select-all whitespace-nowrap"
                                  x-bind:dusk="'screen-uuid-' + item.id"
                                  x-bind:title="item.device_uuid"
                                  x-text="(item.device_uuid || '').split('-').pop()"></span>
                        </td>
                        <td class="px-5 py-4">
                            <div class="flex items-center justify-end gap-2">
                                {{-- One television's answer, inside a shop that has
                                     already agreed. The set over the children's tables
                                     can stay clear while the rest carry adverts. --}}
                                @can('network-ads-toggle')
                                <button x-show="storeAcceptsAds" x-cloak
                                        @click="setScreenAds([item.id], !item.accepts_network_ads)"
                                        x-bind:disabled="savingAds"
                                        x-bind:dusk="'screen-ads-' + item.id"
                                        x-bind:class="item.accepts_network_ads ? 'btn-row-primary' : 'btn-row-neutral'"
                                        x-bind:title="adsLabel(item)"
                                        class="whitespace-nowrap"
                                        x-text="item.accepts_network_ads ? 'Adverts on' : 'Adverts off'"></button>
                                @endcan

                                <a x-bind:href="'/screens/' + item.id" x-bind:dusk="'playlist-screen-' + item.id"
                                    class="btn-row-primary">Playlist</a>

                                @can('screen-update')
                                <button @click="openFormModal(item)" x-bind:dusk="'edit-screen-' + item.id"
                                    class="btn-row-neutral">Edit</button>
                                @endcan

                                @can('screen-store')
                                <button @click="openReplaceModal(item)" x-bind:dusk="'replace-screen-' + item.id"
                                    class="btn-row-neutral whitespace-nowrap">Replace device</button>
                                @endcan

                                @can('screen-destroy')
                                <button @click="confirmDelete(item)" x-bind:dusk="'delete-screen-' + item.id"
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

        {{-- Delete Confirmation --}}
        <x-crud.confirm-delete-modal
            name="confirm-screen-deletion"
            entity="Screen"
            nameExpression="selectedItem?.name"
            deleteAction="deleteItem()"
            disabledVar="deleting" />

        {{-- Pair / Replace Modal --}}
        <x-modal name="screen-pair-modal" :show="false" maxWidth="lg">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100"
                    x-text="pairForm.mode === 'replace' ? 'Replace device' : 'Add Screen'"></h2>

                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400"
                   x-text="pairForm.mode === 'replace'
                        ? 'Open the player on the new device and type the code it shows. This screen keeps its name and settings.'
                        : 'Open the player on your TV and type the 6-character code it shows.'"></p>

                <form @submit.prevent="pairScreen" dusk="screen-pair-form" class="mt-4 space-y-4">
                    <div x-show="!hasStore && pairForm.mode === 'new'" x-cloak
                         class="rounded-md bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
                        Select a store first - a screen belongs to the store it is paired in.
                    </div>

                    <x-crud.form-field label="Pairing code" field="code" :required="true">
                        <x-text-input x-model="pairForm.code" dusk="screen-code" class="block w-full uppercase tracking-[0.4em] text-center text-lg font-mono"
                                      maxlength="6" placeholder="K7M4XQ" autocomplete="off"
                                      @input="pairForm.code = pairForm.code.toUpperCase().replace(/[^A-Z0-9]/g, '')" />
                    </x-crud.form-field>

                    <template x-if="pairForm.mode === 'new'">
                        <div class="space-y-4">
                            <x-crud.form-field label="Screen name" field="name" :required="true">
                                <x-text-input x-model="pairForm.name" dusk="screen-name" class="block w-full"
                                              placeholder="Counter TV" autocomplete="off" />
                            </x-crud.form-field>

                            <x-crud.form-field label="Orientation" field="orientation" :required="true">
                                <select x-model="pairForm.orientation" dusk="screen-orientation" class="form-select block w-full">
                                    @foreach ($orientations as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </x-crud.form-field>
                        </div>
                    </template>

                    <template x-if="pairForm.mode === 'replace'">
                        <div class="rounded-md bg-gray-50 dark:bg-gray-700/40 px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                            Replacing the device for <span class="font-semibold" x-text="pairForm.screen_name"></span>.
                            The old device stops playing immediately.
                        </div>
                    </template>

                    <x-crud.form-actions cancelAction="closePairModal()" savingVar="saving"
                        saveLabel="Pair" dusk="screen-pair-save" cancelDusk="screen-pair-cancel" />
                </form>
            </div>
        </x-modal>

        {{-- Edit Modal --}}
        <x-modal name="screen-form-modal" :show="false" maxWidth="2xl">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Edit Screen</h2>

                <form @submit.prevent="saveItem" dusk="screen-form" class="mt-4 space-y-4">
                    <x-crud.form-field label="Screen name" field="name" :required="true">
                        <x-text-input x-model="form.name" dusk="screen-edit-name" class="block w-full" autocomplete="off" />
                    </x-crud.form-field>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-crud.form-field label="Orientation" field="orientation" :required="true">
                            <select x-model="form.orientation" dusk="screen-edit-orientation" class="form-select block w-full">
                                @foreach ($orientations as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </x-crud.form-field>

                        {{-- The clock this screen keeps. Every schedule on its playlist
                             is wall clock — "Fridays 11:00 to 15:00" means eleven in
                             the morning where this television stands — so this is what
                             they are all read against. An IANA zone, never an offset:
                             it follows the daylight-saving switch by itself. --}}
                        <x-crud.form-field label="Timezone" field="timezone" :required="true">
                            <select x-model="form.timezone" dusk="screen-edit-timezone" class="form-select block w-full">
                                @foreach ($timezones as $zone)
                                    <option value="{{ $zone }}">{{ $zone }}</option>
                                @endforeach
                            </select>
                        </x-crud.form-field>
                    </div>

                    {{-- What fills a gap between two schedules, or an hour nothing was
                         scheduled for. Left unset, the screen simply goes black — which
                         is what a shop usually wants overnight. --}}
                    <div class="pt-2 border-t border-gray-200 dark:border-gray-700">
                        <x-crud.form-field label="Default media" field="default_media_id">
                            <select x-model="form.default_media_id" dusk="screen-edit-default-media"
                                    class="form-select block w-full">
                                <option value="">Nothing &mdash; leave the screen black</option>
                                {{-- The options arrive after the modal opens, and x-model does not go back
                                     to pick one out of a list that grew later — so each option says itself
                                     whether it is the screen's (as on the Members and Users pages). --}}
                                <template x-for="media in mediaOptions" :key="media.id">
                                    {{-- A file the other way round from this screen says so: it holds the glass
                                         with bars (docs/AD-BUILDER-SPEC.md §12) — told by the screen's way as the
                                         form has it, so turning the screen in this form turns the labels too. --}}
                                    <option x-bind:value="media.id"
                                            x-text="holdingLabel(media)"
                                            x-bind:selected="String(media.id) === String(form.default_media_id)"></option>
                                </template>
                            </select>
                        </x-crud.form-field>
                        <p x-show="holdingNote()" x-cloak class="mt-1 text-xs text-amber-600 dark:text-amber-400"
                           dusk="screen-edit-default-media-note" x-text="holdingNote()"></p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Shown whenever nothing on this screen's playlist is due.
                            When to play each file is set on the file itself, inside the screen.
                            <span x-show="loadingMedia" x-cloak>Loading the library&hellip;</span>
                        </p>
                    </div>

                    <x-crud.form-actions savingVar="saving" dusk="screen-save" cancelDusk="screen-edit-cancel" />
                </form>
            </div>
        </x-modal>
    </div>
</x-app-layout>
