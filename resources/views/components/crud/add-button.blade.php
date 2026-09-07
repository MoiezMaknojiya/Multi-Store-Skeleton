{{-- Reusable "Add Entity" button with plus icon used at the top of every CRUD listing page --}}
@props(['label'])

<button {{ $attributes->merge(['class' => 'btn-primary-add']) }}>
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
    </svg>
    {{ $label }}
</button>