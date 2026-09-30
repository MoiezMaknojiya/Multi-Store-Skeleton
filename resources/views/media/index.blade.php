<x-app-layout>
    <x-slot name="header">
        <h1 class="page-title">{{ __('Media Library') }}</h1>
    </x-slot>

    {{-- Js::from, not @json: @json leaves its quotes raw and the first shop's name would close this
         attribute (see .claude/rules/02-project-conventions.md). $libraries is null inside a store. --}}
    <div x-data="mediaTable({{ Js::from(['libraries' => $libraries]) }})"
         class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        {{-- The filters above the list; Upload Files beside its search. --}}
        <div class="space-y-3">
            {{-- A grid, not flex: equal cells put the lists side by side on a desktop and stack them on a phone,
                 each with a visible name. Above the stores the first is the library: the platform's own (the
                 default) or one shop's — what is listed and where an upload lands (docs/CHANNEL-CONTENT-SPEC.md §3). --}}
            <div class="grid grid-cols-1 gap-2 {{ $libraries !== null ? 'sm:grid-cols-4 sm:max-w-4xl' : 'sm:grid-cols-3 sm:max-w-2xl' }}">
                @if ($libraries !== null)
                    <div>
                        <x-input-label for="media-filter-library" value="Library" />
                        <select id="media-filter-library" x-model="library" @change="applyFilters()" dusk="media-filter-library" class="form-select mt-1">
                            <option value="platform">Platform library</option>
                            @foreach ($libraries as $shop)
                                <option value="{{ $shop['id'] }}">{{ $shop['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div>
                    <x-input-label for="media-filter-type" value="Type" />
                    <select id="media-filter-type" x-model="filterType" @change="applyFilters()" dusk="media-filter-type" class="form-select mt-1">
                        <option value="">All types</option>
                        <option value="image">Images</option>
                        <option value="video">Videos</option>
                        <option value="html">Ad pages</option>
                    </select>
                </div>
                <div>
                    <x-input-label for="media-filter-orientation" value="Orientation" />
                    <select id="media-filter-orientation" x-model="filterOrientation" @change="applyFilters()" dusk="media-filter-orientation" class="form-select mt-1">
                        <option value="">Any orientation</option>
                        <option value="landscape">Landscape</option>
                        <option value="portrait">Portrait</option>
                    </select>
                </div>
                <div>
                <x-input-label for="media-sort" value="Sort by" />
                <select id="media-sort" x-model="sort" @change="applyFilters()" dusk="media-sort" class="form-select mt-1">
                    <option value="newest">Newest first</option>
                    <option value="oldest">Oldest first</option>
                    <option value="title_asc">Title A-Z</option>
                    <option value="title_desc">Title Z-A</option>
                    <option value="expiry_asc">Expiry soonest</option>
                    <option value="expiry_desc">Expiry latest</option>
                </select>
                </div>
            </div>

            {{-- The shop's 512 MB: its own inside a store, the chosen shop's above the stores, none for the
                 platform's own library. --}}
            <x-storage-meter />
        </div>

        <x-crud.table-wrapper title="All Media" searchPlaceholder="Search media..." :columns="6">
            @can('media-store')
                <x-slot name="actions">
                    <x-crud.add-button label="Upload Files" @click="openUploadModal()" dusk="upload-media" />
                </x-slot>
            @endcan
            <x-slot name="head">
                <th class="px-5 py-3 text-left font-semibold">Preview</th>
                <th class="px-5 py-3 text-left font-semibold">Title</th>
                <th class="px-5 py-3 text-left font-semibold">Type</th>
                <th class="px-5 py-3 text-left font-semibold">Size</th>
                <th class="px-5 py-3 text-left font-semibold">Schedule</th>
                <th class="px-5 py-3 text-right font-semibold">Actions</th>
            </x-slot>

            <x-slot name="body">
                <x-crud.table-empty :columns="6" itemsVar="items" message="No files yet."
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
                            <p class="text-xs text-gray-500 dark:text-gray-400" x-show="item.description" x-text="item.description"></p>
                        </td>
                        <td class="px-5 py-4 text-gray-600 dark:text-gray-300">
                            {{-- An Ad Builder page is stored as "html" — shown as what it is, an ad page. --}}
                            <span x-text="typeLabel(item)"></span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400" x-text="item.orientation ?? '-'"></span>
                        </td>
                        <td class="px-5 py-4 text-gray-600 dark:text-gray-300 whitespace-nowrap" x-text="formatSize(item.size)"></td>
                        <td class="px-5 py-4 text-gray-600 dark:text-gray-300 text-xs">
                            <span class="whitespace-nowrap" x-text="scheduleLabel(item)"></span>
                            <span x-show="isExpired(item)" class="block text-red-600 font-medium dark:text-red-400">Expired</span>
                        </td>
                        <td class="px-5 py-4">
                            {{-- askToDelete: a file a channel shows is refused at once, before any confirmation. --}}
                            <x-crud.table-actions editClick="openFormModal(item)" deleteClick="askToDelete(item)"
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

        {{-- Upload Modal: the shared uploader (docs/UPLOADS-SPEC.md). Files dropped or chosen go up in chunks and join
             the library as each arrives, titled by their names (Edit renames them). Closing it lets the uploads carry
             on; they are still listed when it opens again. --}}
        <x-modal name="media-upload-modal" :show="false" maxWidth="lg">
            {{-- The page listens here, not on the box: an expression on the box runs with the box's own `this`. --}}
            <div class="p-6" dusk="media-upload-form" x-on:upload-added="onUploaded($event.detail)">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Upload files</h2>

                @if ($libraries !== null)
                    <p class="alert-info mt-4" dusk="media-upload-library">
                        Files you upload here join <span class="font-semibold" x-text="libraryName()"></span>.
                    </p>
                @endif

                <x-upload-dropzone class="mt-4" purpose="media" mode="add" :multiple="true" add-url="/media" dusk="media"
                    context="{ library: libraries !== null ? String(library) : null, fields: libraries !== null && library !== 'platform' ? { store_id: library } : {}, storage: storage }"
                    hint="JPG, PNG, GIF, WEBP, MP4 or WEBM, up to 250 MB each. Videos up to 5 minutes." />

                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <button type="button" class="btn-secondary" @click="closeUploadModal()" dusk="media-upload-close">Close</button>
                </div>
            </div>
        </x-modal>

        {{-- Edit Modal --}}
        <x-modal name="media-form-modal" :show="false" maxWidth="2xl" persistent>
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Edit File</h2>

                <form @submit.prevent="saveItem" novalidate dusk="media-form" class="mt-4 space-y-4">
                    <x-crud.form-field label="Title" field="title" :required="true">
                        <x-text-input x-model="form.title" dusk="media-edit-title" maxlength="255" autocomplete="off" />
                    </x-crud.form-field>

                    {{-- h-auto: form-input is a 40 px line, and a description is a few lines. --}}
                    <x-crud.form-field label="Description" field="description">
                        <textarea x-model="form.description" dusk="media-edit-description" rows="3" maxlength="2000"
                                  class="form-input h-auto min-h-24 py-2"></textarea>
                    </x-crud.form-field>

                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Leave both empty to always play.
                    </p>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <x-crud.form-field label="Start date and time" field="starts_at">
                            <input type="datetime-local" x-model="form.starts_at" dusk="media-starts-at" class="form-input">
                        </x-crud.form-field>
                        <x-crud.form-field label="Expiry date and time" field="expires_at">
                            <input type="datetime-local" x-model="form.expires_at" dusk="media-expires-at" class="form-input">
                        </x-crud.form-field>
                    </div>

                    <x-crud.form-actions savingVar="saving" dusk="media-save" />
                </form>
            </div>
        </x-modal>
    </div>
</x-app-layout>
