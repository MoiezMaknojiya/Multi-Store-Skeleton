{{-- The last few entries of the activity log the person may read (the organization's own inside an organization), and the way to
     the whole log. --}}
<section class="card" aria-labelledby="dashboard-activity-title" dusk="dashboard-activity">
    <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100 dark:border-gray-700">
        <h2 id="dashboard-activity-title" class="text-base font-semibold text-gray-800 dark:text-white">Recent activity</h2>
        <a href="{{ route('activity.view') }}" dusk="dashboard-activity-all"
            class="text-sm font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 whitespace-nowrap rounded-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">
            View all
        </a>
    </div>

    @if (count($entries) === 0)
        <p class="px-5 py-6 text-sm text-gray-500 dark:text-gray-400" dusk="dashboard-activity-none">Nothing has happened here yet.</p>
    @else
        <ul class="divide-y divide-gray-100 dark:divide-gray-700">
            @foreach ($entries as $entry)
                <li class="px-5 py-3" dusk="dashboard-activity-entry">
                    <p class="text-sm text-gray-800 dark:text-gray-100 break-words">{{ $entry['what'] }}</p>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400 break-words">
                        {{ $entry['who'] }}@if ($entry['at']) · <time datetime="{{ $entry['at'] }}" title="{{ $entry['at'] }}">{{ $entry['when'] }}</time>@endif
                    </p>
                </li>
            @endforeach
        </ul>
    @endif
</section>
