{{-- One store's dashboard: its name, its numbers, what needs a look, the first steps of a new shop (gone once all
     are done) and what happened lately. No buttons of its own (owner, 2026-09-30): each thing is started on its own
     page, where its button stands. Every part arrives only when the person holds its permission
     (App\Services\DashboardSummary::forStore). --}}
<div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6" dusk="dashboard-store">
    <h2 class="text-subheading break-words" dusk="dashboard-store-name">{{ $summary['store'] }}</h2>

    @include('dashboard.partials.cards', ['cards' => $summary['cards']])

    @if (count($summary['steps']))
        <section class="card" aria-labelledby="dashboard-steps-title" dusk="dashboard-steps">
            <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 id="dashboard-steps-title" class="text-base font-semibold text-gray-800 dark:text-white">Get your shop on screen</h2>
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400 whitespace-nowrap" dusk="dashboard-steps-progress">
                    {{ collect($summary['steps'])->where('done', true)->count() }} of {{ count($summary['steps']) }} done
                </span>
            </div>
            <ol class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($summary['steps'] as $step)
                    <li>
                        <a href="{{ $step['href'] }}" dusk="dashboard-step-{{ $step['key'] }}"
                            class="group flex items-start gap-4 px-5 py-4 transition hover:bg-gray-50 dark:hover:bg-gray-700/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500">
                            @if ($step['done'])
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-green-600 text-white" aria-hidden="true">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" /></svg>
                                </span>
                            @else
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border-2 border-blue-600 text-xs font-semibold text-blue-600 dark:border-blue-400 dark:text-blue-400" aria-hidden="true">
                                    {{ $loop->iteration }}
                                </span>
                            @endif
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium {{ $step['done'] ? 'text-gray-500 line-through dark:text-gray-400' : 'text-gray-800 dark:text-gray-100' }}">
                                    {{ $step['text'] }}<span class="sr-only">{{ $step['done'] ? ' (done)' : '' }}</span>
                                </p>
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $step['detail'] }}</p>
                            </div>
                            <svg class="mt-1 w-4 h-4 shrink-0 text-gray-500 transition group-hover:translate-x-0.5 group-hover:text-blue-600 dark:group-hover:text-blue-400 dark:text-gray-400"
                                fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </a>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    @if (! is_null($summary['attention']) || ! is_null($summary['activity']))
        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            @if (! is_null($summary['attention']))
                @include('dashboard.partials.attention', ['items' => $summary['attention']])
            @endif
            @if (! is_null($summary['activity']))
                @include('dashboard.partials.activity', ['entries' => $summary['activity']])
            @endif
        </div>
    @endif

    {{-- A role that may look at none of it still lands somewhere that says where to go. --}}
    @if (! count($summary['cards']) && is_null($summary['activity']))
        <div class="card p-8 text-center text-sm text-gray-600 dark:text-gray-300" dusk="dashboard-store-nothing">
            There is nothing for your role in {{ $summary['store'] }} to see here. Everything you may open is in the menu.
        </div>
    @endif
</div>
