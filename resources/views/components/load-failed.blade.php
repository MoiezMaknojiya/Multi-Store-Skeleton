{{-- A list that could not be loaded says so, with the way to try again — never "Nothing here yet" over a list that has
     rows, after a lost connection or an ended session (rule 02: three cases, told apart). `retry` is the page's own
     call; `what` names the list ("the playlist"). --}}
@props(['retry', 'what' => 'this list', 'dusk' => 'load-failed', 'tryDusk' => null])

<div {{ $attributes->class('mx-auto max-w-md space-y-3 text-center') }} role="alert" dusk="{{ $dusk }}">
    <p class="text-sm text-gray-700 dark:text-gray-200">Could not load {{ $what }}. Check the connection, then try again.</p>
    <button type="button" class="btn-row-neutral" @click="{{ $retry }}" dusk="{{ $tryDusk ?? $dusk.'-try-again' }}">Try Again</button>
</div>
