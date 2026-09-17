@props(['name', 'show' => false, 'maxWidth' => '2xl'])

@php
// Falls back rather than exploding: an unknown key used to be a fatal
// "Undefined array key" that took the whole PAGE down, not just the modal — a
// typo in one attribute is not worth a white screen.
$maxWidth = [
    'sm' => 'sm:max-w-sm',
    'md' => 'sm:max-w-md',
    'lg' => 'sm:max-w-lg',
    'xl' => 'sm:max-w-xl',
    '2xl' => 'sm:max-w-2xl',
    '3xl' => 'sm:max-w-3xl',
    '4xl' => 'sm:max-w-4xl',
][$maxWidth] ?? 'sm:max-w-2xl';
@endphp

<div
    x-data="customModal('{{ $name }}', @js($show), @js($attributes->has('focusable')))"
    x-on:open-modal.window="openEvent($event)"
    x-on:close-modal.window="closeEvent($event)"
    x-on:close.stop="closeMe"
    x-on:keydown.escape.window="closeMe"
    x-show="show"
    class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-2 sm:p-0"
    style="display: none;"
>
    <!-- BACKDROP -->
    <div
        x-show="show"
        x-on:click="closeMe"
        class="fixed inset-0 bg-gray-600/60 dark:bg-black/60"
    >
    </div>

    <!-- MODAL PANEL (mobile: almost full width, desktop: respects maxWidth) -->
    <div
        x-show="show"
        x-on:click.stop
        class="relative z-10 bg-white dark:bg-gray-800 rounded-lg shadow-2xl w-[calc(100%-1rem)] sm:w-full {{ $maxWidth }} sm:mx-auto"
    >
        <div class="p-6">
            {{ $slot }}
        </div>
    </div>
</div>