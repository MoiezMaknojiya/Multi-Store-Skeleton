<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-white leading-tight">{{ __('Ad Builder') }}</h2>
    </x-slot>

    <div x-data="builderAssetsTable()" class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <x-builder-tabs active="assets" />

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-3">
                @can('ad-store')
                    <label class="btn-primary-add cursor-pointer" dusk="upload-asset">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v12m0-12l-4 4m4-4l4 4" />
                        </svg>
                        <span x-text="uploading ? 'Uploading...' : 'Upload'"></span>
                        {{-- The formats the player can render; StoreMediaRequest::ALLOWED_MIMES is the source
                             of truth and this list mirrors it (see 02-project-conventions.md). --}}
                        {{-- sr-only rather than hidden: a `display:none` input is out of the keyboard's
                             reach (and a browser test's), while this one is only out of sight. --}}
                        <input type="file" class="sr-only" @change="upload($event)" x-bind:disabled="uploading"
                               accept=".jpg,.jpeg,.png,.gif,.webp,.mp4,.webm" dusk="asset-file" />
                    </label>
                @endcan

                <p class="text-sm text-gray-500 dark:text-gray-400" dusk="assets-scope-note">
                    Pictures and videos used inside ads. Separate from the media library, which is what your
                    screens play.
                    @if ($stores !== [])
                        An upload goes to the shop chosen in the Shop list.
                    @endif
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                @if ($stores !== [])
                    <select x-model="filterStore" @change="applyFilters()" class="form-select w-44 text-sm"
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

                                <p class="text-xs text-gray-400">
                                    <span x-text="item.kind === 'video' ? 'Video' : 'Image'"></span>
                                    <span x-show="item.width" x-cloak x-text="' · ' + item.width + '×' + item.height"></span>
                                    <span x-text="' · ' + sizeLabel(item)"></span>
                                </p>

                                <p class="truncate text-xs" x-bind:class="(item.used_by ?? []).length ? 'text-blue-600 dark:text-blue-400' : 'text-gray-400'"
                                   x-bind:dusk="'asset-usage-' + item.id" x-text="usageLabel(item)"></p>

                                @can('ad-destroy')
                                    <div class="pt-1">
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
                </p>

                <div class="mt-6 flex justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'confirm-asset-deletion')">Cancel</x-secondary-button>
                    <x-danger-button x-bind:disabled="deleting" dusk="confirm-asset-deletion-confirm">Delete file</x-danger-button>
                </div>
            </form>
        </x-modal>
    </div>
</x-app-layout>
