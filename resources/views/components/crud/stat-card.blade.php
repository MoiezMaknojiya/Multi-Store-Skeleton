{{-- Dashboard statistics card with icon, value, label, and change indicator --}}
@props(['label', 'value', 'change' => null, 'up' => true, 'icon', 'bgColor' => 'bg-blue-50 dark:bg-blue-900/30', 'textColor' => 'text-blue-600'])

<div class="bg-white dark:bg-gray-800 rounded-xs p-5 border border-gray-100 dark:border-gray-700">
    <div class="flex items-center justify-between mb-4">
        <div class="w-11 h-11 rounded-xs flex items-center justify-center {{ $bgColor }}">
            <svg class="w-5 h-5 {{ $textColor }}" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icon }}"/>
            </svg>
        </div>
        @if($change)
        <span class="inline-flex items-center gap-1 text-xs font-medium px-2 py-1 rounded-full
            {{ $up ? 'bg-green-50 text-green-600 dark:bg-green-900/30' : 'bg-red-50 text-red-600 dark:bg-red-900/30' }}">
            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="{{ $up ? 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6' : 'M13 17h8m0 0V9m0 8l-8-8-4 4-6-6' }}"/>
            </svg>
            {{ $change }}
        </span>
        @endif
    </div>
    <div class="text-2xl font-bold text-gray-900 dark:text-white mb-1">{{ $value }}</div>
    <div class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</div>
</div>
