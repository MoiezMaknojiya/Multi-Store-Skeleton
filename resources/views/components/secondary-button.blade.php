<button {{ $attributes->merge(['type' => 'button', 'class' => 'btn-secondary disabled:opacity-25']) }}>
    {{ $slot }}
</button>