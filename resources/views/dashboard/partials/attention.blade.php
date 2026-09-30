{{-- What needs a look now, each line a link to where it is put right. An empty list says so plainly, so a quiet
     dashboard reads as "all good" rather than as a part that failed to load. --}}
<section class="card" aria-labelledby="dashboard-attention-title" dusk="dashboard-attention">
    <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100 dark:border-gray-700">
        <h2 id="dashboard-attention-title" class="text-base font-semibold text-gray-800 dark:text-white">Needs attention</h2>
        @if (count($items))
            {{-- Every one of them: a line that counts the rest ("2 more screens are offline") stands for its count. --}}
            <span class="badge-warning" dusk="dashboard-attention-count">{{ collect($items)->sum(fn (array $item) => $item['count'] ?? 1) }}<span class="sr-only"> to look at</span></span>
        @endif
    </div>

    @if (count($items) === 0)
        <div class="flex items-center gap-3 px-5 py-6 text-sm text-gray-600 dark:text-gray-300" dusk="dashboard-attention-none">
            <svg class="w-5 h-5 shrink-0 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span>Everything looks good.</span>
        </div>
    @else
        <ul class="divide-y divide-gray-100 dark:divide-gray-700">
            @foreach ($items as $item)
                <li>
                    <{{ $item['href'] ? 'a' : 'div' }} @if ($item['href']) href="{{ $item['href'] }}" @endif
                        dusk="dashboard-attention-{{ $item['key'] }}"
                        class="group flex items-start gap-3 px-5 py-4 {{ $item['href'] ? 'transition hover:bg-gray-50 dark:hover:bg-gray-700/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500' : '' }}">
                        @if ($item['tone'] === 'warning')
                            <svg class="mt-0.5 w-5 h-5 shrink-0 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                        @else
                            <svg class="mt-0.5 w-5 h-5 shrink-0 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        @endif
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-100 break-words">{{ $item['text'] }}</p>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $item['detail'] }}</p>
                        </div>
                        @if ($item['href'])
                            <svg class="mt-0.5 w-4 h-4 shrink-0 text-gray-500 transition group-hover:translate-x-0.5 group-hover:text-blue-600 dark:group-hover:text-blue-400"
                                fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        @endif
                    </{{ $item['href'] ? 'a' : 'div' }}>
                </li>
            @endforeach
        </ul>
    @endif
</section>
