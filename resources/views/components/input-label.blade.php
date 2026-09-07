@props(['value', 'required' => false])

<label {{ $attributes->merge(['class' => 'form-label']) }}>
    {{ $value ?? $slot }}@if($required) <span class="text-red-500">*</span>@endif
</label>