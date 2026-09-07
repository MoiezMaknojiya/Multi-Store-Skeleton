{{-- Generic delete confirmation modal.
     Pass the modal name, entity label, Alpine expression for the item name, and delete action.
     The disabledVar arg is an Alpine expression; pass 'false' (default) for no disabling,
     or a reactive flag name like 'deleting' to bind the disabled state. --}}
@props(['name', 'entity', 'nameExpression', 'deleteAction', 'disabledVar' => 'false'])

<x-modal :name="$name" :show="false" maxWidth="md" focusable>
    <div class="p-6">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Delete {{ $entity }}</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            Are you sure you want to delete
            <span x-text="{{ $nameExpression }}" class="font-semibold"></span>?
            This action cannot be undone.
        </p>
        <div class="mt-6 flex justify-end gap-3">
            <x-secondary-button x-on:click="$dispatch('close-modal', '{{ $name }}')">
                Cancel
            </x-secondary-button>
            <x-danger-button x-on:click="{{ $deleteAction }}" x-bind:disabled="{{ $disabledVar }}" dusk="{{ $name }}-confirm">
                Delete {{ $entity }}
            </x-danger-button>
        </div>
    </div>
</x-modal>