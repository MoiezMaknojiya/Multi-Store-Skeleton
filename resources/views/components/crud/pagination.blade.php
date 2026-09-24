{{-- Pagination bar: "Showing X of Y" on left, prev/page-numbers/next on right.
     Expects Alpine variables: currentPage, lastPage, total, endItem, and methods prev()/next()/goTo(page) --}}
@props(['itemsVar'])

<div x-show="{{ $itemsVar }}.length > 0" class="card-footer">
    <div class="text-sm text-muted-soft">
        Showing <span class="font-medium text-gray-700 dark:text-white" x-text="endItem"></span>
        of <span class="font-medium text-gray-700 dark:text-white" x-text="total"></span> records
    </div>

    <div class="flex items-center gap-1.5">
        {{-- Previous page --}}
        <button @click="prev()" :disabled="currentPage === 1" class="btn-pager" aria-label="Previous page">
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                <path d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" />
            </svg>
        </button>

        {{-- On a phone: the page it is on, between the arrows. Wider: the page numbers around it, the first and
             the last (pageList() leaves the rest out as "…"), so a long list never runs out of the card. --}}
        <span class="px-1 text-xs font-medium text-muted-soft sm:hidden" dusk="pager-position"
              x-text="'Page ' + currentPage + ' of ' + Math.max(lastPage, 1)"></span>
        <template x-for="page in pageList()" :key="page">
            <button type="button" @click="typeof page === 'number' && goTo(page)"
                :disabled="typeof page !== 'number'"
                :class="typeof page !== 'number'
                    ? 'border-transparent text-gray-400 cursor-default'
                    : (currentPage === page
                        ? 'bg-blue-600 text-white border-blue-600'
                        : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-400 border-gray-200 dark:border-gray-700 hover:bg-gray-100 dark:hover:bg-gray-700')"
                class="hidden w-8 h-8 rounded-md border font-medium text-xs sm:inline-block"
                x-text="typeof page === 'number' ? page : '…'"></button>
        </template>

        {{-- Next page --}}
        <button @click="next()" :disabled="currentPage === lastPage || lastPage === 0" class="btn-pager" aria-label="Next page">
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                <path d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" />
            </svg>
        </button>
    </div>
</div>