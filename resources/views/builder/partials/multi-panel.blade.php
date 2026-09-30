{{-- Several elements selected (§10a): what can be done to them together. Resizing and rotating stay one
     element at a time. --}}
<div class="space-y-5 p-4" dusk="multi-panel">
    <div class="flex items-center justify-between">
        <p class="text-sm font-medium text-gray-700 dark:text-gray-200">
            <span x-text="selectedIds.length" dusk="multi-count"></span> elements selected
        </p>

        <div class="flex gap-1">
            <button type="button" class="btn-pager" @click="duplicate()" title="Duplicate (Ctrl+D)" aria-label="Duplicate them" dusk="multi-duplicate"><x-icon name="copy" /></button>
            <button type="button" class="btn-pager hover:!text-red-700 dark:hover:!text-red-400" @click="remove()" title="Delete (Delete)" aria-label="Delete them" dusk="multi-delete"><x-icon name="trash" /></button>
        </div>
    </div>

    {{-- Made one (§13): moved, resized, turned and animated together from then on. --}}
    <button type="button" class="btn-primary w-full" @click="groupSelection()" x-bind:disabled="!canGroup()"
            title="Group (Ctrl+G)" dusk="group-selection">Group</button>

    @include('builder.partials.align-controls', ['label' => 'Align to each other'])

    <div>
        <span class="text-xs text-gray-500 dark:text-gray-400">Space evenly (three or more)</span>
        <div class="mt-1 grid grid-cols-2 gap-1">
            <button type="button" class="btn-secondary" x-bind:disabled="selectedIds.length < 3"
                    @click="distributeSelection('x')" title="Alt+Shift+H" dusk="distribute-x">Across</button>
            <button type="button" class="btn-secondary" x-bind:disabled="selectedIds.length < 3"
                    @click="distributeSelection('y')" title="Alt+Shift+V" dusk="distribute-y">Down</button>
        </div>
    </div>

    <label class="block text-xs text-gray-500 dark:text-gray-400">Opacity of all (%)
        <input type="number" step="5" min="0" max="100" class="form-input mt-1"
               x-bind:value="Math.round((selection()[0]?.opacity ?? 1) * 100)"
               @change="$el.value = setSelectionOpacityPercent($el.value)" dusk="multi-opacity" />
    </label>

    <div class="grid grid-cols-2 gap-1">
        <button type="button" class="btn-secondary" x-bind:disabled="clipboardSize === 0"
                @click="pasteStyle()" title="Ctrl+Shift+V" dusk="multi-paste-style">Paste style</button>
        <button type="button" class="btn-secondary" x-bind:disabled="clipboardSize === 0"
                @click="pasteAnimation()" title="Ctrl+Alt+V" dusk="multi-paste-animation">Paste animation</button>
        <button type="button" class="btn-secondary" @click="toggleLockSelection()" title="Ctrl+L" dusk="multi-lock">Lock</button>
        <button type="button" class="btn-secondary" @click="hideSelection()" dusk="multi-hide">Hide</button>
    </div>
</div>
