{{-- The row a listing shows once it has loaded and holds nothing. Three cases, told apart, because they need
     different help: a list that could not be loaded (and Try again — never "nothing here yet" after a lost
     connection or an ended session); a search (or a filter) that matched nothing — said with the words searched for,
     and the way back; and a list that is really empty — `message`, and a short `hint` only where a first step needs
     saying. No add button here: the list's own, beside its search, is the one (owner, 2026-09-30: two of the same
     button on one page). `filtered` is the page's own expression for "a filter is on", and `clearFilters` the call
     that takes them off. --}}
@props([
    'columns' => 2,
    'itemsVar',
    'message' => 'Nothing here yet.',
    'hint' => null,
    'filtered' => 'false',
    'clearFilters' => null,
])

<template x-if="!loading && {{ $itemsVar }}.length === 0">
    <tr>
        <td colspan="{{ $columns }}" class="px-5 py-10 text-center">
            <template x-if="loadFailed">
                <div class="mx-auto max-w-md space-y-3" role="alert" dusk="table-load-failed">
                    <p class="text-sm text-gray-700 dark:text-gray-200">Could not load this list. Check the connection, then try again.</p>
                    <button type="button" class="btn-row-neutral" @click="fetchItems()" dusk="table-try-again">Try Again</button>
                </div>
            </template>
            <template x-if="!loadFailed && (search || ({{ $filtered }}))">
                <div class="mx-auto max-w-md space-y-3" dusk="table-no-match">
                    <p class="text-sm text-gray-700 dark:text-gray-200">
                        <span x-show="search">Nothing matches &ldquo;<span class="font-medium" x-text="search"></span>&rdquo;.</span>
                        <span x-show="!search">Nothing matches these filters.</span>
                    </p>
                    <div class="flex flex-wrap justify-center gap-2">
                        <button type="button" x-show="search" class="btn-row-neutral" @click="search = ''" dusk="table-clear-search">Clear Search</button>
                        @if ($clearFilters)
                            <button type="button" x-show="!search" class="btn-row-neutral" @click="{{ $clearFilters }}" dusk="table-clear-filters">Clear Filters</button>
                        @endif
                    </div>
                </div>
            </template>
            <template x-if="!loadFailed && !search && !({{ $filtered }})">
                <div class="mx-auto max-w-md space-y-2" dusk="table-empty">
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ $message }}</p>
                    @if (filled($hint))
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $hint }}</p>
                    @endif
                </div>
            </template>
        </td>
    </tr>
</template>
