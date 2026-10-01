{{-- The password a big delete asks for (owner's rule, 2026-09-16; ConfirmsPassword on the server).
     Use it inside a real <form> — without a form boundary Chrome treats the field as an "unowned"
     password and autofills the nearest plain input on the page (the search box) as its username.

     Props:
       id    — the input id, also its dusk selector
       model — the Alpine property holding the password
       error — an Alpine expression for the message to show under the field (empty = none)
       hint  — the line under the label: a removal that can be put back (a member, an organization's access, a
               platform role) says only that it is you, not that it cannot be undone --}}
@props(['id', 'model' => 'deletePassword', 'error' => 'deletePasswordError', 'hint' => 'This cannot be undone, so confirm it is you.'])

<div class="mt-4">
    <label for="{{ $id }}" class="form-label">Your password <span class="text-red-600 dark:text-red-400" aria-hidden="true">*</span></label>
    <p id="{{ $id }}-hint" class="text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
    <div class="mt-1.5" x-bind:class="{{ $error }} ? 'crud-field-error' : ''">
        <x-text-input id="{{ $id }}" type="password" x-model="{{ $model }}" aria-required="true"
            autocomplete="current-password" dusk="{{ $id }}"
            x-bind:aria-invalid="{{ $error }} ? 'true' : null"
            x-bind:aria-describedby="'{{ $id }}-hint' + ({{ $error }} ? ' {{ $id }}-error' : '')" />
    </div>
    <template x-if="{{ $error }}">
        <p id="{{ $id }}-error" class="form-error" role="alert" x-text="{{ $error }}"></p>
    </template>
</div>
