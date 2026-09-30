{{-- The one "working on it" circle, for a button while its request is on its way and for a list while it loads.
     Decorative: the button's own words, or the row's "Loading...", say what is happening. It stands still for
     anybody who asked for less motion. --}}
{{-- 16 px unless the caller gives a size of its own: two heights on one element are a coin toss in the stylesheet. --}}
@php($__sized = preg_match('/(^|\s)(h|w|size)-/', (string) $attributes->get('class')) === 1)
<svg {{ $attributes->class(['h-4 w-4' => ! $__sized, 'shrink-0 animate-spin motion-reduce:animate-none']) }} fill="none" viewBox="0 0 24 24" aria-hidden="true">
    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
</svg>
