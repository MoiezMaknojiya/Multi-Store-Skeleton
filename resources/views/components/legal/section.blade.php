@props(['id', 'title'])

{{-- One section of a document page: its heading is the anchor the page's contents list points to. --}}
<section aria-labelledby="{{ $id }}" class="mt-10 scroll-mt-6">
    <h2 id="{{ $id }}" class="text-xl font-semibold text-gray-800 dark:text-white">{{ $title }}</h2>
    <div class="mt-3 space-y-3 leading-relaxed text-gray-700 dark:text-gray-300 [&_ul]:list-disc [&_ul]:space-y-2 [&_ul]:pl-5 [&_strong]:font-semibold [&_strong]:text-gray-800 dark:[&_strong]:text-white">
        {{ $slot }}
    </div>
</section>
