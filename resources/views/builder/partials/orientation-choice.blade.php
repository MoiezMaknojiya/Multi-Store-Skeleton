{{-- Which way is the screen mounted? (docs/AD-BUILDER-SPEC.md §12) — the same two choices on the Create tab's
     page and in the Ads tab's New ad dialog. Links, so the editor opens on a stage of that shape. --}}
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <a href="{{ route('builder.create', ['orientation' => 'landscape']) }}" dusk="new-ad-landscape"
       class="group rounded-lg border-2 border-gray-200 p-4 text-center transition hover:border-blue-500 hover:bg-blue-50 focus:outline-none focus-visible:border-blue-500 focus-visible:ring-2 focus-visible:ring-blue-500 dark:border-gray-700 dark:hover:border-blue-400 dark:hover:bg-gray-700/50">
        <span class="mx-auto block h-[72px] w-32 rounded-md border-4 border-gray-700 bg-gray-900 group-hover:border-blue-600 dark:border-gray-300 dark:bg-gray-900" aria-hidden="true"></span>
        <span class="mt-3 block font-semibold text-gray-900 dark:text-gray-100">Landscape</span>
        <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">1920 × 1080 · a television the usual way round</span>
    </a>

    <a href="{{ route('builder.create', ['orientation' => 'portrait']) }}" dusk="new-ad-portrait"
       class="group rounded-lg border-2 border-gray-200 p-4 text-center transition hover:border-blue-500 hover:bg-blue-50 focus:outline-none focus-visible:border-blue-500 focus-visible:ring-2 focus-visible:ring-blue-500 dark:border-gray-700 dark:hover:border-blue-400 dark:hover:bg-gray-700/50">
        <span class="mx-auto block h-[72px] w-10 rounded-md border-4 border-gray-700 bg-gray-900 group-hover:border-blue-600 dark:border-gray-300 dark:bg-gray-900" aria-hidden="true"></span>
        <span class="mt-3 block font-semibold text-gray-900 dark:text-gray-100">Portrait</span>
        <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">1080 × 1920 · a television mounted upright — menu boards, posters</span>
    </a>
</div>
