{{-- A list on its way: the spinner and the words, the one look every page gives it (rule 02 — something working shows
     <x-spinner>). `text` when the words should say what is coming ("Loading the other screens..."). --}}
@props(['text' => 'Loading...'])

<span {{ $attributes->class('inline-flex items-center gap-2') }}><x-spinner class="text-blue-600 dark:text-blue-400" /> {{ $text }}</span>
