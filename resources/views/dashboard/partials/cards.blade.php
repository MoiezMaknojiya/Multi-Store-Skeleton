{{-- The dashboard's numbers: one card per page the person may look at, each a link to that page. --}}
@if (count($cards))
    <div class="grid grid-cols-2 gap-3 sm:gap-5 xl:grid-cols-4" dusk="dashboard-cards">
        @foreach ($cards as $card)
            <x-crud.stat-card :label="$card['label']" :value="$card['value']" :detail="$card['detail']" :href="$card['href']"
                :icon="$cardIcons[$card['key']]" dusk="dashboard-card-{{ $card['key'] }}" />
        @endforeach
    </div>
@endif
