{{-- A shape's controls (stage 3): rectangle or ellipse, one colour or a gradient, corners, a frame and a
     shadow — enough for a price badge, a ribbon, a glow behind a product. A line (§14) keeps only what a
     line has: its thickness, its dash pattern and its colour. --}}
<div class="space-y-3">
    <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-400">Shape</h4>

    <div class="grid grid-cols-3 gap-1">
        @foreach ($shapes as $shape => $label)
            <button type="button" class="btn-pager text-xs"
                    x-bind:class="(selected.style?.shape ?? 'rect') === '{{ $shape }}' ? '!border-blue-500 !text-blue-600' : ''"
                    @click="setStyle('shape', '{{ $shape }}')" dusk="shape-kind-{{ $shape }}">{{ $label }}</button>
        @endforeach
    </div>

    {{-- A line: thickness, dash pattern and colour, nothing else. --}}
    <template x-if="selected.style?.shape === 'line'">
        <div class="space-y-3" dusk="line-controls">
            <div class="grid grid-cols-2 gap-2">
                <label class="text-xs text-gray-500 dark:text-gray-400">Thickness
                    <input type="number" min="1" max="200" class="form-input mt-1 w-full text-sm"
                           x-bind:value="selected.style?.lineWidth ?? 6"
                           @change="$el.value = setStyleNumber('lineWidth', $el.value)" dusk="line-width" />
                </label>
                <label class="text-xs text-gray-500 dark:text-gray-400">Style
                    <select class="form-select mt-1 w-full text-sm" x-bind:value="selected.style?.lineStyle ?? 'solid'"
                            @change="setStyle('lineStyle', $el.value)" dusk="line-style">
                        @foreach ($lineStyles as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <label class="block text-xs text-gray-500 dark:text-gray-400">Colour
                <input type="color" class="mt-1 h-9 w-full rounded border border-gray-300 dark:border-gray-600"
                       x-bind:value="selected.style?.fill ?? '#ffffff'"
                       @change="setStyle('fill', $el.value)" dusk="line-color" />
            </label>

            <p class="text-xs text-gray-400">Its length is the box's width; turn the box to angle it.</p>
        </div>
    </template>

    <template x-if="selected.style?.shape !== 'line'">
        <div class="space-y-3">
            <div class="grid grid-cols-2 gap-1">
                <button type="button" class="btn-pager text-xs"
                        x-bind:class="!selected.style?.gradient ? '!border-blue-500 !text-blue-600' : ''"
                        @click="setShapeFill('solid')" dusk="shape-fill-solid">One colour</button>
                <button type="button" class="btn-pager text-xs"
                        x-bind:class="selected.style?.gradient ? '!border-blue-500 !text-blue-600' : ''"
                        @click="setShapeFill('gradient')" dusk="shape-fill-gradient">Gradient</button>
            </div>

            <label class="block text-xs text-gray-500 dark:text-gray-400" x-show="!selected.style?.gradient">Fill
                <input type="color" class="mt-1 h-9 w-full rounded border border-gray-300 dark:border-gray-600"
                       x-bind:value="selected.style?.fill ?? '#2563eb'"
                       @change="setStyle('fill', $el.value)" dusk="shape-fill" />
            </label>

            <div x-show="selected.style?.gradient" x-cloak>
                @include('builder.partials.gradient-editor', ['target' => 'shape', 'prefix' => 'shape'])
            </div>

            <label class="block text-xs text-gray-500 dark:text-gray-400" x-show="selected.style?.shape !== 'ellipse'">Corner radius
                <input type="number" min="0" max="999" class="form-input mt-1 w-full text-sm"
                       x-bind:value="selected.style?.radius ?? 0"
                       @change="$el.value = setStyleNumber('radius', $el.value)" dusk="shape-radius" />
            </label>

            @include('builder.partials.frame-controls', ['prefix' => 'shape', 'withShadow' => true])
        </div>
    </template>
</div>
