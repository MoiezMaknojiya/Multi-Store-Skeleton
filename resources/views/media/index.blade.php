<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-white leading-tight">{{ __('Media Library') }}</h2>
    </x-slot>

    {{-- Js::from, not @json: @json leaves its quotes raw and the first shop's name would close this
         attribute (see .claude/rules/02-project-conventions.md). $libraries is null inside a store. --}}
    <div x-data="mediaTable({{ Js::from(['libraries' => $libraries]) }})"
         class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        {{-- Upload on its own line, with the filters sitting in a row underneath —
             they belong to the table below them, not to the upload button. --}}
        <div class="space-y-3 mb-6">
            @can('media-store')
            <div class="flex flex-wrap items-center gap-3">
                <x-crud.add-button label="Upload File" @click="openUploadModal()" dusk="upload-media" />
                @if ($libraries !== null)
                    <p class="text-sm text-gray-500 dark:text-gray-400" dusk="media-upload-target-note">
                        An upload goes to the library chosen in the Library list.
                    </p>
                @endif
            </div>
            @endcan

            {{-- A grid, not flex: .form-select is width:100%, so in a flex row each
                 select would claim the full line. Equal cells put them side by
                 side on a desktop and stack them on a phone. Above the stores the first
                 cell is the library: the platform's own (the default) or one shop's —
                 what is listed and where an upload lands (docs/CHANNEL-CONTENT-SPEC.md §3). --}}
            <div class="grid grid-cols-1 gap-2 {{ $libraries !== null ? 'sm:grid-cols-4 sm:max-w-4xl' : 'sm:grid-cols-3 sm:max-w-2xl' }}">
                @if ($libraries !== null)
                    <select x-model="library" @change="applyFilters()" dusk="media-filter-library" class="form-select text-sm"
                            aria-label="Library">
                        <option value="platform">Platform library</option>
                        @foreach ($libraries as $shop)
                            <option value="{{ $shop['id'] }}">{{ $shop['name'] }}</option>
                        @endforeach
                    </select>
                @endif
                <select x-model="filterType" @change="applyFilters()" dusk="media-filter-type" class="form-select text-sm">
                    <option value="">All types</option>
                    <option value="image">Images</option>
                    <option value="video">Videos</option>
                    <option value="html">Ad pages</option>
                </select>
                <select x-model="filterOrientation" @change="applyFilters()" dusk="media-filter-orientation" class="form-select text-sm">
                    <option value="">Any orientation</option>
                    <option value="landscape">Landscape</option>
                    <option value="portrait">Portrait</option>
                </select>
                <select x-model="sort" @change="applyFilters()" dusk="media-sort" class="form-select text-sm">
                    <option value="newest">Newest first</option>
                    <option value="oldest">Oldest first</option>
                    <option value="title_asc">Title A-Z</option>
                    <option value="title_desc">Title Z-A</option>
                    <option value="expiry_asc">Expiry soonest</option>
                    <option value="expiry_desc">Expiry latest</option>
                </select>
            </div>
        </div>

        <x-crud.table-wrapper title="All Media" searchPlaceholder="Search media (title, description...)" :columns="6">
            <x-slot name="head">
                <th class="px-5 py-3 text-left font-semibold">Preview</th>
                <th class="px-5 py-3 text-left font-semibold">Title</th>
                <th class="px-5 py-3 text-left font-semibold">Type</th>
                <th class="px-5 py-3 text-left font-semibold">Size</th>
                <th class="px-5 py-3 text-left font-semibold">Schedule</th>
                <th class="px-5 py-3 text-right font-semibold">Actions</th>
            </x-slot>

            <x-slot name="body">
                <x-crud.table-empty :columns="6" itemsVar="items" message="No media found." />

                <template x-for="item in items" :key="item.id">
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                        <td class="px-5 py-4">
                            <div class="relative w-24 h-14 rounded overflow-hidden bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                                {{-- An upright picture is shown whole, not cut to its middle (docs/AD-BUILDER-SPEC.md §12). --}}
                                <template x-if="item.thumbnail_url">
                                    <img :src="item.thumbnail_url" :alt="item.title" class="w-full h-full"
                                         x-bind:class="item.orientation === 'portrait' ? 'object-contain' : 'object-cover'">
                                </template>
                                <template x-if="!item.thumbnail_url">
                                    <span class="text-xs text-gray-400" x-text="typeLabel(item)"></span>
                                </template>
                                <template x-if="item.duration_seconds">
                                    <span class="absolute bottom-1 right-1 px-1 rounded bg-black/70 text-white text-[10px]"
                                          x-text="formatDuration(item.duration_seconds)"></span>
                                </template>
                            </div>
                        </td>
                        <td class="px-5 py-4">
                            <p class="font-medium text-gray-800 dark:text-white" x-text="item.title"></p>
                            <p class="text-xs text-gray-400" x-show="item.description" x-text="item.description"></p>
                        </td>
                        <td class="px-5 py-4 text-gray-600 dark:text-gray-300">
                            {{-- An Ad Builder page is stored as "html" — shown as what it is, an ad page. --}}
                            <span x-text="typeLabel(item)"></span>
                            <span class="block text-xs text-gray-400" x-text="item.orientation ?? '-'"></span>
                        </td>
                        <td class="px-5 py-4 text-gray-600 dark:text-gray-300" x-text="formatSize(item.size)"></td>
                        <td class="px-5 py-4 text-gray-600 dark:text-gray-300 text-xs">
                            <span x-text="scheduleLabel(item)"></span>
                            <span x-show="isExpired(item)" class="block text-red-500 font-medium">Expired</span>
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
            disabledVar="deleting" />

        {{-- Upload Modal --}}
        <x-modal name="media-upload-modal" :show="false" maxWidth="lg">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Upload File</h2>

                <form @submit.prevent="uploadFile" dusk="media-upload-form" class="mt-4 space-y-4">
                    @if ($libraries !== null)
                        <p class="rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-200"
                           dusk="media-upload-library">
                            It joins <span class="font-semibold" x-text="libraryName()"></span>.
                        </p>
                    @endif

                    <x-crud.form-field label="File" field="file" :required="true">
                        <input type="file" x-ref="fileInput" dusk="media-file"
                               accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm"
                               @change="onFileSelected($event)"
                               class="block w-full text-sm text-gray-600 dark:text-gray-300
                                      file:mr-3 file:py-2 file:px-4 file:rounded-md file:border-0
                                      file:text-sm file:font-medium file:bg-blue-600 file:text-white
                                      hover:file:bg-blue-700">
                        <p class="mt-1 text-xs text-gray-400">Images (JPG, PNG, GIF, WEBP) and videos (MP4, WEBM), up to 250 MB.</p>
                    </x-crud.form-field>

                    <x-crud.form-field label="Title" field="title">
                        <x-text-input x-model="uploadForm.title" dusk="media-title" class="block w-full"
                                      placeholder="Leave blank to use the file name" autocomplete="off" />
                    </x-crud.form-field>

                    <div x-show="preparing" x-cloak class="text-xs text-gray-500">Reading the file...</div>

                    {{-- This modal is not the shared form modal, so Cancel has to close
                         the upload one — the default closeFormModal() would target the
                         edit modal and leave this one open. Upload waits while a video is
                         still being measured, or it would go without its length and poster. --}}
                    <x-crud.form-actions cancelAction="closeUploadModal()" savingVar="saving || preparing"
                        saveLabel="Upload" dusk="media-upload-save" cancelDusk="media-upload-cancel" />
                </form>
            </div>
        </x-modal>

        {{-- Edit Modal --}}
        <x-modal name="media-form-modal" :show="false" maxWidth="2xl">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Edit File</h2>

                <form @submit.prevent="saveItem" dusk="media-form" class="mt-4 space-y-4">
                    <x-crud.form-field label="Title" field="title" :required="true">
                        <x-text-input x-model="form.title" dusk="media-edit-title" class="block w-full" autocomplete="off" />
                    </x-crud.form-field>

                    <x-crud.form-field label="Description" field="description">
                        <textarea x-model="form.description" dusk="media-edit-description" rows="3"
                                  class="form-input block w-full"></textarea>
                    </x-crud.form-field>

                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        The file will not play on any screen before the start or after the expiry. Leave both blank to always play.
                    </p>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <x-crud.form-field label="Start date and time" field="starts_at">
                            <input type="datetime-local" x-model="form.starts_at" dusk="media-starts-at" class="form-input block w-full">
                        </x-crud.form-field>
                        <x-crud.form-field label="Expiry date and time" field="expires_at">
                            <input type="datetime-local" x-model="form.expires_at" dusk="media-expires-at" class="form-input block w-full">
                        </x-crud.form-field>
                    </div>

                    <x-crud.form-actions savingVar="saving" dusk="media-save" />
                </form>
            </div>
        </x-modal>
    </div>
</x-app-layout>
