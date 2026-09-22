{{-- Reusable server-rendered form field: label + input + validation error.
     The ONE component for plain-POST forms (auth pages AND profile pages) — the
     error/required logic lives HERE, inside the template, where Blade directives
     compile (see the "Blade gotcha" convention).

     Props:
       bag      — named error bag ('default', 'updatePassword', ...)
       value    — default input value (falls back behind old())
       id       — input id when $name would collide with another field on the page

     Renders one of three things in order of priority:
       1. The named "input" slot (e.g. password fields with eye-toggle)
       2. The default slot if filled
       3. A standard <input> tag otherwise --}}
@props(['name', 'label', 'type' => 'text', 'placeholder' => '', 'required' => false, 'bag' => 'default', 'value' => null, 'id' => null])

@php($fieldId = $id ?? $name)
@php($fieldErrors = $errors->getBag($bag))
{{-- The value flashed back after a failed submit is whatever shape the request had: email[]=x comes back
     as an array, and echoing an array is a TypeError — a 500 on the very page that should have shown the
     validation error. Only one plain value is ever put back in the field. --}}
@php($fieldValue = old($name, $value))

<div>
    <label for="{{ $fieldId }}" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">{{ $label }}@if($required) <span class="text-red-500">*</span>@endif</label>

    @if(isset($input))
        {{-- Custom input slot (e.g. password field with show/hide toggle) --}}
        {{ $input }}
    @elseif(!$slot->isEmpty())
        {{-- Anything else passed as default slot content --}}
        {{ $slot }}
    @else
        {{-- Default text input --}}
        <input id="{{ $fieldId }}" type="{{ $type }}" name="{{ $name }}" value="{{ is_scalar($fieldValue) ? $fieldValue : '' }}" placeholder="{{ $placeholder }}"
            {{ $attributes->class(['form-input-auth', '!border-red-500' => $fieldErrors->has($name)]) }}>
    @endif

    @if($fieldErrors->has($name))<p class="mt-1.5 text-xs text-red-500">{{ $fieldErrors->first($name) }}</p>@endif
</div>
