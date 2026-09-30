@props(['value', 'required' => false])

<label {{ $attributes->merge(['class' => 'form-label']) }}>
    {{ $value ?? $slot }}@if($required) <span class="text-red-600 dark:text-red-400" aria-hidden="true">*</span>@endif
</label>