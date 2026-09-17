<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-white leading-tight">{{ __('Media Library') }}</h2>
    </x-slot>

    <div x-data="mediaTable({ hasStore: @json((bool) session('current_store_id')) })"
         class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        {{-- Upload on its own line, with the filters sitting in a row underneath —
             they belong to the table below them, not to the upload button. --}}
        <div class="space-y-3 mb-6">
            @can('media-store')
            <div>
                <x-crud.add-button label="Upload File" @click="openUploadModal()" dusk="upload-media" />
            </div>
            @endcan

            {{-- A grid, not flex: .form-select is width:100%, so in a flex row each
                 select would claim the full line. Three equal cells put them side by
                 side on a desktop and stack them on a phone. --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 sm:max-w-2xl">
                <select x-model="filterType" @change="applyFilters()" dusk="media-filter-type" class="form-select text-sm">
                    <option value="">All types</option>
                    <option value="image">Images</option>
                    <option value="video">Videos</option>
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
                                <template x-if="item.thumbnail_url">
                                    <img :src="item.thumbnail_url" :alt="item.title" class="w-full h-full object-cover">
                                </template>
                                <template x-if="!item.thumbnail_url">
                                    <span class="text-xs text-gray-400" x-text="item.type === 'video' ? 'Video' : 'Image'"></span>
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
                            <span class="capitalize" x-text="item.type"></span>
                            <span class="block text-xs text-gray-400" x-text="item.orientation ?? '-'"></span>
                        </td>
                        <td class="px-5 py-4 text-gray-600 dark:text-gray-300" x-text="formatSize(item.size)"></td>
                        <td class="px-5 py-4 text-gray-600 dark:text-gray-300 text-xs">
                            <span x-text="scheduleLabel(item)"></span>
                            <span x-show="isExpired(item)" class="block text-red-500 font-medium">Expired</span>
                        </td>
                        <td class="px-5 py-4">
                            <div class="flex items-center justify-end gap-2">
                                @can('media-update')
                                <button @click="openFormModal(item)" x-bind:dusk="'edit-media-' + item.id"
                                    class="btn-row-neutral">Edit</button>
                                @endcan

                                @can('media-destroy')
                                <button @click="confirmDelete(item)" x-bind:dusk="'delete-media-' + item.id"
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
                    <div x-show="!hasStore" x-cloak class="rounded-md bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
                        Select a store first - media belongs to the store it is uploaded in.
                    </div>

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
                         edit modal and leave this one open. --}}
                    <x-crud.form-actions cancelAction="closeUploadModal()" savingVar="saving"
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
