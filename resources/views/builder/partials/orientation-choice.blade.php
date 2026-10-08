{{-- Which way is the screen mounted? (docs/AD-BUILDER-SPEC.md §12) — the two choices in the Ads page's Create Ad dialog. Above
     the organizations they are links, so the editor opens on a stage of that shape; inside an organization ($asksHowToStart) the
     dialog asks next how to start — Create Your Own or Premium Template (owner, 2026-10-07). --}}
@php
    $shapes = [
        'landscape' => ['Landscape', '1920 × 1080 · a television the usual way round', 'w-32'],
        'portrait' => ['Portrait', '1080 × 1920 · a television mounted upright — menu boards, posters', 'w-10'],
    ];
    $choice = 'group rounded-lg border-2 border-gray-200 p-4 text-center transition hover:border-blue-500 hover:bg-blue-50 focus:outline-none focus-visible:border-blue-500 focus-visible:ring-2 focus-visible:ring-blue-500 dark:border-gray-700 dark:hover:border-blue-400 dark:hover:bg-gray-700/50';
@endphp
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    @foreach ($shapes as $shape => [$label, $size, $width])
        @if ($asksHowToStart)
            <button type="button" @click="chooseOrientation('{{ $shape }}')" id="new-ad-{{ $shape }}" dusk="new-ad-{{ $shape }}" class="{{ $choice }}">
        @else
            <a href="{{ route('builder.create', ['orientation' => $shape]) }}" dusk="new-ad-{{ $shape }}" class="{{ $choice }}">
        @endif
            <span class="mx-auto block h-[72px] {{ $width }} rounded-md border-4 border-gray-700 bg-gray-900 group-hover:border-blue-600 dark:border-gray-300" aria-hidden="true"></span>
            <span class="mt-3 block font-semibold text-gray-900 dark:text-gray-100">{{ $label }}</span>
            <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">{{ $size }}</span>
        @if ($asksHowToStart)
            </button>
        @else
            </a>
        @endif
    @endforeach
</div>
