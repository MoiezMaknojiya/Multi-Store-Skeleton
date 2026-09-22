{{-- A picture's or a video's own controls (stage 3): the file, how it fills its box, where it is pinned,
     its corners, a mirror, a frame and a shadow, and the colour filters. Every option is a value
     AdCompiler writes. --}}
<div class="space-y-3">
    <div class="flex items-center justify-between">
        <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-400"
            x-text="selected.type === 'video' ? 'Video' : 'Picture'"></h4>
        <button type="button" class="text-xs text-blue-600 hover:underline dark:text-blue-400"
                @click="openAssetPicker('replace', selected.type === 'video' ? 'video' : 'image')"
                dusk="image-replace">Replace…</button>
    </div>

    <label class="block text-xs text-gray-500 dark:text-gray-400">Fit
        <select class="form-select mt-1 w-full text-sm" x-bind:value="selected.style?.fit ?? 'cover'"
                @change="setStyle('fit', $el.value)" dusk="image-fit">
            @foreach ($fits as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </label>

    <div class="flex items-start gap-4">
        {{-- Which part of the picture stays in view when the box crops it. --}}
        <div>
            <span class="text-xs text-gray-500 dark:text-gray-400">Focus</span>
            <div class="mt-1 grid w-20 grid-cols-3 gap-1">
                @foreach ($positions as $position)
                    <button type="button" class="h-5 rounded-sm border"
                            x-bind:class="(selected.style?.position ?? 'center center') === '{{ $position }}'
                                ? 'border-blue-500 bg-blue-500'
                                : 'border-gray-300 hover:border-blue-400 dark:border-gray-600'"
                            @click="setStyle('position', '{{ $position }}')" title="{{ ucwords($position) }}"
                            dusk="image-position-{{ str_replace(' ', '-', $position) }}"></button>
                @endforeach
            </div>
        </div>

        <div class="flex-1 space-y-2">
            <label class="block text-xs text-gray-500 dark:text-gray-400">Corner radius
                <input type="number" min="0" max="999" class="form-input mt-1 w-full text-sm"
                       x-bind:value="selected.style?.radius ?? 0"
                       @change="$el.value = setStyleNumber('radius', $el.value)" dusk="image-radius" />
            </label>

            <div class="grid grid-cols-2 gap-1">
                <button type="button" class="btn-pager text-xs" title="Mirror left to right"
                        x-bind:class="selected.style?.flipX ? '!border-blue-500 !text-blue-600' : ''"
                        @click="toggleFlip('x')" dusk="image-flip-x">⇋</button>
                <button type="button" class="btn-pager text-xs" title="Turn upside down"
                        x-bind:class="selected.style?.flipY ? '!border-blue-500 !text-blue-600' : ''"
                        @click="toggleFlip('y')" dusk="image-flip-y">⇵</button>
            </div>
        </div>
    </div>

    @include('builder.partials.frame-controls', ['prefix' => 'image', 'withShadow' => true])

    <button type="button" class="btn-secondary w-full text-xs" @click="showFilters = !showFilters" dusk="image-filters-toggle">
        <span x-text="showFilters ? 'Hide filters' : 'Filters'"></span>
    </button>

    <div x-show="showFilters" x-cloak class="space-y-2">
        @foreach ($filters as $filter)
            <label class="block text-xs text-gray-500 dark:text-gray-400">
                <span class="flex justify-between">
                    <span>{{ $filter['label'] }}</span>
                    <span x-text="(selected.style?.filters?.{{ $filter['key'] }} ?? {{ $filter['neutral'] }}) + '{{ $filter['unit'] }}'"></span>
                </span>
                <input type="range" class="mt-1 w-full" min="{{ $filter['min'] }}" max="{{ $filter['max'] }}" step="1"
                       x-bind:value="selected.style?.filters?.{{ $filter['key'] }} ?? {{ $filter['neutral'] }}"
                       @input="setFilter('{{ $filter['key'] }}', $el.value)" dusk="image-filter-{{ $filter['key'] }}" />
            </label>
        @endforeach

        <button type="button" class="btn-secondary w-full text-xs" @click="resetFilters()" dusk="image-filters-reset">Reset filters</button>
    </div>
</div>
