{{-- A table heading that sorts its listing (createCrudTable's `sortable` and sortOn): pressed, the rows are sorted by
     it, from `first` (asc or desc: the way a person means it first — newest, online); pressed again, the other way
     round. aria-sort says which way to a screen reader, the arrow says it to the eye. --}}
@props(['column', 'label', 'first' => 'asc'])

<th scope="col" class="px-5 py-3 text-left font-semibold" aria-sort="none" x-bind:aria-sort="sortState('{{ $column }}')">
    <button type="button" @click="sortOn('{{ $column }}', '{{ $first }}')" dusk="sort-{{ $column }}"
            class="inline-flex items-center gap-1 rounded hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:hover:text-white">
        {{ $label }}
        <x-icon name="arrow-up" class="h-3.5 w-3.5" x-show="sortState('{{ $column }}') === 'ascending'" x-cloak />
        <x-icon name="arrow-down" class="h-3.5 w-3.5" x-show="sortState('{{ $column }}') === 'descending'" x-cloak />
        <x-icon name="chevron-up-down" class="h-3.5 w-3.5 opacity-50" x-show="sortState('{{ $column }}') === 'none'" />
    </button>
</th>
