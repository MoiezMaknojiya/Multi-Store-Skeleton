<x-app-layout>
    <x-slot name="header">
        <h1 class="page-title">{{ __('Ad Builder') }}</h1>
    </x-slot>

    <div x-data="adsTable()" class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        {{-- Create Ad, then the shop list (above the stores; a store sees its own only) and the search, on one line:
             the button first, as on every page (owner, 2026-09-30). --}}
        <div class="flex flex-wrap items-center justify-end gap-3">
            <div class="flex w-full flex-wrap items-center gap-3 sm:w-auto">
                @can('ad-store')
                    {{-- An ad's shape is chosen before the editor opens and fixed after (docs/AD-BUILDER-SPEC.md
                         §12), so Create Ad asks first. --}}
                    <x-crud.add-button label="Create Ad" dusk="new-ad" @click="$dispatch('open-modal', 'new-ad-orientation')" />
                @endcan

                @if ($stores !== [])
                    <select x-model="filterStore" @change="applyFilters()" class="form-select sm:w-44"
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

        {{-- A gallery, not a table: an ad is a picture, and a picture is how a person finds it again. The same three
             "nothing to show" cases as every listing (x-crud.table-empty): a list that did not load, a search or a
             shop that matched nothing, and no ads at all. --}}
        <div class="card" data-list-card>
            <div class="p-5" x-bind:aria-busy="loading ? 'true' : 'false'">
                <template x-if="loading">
                    <p class="py-12 text-center text-sm text-muted-soft">
                        <span class="inline-flex items-center gap-2"><x-spinner class="text-blue-600 dark:text-blue-400" /> Loading...</span>
                    </p>
                </template>

                <template x-if="!loading && items.length === 0 && loadFailed">
                    <div class="mx-auto max-w-md space-y-3 py-12 text-center" role="alert" dusk="table-load-failed">
                        <p class="text-sm text-gray-700 dark:text-gray-200">Could not load the ads. Check the connection, then try again.</p>
                        <button type="button" class="btn-row-neutral" @click="fetchItems()" dusk="table-try-again">Try Again</button>
                    </div>
                </template>

                <template x-if="!loading && items.length === 0 && !loadFailed && (search || filterStore)">
                    <div class="mx-auto max-w-md space-y-3 py-12 text-center" dusk="table-no-match">
                        <p class="text-sm text-gray-700 dark:text-gray-200">
                            <span x-show="search">No ad matches &ldquo;<span class="font-medium" x-text="search"></span>&rdquo;.</span>
                            <span x-show="!search">This shop has no ads yet.</span>
                        </p>
                        <div class="flex flex-wrap justify-center gap-2">
                            <button type="button" x-show="search" class="btn-row-neutral" @click="search = ''" dusk="table-clear-search">Clear Search</button>
                            <button type="button" x-show="!search" class="btn-row-neutral" @click="filterStore = ''; applyFilters()" dusk="table-clear-filters">Show Every Shop</button>
                        </div>
                    </div>
                </template>

                <template x-if="!loading && items.length === 0 && !loadFailed && !search && !filterStore">
                    <div class="mx-auto max-w-md py-12 text-center" dusk="ads-empty">
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-200">No ads yet.</p>
                    </div>
                </template>

                <div x-show="!loading && items.length > 0" x-cloak
                     class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3" dusk="ads-grid">
                    <template x-for="item in items" :key="item.id">
                        <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700"
                             x-bind:dusk="'ad-card-' + item.id">

                            {{-- The poster the editor captured when the ad was saved. It opens the editor — which
                                 is Update Ads, and above the stores alone for an ad made for every shop — so it is a
                                 link only for somebody who may change this ad (the row's `can`); a mouse's short cut
                                 only, since the name and Edit below lead to the same place (tabindex -1). --}}
                            {{-- The tile stays a television's shape; a portrait poster is drawn inside it whole
                                 (contained, never cropped) and the tile says which way the ad is (§12). --}}
                            <div class="relative">
                                @can('ad-update')
                                    <a x-bind:href="item.can?.update ? '/builder/' + item.id : null" tabindex="-1" aria-hidden="true" class="block aspect-video bg-gray-100 dark:bg-gray-900">
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

                                {{-- The saved design, full screen, the way a television would show it — in a new tab,
                                     which its name says. --}}
                                <a x-bind:href="'/builder/' + item.id + '/preview'" target="_blank" rel="noopener"
                                   x-bind:aria-label="'Preview ' + item.name + ' (opens in a new tab)'"
                                   class="absolute right-2 top-2 inline-flex items-center gap-1 rounded bg-black/70 px-2 py-1 text-xs font-medium text-white hover:bg-black/85 focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
                                   x-bind:dusk="'preview-ad-' + item.id">
                                    <svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.3 2.84A1.5 1.5 0 0 0 4 4.11v11.78a1.5 1.5 0 0 0 2.3 1.27l9.34-5.89a1.5 1.5 0 0 0 0-2.54L6.3 2.84Z" /></svg>
                                    Preview
                                </a>
                            </div>

                            {{-- The buttons go under the name when the card is too narrow for both: side by
                                 side they once squeezed the name to nothing and pushed Delete out of the card. --}}
                            <div class="flex flex-wrap items-start justify-between gap-3 p-4">
                                <div class="min-w-[8rem] flex-1">
                                    @can('ad-update')
                                        <a x-bind:href="item.can?.update ? '/builder/' + item.id : null" x-bind:dusk="'ad-name-' + item.id" x-bind:title="item.name"
                                           class="block truncate font-medium text-gray-800 dark:text-white" x-bind:class="item.can?.update ? 'hover:underline' : ''"
                                           x-text="item.name"></a>
                                    @else
                                        <p x-bind:dusk="'ad-name-' + item.id" x-bind:title="item.name"
                                           class="truncate font-medium text-gray-800 dark:text-white" x-text="item.name"></p>
                                    @endcan

                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        <span x-show="item.store_name" x-cloak x-text="item.store_name + ' · '"></span>
                                        <span x-text="item.updated_by_name ? 'by ' + item.updated_by_name : ''"></span>
                                    </p>

                                    {{-- Made for every shop (owner, 2026-10-01): "Every shop" above the stores, "From the
                                         platform" inside one, as the Assets page says it of a shared file. --}}
                                    <span x-show="item.shared" x-cloak class="badge-info mr-1 mt-2 inline-block"
                                          x-bind:dusk="'ad-owner-' + item.id" x-text="item.owner_label"></span>

                                    {{-- The industry's draft/publish model (docs/AD-BUILDER-SPEC.md §9): a changed ad
                                         stays on the screens as it was published until the changes are published. A shop
                                         is only ever shown the platform's published version, so its card says no more. --}}
                                    <span class="mt-2 inline-block" x-show="!item.shared || item.can?.update" x-cloak
                                          x-bind:class="{ published: 'badge-success', changed: 'badge-warning' }[item.status] ?? 'badge-neutral'"
                                          x-bind:dusk="'ad-status-' + item.id"
                                          x-bind:title="{
                                              published: 'On the screens, exactly as designed',
                                              changed: 'On the screens as last published — the newer changes are not published yet',
                                          }[item.status] ?? 'Not on any screen'"
                                          x-text="{ published: 'Published', changed: 'Changes not published' }[item.status] ?? 'Draft'"></span>
                                </div>

                                {{-- Each names the ad it acts on. A design a channel shows is not deleted: said at once,
                                     before the password (askToDelete). --}}
                                {{-- What may be done to this ad comes with it (`can`): Update Ads and Delete Ads, and
                                     for one made for every shop only above the stores. A shop's Copy of the platform's
                                     ad makes it the shop's own. --}}
                                <div class="flex shrink-0 items-center gap-2">
                                    @can('ad-update')
                                        <a x-show="item.can?.update" x-bind:href="'/builder/' + item.id" class="btn-row-neutral"
                                           x-bind:aria-label="'Edit ' + item.name"
                                           x-bind:dusk="'edit-ad-' + item.id">Edit</a>
                                    @endcan
                                    @can('ad-store')
                                        <button type="button" class="btn-row-neutral" @click="duplicate(item)" x-show="item.can?.copy"
                                                x-bind:disabled="busyId === item.id"
                                                x-bind:aria-label="'Copy ' + item.name"
                                                x-bind:dusk="'duplicate-ad-' + item.id">
                                            <x-spinner x-show="busyId === item.id" x-cloak class="h-3 w-3" />
                                            Copy
                                        </button>
                                    @endcan
                                    @can('ad-destroy')
                                        <button type="button" class="btn-row-danger" @click="askToDelete(item)" x-show="item.can?.delete"
                                                x-bind:aria-label="'Delete ' + item.name"
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
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Create Ad — which way is the screen?</h2>
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
                    <span x-show="selectedItem?.shared" x-cloak dusk="confirm-ad-deletion-shared">It goes from every shop; the copies shops made stay theirs.</span>
                </p>

                <x-crud.password-confirm id="delete-ad-password" />

                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'confirm-ad-deletion')">Cancel</x-secondary-button>
                    <x-danger-button x-bind:disabled="deleting" dusk="confirm-ad-deletion-confirm">
                        <x-spinner x-show="deleting" x-cloak />
                        Delete Ad
                    </x-danger-button>
                </div>
            </form>
        </x-modal>
    </div>
</x-app-layout>
