{{-- Dashboard statistics card: an icon, a number, its label and — when given — a line under it. With an href the
     whole card is a link to the page it counts: the card is what the eye reads as one thing, so it is what a click
     aims at. The dashboard passes an href only where the person may open that page. --}}
@props(['label', 'value', 'icon', 'detail' => null, 'href' => null])

<{{ $href ? 'a' : 'div' }} @if ($href) href="{{ $href }}" @endif
    {{ $attributes->class([
        'group block bg-white dark:bg-gray-800 rounded-2xl p-4 sm:p-5 border border-gray-100 dark:border-gray-700',
        'transition hover:border-blue-500 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500' => $href,
    ]) }}>
    <div class="flex items-center justify-between mb-3 sm:mb-4">
        <div class="w-11 h-11 rounded-xl flex items-center justify-center bg-blue-50 dark:bg-blue-900/30">
            <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icon }}"/>
            </svg>
        </div>
        @if ($href)
            <svg class="w-4 h-4 text-gray-500 transition group-hover:translate-x-0.5 group-hover:text-blue-600 dark:group-hover:text-blue-400 dark:text-gray-400"
                fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
            </svg>
        @endif
    </div>
    <div class="text-2xl font-bold text-gray-900 dark:text-white mb-1">{{ $value }}</div>
    <div class="text-sm font-medium text-gray-600 dark:text-gray-300">{{ $label }}</div>
    @if (filled($detail))
        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $detail }}</div>
    @endif
</{{ $href ? 'a' : 'div' }}>
