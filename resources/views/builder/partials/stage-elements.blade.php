{{-- The elements inside one node of the tree (§13), painted back to front — the stage's own at depth 0,
     a group's children inside its wrapper one level down, four levels in all (an element may be inside
     three groups). Each is the box (where it sits, against the origin of what holds it), then `.ad-anim`
     (what the animations move — the published page has the same two layers), then what it shows.

     Alpine cannot recurse a template, so this partial includes itself with the next depth's variable name:
     `$node` is this level's element, `$parent` the variable holding the group it is inside (null on the
     stage). The include stops where the rules stop groups. --}}
@php($node = 'n'.$depth)
<template x-for="{{ $node }} in paintChildren({{ $parent ?? 'null' }})" :key="{{ $node }}.id">
    <div class="absolute" x-bind:style="boxStyle({{ $node }}, {{ $parent ?? 'null' }})"
         x-bind:dusk="'element-' + {{ $node }}.id"
         x-bind:data-anim-id="{{ $node }}.id"
         @pointerdown="startDrag($event, {{ $node }})"
         @contextmenu.stop="openContextMenu($event, {{ $node }})"
         @dblclick.stop="onDoubleClick({{ $node }})">

        <div class="ad-anim h-full w-full">
            {{-- Text --}}
            <template x-if="{{ $node }}.type === 'text'">
                <div class="h-full w-full outline-none" dir="auto"
                     x-bind:style="textStyle({{ $node }})"
                     x-bind:contenteditable="editingTextId === {{ $node }}.id"
                     @blur="finishTextEdit($event, {{ $node }})"
                     x-text="{{ $node }}.text"></div>
            </template>

            {{-- Image --}}
            <template x-if="{{ $node }}.type === 'image'">
                <img x-bind:src="assetUrl({{ $node }})" alt="" draggable="false"
                     class="pointer-events-none"
                     x-bind:style="pictureStyle({{ $node }})" />
            </template>

            {{-- Video: a still frame while designing; ▶ Play runs it. --}}
            <template x-if="{{ $node }}.type === 'video'">
                <video x-bind:src="assetUrl({{ $node }})" muted loop playsinline preload="metadata"
                       class="pointer-events-none"
                       x-bind:style="pictureStyle({{ $node }})"></video>
            </template>

            {{-- Shape --}}
            <template x-if="{{ $node }}.type === 'shape'">
                <div x-bind:style="shapeStyle({{ $node }})"></div>
            </template>

            {{-- A group: what it holds, each child placed against this box. Anything but a group has no
                 children, so the loop below simply draws nothing for it. --}}
            @if ($depth < \App\Http\Requests\Builder\BuilderAdRequest::MAX_GROUP_DEPTH)
                @include('builder.partials.stage-elements', ['depth' => $depth + 1, 'parent' => $node])
            @endif
        </div>
    </div>
</template>
