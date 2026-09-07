{{-- Empty-state table row shown when there are no records after loading completes --}}
@props(['columns' => 2, 'itemsVar', 'message' => 'No records found.'])

<template x-if="!loading && {{ $itemsVar }}.length === 0">
    <tr>
        <td colspan="{{ $columns }}" class="px-5 py-6 text-center text-muted-soft">
            {{ $message }}
        </td>
    </tr>
</template>