{{-- The right-click menu (§10a), opened on an element, a layer or the empty stage. It stops its own
     presses, so a click inside it never counts as a click "somewhere else" that would close it.
     Each row: the editor method it runs, its words, its shortcut, its dusk name, and what it needs to
     be offered — a selection, something on the clipboard, both, or nothing. '-' draws a divider. --}}
<div x-show="contextMenu" x-cloak class="fixed z-[60] w-60 rounded-lg border border-gray-200 bg-white py-1 text-sm shadow-xl dark:border-gray-700 dark:bg-gray-800"
     x-bind:style="contextMenuStyle()" @pointerdown.stop @contextmenu.prevent dusk="context-menu" role="menu">

    <template x-if="contextMenu?.locked">
        <button type="button" class="flex w-full items-center justify-between px-3 py-1.5 text-left text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60"
                @click="runMenu('unlock')" dusk="menu-unlock" role="menuitem">
            <span x-text="'Unlock ' + (contextMenu.locked.name ?? contextMenu.locked.type)"></span>
        </button>
    </template>

    @foreach ([
        ['cutSelection', 'Cut', 'Ctrl+X', 'menu-cut', 'selection'],
        ['copySelection', 'Copy', 'Ctrl+C', 'menu-copy', 'selection'],
        ['pasteClipboard', 'Paste', 'Ctrl+V', 'menu-paste', 'clipboard'],
        ['pasteStyle', 'Paste style', 'Ctrl+Shift+V', 'menu-paste-style', 'both'],
        ['pasteAnimation', 'Paste animation', 'Ctrl+Alt+V', 'menu-paste-animation', 'both'],
        '-',
        ['duplicate', 'Duplicate', 'Ctrl+D', 'menu-duplicate', 'selection'],
        ['remove', 'Delete', 'Del', 'menu-delete', 'selection'],
        '-',
        ['bringToFront', 'Bring to front', 'Ctrl+Shift+]', 'menu-front', 'selection'],
        ['bringForward', 'Bring forward', 'Ctrl+]', 'menu-forward', 'selection'],
        ['sendBackward', 'Send backward', 'Ctrl+[', 'menu-backward', 'selection'],
        ['sendToBack', 'Send to back', 'Ctrl+Shift+[', 'menu-back', 'selection'],
        '-',
        ['toggleLockSelection', 'Lock', 'Ctrl+L', 'menu-lock', 'selection'],
        ['hideSelection', 'Hide', '', 'menu-hide', 'selection'],
        ['selectAll', 'Select all', 'Ctrl+A', 'menu-select-all', 'always'],
    ] as $item)
        @if ($item === '-')
            <div class="my-1 border-t border-gray-100 dark:border-gray-700"></div>
        @else
            <button type="button" role="menuitem"
                    class="flex w-full items-center justify-between px-3 py-1.5 text-left text-gray-700 hover:bg-gray-50 disabled:cursor-default disabled:text-gray-300 disabled:hover:bg-transparent dark:text-gray-200 dark:hover:bg-gray-700/60 dark:disabled:text-gray-600"
                    x-bind:disabled="{{ match ($item[4]) {
                        'selection' => 'selectedIds.length === 0',
                        'clipboard' => 'clipboardSize === 0',
                        'both' => 'selectedIds.length === 0 || clipboardSize === 0',
                        default => 'false',
                    } }}"
                    @click="runMenu('{{ $item[0] }}')" dusk="{{ $item[3] }}">
                <span>{{ $item[1] }}</span>
                <span class="text-xs text-gray-400">{{ $item[2] }}</span>
            </button>
        @endif
    @endforeach
</div>
