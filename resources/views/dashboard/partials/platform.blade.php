{{-- The platform's dashboard: its numbers (each a link to its page where the person may open it), what needs a
     look — a store with no Owner, a server running low — and what happened lately, every part by its permission
     (App\Services\DashboardSummary::forPlatform). --}}
<div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6" dusk="dashboard-platform">
    @include('dashboard.partials.cards', ['cards' => $summary['cards']])

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
</div>
