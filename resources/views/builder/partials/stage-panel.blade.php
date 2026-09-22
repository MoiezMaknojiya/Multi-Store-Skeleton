{{-- The stage itself, when nothing on it is selected (stage 3): its colour and the layers stacked on it —
     colours, gradients, pictures and videos, each with its own opacity and blend mode. --}}
<div class="space-y-5 p-4" dusk="stage-panel">
    <div>
        <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-400">Background</h4>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            Select something on the stage to change it — or build the background here, one layer on another.
        </p>
    </div>

    <label class="block text-xs text-gray-500 dark:text-gray-400">Stage colour
        <input type="color" class="mt-1 h-9 w-full rounded border border-gray-300 dark:border-gray-600"
               x-bind:value="stageColour()" @change="setStageColour($el.value)" dusk="stage-color" />
    </label>

    <div>
        <div class="flex items-center justify-between">
            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-400">Layers</h4>
            <span class="text-xs text-gray-400" x-text="doc.stage.background.layers.length + ' / ' + maxLayers"></span>
        </div>

        <div class="mt-2 grid grid-cols-2 gap-1">
            <button type="button" class="btn-secondary text-xs" @click="addLayer('color')"
                    x-bind:disabled="!canAddLayer()" dusk="bg-add-color">+ Colour</button>
            <button type="button" class="btn-secondary text-xs" @click="addLayer('gradient')"
                    x-bind:disabled="!canAddLayer()" dusk="bg-add-gradient">+ Gradient</button>
            <button type="button" class="btn-secondary text-xs" @click="addLayer('image')"
                    x-bind:disabled="!canAddLayer()" dusk="bg-add-image">+ Picture</button>
            <button type="button" class="btn-secondary text-xs" @click="addLayer('video')"
                    x-bind:disabled="!canAddLayer()" dusk="bg-add-video">+ Video</button>
        </div>

        <p x-show="doc.stage.background.layers.length === 0" x-cloak class="mt-3 text-xs text-gray-400">
            No layers yet — the stage colour fills the frame.
        </p>

        {{-- Front first, like every layers panel. --}}
        <div class="mt-2 space-y-1" dusk="bg-layers">
            <template x-for="layer in layersFrontFirst()" :key="layer.id">
                <div class="group flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm"
                     x-bind:class="selectedLayerId === layer.id
                         ? 'bg-blue-50 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200'
                         : 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700/50'"
                     @click="selectLayer(layer)" x-bind:dusk="'bg-layer-' + layer.id">
                    <button type="button" class="text-gray-400 hover:text-gray-600"
                            @click.stop="toggleLayer(layer)"
                            x-bind:title="layer.visible === false ? 'Show' : 'Hide'"
                            x-bind:dusk="'bg-layer-visible-' + layer.id"
                            x-text="layer.visible === false ? '◌' : '●'"></button>
                    <span class="h-4 w-4 shrink-0 rounded border border-gray-300 dark:border-gray-600"
                          x-bind:style="layerSwatch(layer)"></span>
                    <span class="min-w-0 flex-1 truncate" x-text="layerName(layer)"></span>
                    <button type="button" class="text-xs text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                            @click.stop="moveLayer(layer, 1)" title="Bring forward"
                            x-bind:dusk="'bg-layer-up-' + layer.id">▲</button>
                    <button type="button" class="text-xs text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                            @click.stop="moveLayer(layer, -1)" title="Send backward"
                            x-bind:dusk="'bg-layer-down-' + layer.id">▼</button>
                    <button type="button" class="text-xs text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                            @click.stop="duplicateLayer(layer)" title="Duplicate"
                            x-bind:dusk="'bg-layer-duplicate-' + layer.id">⧉</button>
                    <button type="button" class="text-xs text-gray-400 hover:text-red-500"
                            @click.stop="removeLayer(layer)" title="Delete"
                            x-bind:dusk="'bg-layer-delete-' + layer.id">✕</button>
                </div>
            </template>
        </div>
    </div>

    {{-- The layer being edited. --}}
    <template x-if="selectedLayer()">
        <div class="space-y-3 border-t border-gray-200 pt-4 dark:border-gray-700" dusk="bg-layer-settings">
            <h4 class="truncate text-xs font-semibold uppercase tracking-wide text-gray-400" x-text="layerName(selectedLayer())"></h4>

            <div class="grid grid-cols-2 gap-2">
                <label class="text-xs text-gray-500 dark:text-gray-400">
                    <span class="flex justify-between">
                        <span>Opacity</span>
                        <span x-text="Math.round((selectedLayer().opacity ?? 1) * 100) + '%'"></span>
                    </span>
                    <input type="range" min="0" max="1" step="0.05" class="mt-2 w-full"
                           x-bind:value="selectedLayer().opacity ?? 1"
                           @input="setLayerNumber('opacity', $el.value)" dusk="bg-layer-opacity" />
                </label>
                <label class="text-xs text-gray-500 dark:text-gray-400">Blend
                    <select class="form-select mt-1 w-full text-sm" x-bind:value="selectedLayer().blend ?? 'normal'"
                            @change="setLayer('blend', $el.value)" dusk="bg-layer-blend">
                        @foreach ($blends as $blend)
                            <option value="{{ $blend }}">{{ ucfirst(str_replace('-', ' ', $blend)) }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <template x-if="selectedLayer().type === 'color'">
                <label class="block text-xs text-gray-500 dark:text-gray-400">Colour
                    <input type="color" class="mt-1 h-9 w-full rounded border border-gray-300 dark:border-gray-600"
                           x-bind:value="selectedLayer().color ?? '#000000'"
                           @change="setLayer('color', $el.value)" dusk="bg-layer-color" />
                </label>
            </template>

            <template x-if="selectedLayer().type === 'gradient'">
                <div>
                    @include('builder.partials.gradient-editor', ['target' => 'layer', 'prefix' => 'bg'])
                </div>
            </template>

            <template x-if="selectedLayer().type === 'image' || selectedLayer().type === 'video'">
                <div class="space-y-3">
                    <button type="button" class="btn-secondary w-full text-xs"
                            @click="openAssetPicker('layer', selectedLayer().type)" dusk="bg-layer-choose"
                            x-text="selectedLayer().assetId ? 'Choose another…' : (selectedLayer().type === 'video' ? 'Choose a video…' : 'Choose a picture…')"></button>

                    <template x-if="selectedLayer().type === 'image'">
                        <div class="space-y-3">
                            <div class="grid grid-cols-2 gap-2">
                                <label class="text-xs text-gray-500 dark:text-gray-400">Size
                                    <select class="form-select mt-1 w-full text-sm" x-bind:value="selectedLayer().size ?? 'cover'"
                                            @change="setLayer('size', $el.value)" dusk="bg-layer-size">
                                        @foreach ($sizes as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="text-xs text-gray-500 dark:text-gray-400">Repeat
                                    <select class="form-select mt-1 w-full text-sm" x-bind:value="selectedLayer().repeat ?? 'no-repeat'"
                                            @change="setLayer('repeat', $el.value)" dusk="bg-layer-repeat">
                                        @foreach ($repeats as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            </div>

                            <label class="block text-xs text-gray-500 dark:text-gray-400" x-show="selectedLayer().size === 'custom'">Scale (% of the stage width)
                                <input type="number" min="1" max="1000" class="form-input mt-1 w-full text-sm"
                                       x-bind:value="selectedLayer().scale ?? 100"
                                       @change="$el.value = setLayerNumber('scale', $el.value)" dusk="bg-layer-scale" />
                            </label>

                            <div>
                                <span class="text-xs text-gray-500 dark:text-gray-400">Position</span>
                                <div class="mt-1 grid w-20 grid-cols-3 gap-1">
                                    @foreach ($positions as $position)
                                        <button type="button" class="h-5 rounded-sm border"
                                                x-bind:class="(selectedLayer().position ?? 'center center') === '{{ $position }}'
                                                    ? 'border-blue-500 bg-blue-500'
                                                    : 'border-gray-300 hover:border-blue-400 dark:border-gray-600'"
                                                @click="setLayer('position', '{{ $position }}')" title="{{ ucwords($position) }}"
                                                dusk="bg-layer-position-{{ str_replace(' ', '-', $position) }}"></button>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </template>

                    <template x-if="selectedLayer().type === 'video'">
                        <label class="block text-xs text-gray-500 dark:text-gray-400">Fit
                            <select class="form-select mt-1 w-full text-sm" x-bind:value="selectedLayer().fit ?? 'cover'"
                                    @change="setLayer('fit', $el.value)" dusk="bg-layer-fit">
                                @foreach ($videoFits as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                    </template>
                </div>
            </template>
        </div>
    </template>
</div>
