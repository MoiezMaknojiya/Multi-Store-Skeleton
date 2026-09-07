@props(['placeholder' => 'Search...', 'model' => 'search'])

<div class="relative w-full max-w-xs">
    <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
        </svg>
    </span>
    {{-- autocomplete="off" is silently ignored by Chrome on fields it heuristically treats as
         credential-related; "new-password" is a well-known, more reliable way to actually stop it --}}
    <input x-model="{{ $model }}" type="text" placeholder="{{ $placeholder }}" autocomplete="new-password"
        class="form-input pl-10" />
</div>