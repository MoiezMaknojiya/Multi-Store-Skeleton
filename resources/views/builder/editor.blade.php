@php
    // The platform team stands in no store, so a new ad asks which shop it is for (the controller hands
    // them the shops). A store's own people never see this: they are already working somewhere.
    $platformUser = auth()->user()->globalRole() !== null;
    $stores = $ad ? [] : ($stores ?? []);

    // The panel's lists are the server's own (AdCompiler, AdAnimations): every option is a value the
    // compiler writes and the request accepts — never a copy that could drift from them. Only the words
    // are the panel's: $choices offers every value of a list, in the order its words are given here, and
    // a value the panel has no words for yet still appears, under its own name.
    $choices = fn (array $values, array $words) => collect($words)->only($values)
        ->union(collect($values)->mapWithKeys(fn (string $value) => [$value => ucfirst($value)]))
        ->all();
    $blends = \App\Services\AdCompiler::BLENDS;
    $positions = \App\Services\AdCompiler::POSITIONS;
    $alignments = $choices(\App\Services\AdCompiler::ALIGNMENTS, [
        'left' => 'Align left', 'center' => 'Centre', 'right' => 'Align right', 'justify' => 'Justify',
    ]);
    $alignGlyphs = ['left' => '⯇', 'center' => '≡', 'right' => '⯈', 'justify' => '☰'];
    $verticals = $choices(\App\Services\AdCompiler::VERTICAL, [
        'flex-start' => 'Top', 'center' => 'Middle', 'flex-end' => 'Bottom',
    ]);
    $transforms = $choices(\App\Services\AdCompiler::TRANSFORMS, [
        'none' => 'As typed', 'uppercase' => 'UPPERCASE', 'lowercase' => 'lowercase', 'capitalize' => 'Capitalise',
    ]);
    $decorations = $choices(\App\Services\AdCompiler::DECORATIONS, [
        'none' => 'None', 'underline' => 'Underline', 'line-through' => 'Strike', 'overline' => 'Overline',
    ]);
    $fontStyles = $choices(\App\Services\AdCompiler::FONT_STYLES, ['normal' => 'Upright', 'italic' => 'Italic']);
    $fits = $choices(\App\Services\AdCompiler::FITS, [
        'cover' => 'Fill the box (cover)', 'contain' => 'Fit inside (contain)', 'fill' => 'Stretch',
        'none' => 'Actual size', 'scale-down' => 'Shrink only',
    ]);
    $sizes = $choices(\App\Services\AdCompiler::SIZES, [
        'cover' => 'Cover', 'contain' => 'Contain', 'auto' => 'Actual size', 'custom' => 'Scale…',
    ]);
    $repeats = $choices(\App\Services\AdCompiler::REPEATS, [
        'no-repeat' => 'Once', 'repeat' => 'Tile', 'repeat-x' => 'Across', 'repeat-y' => 'Down',
    ]);
    $videoFits = $choices(\App\Services\AdCompiler::VIDEO_FITS, [
        'cover' => 'Fill the stage (cover)', 'contain' => 'Fit inside (contain)',
    ]);
    $borderStyles = $choices(\App\Services\AdCompiler::BORDER_STYLES, []);
    $gradientKinds = $choices(\App\Services\AdCompiler::GRADIENTS, []);
    $shapes = $choices(\App\Services\AdCompiler::SHAPES, ['rect' => '▭ Rectangle', 'ellipse' => '◯ Ellipse']);
    $axes = $choices(\App\Services\AdAnimations::AXES, ['y' => 'Up and down', 'x' => 'Side to side']);
    $filterLabels = [
        'blur' => 'Blur', 'brightness' => 'Brightness', 'contrast' => 'Contrast', 'saturate' => 'Saturation',
        'grayscale' => 'Greyscale', 'sepia' => 'Sepia', 'hueRotate' => 'Hue', 'invert' => 'Invert',
    ];
    $filters = collect(\App\Services\AdCompiler::FILTERS)->map(fn (array $filter, string $key) => [
        'key' => $key,
        'label' => $filterLabels[$key] ?? $key,
        'unit' => $filter[1],
        'neutral' => $filter[2],
        'min' => \App\Services\AdCompiler::LIMITS["filters.{$key}"][0],
        'max' => \App\Services\AdCompiler::LIMITS["filters.{$key}"][1],
    ])->values();
    $entrances = \App\Services\AdAnimations::ENTRANCES;
    $loops = \App\Services\AdAnimations::LOOPS;
    $directions = \App\Services\AdAnimations::DIRECTIONS;
    $easeGroups = collect(\App\Services\AdAnimations::EASES)
        ->reject(fn (string $ease) => $ease === 'none')
        ->groupBy(fn (string $ease) => \Illuminate\Support\Str::before($ease, '.'));
    $easeFamilies = [
        'power1' => 'Power 1 — gentle', 'power2' => 'Power 2', 'power3' => 'Power 3', 'power4' => 'Power 4 — strong',
        'sine' => 'Sine — smooth', 'expo' => 'Expo', 'circ' => 'Circ', 'back' => 'Back — overshoots',
        'elastic' => 'Elastic', 'bounce' => 'Bounce',
    ];
    $effectLabels = [
        'fade' => 'Fade', 'slide' => 'Slide', 'zoom' => 'Zoom', 'rotate' => 'Rotate', 'blur' => 'Blur',
        'flip' => 'Flip', 'wipe' => 'Wipe (reveal)', 'bounce' => 'Bounce',
        'float' => 'Float', 'pulse' => 'Pulse', 'sway' => 'Sway', 'drift' => 'Drift', 'spin' => 'Spin',
        'blink' => 'Blink', 'shake' => 'Shake', 'kenburns' => 'Ken Burns (slow zoom)',
    ];
@endphp

