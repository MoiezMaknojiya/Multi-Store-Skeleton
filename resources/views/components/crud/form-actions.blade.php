{{-- Standard Cancel + Save button pair for form modals.
     savingVar is an Alpine expression name; pass 'false' (default) for no disabling,
     or a reactive flag name like 'saving' to bind the disabled state during submit. --}}
@props(['cancelAction' => 'closeFormModal()', 'savingVar' => 'false', 'saveLabel' => 'Save', 'dusk' => null, 'cancelDusk' => null])

<div class="flex flex-wrap justify-end gap-3 mt-6">
    <button type="button" @click="{{ $cancelAction }}" @if ($cancelDusk) dusk="{{ $cancelDusk }}" @endif class="btn-secondary">
        Cancel
    </button>
    <button type="submit" x-bind:disabled="{{ $savingVar }}" @if ($dusk) dusk="{{ $dusk }}" @endif class="btn-primary">
        {{ $saveLabel }}
    </button>
</div>