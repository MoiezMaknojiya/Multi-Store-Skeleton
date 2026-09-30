<x-app-layout>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-gray-800 dark:text-white leading-tight">{{ __('Ad Builder') }}</h1>
    </x-slot>

    <div x-data="builderAssetsTable()" class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <x-builder-tabs active="assets" />

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-3">
                <p class="text-sm text-gray-500 dark:text-gray-400" dusk="assets-scope-note">
                    Pictures and videos used inside ads. A video is 30 seconds at most, and it repeats for as long as
                    the ad is on screen. Separate from the media library, which is what your screens play.
                    @if ($aboveTheStores)
                        {{-- Where an upload goes, as the Shop list stands (owner, 2026-09-29: an upload for every shop). --}}
                        <span x-text="filterStore
                            ? 'An upload goes to the shop chosen in the Shop list, for its ads alone.'
                            : 'An upload is shared with every shop: each shop can use it in its ads.'" dusk="assets-upload-target"></span>
                    @else
                        Files marked "From the platform" are shared with every shop to use in its ads.
                    @endif
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                @if ($aboveTheStores)
                    <select x-model="filterStore" @change="applyFilters()" class="form-select w-52 text-sm"
                            dusk="assets-filter-store" aria-label="Shop">
                        <option value="">All shops</option>
                        @foreach ($stores as $store)
                            <option value="{{ $store['id'] }}">{{ $store['name'] }}</option>
                        @endforeach
                    </select>
                @endif

                <x-crud.search-input placeholder="Search assets..." />
            </div>
        </div>

        {{-- The shelf counts toward the shop's 512 MB like its library does. --}}
        <x-storage-meter />

        @can('ad-store')
            {{-- The shared uploader (docs/UPLOADS-SPEC.md): each picture or video joins the shelf as it arrives. The
                 page listens here, not on the box: an expression on the box runs with the box's own `this`. --}}
            {{-- Above the stores a shop chosen in the Shop list gets the file; with All shops it is shared with every
                 shop. --}}
            <div x-on:upload-added="onUploaded($event.detail)" dusk="upload-asset">
                <x-upload-dropzone purpose="asset" mode="add" :multiple="true" add-url="/builder/assets" dusk="asset"
                    :max-video-seconds="\App\Models\BuilderAsset::MAX_VIDEO_SECONDS"
                    context="{ store: filterStore || null, fields: filterStore ? { store_id: filterStore } : {}, storage: storage }"
                    hint="Pictures (JPG, PNG, GIF, WEBP) and videos (MP4, WEBM), up to 250 MB each. A video is 30 seconds at most." />
            </div>
        @endcan

        <div class="card">
            <div class="p-5">
                <template x-if="loading">
                    <p class="py-12 text-center text-sm text-muted-soft">Loading...</p>
                </template>

                <template x-if="!loading && items.length === 0">
                    <p class="py-16 text-center text-sm text-gray-500 dark:text-gray-400" dusk="assets-empty">
                        Nothing on the shelf yet. Upload a picture or a video to use it in an ad.
                    </p>
                </template>

                <div x-show="!loading && items.length > 0" x-cloak
                     class="grid grid-cols-2 gap-5 sm:grid-cols-3 lg:grid-cols-4" dusk="assets-grid">
                    <template x-for="item in items" :key="item.id">
                        <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700"
                             x-bind:dusk="'asset-card-' + item.id">

                            <div class="aspect-video bg-gray-100 dark:bg-gray-900">
                                {{-- An upright picture is shown whole, not cut to its middle. --}}
                                <img x-show="item.thumbnail_url" x-cloak x-bind:src="item.thumbnail_url" alt=""
                                     class="h-full w-full"
                                     x-bind:class="Number(item.height) > Number(item.width) ? 'object-contain' : 'object-cover'" />
                                <span x-show="!item.thumbnail_url" x-cloak
                                      class="flex h-full w-full items-center justify-center text-xs text-muted-soft"
                                      x-text="item.kind === 'video' ? 'Video' : 'Image'"></span>
                            </div>

                            <div class="space-y-1 p-3">
                                <p class="truncate text-sm font-medium text-gray-800 dark:text-white"
                                   x-bind:dusk="'asset-title-' + item.id" x-text="item.title"></p>

                                {{-- Whose it is: above the stores its shop or "Every shop"; inside a store, the platform's. --}}
                                <p x-show="item.owner_label" x-cloak class="flex flex-wrap items-center gap-1">
                                    <span x-bind:class="item.shared ? 'badge-info' : 'badge-neutral'" x-text="item.owner_label"
                                          x-bind:dusk="'asset-owner-' + item.id"></span>
                                </p>

                                <p class="text-xs text-gray-500">
                                    <span x-text="item.kind === 'video' ? 'Video' : 'Image'"></span>
                                    <span x-show="item.width" x-cloak x-text="' · ' + item.width + '×' + item.height"></span>
                                    <span x-text="' · ' + sizeLabel(item)"></span>
                                </p>

                                <p class="truncate text-xs" x-bind:class="isUsed(item) ? 'text-blue-600 dark:text-blue-400' : 'text-gray-500'"
                                   x-bind:dusk="'asset-usage-' + item.id" x-text="usageLabel(item)"></p>

                                {{-- A shop's own file with Delete Ads, a shared one with Delete Shared Assets: the row says which
                                     this person holds (can_delete), the route asks again. --}}
                                @can('delete-builder-assets')
                                    <div class="pt-1" x-show="item.can_delete" x-cloak>
                                        <button type="button" class="btn-row-danger" @click="confirmDelete(item)"
                                                x-bind:dusk="'delete-asset-' + item.id">Delete</button>
                                    </div>
                                @endcan
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <x-crud.pagination itemsVar="items" />
        </div>

        <x-modal name="confirm-asset-deletion" :show="false" maxWidth="md" focusable>
            <form @submit.prevent="deleteItem()" class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Delete this file?</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    <span class="font-medium" x-text="selectedItem?.title"></span> goes for good. An ad still using
                    it keeps it — the delete is refused and says which ad.
                    <span x-show="selectedItem?.shared" x-cloak dusk="confirm-asset-deletion-shared">It is shared with every
                        shop, so it goes from every shop's shelf.</span>
                </p>

                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'confirm-asset-deletion')">Cancel</x-secondary-button>
                    <x-danger-button x-bind:disabled="deleting" dusk="confirm-asset-deletion-confirm">Delete file</x-danger-button>
                </div>
            </form>
        </x-modal>
    </div>
</x-app-layout>