<x-builder-layout>
    {{-- Js::from, not @json: @json leaves its quotes raw and the first string would close this
         attribute (see .claude/rules/02-project-conventions.md). --}}
    <div class="flex min-h-0 flex-1 flex-col"
         x-data="adEditor({{ Js::from([
             'adId' => $ad?->id,
             'name' => $ad?->name ?? 'Untitled ad',
             'document' => $document,
             'assets' => $assets,
             'storeId' => $platformUser ? ($ad?->store_id) : null,
             'choosesShop' => $platformUser && ! $ad,
             // Where the ad stands with the screens (docs/AD-BUILDER-SPEC.md §9): on them or not, whether they
             // show its latest changes, and whether there is a published version to go back to.
             'published' => (bool) $ad?->isPublished(),
             'hasChanges' => (bool) $ad?->hasUnpublishedChanges(),
             'hasPublishedVersion' => (bool) $ad?->hasPublishedVersion(),
             'hasPoster' => (bool) $ad?->thumbnail_path,
             // What the routes let this person do: change (and publish) a saved ad, and fetch a font.
             'canUpdate' => (bool) auth()->user()?->can('ad-update'),
             'canInstallFonts' => (bool) auth()->user()?->canAny(['ad-store', 'ad-update']),
             'limits' => \App\Services\AdCompiler::LIMITS,
             'filters' => \App\Services\AdCompiler::FILTERS,
             'animationNumbers' => \App\Services\AdAnimations::NUMBERS,
             'maxLayers' => \App\Http\Requests\Builder\BuilderAdRequest::MAX_LAYERS,
             'maxStops' => \App\Http\Requests\Builder\BuilderAdRequest::MAX_STOPS,
             'maxElements' => \App\Http\Requests\Builder\BuilderAdRequest::MAX_ELEMENTS,
             'maxGuides' => \App\Http\Requests\Builder\BuilderAdRequest::MAX_GUIDES,
         ]) }})"
         @keydown.window="onKeydown($event)"
         @keyup.window="onKeyup($event)">

        {{-- ── Top bar ───────────────────────────────────────────────── --}}
        <header class="flex h-14 shrink-0 items-center justify-between gap-3 border-b border-gray-200 bg-white px-4 dark:border-gray-700 dark:bg-gray-800">
            <div class="flex min-w-0 items-center gap-3">
                {{-- The editor opens with Create Ads or Update Ads, the gallery with View Ads — so the way
                     out goes to the gallery only for somebody who may see it. --}}
                @can('ad-view')
                    <a href="{{ route('builder.index') }}" class="btn-row-neutral shrink-0" dusk="builder-exit">← Ads</a>
                @else
                    <a href="{{ route('dashboard') }}" class="btn-row-neutral shrink-0" dusk="builder-exit">← Back</a>
                @endcan

                {{-- The name is not part of the design, so renaming is not a step undo could take back: it
                     only marks the ad unsaved. Narrower on a laptop, where the bar has to hold everything. --}}
                <input type="text" x-model="name" @change="markChanged()" maxlength="120"
                       class="form-input h-9 w-36 text-sm font-medium xl:w-52" dusk="ad-name" aria-label="Ad name" />

                @if ($platformUser && ! $ad)
                    <select x-model.number="storeId" class="form-select h-9 w-44 text-sm" dusk="ad-store">
                        <option value="">Choose a shop…</option>
                        @foreach ($stores as $store)
                            <option value="{{ $store['id'] }}">{{ $store['name'] }}</option>
                        @endforeach
                    </select>
                @endif

                {{-- Shrinks before anything else does: on a narrow bar each line is cut short, never drawn over
                     the buttons, and the whole sentence is its tooltip. --}}
                <div class="flex min-w-0 flex-col leading-tight">
                    <span class="truncate text-xs" x-bind:class="dirty ? 'text-amber-600 dark:text-amber-400' : 'text-gray-400'"
                          x-text="saveStatus()" dusk="save-status"></span>
                    {{-- Where the ad stands with the screens — the industry's draft/publish model (docs/AD-BUILDER-SPEC.md
                         §9): changes are saved to a draft, and the screens keep the published version until they are
                         published. --}}
                    <span class="truncate text-[11px]" x-show="publicationStatus() !== ''" x-cloak dusk="publication-status"
                          x-bind:class="publicationTone()"
                          x-bind:title="publicationHint()" x-text="publicationStatus()"></span>
                    <label class="flex items-center gap-1 truncate text-[11px] text-gray-400" title="Save a saved ad every 30 seconds">
                        <input type="checkbox" class="h-3 w-3 rounded border-gray-300 dark:border-gray-600"
                               x-bind:checked="autosave" @change="toggleAutosave()" dusk="autosave-toggle" />
                        Autosave
                    </label>
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-1.5">
                <button type="button" class="btn-pager" @click="undo()" x-bind:disabled="!history?.canUndo()"
                        title="Undo (Ctrl+Z)" dusk="ad-undo">↶</button>
                <button type="button" class="btn-pager" @click="redo()" x-bind:disabled="!history?.canRedo()"
                        title="Redo (Ctrl+Y)" dusk="ad-redo">↷</button>

                {{-- Every step, with its name, newest first; a click goes back (or forward) to it. --}}
                <div class="relative" @click.outside="historyOpen = false">
                    <button type="button" class="btn-pager" @click="historyOpen = !historyOpen" title="History"
                            dusk="history-toggle">☰</button>
                    <div x-show="historyOpen" x-cloak dusk="history-panel"
                         class="absolute right-0 top-full z-50 mt-1 max-h-80 w-64 overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 shadow-xl dark:border-gray-700 dark:bg-gray-800">
                        <p class="px-3 pb-1 pt-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400">History</p>
                        <template x-for="step in historySteps()" :key="step.index">
                            <button type="button" class="flex w-full items-center justify-between px-3 py-1.5 text-left text-xs"
                                    x-bind:class="step.current
                                        ? 'bg-blue-50 font-semibold text-blue-700 dark:bg-blue-900/40 dark:text-blue-200'
                                        : (step.undone ? 'text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700/60' : 'text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60')"
                                    @click="jumpToStep(step.index)" x-bind:dusk="'history-step-' + step.index">
                                <span x-text="step.label"></span>
                                <span x-show="step.current">●</span>
                            </button>
                        </template>
                    </div>
                </div>

                <div class="mx-1 flex items-center gap-1">
                    <button type="button" class="btn-pager" @click="zoomBy(-0.1)" title="Zoom out (Ctrl+−)" dusk="zoom-out">−</button>
                    <button type="button" class="w-12 text-xs text-gray-500 dark:text-gray-400" @click="zoomToFit()"
                            title="Fit to window (Ctrl+0)" dusk="zoom-fit"><span x-text="zoomPercent + '%'"></span></button>
                    <button type="button" class="btn-pager" @click="zoomBy(0.1)" title="Zoom in (Ctrl+=)" dusk="zoom-in">+</button>
                </div>

                {{-- On a wide screen the rulers and the shortcuts have buttons of their own; on a laptop they
                     live in "More" (⋯), so the bar keeps every control without drawing one over another. The
                     keys work either way: Shift+R and ?. --}}
                <button type="button" class="btn-pager hidden xl:inline-block" @click="toggleRulers()" title="Rulers and guides (Shift+R)"
                        x-bind:class="showRulers ? '!border-blue-500 !text-blue-600' : ''" dusk="rulers-toggle">📏</button>
                <button type="button" class="btn-pager hidden xl:inline-block" @click="shortcutsOpen = true" title="Keyboard shortcuts (?)"
                        dusk="shortcuts-open">⌨</button>
                <div class="relative xl:hidden" @click.outside="moreOpen = false">
                    <button type="button" class="btn-pager" @click="moreOpen = !moreOpen" title="More tools" aria-label="More tools"
                            x-bind:aria-expanded="moreOpen" dusk="toolbar-more">⋯</button>
                    <div x-show="moreOpen" x-cloak dusk="toolbar-more-menu"
                         class="absolute right-0 top-full z-50 mt-1 w-60 rounded-lg border border-gray-200 bg-white py-1 shadow-xl dark:border-gray-700 dark:bg-gray-800">
                        <button type="button" class="flex w-full items-center justify-between px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60"
                                @click="toggleRulers(); moreOpen = false" dusk="toolbar-more-rulers">
                            <span>📏 Rulers and guides</span>
                            <span class="text-xs text-gray-400" x-text="showRulers ? 'On · Shift+R' : 'Off · Shift+R'"></span>
                        </button>
                        <button type="button" class="flex w-full items-center justify-between px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60"
                                @click="shortcutsOpen = true; moreOpen = false" dusk="toolbar-more-shortcuts">
                            <span>⌨ Keyboard shortcuts</span>
                            <span class="text-xs text-gray-400">?</span>
                        </button>
                    </div>
                </div>

                {{-- Play runs the television's own animation runtime over the whole stage. The word goes on a
                     laptop; the symbol, the tooltip and the name for a screen reader stay. --}}
                <button type="button" class="btn-secondary" @click="togglePlay()" dusk="ad-play"
                        title="Play the animations the way a screen will (Ctrl+P, Esc stops)"
                        x-bind:aria-label="previewing === 'all' ? 'Stop' : 'Play'">
                    <span aria-hidden="true" x-text="previewing === 'all' ? '■' : '▶'"></span>
                    <span class="hidden xl:inline" x-text="previewing === 'all' ? 'Stop' : 'Play'"></span>
                </button>

                {{-- The preview route answers View Ads or Update Ads (BuilderController::preview). --}}
                @canany(['ad-view', 'ad-update'])
                    <button type="button" class="btn-secondary" @click="openPreview()" x-bind:disabled="saving" dusk="ad-preview"
                            title="The saved ad full screen, in a new tab, exactly as a television shows it" aria-label="Preview">
                        <span class="hidden xl:inline">Preview</span>
                        <span aria-hidden="true">↗</span>
                    </button>
                @endcanany

                <button type="button" class="btn-secondary" @click="save()" x-bind:disabled="saving" dusk="ad-save">
                    <span x-text="saving ? 'Saving…' : 'Save'"></span>
                </button>

                {{-- Publish is what puts the ad on a television: it compiles the design into a page and drops it
                     in the media library, where a playlist can pick it up. Beside it, the rest of the draft/publish
                     model (docs/AD-BUILDER-SPEC.md §9): Discard changes and Unpublish. All three change a saved ad,
                     so they are Update Ads — the lock on their routes. --}}
                @can('ad-update')
                    <div class="relative flex" @click.outside="publishMenuOpen = false">
                        <button type="button" class="btn-primary !rounded-r-none" @click="publish()"
                                x-bind:disabled="publishing || saving || unpublishing || discarding" dusk="ad-publish"
                                x-bind:title="publishHint()">
                            <span x-text="publishLabel()"></span>
                        </button>
                        <button type="button" class="btn-primary !rounded-l-none border-l border-blue-400 !px-2"
                                @click="publishMenuOpen = !publishMenuOpen" x-bind:disabled="!adId || publishing || saving"
                                aria-label="More publishing options" x-bind:aria-expanded="publishMenuOpen" dusk="ad-publish-menu">▾</button>
                        <div x-show="publishMenuOpen" x-cloak dusk="publish-menu"
                             class="absolute right-0 top-full z-50 mt-1 w-72 rounded-lg border border-gray-200 bg-white py-1 shadow-xl dark:border-gray-700 dark:bg-gray-800">
                            <button type="button" class="block w-full px-3 py-2 text-left hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40 dark:hover:bg-gray-700/60"
                                    @click="publishMenuOpen = false; $dispatch('open-modal', 'confirm-discard-changes')"
                                    x-bind:disabled="!mayDiscard()" x-bind:title="discardHint()" dusk="ad-discard">
                                <span class="block text-sm text-gray-800 dark:text-gray-100">Discard changes</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">Go back to the version on the screens</span>
                            </button>
                            <button type="button" class="block w-full px-3 py-2 text-left hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40 dark:hover:bg-gray-700/60"
                                    @click="publishMenuOpen = false; $dispatch('open-modal', 'confirm-unpublish')"
                                    x-bind:disabled="!published" dusk="ad-unpublish">
                                <span class="block text-sm text-red-600 dark:text-red-400">Unpublish</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">Take it off every screen until it is published again</span>
                            </button>
                        </div>
                    </div>
                @endcan
            </div>
        </header>

        <div class="flex min-h-0 flex-1">
            {{-- ── Left: add & layers ────────────────────────────────── --}}
            <aside class="flex w-64 shrink-0 flex-col border-r border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <div class="border-b border-gray-200 p-4 dark:border-gray-700">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400">Add</h3>

                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <button type="button" class="btn-secondary text-sm" @click="addText()" dusk="add-text">Text</button>
                        <button type="button" class="btn-secondary text-sm" @click="openAssetPicker('element')" dusk="add-image">Image</button>
                        <button type="button" class="btn-secondary col-span-2 text-sm" @click="addShape()" dusk="add-shape">Shape</button>
                    </div>
                </div>

                <div class="flex min-h-0 flex-1 flex-col">
                    <h3 class="px-4 pt-4 text-xs font-semibold uppercase tracking-wide text-gray-400">Layers</h3>

                    <div class="min-h-0 flex-1 overflow-y-auto p-2" dusk="layers-panel">
                        <p x-show="layers.length === 0" x-cloak class="px-2 py-6 text-center text-xs text-gray-400">
                            Nothing on the stage yet.
                        </p>

                        {{-- Front first. Drag a row to reorder; double-click a name to rename it. --}}
                        <template x-for="layer in layers" :key="layer.id">
                            <div class="group flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm"
                                 draggable="true"
                                 x-bind:class="[
                                     isSelected(layer)
                                         ? 'bg-blue-50 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200'
                                         : 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700/50',
                                     layerDropClass(layer),
                                 ]"
                                 x-bind:dusk="'layer-' + layer.id"
                                 @click="select(layer, $event)"
                                 @contextmenu="openContextMenu($event, layer)"
                                 @dragstart="layerDragStart($event, layer)"
                                 @dragover.prevent="layerDragOver($event, layer)"
                                 @drop.prevent="layerDrop($event, layer)"
                                 @dragend="layerDragEnd()">

                                <button type="button" class="text-gray-400 hover:text-gray-600"
                                        @click.stop="toggleVisible(layer)"
                                        x-bind:title="layer.visible === false ? 'Show' : 'Hide'"
                                        x-bind:dusk="'layer-visible-' + layer.id"
                                        x-text="layer.visible === false ? '◌' : '●'"></button>

                                <template x-if="renamingId !== layer.id">
                                    <span class="min-w-0 flex-1 truncate" x-text="layer.name ?? layer.type"
                                          @dblclick.stop="startRename(layer)" x-bind:dusk="'layer-name-' + layer.id"></span>
                                </template>
                                <template x-if="renamingId === layer.id">
                                    <input type="text" class="form-input h-6 min-w-0 flex-1 px-1 text-sm" maxlength="120"
                                           x-bind:value="layer.name ?? layer.type"
                                           x-init="$nextTick(() => { $el.focus(); $el.select(); })"
                                           @click.stop @dblclick.stop
                                           @keydown.enter.prevent="finishRename(layer, $el.value)"
                                           @keydown.escape.prevent="renamingId = null"
                                           @blur="finishRename(layer, $el.value)"
                                           x-bind:dusk="'layer-rename-' + layer.id" />
                                </template>

                                <span class="text-xs text-purple-500" x-show="animates(layer)" title="Animated">✦</span>

                                <button type="button" class="text-gray-400 transition hover:text-gray-600"
                                        x-bind:class="layer.locked ? '' : 'opacity-0 group-hover:opacity-100'"
                                        @click.stop="toggleLock(layer)"
                                        x-bind:title="layer.locked ? 'Unlock' : 'Lock'"
                                        x-bind:dusk="'layer-lock-' + layer.id"
                                        x-text="layer.locked ? '🔒' : '🔓'"></button>
                            </div>
                        </template>

                        {{-- The stage's own background, always last — it is behind everything. --}}
                        <div class="mt-1 flex cursor-pointer items-center gap-2 rounded border-t border-gray-100 px-2 py-1.5 text-sm dark:border-gray-700"
                             x-bind:class="selectedIds.length === 0
                                 ? 'bg-blue-50 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200'
                                 : 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700/50'"
                             @click="clearSelection()" dusk="layer-background">
                            <span class="h-3 w-3 shrink-0 rounded-sm border border-gray-300 dark:border-gray-600"
                                  x-bind:style="{ backgroundColor: stageColour() }"></span>
                            <span class="min-w-0 flex-1 truncate">Background</span>
                            <span class="text-xs text-gray-400" x-show="doc.stage.background.layers.length > 0"
                                  x-text="doc.stage.background.layers.length"></span>
                        </div>
                    </div>
                </div>
            </aside>

            {{-- ── Middle: the stage ─────────────────────────────────── --}}
            <main class="relative flex min-w-0 flex-1 items-center justify-center overflow-hidden bg-gray-200 dark:bg-gray-950"
                  x-ref="viewport"
                  x-bind:class="spaceHeld ? 'cursor-grab' : ''"
                  @pointerdown.self="startMarquee($event)"
                  @contextmenu.self="openContextMenu($event, null)"
                  @wheel.prevent="onWheel($event)">

                {{-- The frame: the stage and everything drawn over it, zoomed and panned as one piece.
                     shrink-0 matters: `transform: scale()` does not change an element's LAYOUT size, so a
                     flex child 1920 px wide inside a narrower column is squeezed first and scaled after. --}}
                <div class="relative shrink-0" x-ref="frame" x-bind:style="canvasStyle()" style="transform-origin: center center;">

                    {{-- The stage: what a television shows and nothing else — clipped at its edges like a
                         screen, and the only thing a poster photographs. --}}
                    <div class="absolute left-0 top-0 shadow-2xl"
                         x-ref="stage"
                         dusk="ad-stage"
                         x-bind:style="{ width: stage.width + 'px', height: stage.height + 'px' }"
                         style="background: #000; overflow: hidden;"
                         @pointerdown.self="startMarquee($event)"
                         @contextmenu.self="openContextMenu($event, null)">

                        {{-- The background: the stage's colour, then its layers, first one furthest back —
                             the same stack AdCompiler::background() writes. --}}
                        <div class="pointer-events-none absolute inset-0 overflow-hidden" style="z-index: 0"
                             x-bind:style="{ backgroundColor: stageColour() }" dusk="stage-background">
                            <template x-for="layer in doc.stage.background.layers" :key="layer.id">
                                <div class="absolute inset-0"
                                     x-show="layer.visible !== false && layerStyleFor(layer) !== null"
                                     x-bind:style="layerStyleFor(layer) ?? {}"
                                     x-bind:dusk="'bg-render-' + layer.id">
                                    <template x-if="layer.type === 'video' && layerAsset(layer)">
                                        <video class="h-full w-full" x-bind:src="layerAsset(layer).url"
                                               muted loop playsinline preload="metadata"
                                               x-bind:style="{ objectFit: layer.fit === 'contain' ? 'contain' : 'cover' }"></video>
                                    </template>
                                </div>
                            </template>
                        </div>

                        {{-- Elements, painted back to front. Each is the box (where it sits), then `.ad-anim`
                             (what the animations move — the published page has the same two layers), then
                             what it shows. --}}
                        <template x-for="element in elementsByDepth" :key="element.id">
                            <div class="absolute" x-bind:style="boxStyle(element)"
                                 x-bind:dusk="'element-' + element.id"
                                 x-bind:data-anim-id="element.id"
                                 @pointerdown="startDrag($event, element)"
                                 @contextmenu.stop="openContextMenu($event, element)"
                                 @dblclick="startTextEdit(element)">

                                <div class="ad-anim h-full w-full">
                                    {{-- Text --}}
                                    <template x-if="element.type === 'text'">
                                        <div class="h-full w-full outline-none" dir="auto"
                                             x-bind:style="textStyle(element)"
                                             x-bind:contenteditable="editingTextId === element.id"
                                             @blur="finishTextEdit($event, element)"
                                             x-text="element.text"></div>
                                    </template>

                                    {{-- Image --}}
                                    <template x-if="element.type === 'image'">
                                        <img x-bind:src="assetUrl(element)" alt="" draggable="false"
                                             class="pointer-events-none"
                                             x-bind:style="pictureStyle(element)" />
                                    </template>

                                    {{-- Video: a still frame while designing; ▶ Play runs it. --}}
                                    <template x-if="element.type === 'video'">
                                        <video x-bind:src="assetUrl(element)" muted loop playsinline preload="metadata"
                                               class="pointer-events-none"
                                               x-bind:style="pictureStyle(element)"></video>
                                    </template>

                                    {{-- Shape --}}
                                    <template x-if="element.type === 'shape'">
                                        <div x-bind:style="shapeStyle(element)"></div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>

                    {{-- Over the stage — never clipped by it, never in a poster or on a television: the rulers,
                         the guides, the selection, the snap lines, the marquee and the empty-stage hint. --}}
                    <div class="pointer-events-none absolute inset-0" style="z-index: 9000">

                        {{-- Rulers, and the guides dragged out of them. --}}
                        <template x-if="showRulers">
                            <div>
                                <div class="pointer-events-auto absolute cursor-row-resize select-none overflow-hidden bg-white/95 text-gray-500 dark:bg-gray-800/95 dark:text-gray-400"
                                     x-bind:style="rulerStyle('x')" @pointerdown="startGuideFromRuler($event, 'y')"
                                     title="Drag down to add a guide across" dusk="ruler-top">
                                    <template x-for="tick in rulerTicks('x')" :key="'tx' + tick">
                                        <span class="absolute" x-bind:style="rulerLabelStyle('x', tick)" x-text="tick"></span>
                                    </template>
                                </div>
                                <div class="pointer-events-auto absolute cursor-col-resize select-none overflow-hidden bg-white/95 text-gray-500 dark:bg-gray-800/95 dark:text-gray-400"
                                     x-bind:style="rulerStyle('y')" @pointerdown="startGuideFromRuler($event, 'x')"
                                     title="Drag right to add a guide down" dusk="ruler-left">
                                    <template x-for="tick in rulerTicks('y')" :key="'ty' + tick">
                                        <span class="absolute" x-bind:style="rulerLabelStyle('y', tick)" x-text="tick"></span>
                                    </template>
                                </div>

                                <template x-for="(value, index) in doc.guides.x" :key="'gx' + index">
                                    <div class="pointer-events-auto absolute cursor-col-resize" x-bind:style="guideStyle('x', value)"
                                         @pointerdown.stop="startGuideDrag($event, 'x', index)" x-bind:dusk="'guide-x-' + index"
                                         title="Drag to move; drag onto the ruler to remove">
                                        <div class="bg-cyan-500" x-bind:style="guideLineStyle('x')"></div>
                                    </div>
                                </template>
                                <template x-for="(value, index) in doc.guides.y" :key="'gy' + index">
                                    <div class="pointer-events-auto absolute cursor-row-resize" x-bind:style="guideStyle('y', value)"
                                         @pointerdown.stop="startGuideDrag($event, 'y', index)" x-bind:dusk="'guide-y-' + index"
                                         title="Drag to move; drag onto the ruler to remove">
                                        <div class="bg-cyan-500" x-bind:style="guideLineStyle('y')"></div>
                                    </div>
                                </template>

                                {{-- The guide being dragged, with where it would land. --}}
                                <template x-if="draggingGuide">
                                    <div class="absolute bg-cyan-400/80"
                                         x-bind:style="draggingGuide.axis === 'x'
                                             ? { left: draggingGuide.value + 'px', top: '0', width: (1 / zoom) + 'px', height: stage.height + 'px' }
                                             : { top: draggingGuide.value + 'px', left: '0', height: (1 / zoom) + 'px', width: stage.width + 'px' }"
                                         dusk="guide-dragging"></div>
                                </template>
                            </div>
                        </template>

                        {{-- The selection: a frame per element; the handles when there is exactly one; a dashed
                             box around several. Hidden while the whole ad plays. --}}
                        <template x-if="previewing !== 'all'">
                            <div>
                                <template x-for="element in selection()" :key="'frame-' + element.id">
                                    <div class="absolute" x-bind:style="frameStyle(element)"
                                         x-bind:dusk="selectedIds.length === 1 ? 'selection-frame' : 'selection-frame-' + element.id">
                                        <template x-if="selectedIds.length === 1">
                                            <div>
                                                <template x-for="handle in ['nw','n','ne','e','se','s','sw','w']" :key="handle">
                                                    <div class="pointer-events-auto absolute rounded-sm border-white bg-blue-500"
                                                         style="border-style: solid"
                                                         x-bind:style="handleStyle(element, handle)"
                                                         x-bind:dusk="'handle-' + handle"
                                                         @pointerdown="startResize($event, element, handle)"></div>
                                                </template>

                                                {{-- Rotate, above the top edge. --}}
                                                <div class="pointer-events-auto absolute cursor-grab rounded-full border-white bg-blue-500"
                                                     style="border-style: solid"
                                                     x-bind:style="rotateHandleStyle(element)"
                                                     dusk="handle-rotate"
                                                     @pointerdown="startRotate($event, element)"></div>
                                            </div>
                                        </template>
                                    </div>
                                </template>

                                <div class="absolute" x-bind:style="groupFrameStyle()" dusk="selection-group"></div>
                            </div>
                        </template>

                        {{-- Snap lines while something is dragged. --}}
                        <template x-if="snapLines.x !== null">
                            <div class="absolute bg-pink-500" x-bind:style="snapLineStyle('x')"></div>
                        </template>
                        <template x-if="snapLines.y !== null">
                            <div class="absolute bg-pink-500" x-bind:style="snapLineStyle('y')"></div>
                        </template>

                        {{-- The marquee. --}}
                        <div class="absolute bg-blue-500/10" x-bind:style="marqueeStyle()" dusk="marquee"></div>

                        {{-- An empty stage says where to start. Drawn here, so no poster or page carries it. --}}
                        <div x-show="doc.elements.length === 0 && doc.stage.background.layers.length === 0 && !previewing" x-cloak
                             class="absolute inset-0 flex items-center justify-center" dusk="stage-empty-hint">
                            <p class="rounded-lg bg-black/50 text-center text-white/90"
                               x-bind:style="{ fontSize: screenText(14), padding: screenText(14) + ' ' + screenText(20), maxWidth: screenText(460) }">
                                Start with <strong>Text</strong>, an <strong>Image</strong> or a <strong>Shape</strong> on the
                                left — or build the background on the right.
                            </p>
                        </div>

                        {{-- While the whole ad plays, the stage is a screen, not a canvas: a click stops it. --}}
                        <div x-show="previewing === 'all'" x-cloak class="pointer-events-auto absolute inset-0 cursor-pointer"
                             @pointerdown.stop="stopPreview()" dusk="preview-overlay"></div>
                    </div>
                </div>

                {{-- The frame's size, stated once, so nobody wonders what they are designing for. --}}
                <p class="pointer-events-none absolute bottom-3 left-1/2 -translate-x-1/2 text-xs text-gray-500 dark:text-gray-400"
                   x-text="previewing === 'all'
                       ? 'Playing — the way a screen shows it. Click the stage or press Esc to stop.'
                       : '1920 × 1080 — a television screen · ? for shortcuts'"></p>
            </main>

            {{-- ── Right: the selected element(s), or the stage ──────── --}}
            <aside class="flex w-72 shrink-0 flex-col overflow-y-auto border-l border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800"
                   dusk="properties-panel">

                <template x-if="selectedIds.length === 0">
                    @include('builder.partials.stage-panel')
                </template>

                <template x-if="selectedIds.length > 1">
                    @include('builder.partials.multi-panel')
                </template>

                <template x-if="selected">
                    <div class="space-y-4 p-4">
                        <div class="flex items-center justify-between">
                            <input type="text" class="form-input h-8 w-40 text-sm"
                                   x-bind:value="selected.name ?? selected.type"
                                   @change="rename(selected, $event.target.value)"
                                   dusk="element-name" aria-label="Element name" />

                            <div class="flex gap-1">
                                <button type="button" class="btn-pager" @click="duplicate()" title="Duplicate (Ctrl+D)" dusk="element-duplicate">⧉</button>
                                <button type="button" class="btn-pager" @click="remove()" title="Delete" dusk="element-delete">🗑</button>
                            </div>
                        </div>

                        {{-- Style or Animation — Elementor's panel keeps them apart for the same reason:
                             they are two different jobs. --}}
                        <div class="grid grid-cols-2 gap-1 rounded-lg bg-gray-100 p-1 dark:bg-gray-900">
                            <button type="button" class="rounded px-2 py-1 text-xs font-medium"
                                    x-bind:class="panelTab === 'style'
                                        ? 'bg-white text-gray-900 shadow dark:bg-gray-700 dark:text-white'
                                        : 'text-gray-500 hover:text-gray-700 dark:text-gray-400'"
                                    @click="panelTab = 'style'" dusk="panel-tab-style">Style</button>
                            <button type="button" class="rounded px-2 py-1 text-xs font-medium"
                                    x-bind:class="panelTab === 'animation'
                                        ? 'bg-white text-gray-900 shadow dark:bg-gray-700 dark:text-white'
                                        : 'text-gray-500 hover:text-gray-700 dark:text-gray-400'"
                                    @click="panelTab = 'animation'" dusk="panel-tab-animation">
                                Animation <span class="text-purple-500" x-show="animates(selected)">✦</span>
                            </button>
                        </div>

                        {{-- ── Style ─────────────────────────────────────── --}}
                        <div x-show="panelTab === 'style'" class="space-y-5">
                            {{-- Text content --}}
                            <template x-if="selected.type === 'text'">
                                <div>
                                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Text</label>
                                    <textarea class="form-input mt-1 w-full text-sm" rows="3" dir="auto"
                                              x-bind:value="selected.text"
                                              @change="setText($event.target.value)" dusk="element-text"></textarea>
                                </div>
                            </template>

                            {{-- Size & position --}}
                            <div>
                                <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-400">Size &amp; position</h4>

                                <div class="mt-2 grid grid-cols-2 gap-2">
                                    <label class="text-xs text-gray-500 dark:text-gray-400">X
                                        <input type="number" class="form-input mt-1 w-full text-sm" x-bind:value="selected.x"
                                               @change="$el.value = setBox('x', $el.value)" dusk="element-x" />
                                    </label>
                                    <label class="text-xs text-gray-500 dark:text-gray-400">Y
                                        <input type="number" class="form-input mt-1 w-full text-sm" x-bind:value="selected.y"
                                               @change="$el.value = setBox('y', $el.value)" dusk="element-y" />
                                    </label>
                                    <label class="text-xs text-gray-500 dark:text-gray-400">Width
                                        <input type="number" min="1" class="form-input mt-1 w-full text-sm" x-bind:value="selected.w"
                                               @change="$el.value = setBox('w', $el.value)" dusk="element-w" />
                                    </label>
                                    <label class="text-xs text-gray-500 dark:text-gray-400">Height
                                        <input type="number" min="1" class="form-input mt-1 w-full text-sm" x-bind:value="selected.h"
                                               @change="$el.value = setBox('h', $el.value)" dusk="element-h" />
                                    </label>
                                    <label class="text-xs text-gray-500 dark:text-gray-400">Rotation
                                        <input type="number" min="-360" max="360" class="form-input mt-1 w-full text-sm" x-bind:value="selected.rotation ?? 0"
                                               @change="$el.value = setBox('rotation', $el.value)" dusk="element-rotation" />
                                    </label>
                                    <label class="text-xs text-gray-500 dark:text-gray-400">Opacity
                                        <input type="number" step="0.05" min="0" max="1" class="form-input mt-1 w-full text-sm"
                                               x-bind:value="selected.opacity ?? 1"
                                               @change="$el.value = setOpacity($el.value)"
                                               dusk="element-opacity" />
                                    </label>
                                    <label class="col-span-2 text-xs text-gray-500 dark:text-gray-400">Blend with what is behind
                                        <select class="form-select mt-1 w-full text-sm" x-bind:value="selected.style?.blend ?? 'normal'"
                                                @change="setStyle('blend', $el.value)" dusk="element-blend">
                                            @foreach ($blends as $blend)
                                                <option value="{{ $blend }}">{{ ucfirst(str_replace('-', ' ', $blend)) }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                </div>

                                <div class="mt-3">
                                    @include('builder.partials.align-controls', ['label' => 'Align on the stage'])
                                </div>
                            </div>

                            {{-- Typography. The six controls a person reaches for are here; the rest are one
                                 click away under "Show more", which is the shape Elementor's panel uses and
                                 the reason its panel stays readable. --}}
                            <template x-if="selected.type === 'text'">
                                <div>
                                    <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-400">Typography</h4>

                                    <label class="mt-2 block text-xs text-gray-500 dark:text-gray-400">Font
                                        <button type="button" class="form-input mt-1 flex w-full items-center justify-between text-left text-sm"
                                                @click="openFontPicker()" dusk="text-font">
                                            <span x-text="selected.style?.fontFamily ?? 'Inter'"
                                                  x-bind:style="{ fontFamily: (selected.style?.fontFamily ?? 'Inter') + ', sans-serif' }"></span>
                                            <span class="text-gray-400">▾</span>
                                        </button>
                                    </label>

                                    <div class="mt-2 grid grid-cols-2 gap-2">
                                        <label class="text-xs text-gray-500 dark:text-gray-400">Size
                                            <input type="number" min="1" max="2000" class="form-input mt-1 w-full text-sm"
                                                   x-bind:value="selected.style?.fontSize ?? 48"
                                                   @change="$el.value = setStyleNumber('fontSize', $el.value)" dusk="text-size" />
                                        </label>
                                        <label class="text-xs text-gray-500 dark:text-gray-400">Weight
                                            <select class="form-select mt-1 w-full text-sm" x-bind:value="selected.style?.fontWeight ?? 400"
                                                    @change="setStyle('fontWeight', Number($event.target.value))" dusk="text-weight">
                                                <template x-for="weight in weightsForSelected" :key="weight">
                                                    <option x-bind:value="weight" x-text="weight"
                                                            x-bind:selected="(selected.style?.fontWeight ?? 400) === weight"></option>
                                                </template>
                                            </select>
                                        </label>

                                        <label class="col-span-2 text-xs text-gray-500 dark:text-gray-400">Colour
                                            <input type="color" class="mt-1 h-9 w-full rounded border border-gray-300 dark:border-gray-600"
                                                   x-bind:value="selected.style?.color ?? '#ffffff'"
                                                   @change="setStyle('color', $event.target.value)" dusk="text-color" />
                                        </label>
                                    </div>

                                    {{-- Alignment as buttons, not a dropdown: it is a thing you see, not read. A
                                         glyph is no name, so each button says what it does. --}}
                                    <div class="mt-2">
                                        <span class="text-xs text-gray-500 dark:text-gray-400">Align</span>
                                        <div class="mt-1 grid grid-cols-4 gap-1">
                                            @foreach ($alignments as $align => $label)
                                                <button type="button" class="btn-pager" title="{{ $label }}" aria-label="{{ $label }}"
                                                        x-bind:class="(selected.style?.align ?? 'left') === '{{ $align }}' ? '!border-blue-500 !text-blue-600' : ''"
                                                        @click="setStyle('align', '{{ $align }}')" dusk="text-align-{{ $align }}">{{ $alignGlyphs[$align] ?? $label }}</button>
                                            @endforeach
                                        </div>
                                    </div>

                                    <button type="button" class="btn-secondary mt-3 w-full text-xs"
                                            @click="showMoreType = !showMoreType" dusk="text-show-more">
                                        <span x-text="showMoreType ? 'Show less' : 'Show more'"></span>
                                    </button>

                                    <div x-show="showMoreType" x-cloak class="mt-3 space-y-3 border-t border-gray-200 pt-3 dark:border-gray-700">
                                        <div class="grid grid-cols-2 gap-2">
                                            <label class="text-xs text-gray-500 dark:text-gray-400">Line height
                                                <input type="number" step="0.05" min="0.5" max="10" class="form-input mt-1 w-full text-sm"
                                                       x-bind:value="selected.style?.lineHeight ?? 1.2"
                                                       @change="$el.value = setStyleNumber('lineHeight', $el.value)" dusk="text-line-height" />
                                            </label>
                                            <label class="text-xs text-gray-500 dark:text-gray-400">Letter spacing
                                                <input type="number" step="0.5" min="-100" max="100" class="form-input mt-1 w-full text-sm"
                                                       x-bind:value="selected.style?.letterSpacing ?? 0"
                                                       @change="$el.value = setStyleNumber('letterSpacing', $el.value)" dusk="text-letter-spacing" />
                                            </label>
                                            <label class="text-xs text-gray-500 dark:text-gray-400">Word spacing
                                                <input type="number" step="0.5" min="-200" max="200" class="form-input mt-1 w-full text-sm"
                                                       x-bind:value="selected.style?.wordSpacing ?? 0"
                                                       @change="$el.value = setStyleNumber('wordSpacing', $el.value)" dusk="text-word-spacing" />
                                            </label>
                                            <label class="text-xs text-gray-500 dark:text-gray-400">Vertical
                                                <select class="form-select mt-1 w-full text-sm" x-bind:value="selected.style?.verticalAlign ?? 'flex-start'"
                                                        @change="setStyle('verticalAlign', $event.target.value)" dusk="text-vertical">
                                                    @foreach ($verticals as $value => $label)
                                                        <option value="{{ $value }}">{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                            <label class="text-xs text-gray-500 dark:text-gray-400">Case
                                                <select class="form-select mt-1 w-full text-sm" x-bind:value="selected.style?.textTransform ?? 'none'"
                                                        @change="setStyle('textTransform', $event.target.value)" dusk="text-transform">
                                                    @foreach ($transforms as $value => $label)
                                                        <option value="{{ $value }}">{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                            <label class="text-xs text-gray-500 dark:text-gray-400">Line
                                                <select class="form-select mt-1 w-full text-sm" x-bind:value="selected.style?.textDecoration ?? 'none'"
                                                        @change="setStyle('textDecoration', $event.target.value)" dusk="text-decoration">
                                                    @foreach ($decorations as $value => $label)
                                                        <option value="{{ $value }}">{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                            <label class="text-xs text-gray-500 dark:text-gray-400">Style
                                                <select class="form-select mt-1 w-full text-sm" x-bind:value="selected.style?.fontStyle ?? 'normal'"
                                                        @change="setStyle('fontStyle', $event.target.value)" dusk="text-style">
                                                    @foreach ($fontStyles as $value => $label)
                                                        <option value="{{ $value }}">{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                            <label class="text-xs text-gray-500 dark:text-gray-400">Padding
                                                <input type="number" min="0" max="400" class="form-input mt-1 w-full text-sm"
                                                       x-bind:value="selected.style?.padding ?? 0"
                                                       @change="$el.value = setStyleNumber('padding', $el.value)" dusk="text-padding" />
                                            </label>
                                        </div>

                                        {{-- A panel behind the words: a price tag, a ribbon, a strip along the bottom. --}}
                                        <div class="grid grid-cols-2 gap-2">
                                            <label class="text-xs text-gray-500 dark:text-gray-400">Panel colour
                                                <input type="color" class="mt-1 h-9 w-full rounded border border-gray-300 dark:border-gray-600"
                                                       x-bind:value="selected.style?.background ?? '#000000'"
                                                       @change="setStyle('background', $event.target.value)" dusk="text-background" />
                                            </label>
                                            <label class="text-xs text-gray-500 dark:text-gray-400">Panel radius
                                                <input type="number" min="0" max="999" class="form-input mt-1 w-full text-sm"
                                                       x-bind:value="selected.style?.radius ?? 0"
                                                       @change="$el.value = setStyleNumber('radius', $el.value)" dusk="text-radius" />
                                            </label>
                                            <button type="button" class="btn-secondary col-span-2 text-xs"
                                                    x-show="selected.style?.background" x-cloak
                                                    @click="setStyle('background', null)" dusk="text-background-clear">Remove panel</button>
                                        </div>

                                        {{-- Shadow and outline: what makes white text readable over a photograph. --}}
                                        <div>
                                            <button type="button" class="btn-secondary w-full text-xs"
                                                    @click="toggleNestedStyle('textShadow', { x: 0, y: 4, blur: 12, color: '#000000' })"
                                                    dusk="text-shadow-toggle">
                                                <span x-text="selected.style?.textShadow ? 'Remove shadow' : 'Add shadow'"></span>
                                            </button>

                                            <div x-show="selected.style?.textShadow" x-cloak class="mt-2 grid grid-cols-3 gap-2">
                                                <label class="text-xs text-gray-500 dark:text-gray-400">X
                                                    <input type="number" min="-200" max="200" class="form-input mt-1 w-full text-sm"
                                                           x-bind:value="selected.style?.textShadow?.x ?? 0"
                                                           @change="$el.value = setNestedNumber('textShadow', 'x', $el.value)" dusk="text-shadow-x" />
                                                </label>
                                                <label class="text-xs text-gray-500 dark:text-gray-400">Y
                                                    <input type="number" min="-200" max="200" class="form-input mt-1 w-full text-sm"
                                                           x-bind:value="selected.style?.textShadow?.y ?? 0"
                                                           @change="$el.value = setNestedNumber('textShadow', 'y', $el.value)" dusk="text-shadow-y" />
                                                </label>
                                                <label class="text-xs text-gray-500 dark:text-gray-400">Blur
                                                    <input type="number" min="0" max="200" class="form-input mt-1 w-full text-sm"
                                                           x-bind:value="selected.style?.textShadow?.blur ?? 0"
                                                           @change="$el.value = setNestedNumber('textShadow', 'blur', $el.value)" dusk="text-shadow-blur" />
                                                </label>
                                                <label class="col-span-3 text-xs text-gray-500 dark:text-gray-400">Shadow colour
                                                    <input type="color" class="mt-1 h-9 w-full rounded border border-gray-300 dark:border-gray-600"
                                                           x-bind:value="selected.style?.textShadow?.color ?? '#000000'"
                                                           @change="setNestedStyle('textShadow', 'color', $event.target.value)" dusk="text-shadow-color" />
                                                </label>
                                            </div>
                                        </div>

                                        <div>
                                            <button type="button" class="btn-secondary w-full text-xs"
                                                    @click="toggleNestedStyle('textStroke', { width: 2, color: '#000000' })"
                                                    dusk="text-stroke-toggle">
                                                <span x-text="selected.style?.textStroke ? 'Remove outline' : 'Add outline'"></span>
                                            </button>

                                            <div x-show="selected.style?.textStroke" x-cloak class="mt-2 grid grid-cols-2 gap-2">
                                                <label class="text-xs text-gray-500 dark:text-gray-400">Width
                                                    <input type="number" min="0" max="40" class="form-input mt-1 w-full text-sm"
                                                           x-bind:value="selected.style?.textStroke?.width ?? 0"
                                                           @change="$el.value = setNestedNumber('textStroke', 'width', $el.value)" dusk="text-stroke-width" />
                                                </label>
                                                <label class="text-xs text-gray-500 dark:text-gray-400">Colour
                                                    <input type="color" class="mt-1 h-9 w-full rounded border border-gray-300 dark:border-gray-600"
                                                           x-bind:value="selected.style?.textStroke?.color ?? '#000000'"
                                                           @change="setNestedStyle('textStroke', 'color', $event.target.value)" dusk="text-stroke-color" />
                                                </label>
                                            </div>
                                        </div>

                                        {{-- A frame around the whole box — the panel's edge, a boxed caption. --}}
                                        @include('builder.partials.frame-controls', ['prefix' => 'text', 'withShadow' => false])
                                    </div>
                                </div>
                            </template>

                            <template x-if="selected.type === 'shape'">
                                @include('builder.partials.shape-controls')
                            </template>

                            <template x-if="selected.type === 'image' || selected.type === 'video'">
                                @include('builder.partials.picture-controls')
                            </template>
                        </div>

                        {{-- ── Animation ─────────────────────────────────── --}}
                        <div x-show="panelTab === 'animation'" x-cloak class="space-y-3" dusk="animation-panel">
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                Animations start when the ad comes on screen and never depend on how long a playlist
                                shows it: a loop simply keeps going until the next item.
                            </p>

                            <button type="button" class="btn-secondary w-full text-xs"
                                    x-bind:disabled="!animates(selected)"
                                    @click="previewing === 'element' ? stopPreview() : previewElement(selected)" dusk="anim-preview">
                                <span x-text="previewing === 'element' ? '■ Stop' : '▶ Preview this element'"></span>
                            </button>

                            @include('builder.partials.animation-slot', [
                                'slot' => 'in', 'title' => 'Entrance', 'effects' => $entrances,
                                'hint' => 'Plays once, when the ad appears.',
                            ])
                            @include('builder.partials.animation-slot', [
                                'slot' => 'loop', 'title' => 'Loop', 'effects' => $loops,
                                'hint' => 'Then repeats for as long as the ad is on screen.',
                            ])
                            @include('builder.partials.animation-slot', [
                                'slot' => 'out', 'title' => 'Exit', 'effects' => $entrances,
                                'hint' => 'Plays once, a number of seconds after the ad appears.',
                            ])
                        </div>
                    </div>
                </template>
            </aside>
        </div>

        @include('builder.partials.context-menu')
        @include('builder.partials.shortcuts-modal')

        {{-- ── The font picker ───────────────────────────────────────── --}}
        <div x-show="fontPickerOpen" x-cloak class="fixed inset-0 z-50 flex items-start justify-center bg-black/50 p-6"
             @click.self="fontPickerOpen = false" dusk="font-picker">
            <div class="mt-16 max-h-[70vh] w-full max-w-lg overflow-y-auto rounded-lg bg-white p-5 dark:bg-gray-800">
                <div class="flex items-center justify-between gap-4">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Font</h3>
                    <input type="text" x-model="fontQuery" placeholder="Search fonts..." autocomplete="new-password"
                           class="form-input h-9 w-48 text-sm" dusk="font-search" />
                </div>

                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    A font you pick is downloaded once and served from this server, so a television with no
                    internet still shows it.
                </p>

                {{-- A family nobody has installed yet is fetched on the spot by whoever may create or change
                     an ad (the route's own lock); anybody else sees it is not here yet. --}}
                <div class="mt-4 divide-y divide-gray-100 dark:divide-gray-700">
                    <template x-for="font in fontResults" :key="font.family">
                        <button type="button" class="flex w-full items-center justify-between gap-3 py-2 text-left hover:bg-gray-50 disabled:cursor-default dark:hover:bg-gray-700/50"
                                @click="pickFont(font)" x-bind:disabled="installingFamily !== null || (!font.installed && !canInstallFonts)"
                                x-bind:dusk="'font-' + font.family.replace(/ /g, '-').toLowerCase()">
                            <span class="min-w-0">
                                <span class="block truncate text-sm text-gray-800 dark:text-white"
                                      x-bind:style="font.installed ? { fontFamily: font.family + ', sans-serif' } : {}"
                                      x-text="font.family"></span>
                                <span class="text-xs text-gray-400" x-text="font.kind"></span>
                            </span>

                            <span class="shrink-0 text-xs"
                                  x-bind:class="font.installed ? 'text-green-600 dark:text-green-400' : 'text-gray-400'"
                                  x-text="installingFamily === font.family
                                      ? 'Installing…'
                                      : (font.installed ? 'Ready' : (canInstallFonts ? 'Install' : 'Not installed'))"></span>
                        </button>
                    </template>
                </div>
            </div>
        </div>

        {{-- ── The asset picker: a new element, a background layer's file, or a replacement ── --}}
        <div x-show="assetPickerOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-6"
             @click.self="assetPickerOpen = false" dusk="asset-picker">
            <div class="max-h-[80vh] w-full max-w-3xl overflow-y-auto rounded-lg bg-white p-5 dark:bg-gray-800">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100" x-text="pickerTitle()"></h3>
                    @can('ad-view')
                        <a href="{{ route('builder.assets') }}" class="text-sm text-blue-600 hover:underline dark:text-blue-400">Manage assets</a>
                    @endcan
                </div>

                <p x-show="pickerAssets().length === 0" x-cloak class="py-10 text-center text-sm text-gray-500 dark:text-gray-400"
                   x-text="choosesShop && !storeId
                       ? 'Choose the shop this ad is for first (at the top) — its pictures come from that shop’s shelf.'
                       : (assetPickerKind === 'video'
                           ? 'No videos on the shelf yet — upload one on the Assets tab first.'
                           : 'Nothing on the shelf yet — upload something on the Assets tab first.')"></p>

                <div class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <template x-for="asset in pickerAssets()" :key="asset.id">
                        <button type="button" class="overflow-hidden rounded border border-gray-200 text-left hover:border-blue-500 dark:border-gray-700"
                                @click="pickAsset(asset)" x-bind:dusk="'pick-asset-' + asset.id">
                            <span class="relative block aspect-video bg-gray-100 dark:bg-gray-900">
                                <template x-if="assetThumb(asset)">
                                    <img x-bind:src="assetThumb(asset)" alt="" class="h-full w-full object-cover" />
                                </template>
                                <span x-show="asset.kind === 'video'"
                                      class="absolute bottom-1 left-1 rounded bg-black/70 px-1.5 py-0.5 text-xs text-white">▶ Video</span>
                            </span>
                            <span class="block truncate p-2 text-xs text-gray-600 dark:text-gray-300" x-text="asset.title"></span>
                        </button>
                    </template>
                </div>
            </div>
        </div>

        {{-- The two steps of the draft/publish model that undo cannot take back, each confirmed once. Neither
             deletes anything, so neither asks for the password (the everyday deletes do not either). --}}
        @can('ad-update')
            <x-modal name="confirm-discard-changes" maxWidth="md">
                <div class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Discard changes?</h2>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        The design goes back to the version on the screens. Everything changed since it was published
                        is lost — saved or not.
                    </p>
                    <div class="mt-6 flex justify-end gap-3">
                        <x-secondary-button x-on:click="$dispatch('close-modal', 'confirm-discard-changes')">Cancel</x-secondary-button>
                        <x-danger-button type="button" x-on:click="discardChanges()" x-bind:disabled="discarding"
                                         dusk="confirm-discard-changes">Discard changes</x-danger-button>
                    </div>
                </div>
            </x-modal>

            <x-modal name="confirm-unpublish" maxWidth="md">
                <div class="p-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Unpublish this ad?</h2>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        It leaves every screen and channel showing it — and the media library — until you publish it
                        again. Nothing is deleted: every playlist and channel keeps its place for it.
                    </p>
                    <div class="mt-6 flex justify-end gap-3">
                        <x-secondary-button x-on:click="$dispatch('close-modal', 'confirm-unpublish')">Cancel</x-secondary-button>
                        <x-danger-button type="button" x-on:click="unpublish()" x-bind:disabled="unpublishing"
                                         dusk="confirm-unpublish">Unpublish</x-danger-button>
                    </div>
                </div>
            </x-modal>
        @endcan
    </div>
</x-builder-layout>
