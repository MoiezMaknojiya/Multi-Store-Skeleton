<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-white leading-tight">{{ __('Ad Builder') }}</h2>
    </x-slot>

    <div x-data="adsTable()" class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <x-builder-tabs active="ads" />

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-3">
                @can('ad-store')
                    {{-- An ad's shape is chosen before the editor opens and fixed after (docs/AD-BUILDER-SPEC.md
                         §12), so New ad asks first. --}}
                    <button type="button" class="btn-primary-add" dusk="new-ad"
                            @click="$dispatch('open-modal', 'new-ad-orientation')">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                        </svg>
                        New ad
                    </button>
                @endcan

                <p class="text-sm text-gray-500 dark:text-gray-400" dusk="ads-scope-note">
                    An ad is the size of a television — 1920 × 1080, or 1080 × 1920 for a screen mounted upright.
                    Publish one and it joins the media a playlist can play.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                {{-- Above the stores the gallery can be narrowed to one shop; a store sees its own only. --}}
                @if ($stores !== [])
                    <select x-model="filterStore" @change="applyFilters()" class="form-select w-44 text-sm"
                            dusk="ads-filter-store" aria-label="Shop">
                        <option value="">All shops</option>
                        @foreach ($stores as $store)
                            <option value="{{ $store['id'] }}">{{ $store['name'] }}</option>
                        @endforeach
                    </select>
                @endif

                <x-crud.search-input placeholder="Search ads..." />
            </div>
        </div>

        {{-- A gallery, not a table: an ad is a picture, and a picture is how a person finds it again. --}}
        <div class="card">
            <div class="p-5">
                <template x-if="loading">
                    <p class="py-12 text-center text-sm text-muted-soft">Loading...</p>
                </template>

                <template x-if="!loading && items.length === 0">
                    <div class="py-16 text-center" dusk="ads-empty">
                        <p class="text-sm text-gray-500 dark:text-gray-400">No ads yet.</p>
                        @can('ad-store')
                            <button type="button" class="mt-2 inline-block text-sm font-medium text-blue-600 hover:underline dark:text-blue-400"
                                    dusk="new-ad-empty" @click="$dispatch('open-modal', 'new-ad-orientation')">Build your first one</button>
                        @endcan
                    </div>
                </template>

                <div x-show="!loading && items.length > 0" x-cloak
                     class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3" dusk="ads-grid">
                    <template x-for="item in items" :key="item.id">
                        <div class="group overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700"
                             x-bind:dusk="'ad-card-' + item.id">

                            {{-- The poster the editor captured when the ad was saved. It opens the editor — which
                                 is Update Ads — so it is a link only for somebody who may change the ad. --}}
                            {{-- The tile stays a television's shape; a portrait poster is drawn inside it whole
                                 (contained, never cropped) and the tile says which way the ad is (§12). --}}
                            <div class="relative">
                                @can('ad-update')
                                    <a x-bind:href="'/builder/' + item.id" class="block aspect-video bg-gray-100 dark:bg-gray-900">
                                        <img x-show="item.thumbnail_url" x-cloak x-bind:src="item.thumbnail_url" alt=""
                                             class="h-full w-full" x-bind:class="item.orientation === 'portrait' ? 'object-contain' : 'object-cover'"
                                             x-bind:dusk="'ad-poster-' + item.id" />
                                        <span x-show="!item.thumbnail_url" x-cloak
                                              class="flex h-full w-full items-center justify-center text-xs text-muted-soft">No preview yet</span>
                                    </a>
                                @else
                                    <div class="aspect-video bg-gray-100 dark:bg-gray-900">
                                        <img x-show="item.thumbnail_url" x-cloak x-bind:src="item.thumbnail_url" alt=""
                                             class="h-full w-full" x-bind:class="item.orientation === 'portrait' ? 'object-contain' : 'object-cover'"
                                             x-bind:dusk="'ad-poster-' + item.id" />
                                        <span x-show="!item.thumbnail_url" x-cloak
                                              class="flex h-full w-full items-center justify-center text-xs text-muted-soft">No preview yet</span>
                                    </div>
                                @endcan

                                <span x-show="item.orientation === 'portrait'" x-cloak
                                      class="absolute left-2 top-2 rounded bg-black/60 px-2 py-1 text-xs font-medium text-white"
                                      title="For a screen mounted upright — 1080 × 1920"
                                      x-bind:dusk="'ad-orientation-' + item.id">Portrait</span>

                                {{-- The saved design, full screen, the way a television would show it. --}}
                                <a x-bind:href="'/builder/' + item.id + '/preview'" target="_blank" rel="noopener"
                                   class="absolute right-2 top-2 rounded bg-black/60 px-2 py-1 text-xs font-medium text-white hover:bg-black/80"
                                   x-bind:dusk="'preview-ad-' + item.id">&#9654; Preview</a>
                            </div>

                            {{-- The buttons go under the name when the card is too narrow for both: side by
                                 side they once squeezed the name to nothing and pushed Delete out of the card. --}}
                            <div class="flex flex-wrap items-start justify-between gap-3 p-4">
                                <div class="min-w-[8rem] flex-1">
                                    @can('ad-update')
                                        <a x-bind:href="'/builder/' + item.id" x-bind:dusk="'ad-name-' + item.id"
                                           class="block truncate font-medium text-gray-800 hover:underline dark:text-white" x-text="item.name"></a>
                                    @else
                                        <p x-bind:dusk="'ad-name-' + item.id"
                                           class="truncate font-medium text-gray-800 dark:text-white" x-text="item.name"></p>
                                    @endcan

                                    <p class="mt-1 text-xs text-gray-400">
                                        <span x-show="item.store_name" x-cloak x-text="item.store_name + ' · '"></span>
                                        <span x-text="item.updated_by_name ? 'by ' + item.updated_by_name : ''"></span>
                                    </p>

                                    {{-- The industry's draft/publish model (docs/AD-BUILDER-SPEC.md §9): a changed ad
                                         stays on the screens as it was published until the changes are published. --}}
                                    <span class="mt-2 inline-block"
                                          x-bind:class="{ published: 'badge-success', changed: 'badge-warning' }[item.status] ?? 'badge-neutral'"
                                          x-bind:dusk="'ad-status-' + item.id"
                                          x-bind:title="{
                                              published: 'On the screens, exactly as designed',
                                              changed: 'On the screens as last published — the newer changes are not published yet',
                                          }[item.status] ?? 'Not on any screen'"
                                          x-text="{ published: 'Published', changed: 'Changes not published' }[item.status] ?? 'Draft'"></span>

                                    {{-- Where a published ad may play (owner's rule, 2026-09-22): a shop's own
                                         playlists too, or only inside a channel. A draft is on nothing, so it
                                         says nothing; the tick itself lives in the editor, beside Publish. --}}
                                    <span class="ml-1 mt-2 inline-block" x-show="item.status !== 'draft'" x-cloak
                                          x-bind:class="item.in_playlists ? 'badge-info' : 'badge-neutral'"
                                          x-bind:dusk="'ad-playlist-use-' + item.id"
                                          x-bind:title="item.in_playlists
                                              ? 'A screen can add this ad to its own playlist'
                                              : 'Only inside a channel — playlists cannot pick it'"
                                          x-text="item.in_playlists ? 'Playlists' : 'Channels only'"></span>
                                </div>

                                <div class="flex shrink-0 items-center gap-2">
                                    @can('ad-update')
                                        <a x-bind:href="'/builder/' + item.id" class="btn-row-neutral"
                                           x-bind:dusk="'edit-ad-' + item.id">Edit</a>
                                    @endcan
                                    @can('ad-store')
                                        <button type="button" class="btn-row-neutral" @click="duplicate(item)"
                                                x-bind:disabled="busyId === item.id"
                                                x-bind:dusk="'duplicate-ad-' + item.id">Copy</button>
                                    @endcan
                                    @can('ad-destroy')
                                        <button type="button" class="btn-row-danger" @click="confirmDelete(item)"
                                                x-bind:dusk="'delete-ad-' + item.id">Delete</button>
                                    @endcan
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <x-crud.pagination itemsVar="items" />
        </div>

        {{-- Which way is the screen mounted? Asked before the editor opens, because it cannot change after
             (docs/AD-BUILDER-SPEC.md §12): every element's box is in stage pixels, so a design for the other
             shape is another design. Two links, so the editor opens on a stage of that shape. --}}
        @can('ad-store')
            <x-modal name="new-ad-orientation" :show="false" maxWidth="lg" focusable>
                <div class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">New ad — which way is the screen?</h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        Chosen once, for this ad: it cannot be changed after.
                    </p>

                    <div class="mt-5">
                        @include('builder.partials.orientation-choice')
                    </div>

                    <div class="mt-6 flex justify-end">
                        <x-secondary-button x-on:click="$dispatch('close-modal', 'new-ad-orientation')" dusk="new-ad-cancel">Cancel</x-secondary-button>
                    </div>
                </div>
            </x-modal>
        @endcan

        {{-- Deleting an ad takes its published copy off every playlist, so it asks for the password. --}}
        <x-modal name="confirm-ad-deletion" :show="false" maxWidth="md" focusable>
            <form @submit.prevent="deleteItem()" class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Delete this ad?</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    <span class="font-medium" x-text="selectedItem?.name"></span> and its published copy go for good —
                    including from any playlist that plays it.
                </p>

                <x-crud.password-confirm id="delete-ad-password" />

                <div class="mt-6 flex justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'confirm-ad-deletion')">Cancel</x-secondary-button>
                    <x-danger-button x-bind:disabled="deleting" dusk="confirm-ad-deletion-confirm">Delete ad</x-danger-button>
                </div>
            </form>
        </x-modal>
    </div>
</x-app-layout>
