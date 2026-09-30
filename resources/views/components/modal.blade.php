{{-- `persistent`: a form of several fields — a click beside it does not close it and lose what was typed
     (Cancel and Escape still do). --}}
@props(['name', 'show' => false, 'maxWidth' => '2xl', 'persistent' => false])

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

{{-- items-center-safe, not items-center: a modal taller than the window is laid from its top and scrolls,
     where a centred one hung its top above the window, out of reach of any scrolling. --}}
<div
    x-data="customModal({{ Js::from($name) }}, @js($show), @js($attributes->has('focusable')))"
    x-on:open-modal.window="openEvent($event)"
    x-on:close-modal.window="closeEvent($event)"
    x-on:close.stop="closeMe"
    x-on:keydown.escape.window="closeOnEscape"
    x-on:keydown.tab.window="keepFocusInside($event)"
    x-show="show"
    x-cloak
    class="fixed inset-0 z-50 flex items-center-safe justify-center overflow-y-auto p-2 sm:p-0"
>
    {{-- BACKDROP --}}
    <div
        x-show="show"
        @unless ($persistent) x-on:click="closeMe" @endunless
        class="fixed inset-0 bg-gray-600/60 dark:bg-black/60"
    >
    </div>

    {{-- MODAL PANEL (mobile: almost full width, desktop: respects maxWidth). A dialog to a screen reader, named by
         its first heading (customModal.nameTheDialog); while it is open Tab stays inside it and Escape closes it.
         No padding of its own: every dialog brings its p-6 (two of them left 48 px a side on a phone). --}}
    <div
        x-show="show"
        x-ref="panel"
        role="dialog"
        aria-modal="true"
        tabindex="-1"
        x-on:click.stop
        class="relative z-10 focus:outline-none bg-white dark:bg-gray-800 rounded-lg shadow-2xl w-[calc(100%-1rem)] sm:w-full {{ $maxWidth }} sm:mx-auto"
    >
        {{ $slot }}
    </div>
</div>