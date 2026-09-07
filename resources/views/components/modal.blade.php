@props(['name', 'show' => false, 'maxWidth' => '2xl', 'title' => null])

@php
$maxWidth = [
    'sm' => 'sm:max-w-sm',
    'md' => 'sm:max-w-md',
    'lg' => 'sm:max-w-lg',
    'xl' => 'sm:max-w-xl',
    '2xl' => 'sm:max-w-2xl',
][$maxWidth];
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
        @if($title)
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $title }}</h3>
            </div>
        @endif
        <div class="p-6">
            {{ $slot }}
        </div>
        @isset($footer)
            <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 rounded-b-lg">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>