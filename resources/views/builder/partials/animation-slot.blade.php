{{-- One animation slot ($slot: in|loop|out) — its effect, the controls that effect reads, its timing and
     its ease. $effects is the runtime's own list (AdAnimations), so every option is one a television can
     play. --}}
<div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700" dusk="anim-{{ $slot }}">
    <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-300">{{ $title }}</h4>
    <p class="mt-0.5 text-xs text-gray-400">{{ $hint }}</p>

    <select class="form-select mt-2 w-full text-sm" x-bind:value="slotOf('{{ $slot }}')?.effect ?? ''"
            @change="setEffect('{{ $slot }}', $el.value)" dusk="anim-{{ $slot }}-effect">
        <option value="">None</option>
        @foreach ($effects as $effect)
            <option value="{{ $effect }}">{{ $effectLabels[$effect] ?? ucfirst($effect) }}</option>
        @endforeach
    </select>

    <div x-show="slotOf('{{ $slot }}')" x-cloak class="mt-3 space-y-2">
        @if ($slot === 'loop')
            <label class="block text-xs text-gray-500 dark:text-gray-400" x-show="slotUses('loop', 'axis')">Direction
                <select class="form-select mt-1 w-full text-sm" x-bind:value="slotOf('loop')?.axis ?? 'y'"
                        @change="setSlot('loop', 'axis', $el.value)" dusk="anim-loop-axis">
                    @foreach ($axes as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block text-xs text-gray-500 dark:text-gray-400" x-show="slotUses('loop', 'spinDirection')">Direction
                <select class="form-select mt-1 w-full text-sm" x-bind:value="spinDirection()"
                        @change="setSpinDirection($el.value)" dusk="anim-loop-spin">
                    <option value="clockwise">Clockwise</option>
                    <option value="anticlockwise">Anticlockwise</option>
                </select>
            </label>

            <label class="block text-xs text-gray-500 dark:text-gray-400" x-show="slotUses('loop', 'amount')">
                <span x-text="loopAmount().label"></span>
                <input type="number" step="1" class="form-input mt-1 w-full text-sm"
                       x-bind:min="loopAmount().min" x-bind:max="loopAmount().max"
                       x-bind:value="slotOf('loop')?.amount ?? 0"
                       @change="$el.value = setSlot('loop', 'amount', $el.value)" dusk="anim-loop-amount" />
            </label>

            <div class="grid grid-cols-2 gap-2" x-show="slotUses('loop', 'amountX') || slotUses('loop', 'amountY')">
                <label class="text-xs text-gray-500 dark:text-gray-400">Across (px)
                    <input type="number" step="1" class="form-input mt-1 w-full text-sm"
                           x-bind:value="slotOf('loop')?.amountX ?? 0"
                           @change="$el.value = setSlot('loop', 'amountX', $el.value)" dusk="anim-loop-amount-x" />
                </label>
                <label class="text-xs text-gray-500 dark:text-gray-400">Down (px)
                    <input type="number" step="1" class="form-input mt-1 w-full text-sm"
                           x-bind:value="slotOf('loop')?.amountY ?? 0"
                           @change="$el.value = setSlot('loop', 'amountY', $el.value)" dusk="anim-loop-amount-y" />
                </label>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <label class="text-xs text-gray-500 dark:text-gray-400">
                    <span x-text="slotOf('loop')?.effect === 'spin' ? 'One turn (s)' : 'One swing (s)'"></span>
                    <input type="number" step="0.1" min="0.1" max="120" class="form-input mt-1 w-full text-sm"
                           x-bind:value="slotOf('loop')?.duration ?? 2"
                           @change="$el.value = setSlot('loop', 'duration', $el.value)" dusk="anim-loop-duration" />
                </label>
                <label class="text-xs text-gray-500 dark:text-gray-400">Wait first (s)
                    <input type="number" step="0.1" min="0" max="600" class="form-input mt-1 w-full text-sm"
                           x-bind:value="slotOf('loop')?.delay ?? 0"
                           @change="$el.value = setSlot('loop', 'delay', $el.value)" dusk="anim-loop-delay" />
                </label>
            </div>

            <label class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400" x-show="slotOf('loop')?.effect !== 'spin'">
                <input type="checkbox" class="rounded border-gray-300 dark:border-gray-600"
                       x-bind:checked="slotOf('loop')?.yoyo !== false"
                       @change="setSlot('loop', 'yoyo', $el.checked)" dusk="anim-loop-yoyo" />
                Back and forth (off: start again from the beginning)
            </label>
        @else
            <label class="block text-xs text-gray-500 dark:text-gray-400" x-show="slotUses('{{ $slot }}', 'direction')">
                {{ $slot === 'in' ? 'Comes in moving' : 'Leaves moving' }}
                <select class="form-select mt-1 w-full text-sm" x-bind:value="slotOf('{{ $slot }}')?.direction ?? 'up'"
                        @change="setSlot('{{ $slot }}', 'direction', $el.value)" dusk="anim-{{ $slot }}-direction">
                    @foreach ($directions as $direction)
                        <option value="{{ $direction }}">{{ ucfirst($direction) }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block text-xs text-gray-500 dark:text-gray-400" x-show="slotUses('{{ $slot }}', 'distance')">Distance (px)
                <input type="number" step="1" min="0" max="2000" class="form-input mt-1 w-full text-sm"
                       x-bind:value="slotOf('{{ $slot }}')?.distance ?? 80"
                       @change="$el.value = setSlot('{{ $slot }}', 'distance', $el.value)" dusk="anim-{{ $slot }}-distance" />
            </label>

            <label class="block text-xs text-gray-500 dark:text-gray-400" x-show="slotUses('{{ $slot }}', 'scale')">
                {{ $slot === 'in' ? 'Starts at size (×)' : 'Ends at size (×)' }}
                <input type="number" step="0.05" min="0" max="5" class="form-input mt-1 w-full text-sm"
                       x-bind:value="slotOf('{{ $slot }}')?.scale ?? 0.6"
                       @change="$el.value = setSlot('{{ $slot }}', 'scale', $el.value)" dusk="anim-{{ $slot }}-scale" />
            </label>

            <label class="block text-xs text-gray-500 dark:text-gray-400" x-show="slotUses('{{ $slot }}', 'degrees')">Turn (°)
                <input type="number" step="1" min="-720" max="720" class="form-input mt-1 w-full text-sm"
                       x-bind:value="slotOf('{{ $slot }}')?.degrees ?? -90"
                       @change="$el.value = setSlot('{{ $slot }}', 'degrees', $el.value)" dusk="anim-{{ $slot }}-degrees" />
            </label>

            <label class="block text-xs text-gray-500 dark:text-gray-400" x-show="slotUses('{{ $slot }}', 'blur')">Blur (px)
                <input type="number" step="1" min="0" max="100" class="form-input mt-1 w-full text-sm"
                       x-bind:value="slotOf('{{ $slot }}')?.blur ?? 20"
                       @change="$el.value = setSlot('{{ $slot }}', 'blur', $el.value)" dusk="anim-{{ $slot }}-blur" />
            </label>

            <div class="grid grid-cols-2 gap-2">
                @if ($slot === 'out')
                    <label class="text-xs text-gray-500 dark:text-gray-400">Starts at (s)
                        <input type="number" step="0.1" min="0" max="3600" class="form-input mt-1 w-full text-sm"
                               x-bind:value="slotOf('out')?.at ?? 5"
                               @change="$el.value = setSlot('out', 'at', $el.value)" dusk="anim-out-at" />
                    </label>
                @else
                    <label class="text-xs text-gray-500 dark:text-gray-400">Wait first (s)
                        <input type="number" step="0.1" min="0" max="600" class="form-input mt-1 w-full text-sm"
                               x-bind:value="slotOf('in')?.delay ?? 0"
                               @change="$el.value = setSlot('in', 'delay', $el.value)" dusk="anim-in-delay" />
                    </label>
                @endif
                <label class="text-xs text-gray-500 dark:text-gray-400">Takes (s)
                    <input type="number" step="0.05" min="0.05" max="60" class="form-input mt-1 w-full text-sm"
                           x-bind:value="slotOf('{{ $slot }}')?.duration ?? 0.8"
                           @change="$el.value = setSlot('{{ $slot }}', 'duration', $el.value)" dusk="anim-{{ $slot }}-duration" />
                </label>
            </div>
        @endif

        {{-- The ease: a name from the runtime's list, or a curve drawn by hand. A spin turns at an even
             pace, so it has none. --}}
        <div x-show="!('{{ $slot }}' === 'loop' && slotOf('loop')?.effect === 'spin')">
            <label class="block text-xs text-gray-500 dark:text-gray-400">Ease
                <select class="form-select mt-1 w-full text-sm" x-bind:value="easeChoice('{{ $slot }}')"
                        @change="chooseEase('{{ $slot }}', $el.value)" dusk="anim-{{ $slot }}-ease">
                    <option value="none">Linear (even pace)</option>
                    @foreach ($easeGroups as $family => $eases)
                        <optgroup label="{{ $easeFamilies[$family] ?? ucfirst($family) }}">
                            @foreach ($eases as $ease)
                                <option value="{{ $ease }}">{{ $ease }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                    <option value="custom">Custom curve…</option>
                </select>
            </label>

            <button type="button" class="mt-1 text-xs text-blue-600 hover:underline dark:text-blue-400"
                    @click="easeOpen['{{ $slot }}'] = !easeOpen['{{ $slot }}']; $nextTick(() => easeOpen['{{ $slot }}'] && tryEase('{{ $slot }}'))"
                    dusk="anim-{{ $slot }}-curve-toggle"
                    x-text="easeOpen['{{ $slot }}'] ? 'Hide the curve' : 'Show the curve'"></button>

            <div x-show="easeOpen['{{ $slot }}']" x-cloak>
                @include('builder.partials.ease-visualizer', ['slot' => $slot])
            </div>
        </div>
    </div>
</div>
