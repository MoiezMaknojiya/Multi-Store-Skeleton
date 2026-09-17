@props(['label', 'field', 'required' => false, 'requiredWhen' => null])

<div>
    <label class="form-label">
        {{ $label }}@if($required) <span class="text-red-500">*</span>@endif
        {{-- Required only sometimes (a file kept as it is while editing): the star follows the same expression --}}
        @if($requiredWhen)<span x-show="{{ $requiredWhen }}" class="text-red-500">*</span>@endif
    </label>
    {{-- crud-field-error paints the inputs inside red while the field has an error --}}
    <div class="mt-1" x-bind:class="formErrors['{{ $field }}'] ? 'crud-field-error' : ''">
        {{ $slot }}
    </div>
    <template x-if="formErrors.{{ $field }}">
        <p class="form-error" x-text="formErrors.{{ $field }}[0]"></p>
    </template>
</div>