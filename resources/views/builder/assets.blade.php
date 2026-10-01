<x-app-layout>
    {{-- Reached from the sidebar, under Ad Builder: the page is named for what it holds. --}}
    <x-slot name="header">
        <h1 class="page-title">{{ __('Assets') }}</h1>
    </x-slot>

    <div x-data="builderAssetsTable({{ Js::from(['storage' => $storage]) }})" class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        {{-- The organization's storage where a note once stood (owner, 2026-09-30), the organization list and the search beside it. The
             meter shows only for an organization's shelf: the platform's shared one (All organizations) has no wall. --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            {{-- The shelf counts toward the organization's 512 MB like its library does. --}}
            <x-storage-meter class="w-full sm:w-96" :initial="$storage" />

            <div class="ml-auto flex flex-wrap items-center gap-3">
                @if ($aboveTheOrganizations)
                    <select x-model="filterOrganization" @change="applyFilters()" class="form-select sm:w-52"
                            dusk="assets-filter-organization" aria-label="Organization">
                        <option value="">All organizations</option>
                        @foreach ($organizations as $organization)
                            <option value="{{ $organization['id'] }}">{{ $organization['name'] }}</option>
                        @endforeach
                    </select>
                @endif

                <x-crud.search-input placeholder="Search assets..." />
            </div>
        </div>

        @can('ad-store')
            {{-- The shared uploader (docs/UPLOADS-SPEC.md): each picture or video joins the shelf as it arrives. The
                 page listens here, not on the box: an expression on the box runs with the box's own `this`. --}}
            {{-- Above the organizations an organization chosen in the Organization list gets the file; with All organizations it is shared with every
                 organization. --}}
            <div x-on:upload-added="onUploaded($event.detail)" dusk="upload-asset">
                <x-upload-dropzone purpose="asset" mode="add" :multiple="true" add-url="/builder/assets" dusk="asset"
                    :max-video-seconds="\App\Models\BuilderAsset::MAX_VIDEO_SECONDS"
                    context="{ organization: filterOrganization || null, fields: filterOrganization ? { organization_id: filterOrganization } : {}, storage: storage }"
                    hint="JPG, PNG, GIF, WEBP, MP4 or WEBM, up to 250 MB each. Videos up to 30 seconds." />
            </div>
        @endcan

        {{-- The same three "nothing to show" cases as every listing (x-crud.table-empty). --}}
        <div class="card" data-list-card>
            <div class="p-5" x-bind:aria-busy="loading ? 'true' : 'false'">
                <template x-if="loading">
                    <p class="py-12 text-center text-sm text-muted-soft">
                        <span class="inline-flex items-center gap-2"><x-spinner class="text-blue-600 dark:text-blue-400" /> Loading...</span>
                    </p>
                </template>

                <template x-if="!loading && items.length === 0 && loadFailed">
                    <div class="mx-auto max-w-md space-y-3 py-12 text-center" role="alert" dusk="table-load-failed">
                        <p class="text-sm text-gray-700 dark:text-gray-200">Could not load the shelf. Check the connection, then try again.</p>
                        <button type="button" class="btn-row-neutral" @click="fetchItems()" dusk="table-try-again">Try Again</button>
                    </div>
                </template>

                <template x-if="!loading && items.length === 0 && !loadFailed && search">
                    <div class="mx-auto max-w-md space-y-3 py-12 text-center" dusk="table-no-match">
                        <p class="text-sm text-gray-700 dark:text-gray-200">Nothing matches &ldquo;<span class="font-medium" x-text="search"></span>&rdquo;.</p>
                        <button type="button" class="btn-row-neutral" @click="search = ''" dusk="table-clear-search">Clear Search</button>
                    </div>
                </template>

                <template x-if="!loading && items.length === 0 && !loadFailed && !search">
                    <p class="py-16 text-center text-sm text-gray-500 dark:text-gray-400" dusk="assets-empty">
                        No files yet.
                    </p>
                </template>

                <div x-show="!loading && items.length > 0" x-cloak
                     class="grid grid-cols-2 gap-5 sm:grid-cols-3 lg:grid-cols-4" dusk="assets-grid">
                    <template x-for="item in items" :key="item.id">
                        <div class="group overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700"
                             x-bind:dusk="'asset-card-' + item.id">

                            {{-- The picture, and over it — under the mouse, or while the keyboard is in the card; always on a
                                 touch screen, which has no hover — what the file is in the middle and Delete in the top-left
                                 corner (owner, 2026-09-30). --}}
                            <div class="relative aspect-video bg-gray-100 dark:bg-gray-900">
                                {{-- An upright picture is shown whole, not cut to its middle. --}}
                                <img x-show="item.thumbnail_url" x-cloak x-bind:src="item.thumbnail_url" alt="" loading="lazy"
                                     class="h-full w-full"
                                     x-bind:class="Number(item.height) > Number(item.width) ? 'object-contain' : 'object-cover'" />
                                <span x-show="!item.thumbnail_url" x-cloak
                                      class="flex h-full w-full items-center justify-center text-xs text-muted-soft"
                                      x-text="item.kind === 'video' ? 'Video' : 'Image'"></span>

                                <div class="pointer-events-none absolute inset-0 flex items-center justify-center bg-black/30 opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100 pointer-coarse:bg-transparent pointer-coarse:opacity-100">
                                    <span class="rounded-full bg-black/75 px-2.5 py-1 text-xs font-medium text-white"
                                          x-bind:dusk="'asset-details-' + item.id" x-text="assetDetails(item)"></span>
                                </div>

                                {{-- An organization's own file with Delete Ads; a shared one only above the organizations: the row says
                                     which this person may (can_delete), the controller asks again. --}}
                                @can('ad-destroy')
                                    <button type="button" x-show="item.can_delete" x-cloak @click="askToDelete(item)"
                                            x-bind:aria-label="'Delete ' + item.title" title="Delete"
                                            x-bind:dusk="'delete-asset-' + item.id"
                                            class="absolute left-2 top-2 inline-flex h-8 w-8 items-center justify-center rounded-full bg-white/95 text-red-700 shadow-sm opacity-0 transition-opacity hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500 group-hover:opacity-100 group-focus-within:opacity-100 pointer-coarse:opacity-100 dark:bg-gray-800/95 dark:text-red-400 dark:hover:bg-gray-800">
                                        <x-icon name="trash" />
                                    </button>
                                @endcan
                            </div>

                            <div class="space-y-2 p-3">
                                <p class="truncate text-sm font-medium text-gray-800 dark:text-white" x-bind:title="item.title"
                                   x-bind:dusk="'asset-title-' + item.id" x-text="item.title"></p>

                                {{-- Two pills: whose it is (above the organizations its organization or "Every organization"; inside an organization, the
                                     platform's) and whether an ad uses it — green in use, grey not yet. --}}
                                <div class="flex flex-wrap items-center gap-1">
                                    <span x-show="item.owner_label" x-cloak x-bind:class="item.shared ? 'badge-info' : 'badge-neutral'"
                                          x-text="item.owner_label" x-bind:dusk="'asset-owner-' + item.id"></span>
                                    <span class="max-w-full" x-bind:class="isUsed(item) ? 'badge-success' : 'badge-neutral'"
                                          x-bind:title="usageLabel(item)" x-bind:dusk="'asset-usage-' + item.id">
                                        <span class="min-w-0 truncate" x-text="usageLabel(item)"></span>
                                    </span>
                                </div>
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
                    <span class="font-medium" x-text="selectedItem?.title"></span> goes for good. No ad uses it.
                    <span x-show="selectedItem?.shared" x-cloak dusk="confirm-asset-deletion-shared">It is shared with every
                        organization, so it goes from every organization's shelf.</span>
                </p>

                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'confirm-asset-deletion')">Cancel</x-secondary-button>
                    <x-danger-button x-bind:disabled="deleting" dusk="confirm-asset-deletion-confirm">
                        <x-spinner x-show="deleting" x-cloak />
                        Delete File
                    </x-danger-button>
                </div>
            </form>
        </x-modal>
    </div>
</x-app-layout>
