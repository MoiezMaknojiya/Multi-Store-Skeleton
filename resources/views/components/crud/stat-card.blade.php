{{-- Dashboard statistics card: an icon, a number and its label --}}
@props(['label', 'value', 'icon'])

<div class="bg-white dark:bg-gray-800 rounded-xs p-5 border border-gray-100 dark:border-gray-700">
    <div class="flex items-center justify-between mb-4">
        <div class="w-11 h-11 rounded-xs flex items-center justify-center bg-blue-50 dark:bg-blue-900/30">
            <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icon }}"/>
            </svg>
        </div>
    </div>
    <div class="text-2xl font-bold text-gray-900 dark:text-white mb-1">{{ $value }}</div>
    <div class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</div>
</div>
