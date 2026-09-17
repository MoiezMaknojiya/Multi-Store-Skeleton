{{-- The password a big delete asks for (owner's rule, 2026-09-16; ConfirmsPassword on the server).
     Use it inside a real <form> — without a form boundary Chrome treats the field as an "unowned"
     password and autofills the nearest plain input on the page (the search box) as its username.

     Props:
       id    — the input id, also its dusk selector
       model — the Alpine property holding the password
       error — an Alpine expression for the message to show under the field (empty = none) --}}
@props(['id', 'model' => 'deletePassword', 'error' => 'deletePasswordError'])

<div class="mt-4">
    <label for="{{ $id }}" class="form-label">Your password <span class="text-red-500">*</span></label>
    <p class="text-xs text-gray-500 dark:text-gray-400">This cannot be undone, so confirm it is you.</p>
    <div class="mt-1.5" x-bind:class="{{ $error }} ? 'crud-field-error' : ''">
        <x-text-input id="{{ $id }}" type="password" class="block w-full" x-model="{{ $model }}"
            autocomplete="current-password" dusk="{{ $id }}" />
    </div>
    <template x-if="{{ $error }}">
        <p class="form-error" x-text="{{ $error }}"></p>
    </template>
</div>
