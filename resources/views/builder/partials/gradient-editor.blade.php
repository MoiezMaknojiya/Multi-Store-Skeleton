{{-- One gradient editor for a background layer's gradient and a shape's fill ($target: layer|shape).
     $prefix names its dusk selectors. The stops keep the order they were added in, which is the order
     the compiler writes them. --}}
<div class="space-y-2">
    <div class="grid grid-cols-2 gap-2">
        <label class="text-xs text-gray-500 dark:text-gray-400">Type
            <select class="form-select mt-1 w-full text-sm"
                    x-bind:value="gradientOf('{{ $target }}')?.kind ?? 'linear'"
                    @change="setGradient('{{ $target }}', 'kind', $el.value)" dusk="{{ $prefix }}-gradient-kind">
                @foreach ($gradientKinds as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-xs text-gray-500 dark:text-gray-400"
               x-show="(gradientOf('{{ $target }}')?.kind ?? 'linear') === 'linear'">Angle (°)
            <input type="number" min="0" max="360" class="form-input mt-1 w-full text-sm"
                   x-bind:value="gradientOf('{{ $target }}')?.angle ?? 180"
                   @change="$el.value = setGradient('{{ $target }}', 'angle', $el.value)" dusk="{{ $prefix }}-gradient-angle" />
        </label>
    </div>

    {{-- The stops left to right, so the colours read as one strip. --}}
    <div class="h-3 rounded border border-gray-200 dark:border-gray-700"
         x-bind:style="{ backgroundImage: gradientPreview('{{ $target }}') }"></div>

    <template x-for="(stop, index) in (gradientOf('{{ $target }}')?.stops ?? [])" :key="index">
        <div class="flex items-center gap-2">
            <input type="color" class="h-8 w-10 shrink-0 rounded border border-gray-300 dark:border-gray-600"
                   x-bind:value="stop.color"
                   @change="setStop('{{ $target }}', index, 'color', $el.value)"
                   x-bind:dusk="'{{ $prefix }}-gradient-stop-' + index + '-color'" aria-label="Stop colour" />
            <input type="number" min="0" max="100" class="form-input h-8 w-20 text-sm"
                   x-bind:value="stop.at"
                   @change="$el.value = setStop('{{ $target }}', index, 'at', $el.value)"
                   x-bind:dusk="'{{ $prefix }}-gradient-stop-' + index + '-at'" aria-label="Stop position" />
            <span class="text-xs text-gray-400">%</span>
            <button type="button" class="ml-auto text-gray-400 hover:text-red-500"
                    x-show="(gradientOf('{{ $target }}')?.stops?.length ?? 0) > 2"
                    @click="removeStop('{{ $target }}', index)" title="Remove this colour"
                    x-bind:dusk="'{{ $prefix }}-gradient-stop-' + index + '-remove'">✕</button>
        </div>
    </template>

    <button type="button" class="btn-secondary w-full text-xs"
            x-show="(gradientOf('{{ $target }}')?.stops?.length ?? 0) < maxStops"
            @click="addStop('{{ $target }}')" dusk="{{ $prefix }}-gradient-add-stop">+ Add a colour</button>
</div>
