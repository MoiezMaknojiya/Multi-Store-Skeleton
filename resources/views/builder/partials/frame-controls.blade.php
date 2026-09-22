{{-- A frame and a drop shadow — what lifts a picture or a shape off the background. $prefix names the
     dusk selectors; $withShadow is false for text, which has a shadow of its own. --}}
<div class="space-y-2">
    <button type="button" class="btn-secondary w-full text-xs"
            @click="toggleNestedStyle('border', { width: 6, style: 'solid', color: '#ffffff' })"
            dusk="{{ $prefix }}-border-toggle">
        <span x-text="selected.style?.border ? 'Remove border' : 'Add border'"></span>
    </button>

    <div x-show="selected.style?.border" x-cloak class="grid grid-cols-3 gap-2">
        <label class="text-xs text-gray-500 dark:text-gray-400">Width
            <input type="number" min="0" max="200" class="form-input mt-1 w-full text-sm"
                   x-bind:value="selected.style?.border?.width ?? 0"
                   @change="$el.value = setNestedNumber('border', 'width', $el.value)" dusk="{{ $prefix }}-border-width" />
        </label>
        <label class="text-xs text-gray-500 dark:text-gray-400">Line
            <select class="form-select mt-1 w-full text-sm" x-bind:value="selected.style?.border?.style ?? 'solid'"
                    @change="setNestedStyle('border', 'style', $el.value)" dusk="{{ $prefix }}-border-style">
                @foreach ($borderStyles as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-xs text-gray-500 dark:text-gray-400">Colour
            <input type="color" class="mt-1 h-9 w-full rounded border border-gray-300 dark:border-gray-600"
                   x-bind:value="selected.style?.border?.color ?? '#ffffff'"
                   @change="setNestedStyle('border', 'color', $el.value)" dusk="{{ $prefix }}-border-color" />
        </label>
    </div>

    @if ($withShadow)
        <button type="button" class="btn-secondary w-full text-xs"
                @click="toggleNestedStyle('shadow', { x: 0, y: 16, blur: 40, spread: 0, color: '#000000' })"
                dusk="{{ $prefix }}-shadow-toggle">
            <span x-text="selected.style?.shadow ? 'Remove shadow' : 'Add shadow'"></span>
        </button>

        <div x-show="selected.style?.shadow" x-cloak class="grid grid-cols-2 gap-2">
            <label class="text-xs text-gray-500 dark:text-gray-400">X
                <input type="number" min="-500" max="500" class="form-input mt-1 w-full text-sm"
                       x-bind:value="selected.style?.shadow?.x ?? 0"
                       @change="$el.value = setNestedNumber('shadow', 'x', $el.value)" dusk="{{ $prefix }}-shadow-x" />
            </label>
            <label class="text-xs text-gray-500 dark:text-gray-400">Y
                <input type="number" min="-500" max="500" class="form-input mt-1 w-full text-sm"
                       x-bind:value="selected.style?.shadow?.y ?? 0"
                       @change="$el.value = setNestedNumber('shadow', 'y', $el.value)" dusk="{{ $prefix }}-shadow-y" />
            </label>
            <label class="text-xs text-gray-500 dark:text-gray-400">Blur
                <input type="number" min="0" max="500" class="form-input mt-1 w-full text-sm"
                       x-bind:value="selected.style?.shadow?.blur ?? 0"
                       @change="$el.value = setNestedNumber('shadow', 'blur', $el.value)" dusk="{{ $prefix }}-shadow-blur" />
            </label>
            <label class="text-xs text-gray-500 dark:text-gray-400">Spread
                <input type="number" min="-500" max="500" class="form-input mt-1 w-full text-sm"
                       x-bind:value="selected.style?.shadow?.spread ?? 0"
                       @change="$el.value = setNestedNumber('shadow', 'spread', $el.value)" dusk="{{ $prefix }}-shadow-spread" />
            </label>
            <label class="col-span-2 text-xs text-gray-500 dark:text-gray-400">Shadow colour
                <input type="color" class="mt-1 h-9 w-full rounded border border-gray-300 dark:border-gray-600"
                       x-bind:value="selected.style?.shadow?.color ?? '#000000'"
                       @change="setNestedStyle('shadow', 'color', $el.value)" dusk="{{ $prefix }}-shadow-color" />
            </label>
        </div>
    @endif
</div>
