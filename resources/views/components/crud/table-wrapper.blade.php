{{-- Full data table wrapper: title bar with search, table with head/body slots, pagination footer slot.
     The title is the page's section heading (h2 under the page's h1), and `description`, when given, says in one
     line what the list is for — the first thing a new person reads on the page. --}}
@props(['title', 'searchPlaceholder' => 'Search...', 'columns' => 2, 'description' => null])

<div class="card" data-list-card>
    {{-- Header row with title and search --}}
    <div class="card-header">
        <div class="min-w-0">
            <h2 class="text-subheading">{{ $title }}</h2>
            @if (filled($description))
                <p class="mt-1 max-w-2xl text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>
            @endif
        </div>
        <x-crud.search-input :placeholder="$searchPlaceholder" />
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
