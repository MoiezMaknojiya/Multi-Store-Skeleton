{{-- Generic delete confirmation modal.
     Pass the modal name, entity label, Alpine expression for the item name, and delete action.
     The disabledVar arg is an Alpine expression; pass 'false' (default) for no disabling,
     or a reactive flag name like 'deleting' to bind the disabled state.
     `password` asks for the actor's password first — for big deletes (the table sets
     deleteNeedsPassword in crud-table-base, which supplies deletePassword/deletePasswordError).
     What the dialog says can follow what the action really does: `title` and `confirmLabel` ("Remove from
     channel", "Remove"), `question` (the words before the name), and a `note` slot for what goes with it ("It also
     comes off every screen that plays it."). Left out, it asks "Are you sure you want to delete …?". --}}
@props([
    'name', 'entity', 'nameExpression', 'deleteAction', 'disabledVar' => 'false', 'password' => false,
    'title' => null, 'question' => null, 'confirmLabel' => null,
])

@php($__title = $title ?? 'Delete '.$entity)
@php($__question = $question ?? 'Are you sure you want to delete')
@php($__confirm = $confirmLabel ?? 'Delete '.$entity)

<x-modal :name="$name" :show="false" maxWidth="md" focusable>
    @if ($password)
        <form @submit.prevent="{{ $deleteAction }}" class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $__title }}</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                {{ $__question }}
                <span x-text="{{ $nameExpression }}" class="font-semibold"></span>?
            </p>
            @isset($note)
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $note }}</p>
            @endisset

            <x-crud.password-confirm id="{{ $name }}-password" />

            <div class="mt-6 flex flex-wrap justify-end gap-3">
                <x-secondary-button x-on:click="$dispatch('close-modal', {{ Js::from($name) }})">
                    Cancel
                </x-secondary-button>
                <x-danger-button x-bind:disabled="{{ $disabledVar }}" dusk="{{ $name }}-confirm">
                    <x-spinner x-show="{{ $disabledVar }}" x-cloak />
                    {{ $__confirm }}
                </x-danger-button>
            </div>
        </form>
    @else
        <div class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $__title }}</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                {{ $__question }}
                <span x-text="{{ $nameExpression }}" class="font-semibold"></span>?
                @if ($question === null)
                    This action cannot be undone.
                @endif
            </p>
            @isset($note)
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $note }}</p>
            @endisset
            <div class="mt-6 flex flex-wrap justify-end gap-3">
                <x-secondary-button x-on:click="$dispatch('close-modal', {{ Js::from($name) }})">
                    Cancel
                </x-secondary-button>
                <x-danger-button x-on:click="{{ $deleteAction }}" x-bind:disabled="{{ $disabledVar }}" dusk="{{ $name }}-confirm">
                    <x-spinner x-show="{{ $disabledVar }}" x-cloak />
                    {{ $__confirm }}
                </x-danger-button>
            </div>
        </div>
    @endif
</x-modal>
