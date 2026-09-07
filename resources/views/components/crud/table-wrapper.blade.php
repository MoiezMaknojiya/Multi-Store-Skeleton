{{-- Full data table wrapper: title bar with search, table with head/body slots, pagination footer slot --}}
@props(['title', 'searchPlaceholder' => 'Search...', 'columns' => 2])

<div class="card">
    {{-- Header row with title and search --}}
    <div class="card-header">
        <h3 class="text-subheading">{{ $title }}</h3>
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
            <tbody class="table-tbody">
                {{-- Loading state --}}
                <template x-if="loading">
                    <tr>
                        <td colspan="{{ $columns }}" class="px-5 py-6 text-center text-muted-soft">
                            Loading...
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