{{-- Align and order the selection (§10a): to the stage for one element, to the selection's own bounds for
     several. $label says which. --}}
<div class="space-y-2">
    <div>
        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</span>
        <div class="mt-1 grid grid-cols-6 gap-1">
            @foreach ([
                'left' => ['⇤', 'Align left (Alt+A)'],
                'center' => ['↔', 'Centre across (Alt+H)'],
                'right' => ['⇥', 'Align right (Alt+D)'],
                'top' => ['⤒', 'Align top (Alt+W)'],
                'middle' => ['↕', 'Centre down (Alt+V)'],
                'bottom' => ['⤓', 'Align bottom (Alt+S)'],
            ] as $edge => [$icon, $title])
                <button type="button" class="btn-pager text-xs" title="{{ $title }}" aria-label="{{ $title }}"
                        @click="alignSelection('{{ $edge }}')" dusk="align-{{ $edge }}">{{ $icon }}</button>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-4 gap-1">
        <button type="button" class="btn-secondary px-1 text-xs" title="Bring to front (Ctrl+Shift+])"
                @click="reorder('front')" dusk="element-front">Front</button>
        <button type="button" class="btn-secondary px-1 text-xs" title="Bring forward (Ctrl+])"
                @click="reorder('forward')" dusk="element-forward">Up</button>
        <button type="button" class="btn-secondary px-1 text-xs" title="Send backward (Ctrl+[)"
                @click="reorder('backward')" dusk="element-backward">Down</button>
        <button type="button" class="btn-secondary px-1 text-xs" title="Send to back (Ctrl+Shift+[)"
                @click="reorder('back')" dusk="element-back">Back</button>
    </div>
</div>
