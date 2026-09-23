{{-- Every shortcut the editor knows (§10a), opened with ? or the ⌨ button. --}}
<div x-show="shortcutsOpen" x-cloak class="fixed inset-0 z-50 flex items-start justify-center bg-black/50 p-6"
     @click.self="shortcutsOpen = false" dusk="shortcuts-modal">
    <div class="mt-10 max-h-[80vh] w-full max-w-3xl overflow-y-auto rounded-lg bg-white p-6 dark:bg-gray-800">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Keyboard shortcuts</h3>
            <button type="button" class="btn-pager" @click="shortcutsOpen = false" dusk="shortcuts-close" aria-label="Close">✕</button>
        </div>

        <div class="mt-4 grid gap-x-10 gap-y-6 sm:grid-cols-2">
            @foreach ([
                'Editing' => [
                    ['Ctrl+S', 'Save'],
                    ['Ctrl+Z · Ctrl+Y', 'Undo · Redo (also Ctrl+Shift+Z)'],
                    ['Ctrl+C · Ctrl+X · Ctrl+V', 'Copy · Cut · Paste'],
                    ['Ctrl+Shift+V', 'Paste the copied element\'s style'],
                    ['Ctrl+Alt+V', 'Paste the copied element\'s animation'],
                    ['Ctrl+D', 'Duplicate'],
                    ['Delete', 'Delete'],
                    ['Double-click text', 'Edit the words where they are'],
                    ['Ctrl+G · Ctrl+Shift+G', 'Group · Ungroup'],
                    ['Double-click group · Enter', 'Work inside the group (Esc steps out)'],
                ],
                'Selecting and moving' => [
                    ['Click · Shift+click', 'Select · add or remove'],
                    ['Drag on empty stage', 'Select everything it touches'],
                    ['Ctrl+A', 'Select all'],
                    ['Arrows · Shift+arrows', 'Move 1 px · 10 px'],
                    ['Shift while resizing', 'Keep the proportions'],
                    ['Alt while resizing', 'Resize from the centre'],
                    ['Shift while rotating', 'Turn in 15° steps'],
                    ['Ctrl+L', 'Lock'],
                ],
                'Arranging' => [
                    ['Alt+A · Alt+H · Alt+D', 'Align left · centre · right'],
                    ['Alt+W · Alt+V · Alt+S', 'Align top · middle · bottom'],
                    ['Alt+Shift+H · Alt+Shift+V', 'Space evenly across · down'],
                    ['Ctrl+] · Ctrl+[', 'Bring forward · Send backward'],
                    ['Ctrl+Shift+] · Ctrl+Shift+[', 'Bring to front · Send to back'],
                ],
                'Viewing' => [
                    ['Ctrl+P', 'Play · Stop the whole ad'],
                    ['Ctrl+0 · Shift+1', 'Fit the stage'],
                    ['Shift+0', '100 %'],
                    ['Ctrl+= · Ctrl+− · Ctrl+wheel', 'Zoom in · out'],
                    ['Wheel · Space+drag', 'Move around'],
                    ['Shift+R', 'Rulers and guides'],
                    ['?', 'This list'],
                    ['Esc', 'Close · Stop · Clear the selection'],
                ],
            ] as $group => $shortcuts)
                <div>
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ $group }}</h4>
                    <dl class="mt-2 space-y-1.5">
                        @foreach ($shortcuts as [$keys, $does])
                            <div class="flex items-baseline justify-between gap-4 text-sm">
                                <dt class="shrink-0 font-mono text-xs text-gray-700 dark:text-gray-200">{{ $keys }}</dt>
                                <dd class="text-right text-gray-500 dark:text-gray-400">{{ $does }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endforeach
        </div>
    </div>
</div>
