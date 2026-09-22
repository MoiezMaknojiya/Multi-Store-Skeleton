@props(['label', 'field', 'required' => false, 'requiredWhen' => null])

<div>
    {{-- The label names the field's control for screen readers and focuses it on a click. The control sits in
         the slot, so it is found once the field starts: an id is given only when it has none, and a checkbox,
         a radio or a control already inside its own label is left alone. --}}
    <label class="form-label"
           x-init="(() => { const control = $el.nextElementSibling?.querySelector('input:not([type=hidden]):not([type=checkbox]):not([type=radio]), select, textarea'); if (!control || control.closest('label')) return; control.id ||= $id('field'); $el.htmlFor = control.id; })()">
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