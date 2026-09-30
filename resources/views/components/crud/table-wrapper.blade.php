{{-- Full data table wrapper: title bar with search, table with head/body slots, pagination footer slot.
     The title is the page's section heading (h2 under the page's h1); `description`, when a list needs one, is one
     short line under it. The list's own buttons (`actions`: Add Screen, Upload Files…) stand on the search's line —
     one row for both, and the only place the page offers them. --}}
@props(['title', 'searchPlaceholder' => 'Search...', 'columns' => 2, 'description' => null])

<div class="card" data-list-card>
    {{-- Header row: the title, then the search and the list's buttons --}}
    <div class="card-header">
        <div class="min-w-0">
            <h2 class="text-subheading">{{ $title }}</h2>
            @if (filled($description))
                <p class="mt-1 max-w-2xl text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>
            @endif
        </div>
        {{-- On a phone the line wraps before a button would be squeezed. --}}
        <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto sm:flex-nowrap sm:justify-end">
            <x-crud.search-input :placeholder="$searchPlaceholder" />
            {{ $actions ?? '' }}
        </div>
    </div>

    {{-- Table with loading/empty state built in --}}
    <div class="overflow-x-auto">
        <table class="table-base">
            <thead>
                <tr class="table-head-row">
                    {{ $head }}
                </tr>
            </thead>
            <tbody class="table-tbody" x-bind:aria-busy="loading ? 'true' : 'false'">
                {{-- Loading state --}}
                <template x-if="loading">
                    <tr>
                        <td colspan="{{ $columns }}" class="px-5 py-6 text-center text-muted-soft">
                            <span class="inline-flex items-center gap-2">
                                <x-spinner class="text-blue-600 dark:text-blue-400" />
                                Loading...
                            </span>
                        </td>
                    </tr>
                </template>

                {{-- Table body content (includes empty state and data rows) --}}
                {{ $body }}
            </tbody>
        </table>
    </div>

    {{-- Pagination footer slot --}}
    {{ $footer ?? '' }}
</div>
