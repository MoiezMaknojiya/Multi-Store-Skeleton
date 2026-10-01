{{-- The uploader every page that takes a file uses (docs/UPLOADS-SPEC.md, resources/js/core/upload-dropzone.js): a box a
     file is dropped on or chosen with, and a row per file with its preview, its progress and its buttons. The chooser is
     the box's own input, out of sight but not out of reach: a click, Enter or Space opens it. `context` is an Alpine
     expression the page keeps the box up to date with: where the file goes, the fields its door needs besides, and the
     shop's storage. A page listens for the box's events (upload-added, upload-ready, …) on an element of its OWN around
     the box, never on the box: an expression on the box runs with the box's `this`, and a page method called from there
     would write its own state into the box's (a list refreshed there once replaced the box's rows). --}}
@props([
    'purpose',
    'mode' => 'add',
    'multiple' => false,
    'addUrl' => null,
    'context' => '{}',
    'dusk' => 'upload',
    'hint' => null,
    'maxVideoSeconds' => \App\Models\Media::MAX_VIDEO_SECONDS,
    'videoNoun' => 'A video',
    'needsStore' => null,
])

<div x-data="uploadDropzone({{ Js::from([
        'purpose' => $purpose,
        'mode' => $mode,
        'multiple' => (bool) $multiple,
        'addUrl' => $addUrl,
        'maxVideoSeconds' => (int) $maxVideoSeconds,
        'videoNoun' => $videoNoun,
        'needsStore' => $needsStore,
    ]) }})"
     x-effect="context = ({{ $context }})"
     {{ $attributes->merge(['class' => 'space-y-3']) }}
     dusk="{{ $dusk }}-dropzone">

    {{-- Its colours are written on it, never only in a binding: before Alpine starts, a border with no colour of
         its own is drawn in the text's colour (Tailwind v4), and the box flashed black as a page opened. A file
         held over it turns it blue (data-dragging). --}}
    <label class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-4 py-7 text-center transition-colors
                  border-gray-300 bg-gray-50/60 hover:border-blue-400 hover:bg-blue-50/50
                  dark:border-gray-600 dark:bg-gray-900/40 dark:hover:border-blue-500
                  data-[dragging=true]:border-blue-500 data-[dragging=true]:bg-blue-50 dark:data-[dragging=true]:border-blue-400 dark:data-[dragging=true]:bg-blue-950/40
                  focus-within:ring-2 focus-within:ring-blue-500 focus-within:ring-offset-2 dark:focus-within:ring-offset-gray-800"
           x-bind:data-dragging="dragging ? 'true' : 'false'"
           @dragenter.prevent="dragging = true"
           @dragover.prevent="dragging = true; $event.dataTransfer.dropEffect = 'copy'"
           @dragleave.prevent="leaveBox($event)"
           @drop.prevent="addFiles($event.dataTransfer.files)"
           dusk="{{ $dusk }}-drop">
        <input type="file" class="sr-only" @if ($multiple) multiple @endif
               accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm"
               @change="addFiles($event.target.files); $event.target.value = ''"
               dusk="{{ $dusk }}-file">

        <span class="flex h-11 w-11 items-center justify-center rounded-full bg-blue-100 text-blue-600 dark:bg-blue-900/50 dark:text-blue-300" aria-hidden="true">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
            </svg>
        </span>

        {{-- A phone has nothing to drop a file from: it is told to tap (a coarse pointer — a finger). --}}
        <span class="text-sm text-gray-700 dark:text-gray-200">
            <span class="hidden font-semibold text-blue-600 pointer-coarse:inline dark:text-blue-400">{{ $multiple ? 'Tap to choose files' : 'Tap to choose a file' }}</span>
            <span class="pointer-coarse:hidden">
                <span x-text="dragging ? 'Let go to upload' : '{{ $multiple ? 'Drop files here or' : 'Drop a file here or' }}'">{{ $multiple ? 'Drop files here or' : 'Drop a file here or' }}</span>
                <span x-show="! dragging" class="font-semibold text-blue-600 underline-offset-2 hover:underline dark:text-blue-400">{{ $multiple ? 'choose files' : 'choose a file' }}</span>
            </span>
        </span>

        @if ($hint)
            <span class="text-xs text-gray-500 dark:text-gray-400" dusk="{{ $dusk }}-upload-hint">{{ $hint }}</span>
        @endif
    </label>

    <p x-show="summary()" x-cloak class="text-xs font-medium text-gray-600 dark:text-gray-300" x-text="summary()"
       dusk="{{ $dusk }}-upload-summary"></p>

    {{-- Said to a screen reader when a file's turn comes (uploaded, added, refused, failed) — the rows' percent and
         speed change several times a second and are never read out. --}}
    <p class="sr-only" role="status" x-text="said"></p>

    <ul x-show="uploads.length > 0" x-cloak class="space-y-2">
        <template x-for="item in uploads" :key="item.key">
            {{-- An "Added" row fades away by itself after a few seconds (data-fading, upload-dropzone.js). --}}
            <li class="flex items-start gap-3 rounded-lg border bg-white p-3 transition-opacity duration-300 data-[fading=true]:opacity-0 dark:bg-gray-800"
                :class="['failed', 'refused'].includes(item.status) ? 'border-red-300 dark:border-red-800' : 'border-gray-200 dark:border-gray-700'"
                :data-fading="item.fading ? 'true' : 'false'"
                dusk="{{ $dusk }}-upload-row">
                <span class="flex h-12 w-12 flex-shrink-0 items-center justify-center overflow-hidden rounded-md bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400">
                    <template x-if="item.preview">
                        <img :src="item.preview" alt="" class="h-full w-full object-cover">
                    </template>
                    <template x-if="! item.preview && item.isVideo">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" />
                        </svg>
                    </template>
                    <template x-if="! item.preview && ! item.isVideo">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
                        </svg>
                    </template>
                </span>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-gray-800 dark:text-gray-100" x-text="item.name" :title="item.name"
                       dusk="{{ $dusk }}-upload-name"></p>
                    <p class="text-xs text-gray-500 dark:text-gray-400" x-text="details(item)"></p>

                    <div x-show="showsBar(item)" class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700"
                         role="progressbar" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="item.percent" :aria-label="`Uploading ${item.name}`">
                        <div class="h-full rounded-full transition-[width] duration-300"
                             :class="item.status === 'paused' ? 'bg-gray-400 dark:bg-gray-500' : 'bg-blue-600 dark:bg-blue-500'"
                             :style="`width: ${Math.max(item.percent, 2)}%`"></div>
                    </div>

                    <p x-show="statusText(item)" class="mt-1 flex items-center gap-1 text-xs"
                       :class="['done', 'ready'].includes(item.status) ? 'text-green-700 dark:text-green-400' : 'text-gray-500 dark:text-gray-400'"
                       dusk="{{ $dusk }}-upload-status">
                        <svg x-show="['done', 'ready'].includes(item.status)" class="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                        <span x-text="statusText(item)"></span>
                    </p>
                    <p x-show="item.error" class="form-error" x-text="item.error" dusk="{{ $dusk }}-upload-error"></p>
                </div>

                {{-- A finger's size on a phone (40 px), smaller beside a mouse. --}}
                <div class="flex flex-shrink-0 items-center gap-1">
                    <button type="button" x-show="canPause(item)" @click="togglePause(item)"
                            class="inline-flex h-10 w-10 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 sm:h-8 sm:w-8 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                            :aria-label="item.status === 'paused' ? `Resume ${item.name}` : `Pause ${item.name}`"
                            :title="item.status === 'paused' ? 'Resume' : 'Pause'" dusk="{{ $dusk }}-upload-pause">
                        <svg x-show="item.status !== 'paused'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25v13.5m-7.5-13.5v13.5" />
                        </svg>
                        <svg x-show="item.status === 'paused'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z" />
                        </svg>
                    </button>

                    <button type="button" x-show="canRetry(item)" @click="retry(item)"
                            class="inline-flex h-10 w-10 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 sm:h-8 sm:w-8 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                            :aria-label="`Try ${item.name} again`" title="Try again" dusk="{{ $dusk }}-upload-retry">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                        </svg>
                    </button>

                    <button type="button" @click="remove(item)"
                            class="inline-flex h-10 w-10 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 hover:text-red-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 sm:h-8 sm:w-8 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-red-400"
                            :aria-label="busy() && ! ['done', 'ready', 'failed', 'refused'].includes(item.status) ? `Cancel ${item.name}` : `Remove ${item.name} from the list`"
                            :title="['done', 'ready', 'failed', 'refused'].includes(item.status) ? 'Remove' : 'Cancel'"
                            dusk="{{ $dusk }}-upload-remove">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </li>
        </template>
    </ul>
</div>
