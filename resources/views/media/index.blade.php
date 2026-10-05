<x-app-layout>
    <x-slot name="header">
        <h1 class="page-title">{{ __('Media Library') }}</h1>
    </x-slot>

    {{-- Js::from, not @json: @json leaves its quotes raw and the first organization's name would close this
         attribute (see .claude/rules/02-project-conventions.md). $libraries is null inside an organization. --}}
    <div x-data="mediaTable({{ Js::from(['libraries' => $libraries, 'storage' => $storage]) }})"
         class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        {{-- As on the Ad Builder's Assets page (owner, 2026-09-30): the organization's storage, and the list's filters and its
             search on one line beside it; then the drop box, on the page itself; then the list. Above the organizations the
             first list is the library: the platform's own (the default) or one organization's — what is listed, where an upload
             lands and whose storage shows (docs/CHANNEL-CONTENT-SPEC.md §3). The platform's own has no wall, so no
             meter. --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            {{-- Narrower than the Assets page's, so the meter, the three filters and the search share one line on a
                 laptop (1366 px) — and the line wraps below it where there is no room. --}}
            <x-storage-meter class="w-full sm:w-60" :initial="$storage" />

            <div class="ml-auto flex w-full flex-wrap items-center gap-3 sm:w-auto" dusk="media-filters">
                @if ($libraries !== null)
                    <select x-model="library" @change="applyFilters()" dusk="media-filter-library" aria-label="Library"
                            class="form-select sm:w-44">
                        <option value="platform">Platform library</option>
                        @foreach ($libraries as $organization)
                            <option value="{{ $organization['id'] }}">{{ $organization['name'] }}</option>
                        @endforeach
                    </select>
                @endif
                <select x-model="filterType" @change="applyFilters()" dusk="media-filter-type" aria-label="Type"
                        class="form-select sm:w-32">
                    <option value="">All types</option>
                    <option value="image">Images</option>
                    <option value="video">Videos</option>
                    <option value="html">Ad pages</option>
                </select>
                <select x-model="filterOrientation" @change="applyFilters()" dusk="media-filter-orientation" aria-label="Orientation"
                        class="form-select sm:w-40">
                    <option value="">Any orientation</option>
                    <option value="landscape">Landscape</option>
                    <option value="portrait">Portrait</option>
                </select>
                <select x-model="sort" @change="applyFilters()" dusk="media-sort" aria-label="Sort by"
                        class="form-select sm:w-40">
                    <option value="newest">Newest first</option>
                    <option value="oldest">Oldest first</option>
                    <option value="title_asc">Title A-Z</option>
                    <option value="title_desc">Title Z-A</option>
                </select>
                <x-crud.search-input placeholder="Search media..." width="sm:w-56" />
            </div>
        </div>

        @can('media-store')
            {{-- The shared uploader (docs/UPLOADS-SPEC.md): files dropped or chosen go up in chunks and join the library
                 chosen above as each arrives, titled by their names (Edit renames them). The page listens here, not on
                 the box: an expression on the box runs with the box's own `this`. --}}
            <div x-on:upload-added="onUploaded($event.detail)" dusk="media-upload">
                <x-upload-dropzone purpose="media" mode="add" :multiple="true" add-url="/media" dusk="media"
                    context="{ library: libraries !== null ? String(library) : null, fields: libraries !== null && library !== 'platform' ? { organization_id: library } : {}, storage: storage }"
                    hint="JPG, PNG, GIF, WEBP, MP4 or WEBM, up to 250 MB each. Videos up to 5 minutes." />
            </div>
        @endcan

        {{-- No header of its own: its filters and its search are above. --}}
        <x-crud.table-wrapper :search="false" :columns="5">
            <x-slot name="head">
                <th class="px-5 py-3 text-left font-semibold">Preview</th>
                <th class="px-5 py-3 text-left font-semibold">Title</th>
                <th class="px-5 py-3 text-left font-semibold">Type</th>
                <th class="px-5 py-3 text-left font-semibold">Size</th>
                <th class="px-5 py-3 text-right font-semibold">Actions</th>
            </x-slot>

            <x-slot name="body">
                <x-crud.table-empty :columns="5" itemsVar="items" message="No files yet."
                    filtered="filterType !== '' || filterOrientation !== ''"
                    clearFilters="filterType = ''; filterOrientation = ''; applyFilters()" />

                <template x-for="item in items" :key="item.id">
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                        <td class="px-5 py-4">
                            <div class="relative w-24 h-14 rounded overflow-hidden bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                                {{-- An upright picture is shown whole, not cut to its middle (docs/AD-BUILDER-SPEC.md §12). --}}
                                {{-- The title is beside it: the picture itself says nothing more (alt=""). --}}
                                <template x-if="item.thumbnail_url">
                                    <img :src="item.thumbnail_url" alt="" loading="lazy" class="w-full h-full"
                                         x-bind:class="item.orientation === 'portrait' ? 'object-contain' : 'object-cover'">
                                </template>
                                <template x-if="!item.thumbnail_url">
                                    <span class="text-xs text-gray-500 dark:text-gray-300" x-text="typeLabel(item)"></span>
                                </template>
                                <template x-if="item.duration_seconds">
                                    <span class="absolute bottom-1 right-1 px-1 rounded bg-black/70 text-white text-[10px]"
                                          x-text="formatDuration(item.duration_seconds)"></span>
                                </template>
                            </div>
                        </td>
                        <td class="cell-prose px-5 py-4">
                            <p class="font-medium text-gray-800 dark:text-white" x-text="item.title"></p>
                        </td>
                        <td class="px-5 py-4 text-gray-600 dark:text-gray-300">
                            {{-- An Ad Builder page is stored as "html" — shown as what it is, an ad page. --}}
                            <span x-text="typeLabel(item)"></span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400" x-text="item.orientation ?? '-'"></span>
                        </td>
                        <td class="px-5 py-4 text-gray-600 dark:text-gray-300 whitespace-nowrap" dusk="media-size" x-text="formatSize(item.size)"></td>
                        <td class="px-5 py-4">
                            {{-- askToDelete: a file a channel shows is refused at once, before any confirmation. --}}
                            {{-- A file keeps its name alone (owner, 2026-10-01): when it plays is said on its playlist line. --}}
                            <x-crud.table-actions editClick="openFormModal(item)" deleteClick="askToDelete(item)" editLabel="Rename"
                                editCan="media-update" deleteCan="media-destroy" dusk="media" />
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
            name="confirm-media-deletion"
            entity="File"
            nameExpression="selectedItem?.title"
            deleteAction="deleteItem()"
            disabledVar="deleting">
            <x-slot name="note">It also comes off every screen that plays it.</x-slot>
        </x-crud.confirm-delete-modal>

        {{-- Rename: a file's name is all it keeps of what a person types; when it plays is its playlist line's. --}}
        <x-modal name="media-form-modal" :show="false" maxWidth="md" focusable>
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Rename File</h2>

                <form @submit.prevent="saveItem" novalidate dusk="media-form" class="mt-4 space-y-4">
                    <x-crud.form-field label="Title" field="title" :required="true">
                        <x-text-input x-model="form.title" dusk="media-edit-title" maxlength="255" autocomplete="off" />
                    </x-crud.form-field>

                    <x-crud.form-actions savingVar="saving" dusk="media-save" />
                </form>
            </div>
        </x-modal>
    </div>
</x-app-layout>
