{{-- The Ease Visualizer for one slot ($slot): the curve the element's motion follows — time across,
     progress up — drawn from the runtime's own ease, so the line IS the motion. A custom curve gets two handles
     to drag; ▶ runs a dot along it at the ease's pace.

     No x-if in here: a <template> inside an <svg> is an SVG element, not an HTML template, so Alpine
     cannot stamp it. The handles are shown and hidden instead. --}}
<div class="mt-2 rounded border border-gray-200 bg-gray-50 p-2 dark:border-gray-700 dark:bg-gray-900"
     dusk="anim-{{ $slot }}-curve">
    <svg viewBox="0 0 200 200" class="h-44 w-full touch-none select-none" aria-label="Ease curve">
        {{-- Where the motion starts (bottom left) and where it lands (top right). --}}
        <rect x="20" y="50" width="160" height="100" fill="none" stroke-dasharray="3 3"
              class="stroke-gray-300 dark:stroke-gray-600" />
        <line x1="20" y1="150" x2="180" y2="150" class="stroke-gray-300 dark:stroke-gray-600" />

        <path x-bind:d="easeCurve('{{ $slot }}')" fill="none" stroke-width="2.5" stroke-linecap="round"
              class="stroke-blue-600 dark:stroke-blue-400" dusk="anim-{{ $slot }}-curve-path" />

        <g x-show="easeHandles('{{ $slot }}') !== null">
            <line x1="20" y1="150" stroke-width="1.5" class="stroke-pink-400"
                  x-bind:x2="easeHandles('{{ $slot }}')?.x1 ?? 20" x-bind:y2="easeHandles('{{ $slot }}')?.y1 ?? 150" />
            <line x1="180" y1="50" stroke-width="1.5" class="stroke-pink-400"
                  x-bind:x2="easeHandles('{{ $slot }}')?.x2 ?? 180" x-bind:y2="easeHandles('{{ $slot }}')?.y2 ?? 50" />
            <circle r="8" class="cursor-grab fill-pink-500 stroke-white" stroke-width="2"
                    x-bind:cx="easeHandles('{{ $slot }}')?.x1 ?? 20" x-bind:cy="easeHandles('{{ $slot }}')?.y1 ?? 150"
                    @pointerdown="startEaseHandle($event, '{{ $slot }}', 1)" dusk="anim-{{ $slot }}-handle-1" />
            <circle r="8" class="cursor-grab fill-pink-500 stroke-white" stroke-width="2"
                    x-bind:cx="easeHandles('{{ $slot }}')?.x2 ?? 180" x-bind:cy="easeHandles('{{ $slot }}')?.y2 ?? 50"
                    @pointerdown="startEaseHandle($event, '{{ $slot }}', 2)" dusk="anim-{{ $slot }}-handle-2" />
        </g>

        <circle r="5" cx="20" cy="150" class="fill-orange-500" data-ease-dot="{{ $slot }}" />
    </svg>

    <div class="mt-1 flex items-center gap-2">
        <div class="relative h-3 flex-1 rounded-full bg-gray-200 dark:bg-gray-700">
            <span class="absolute top-0 h-3 w-3 rounded-full bg-orange-500" style="left: 0" data-ease-ball="{{ $slot }}"></span>
        </div>
        <button type="button" class="btn-pager text-xs" @click="tryEase('{{ $slot }}')" title="Try this ease"
                dusk="anim-{{ $slot }}-try">▶</button>
    </div>

    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" x-show="easeHandles('{{ $slot }}') !== null"
       x-text="'Drag the pink handles — ' + (slotOf('{{ $slot }}')?.ease ?? '')"></p>
</div>
