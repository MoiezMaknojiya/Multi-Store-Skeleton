{{--
    How full a shop's storage is: 512 MB each (App\Services\StoreStorage, owner's rule 2026-09-28). Drawn from the
    page's own Alpine state — `storage`, {used, limit} as the server sent it, or null for a library with no wall
    (the platform's own), when nothing shows — and its storageText() and storageLevel() methods. The bar turns amber
    at three quarters and red at nine tenths, and a screen reader hears the words ("120 MB of 512 MB used"), not a bare number.

    `initial` is the same {used, limit} when the page already knows it (the Media page and the shelf bring it): the
    meter is then drawn by the server, so it is there on the first paint instead of appearing once Alpine starts and
    pushing the page down.
--}}
@props(['initial' => null])

@php
    $__words = $initial ? \App\Services\StoreStorage::inWords($initial['used']).' of '.\App\Services\StoreStorage::inWords($initial['limit']).' used' : '';
    $__percent = $initial && $initial['limit'] > 0 ? min(100, (int) round($initial['used'] * 100 / $initial['limit'])) : 0;
@endphp

<div x-show="storage" @if ($initial === null) x-cloak @endif {{ $attributes->merge(['class' => 'card p-4 space-y-2 sm:max-w-md']) }} dusk="storage-meter">
    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
        <span class="font-medium text-gray-700 dark:text-gray-200">Storage</span>
        <span class="whitespace-nowrap text-gray-500 dark:text-gray-400" x-text="storageText()" dusk="storage-meter-text">{{ $__words }}</span>
    </div>
    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700"
         role="progressbar" aria-label="Storage used" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="storageLevel()"
         :aria-valuetext="storageText()">
        {{-- The server's colour is an inline one: Alpine's :style replaces it with the width alone as its :class takes over
             (a static colour class would stay beside the bound one). --}}
        <div class="h-full rounded-full" :class="storageLevel() >= 90 ? 'bg-red-500' : (storageLevel() >= 75 ? 'bg-amber-500' : 'bg-blue-600')"
             style="width: {{ $__percent }}%; background-color: var(--color-{{ $__percent >= 90 ? 'red-500' : ($__percent >= 75 ? 'amber-500' : 'blue-600') }})"
             :style="`width: ${storageLevel()}%`" dusk="storage-meter-bar"></div>
    </div>
    <p x-show="storageLevel() >= 90" x-cloak class="text-xs text-red-600 dark:text-red-400" dusk="storage-meter-warning"
       x-text="storage && storage.used >= storage.limit ? 'Full. Delete files you no longer use to make room.' : 'Almost full. Delete files you no longer use to make room.'"></p>
</div>
