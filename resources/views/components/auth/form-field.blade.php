{{-- Reusable server-rendered form field: label + input + validation error.
     The ONE component for plain-POST forms (auth pages AND profile pages) — the
     error/required logic lives HERE, inside the template, where Blade directives
     compile (see the "Blade gotcha" convention).

     Props:
       bag      — named error bag ('default', 'updatePassword', ...)
       value    — default input value (falls back behind old())
       id       — input id when $name would collide with another field on the page
       hint     — a line under the field saying what it takes ("At least 8 characters."), read with it

     Renders one of four things in order of priority:
       1. The named "input" slot
       2. The default slot if filled
       3. A password field with its show/hide button, for type="password"
       4. A standard <input> tag otherwise

     A screen reader hears what the eye sees: the field is aria-required when it is required (the red star is
     for the eye), and aria-describedby its hint and, after a refused submit, its message (aria-invalid). The
     message carries data-error-for, so the page's own check (resources/js/core/plain-form.js) can put a newer
     one in its place. --}}
@props(['name', 'label', 'type' => 'text', 'placeholder' => '', 'required' => false, 'bag' => 'default', 'value' => null, 'id' => null, 'hint' => null])

@php($fieldId = $id ?? $name)
@php($fieldErrors = $errors->getBag($bag))
@php($hasError = $fieldErrors->has($name))
@php($describedBy = trim((filled($hint) ? $fieldId.'-hint ' : '').($hasError ? $fieldId.'-error' : '')))
{{-- The value flashed back after a failed submit is whatever shape the request had: email[]=x comes back
     as an array, and echoing an array is a TypeError — a 500 on the very page that should have shown the
     validation error. Only one plain value is ever put back in the field. --}}
@php($fieldValue = old($name, $value))

<div>
    <label for="{{ $fieldId }}" class="form-label mb-1.5">{{ $label }}@if($required) <span class="text-red-600 dark:text-red-400" aria-hidden="true">*</span>@endif</label>

    @if(isset($input))
        {{-- Custom input slot --}}
        {{ $input }}
    @elseif(!$slot->isEmpty())
        {{-- Anything else passed as default slot content --}}
        {{ $slot }}
    @elseif($type === 'password')
        {{-- A password with its show/hide button. type="password" is there before Alpine binds, so the password
             is hidden from the first paint; the button says what a press does next, and whether it is pressed. --}}
        <div class="relative" x-data="passwordToggle()">
            <input id="{{ $fieldId }}" type="password" :type="show ? 'text' : 'password'" name="{{ $name }}" placeholder="{{ $placeholder }}"
                @if($required) aria-required="true" @endif
                @if($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
                @if($hasError) aria-invalid="true" @endif
                {{ $attributes->class(['form-input-auth pr-11', '!border-red-500' => $hasError]) }}>

            <button type="button" @click="toggle()" :aria-label="show ? 'Hide password' : 'Show password'" :aria-pressed="show.toString()"
                class="absolute inset-y-0 right-0 flex items-center rounded-r-lg px-3 text-gray-500 hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:text-gray-400 dark:hover:text-gray-200">
                {{-- Eye open icon (password hidden) --}}
                <svg x-show="!show" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0zM2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                </svg>
                {{-- Eye closed icon (password visible) --}}
                <svg x-show="show" x-cloak class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                </svg>
            </button>
        </div>
    @else
        {{-- Default text input --}}
        <input id="{{ $fieldId }}" type="{{ $type }}" name="{{ $name }}" value="{{ is_scalar($fieldValue) ? $fieldValue : '' }}" placeholder="{{ $placeholder }}"
            @if($required) aria-required="true" @endif
            @if($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            @if($hasError) aria-invalid="true" @endif
            {{ $attributes->class(['form-input-auth', '!border-red-500' => $hasError]) }}>
    @endif

    @if(filled($hint))<p id="{{ $fieldId }}-hint" class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>@endif
    @if($hasError)<p id="{{ $fieldId }}-error" data-error-for="{{ $name }}" class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $fieldErrors->first($name) }}</p>@endif
</div>
