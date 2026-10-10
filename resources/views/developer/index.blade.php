{{-- Developer (owner, 2026-10-10 — "super admin mein developer ka tab ho sub se end mein"): the TV app's versions. The super admin
     uploads the APK built with the release key; its version is read from the file (typed only when it cannot be), and every
     TV with the app is offered the newest one (PlayerAppController, /player-app/latest.json). --}}
<x-app-layout>
    <x-slot name="header">
        <h1 class="page-title">{{ __('Developer') }}</h1>
    </x-slot>

    <div x-data="developerPage()" class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6" dusk="developer-page">

        @can('player-app-store')
            <section class="card p-6" aria-labelledby="tv-app-title">
                <h2 id="tv-app-title" class="text-subheading">TV App</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Upload a new version built with your release key. Every TV with the app ({{ $package }}) is offered it the
                    next time it starts, or within a day.
                </p>

                <form @submit.prevent="publish($event)" novalidate class="mt-5 space-y-4" dusk="tv-app-form">
                    {{-- The box's events are heard here, on the page's own element around it (never on the box). --}}
                    <div x-ref="appUpload"
                         x-on:upload-picked="onPicked($event.detail)"
                         x-on:upload-ready="onReady($event.detail)"
                         x-on:upload-cleared="clearFile()"
                         x-on:upload-busy="uploading = $event.detail.busy">
                        <x-crud.form-field label="App file" field="file" :required="true">
                            <x-upload-dropzone purpose="app-release" mode="form" kind="apk" :max-bytes="\App\Models\PlayerAppRelease::MAX_BYTES"
                                               dusk="tv-app" hint="The app's .apk file, up to {{ intdiv(\App\Models\PlayerAppRelease::MAX_BYTES, 1024 * 1024) }} MB." />
                        </x-crud.form-field>
                    </div>

                    <p x-show="checking" x-cloak class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300" dusk="tv-app-checking">
                        <x-spinner class="text-blue-600 dark:text-blue-400" /> Reading the version from the file...
                    </p>

                    {{-- The version: the file's own, or — only when it cannot be read — typed. --}}
                    <p x-show="read && read.version_read" x-cloak class="alert-success" dusk="tv-app-version-read">
                        Version <strong x-text="read?.version_name"></strong> (code <span x-text="read?.version_code"></span>), read from the file.
                    </p>

                    <div x-show="read && ! read.version_read" x-cloak class="space-y-4" dusk="tv-app-version-typed">
                        <p class="alert-warning">
                            <span x-show="typesName() && typesCode()">The version could not be read from this file.</span>
                            <span x-show="! typesName()">The file says version <strong x-text="read?.version_name"></strong>, but not its code.</span>
                            <span x-show="! typesCode()">The file says version code <strong x-text="read?.version_code"></strong>, but not its name.</span>
                            Type what you built: versionName and versionCode in the app's build.gradle.kts.
                        </p>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div x-show="typesName()">
                                <x-crud.form-field label="Version name" field="version_name" :required="true">
                                    <input id="tv-app-version-name" type="text" x-model="form.version_name" maxlength="32" placeholder="1.1"
                                           class="form-input" autocomplete="off" dusk="tv-app-version-name">
                                </x-crud.form-field>
                            </div>
                            <div x-show="typesCode()">
                                <x-crud.form-field label="Version code" field="version_code" :required="true">
                                    <input id="tv-app-version-code" type="number" x-model="form.version_code" min="1" max="2100000000" step="1"
                                           inputmode="numeric" placeholder="2" class="form-input" dusk="tv-app-version-code">
                                </x-crud.form-field>
                            </div>
                        </div>
                    </div>

                    <p x-show="read?.current" x-cloak class="text-sm text-gray-600 dark:text-gray-300" dusk="tv-app-current">
                        TVs are offered <span class="font-medium" x-text="read?.current?.version_name"></span>
                        (code <span x-text="read?.current?.version_code"></span>) now: the new one must have a higher code.
                    </p>

                    <x-crud.form-field label="What's new" field="notes">
                        <textarea id="tv-app-notes" x-model="form.notes" rows="3" maxlength="500" class="form-input h-auto py-2"
                                  placeholder="Shown on the TV with the update, optional" dusk="tv-app-notes"></textarea>
                    </x-crud.form-field>

                    <div class="flex flex-wrap justify-end gap-3">
                        <x-primary-button x-bind:disabled="saving || ! canPublish()" dusk="tv-app-publish">
                            <x-spinner x-show="saving" x-cloak />
                            <span x-text="saving ? 'Publishing...' : 'Publish Version'">Publish Version</span>
                        </x-primary-button>
                    </div>
                </form>
            </section>
        @endcan

        <x-crud.table-wrapper title="Versions" :search="false" :columns="4"
                              description="The newest is the one TVs are offered. A TV that has a version keeps it when it is deleted.">
            <x-slot name="head">
                <th class="px-5 py-3 text-left font-semibold">Version</th>
                <th class="px-5 py-3 text-left font-semibold">Uploaded</th>
                <th class="px-5 py-3 text-left font-semibold">Size</th>
                <th class="px-5 py-3 text-right font-semibold">Actions</th>
            </x-slot>

            <x-slot name="body">
                <x-crud.table-empty :columns="4" itemsVar="items" message="No version uploaded yet." />

                <template x-for="item in items" :key="item.id">
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50" x-bind:dusk="'tv-app-release-' + item.id">
                        <td class="px-5 py-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium text-gray-800 dark:text-white" x-text="item.version_name"></span>
                                <span class="text-xs text-gray-500 dark:text-gray-400" x-text="'code ' + item.version_code"></span>
                                <span x-show="item.current" class="badge-success">Offered to TVs</span>
                                <span x-show="! item.version_read_from_file" class="badge-warning" title="The version was typed: it could not be read from the file">Typed</span>
                            </div>
                            <p x-show="item.notes" class="cell-prose text-xs text-gray-500 dark:text-gray-400" x-text="item.notes"></p>
                        </td>
                        <td class="px-5 py-4 whitespace-nowrap text-gray-600 dark:text-gray-300">
                            <span x-text="uploadedOn(item)"></span>
                            <span x-show="item.uploaded_by" class="block text-xs text-gray-500 dark:text-gray-400" x-text="'by ' + item.uploaded_by"></span>
                        </td>
                        <td class="px-5 py-4 whitespace-nowrap text-gray-600 dark:text-gray-300" x-text="item.size_words"></td>
                        <td class="px-5 py-4">
                            <div class="flex justify-end gap-2">
                                <a x-bind:href="item.url" download class="btn-row-neutral" x-bind:aria-label="'Download version ' + item.version_name"
                                   x-bind:dusk="'tv-app-download-' + item.id">Download</a>
                                @can('player-app-destroy')
                                    <button type="button" class="btn-row-danger" @click="confirmDelete(item)" x-bind:disabled="deleting"
                                            x-bind:aria-label="'Delete version ' + item.version_name" x-bind:dusk="'tv-app-delete-' + item.id">Delete</button>
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

        @can('player-app-destroy')
            <x-modal name="confirm-tv-app-deletion" :show="false" maxWidth="md" focusable>
                <form @submit.prevent="deleteItem()" class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Delete Version</h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        Delete version <span class="font-semibold" x-text="selectedItem?.version_name"></span>?
                        <span x-show="selectedItem?.current">TVs are offered the version before it instead; a TV that has this one keeps it.</span>
                    </p>
                    <div class="mt-6 flex flex-wrap justify-end gap-3">
                        <x-secondary-button x-on:click="$dispatch('close-modal', 'confirm-tv-app-deletion')">Cancel</x-secondary-button>
                        <x-danger-button x-bind:disabled="deleting" dusk="confirm-tv-app-deletion-confirm">
                            <x-spinner x-show="deleting" x-cloak />
                            Delete Version
                        </x-danger-button>
                    </div>
                </form>
            </x-modal>
        @endcan
    </div>
</x-app-layout>
