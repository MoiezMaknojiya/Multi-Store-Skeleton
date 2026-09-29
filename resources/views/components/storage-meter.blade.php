{{--
    How full a shop's storage is: 512 MB each (App\Services\StoreStorage, owner's rule 2026-09-28). Drawn from the
    page's own Alpine state — `storage`, {used, limit} as the server sent it, or null for a library with no wall
    (the platform's own), when nothing shows — and its storageText() and storageLevel() methods.
--}}
<div x-show="storage" x-cloak class="card p-4 space-y-2 sm:max-w-md" dusk="storage-meter">
    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
        <span class="font-medium text-gray-700 dark:text-gray-200">Storage</span>
        <span class="whitespace-nowrap text-gray-500 dark:text-gray-400" x-text="storageText()" dusk="storage-meter-text"></span>
    </div>
    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700"
         role="progressbar" aria-label="Storage used" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="storageLevel()">
        <div class="h-full rounded-full" :class="storageLevel() >= 90 ? 'bg-red-500' : 'bg-blue-600'"
             :style="`width: ${storageLevel()}%`" dusk="storage-meter-bar"></div>
    </div>
    <p x-show="storageLevel() >= 90" x-cloak class="text-xs text-red-600 dark:text-red-400" dusk="storage-meter-warning"
       x-text="storage && storage.used >= storage.limit ? 'Full. Delete files you no longer use to make room.' : 'Almost full. Delete files you no longer use to make room.'"></p>
</div>
