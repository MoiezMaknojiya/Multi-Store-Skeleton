@props(['label', 'field', 'required' => false, 'requiredWhen' => null])

{{-- A field of an Alpine form. Its message is tied to its control: while the field has an error the control is
     aria-invalid and aria-describedby the message, and the message is an alert, so a screen reader says what is
     wrong the moment it appears — and crud-table-base puts the focus on the first such field. The star is for the
     eye; the control says "required" itself where it has the attribute. --}}
<div data-crud-field>
    {{-- The label names the field's control for screen readers and focuses it on a click. The control sits in
         the slot, so it is found once the field starts: an id is given only when it has none, and a checkbox,
         a radio or a control already inside its own label is left alone. --}}
    <label class="form-label"
           x-init="(() => { const control = $el.nextElementSibling?.querySelector('input:not([type=hidden]):not([type=checkbox]):not([type=radio]), select, textarea'); if (!control || control.closest('label')) return; control.id ||= $id('field'); $el.htmlFor = control.id; })()">
        {{ $label }}@if($required) <span class="text-red-600 dark:text-red-400" aria-hidden="true">*</span>@endif
        {{-- Required only sometimes (a file kept as it is while editing): the star follows the same expression --}}
        @if($requiredWhen)<span x-show="{{ $requiredWhen }}" class="text-red-600 dark:text-red-400" aria-hidden="true">*</span>@endif
    </label>
    {{-- crud-field-error paints the inputs inside red while the field has an error --}}
    <div class="mt-1" x-bind:class="formErrors['{{ $field }}'] ? 'crud-field-error' : ''"
         x-effect="(() => { const control = $el.querySelector('input:not([type=hidden]):not([type=checkbox]):not([type=radio]), select, textarea'); if (!control || !control.id) return; if (formErrors['{{ $field }}']) { control.setAttribute('aria-invalid', 'true'); control.setAttribute('aria-describedby', control.id + '-error'); } else { control.removeAttribute('aria-invalid'); control.removeAttribute('aria-describedby'); } })()">
        {{ $slot }}
    </div>
    <template x-if="formErrors.{{ $field }}">
        <p class="form-error" role="alert"
           x-bind:id="($el.closest('[data-crud-field]')?.querySelector('input:not([type=hidden]):not([type=checkbox]):not([type=radio]), select, textarea')?.id ?? '{{ $field }}') + '-error'"
           x-text="formErrors.{{ $field }}[0]"></p>
    </template>
</div>