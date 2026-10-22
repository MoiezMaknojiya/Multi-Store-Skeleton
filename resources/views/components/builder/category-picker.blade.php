{{-- The categories an ad is in (Ad Builder → Categories, owner 2026-10-22): several may be chosen, at most AdCategory::MAX_PER_AD.
     A button that opens the list of the platform's categories, each a tick box, drawn over what is below it — opened in the flow
     it made a centred dialog taller, the button moved up, and the second press of a double click landed in the list and shut it.
     `model` is the Alpine array of ids it writes (the page's own state); `changed` is what the page runs after each change.
     Escape, or a press anywhere else, shuts the list (Escape onto its button, and no further).

     Two looks: Create Ad's field (`form`), labelled, with the ones chosen as chips under it that a press takes off; and the
     editor's top bar (`bar`, owner 2026-10-22: "jaha Ads ka naam dete ha wahi barabar mein category ka box do"): from 1536 px a box
     the ones chosen said by name on it; on a laptop, where the bar has to hold everything (measured: at 1280 px a box had 59 px
     left), a tag button saying how many, their names in its tooltip and its name. --}}
@props(['categories', 'model', 'changed' => '', 'id' => 'ad-categories', 'variant' => 'form'])

@php($__max = \App\Models\AdCategory::MAX_PER_AD)
@php($__bar = $variant === 'bar')

<div class="relative {{ $__bar ? 'min-w-9 shrink' : '' }}"
     x-data="{
         categoriesOpen: false, categoryQuery: '', categoryOptions: {{ Js::from($categories) }},
         chosenNames() { return this.categoryOptions.filter((option) => this.{{ $model }}.includes(option.id)).map((option) => option.name).join(', ') },
         focusButton() { [this.$refs.categoriesButton, this.$refs.categoriesCompact].find((button) => button?.offsetParent)?.focus() },
     }"
     @keydown.escape="if (categoriesOpen) { $event.stopPropagation(); $event.preventDefault(); categoriesOpen = false; focusButton() }"
     @focusout="if (! $el.contains($event.relatedTarget)) categoriesOpen = false"
     @click.outside="categoriesOpen = false"
     dusk="{{ $id }}-picker">
    @if ($__bar)
        <span id="{{ $id }}-label" class="sr-only">Categories</span>
    @else
        <span id="{{ $id }}-label" class="form-label">Categories <span class="font-normal text-gray-500 dark:text-gray-400">(optional)</span></span>
    @endif

    {{-- In the bar the names chosen, A to Z, cut short when they do not fit, and said whole in the tooltip. --}}
    <button type="button" id="{{ $id }}" x-ref="categoriesButton"
            class="form-select text-left {{ $__bar ? 'hidden h-9 w-40 max-w-full truncate 2xl:block' : 'mt-1' }}"
            aria-labelledby="{{ $id }}-label {{ $id }}" aria-controls="{{ $id }}-list"
            x-bind:aria-expanded="categoriesOpen ? 'true' : 'false'"
            @if ($__bar) x-bind:title="chosenNames() || 'Categories'" @endif
            @click="categoriesOpen = ! categoriesOpen; categoryQuery = ''" dusk="{{ $id }}">
        @if ($__bar)
            <span x-bind:class="{{ $model }}.length === 0 ? 'text-gray-500 dark:text-gray-400' : ''" x-text="chosenNames() || 'Categories'">Categories</span>
        @else
            <span x-text="{{ $model }}.length === 0 ? 'Choose Categories' : {{ $model }}.length + ' Chosen'">Choose Categories</span>
        @endif
    </button>

    @if ($__bar)
        {{-- Below 1536 px: a tag and how many, the names in its name and tooltip (which hold the number it shows). --}}
        <button type="button" x-ref="categoriesCompact" class="btn-pager h-9 gap-1 2xl:hidden" aria-controls="{{ $id }}-list"
                x-bind:aria-expanded="categoriesOpen ? 'true' : 'false'"
                x-bind:aria-label="{{ $model }}.length === 0 ? 'Categories' : 'Categories (' + {{ $model }}.length + '): ' + chosenNames()"
                x-bind:title="{{ $model }}.length === 0 ? 'Categories' : 'Categories (' + {{ $model }}.length + '): ' + chosenNames()"
                @click="categoriesOpen = ! categoriesOpen; categoryQuery = ''" dusk="{{ $id }}-compact">
            <x-icon name="tag" />
            <span x-show="{{ $model }}.length > 0" x-cloak class="text-xs font-semibold text-gray-700 dark:text-gray-200" x-text="{{ $model }}.length"></span>
        </button>
    @endif

    <div x-show="categoriesOpen" x-cloak id="{{ $id }}-list"
         class="absolute z-40 mt-1 rounded-md border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-900 {{ $__bar ? 'left-0 w-64' : 'inset-x-0' }}"
         dusk="{{ $id }}-list">
        <div x-show="categoryOptions.length > 8" class="border-b border-gray-200 p-2 dark:border-gray-700">
            <input type="search" x-model="categoryQuery" @keydown.enter.prevent maxlength="{{ \App\Models\AdCategory::MAX_NAME }}"
                   placeholder="Search categories..." aria-label="Search categories" autocomplete="off" class="form-input"
                   dusk="{{ $id }}-search" />
        </div>
        <div class="max-h-48 overflow-y-auto py-1" role="group" aria-labelledby="{{ $id }}-label">
            <template x-for="option in categoryOptions.filter((option) => option.name.toLowerCase().includes(categoryQuery.trim().toLowerCase()))" :key="option.id">
                <label class="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-800"
                       x-bind:class="! {{ $model }}.includes(option.id) && {{ $model }}.length >= {{ $__max }} ? 'cursor-not-allowed opacity-60' : ''">
                    <input type="checkbox" class="form-checkbox"
                           x-bind:checked="{{ $model }}.includes(option.id)"
                           x-bind:disabled="! {{ $model }}.includes(option.id) && {{ $model }}.length >= {{ $__max }}"
                           @change="{{ $model }} = {{ $model }}.includes(option.id) ? {{ $model }}.filter((id) => id !== option.id) : [...{{ $model }}, option.id]; {{ $changed }}"
                           x-bind:dusk="'{{ $id }}-option-' + option.id" />
                    <span class="break-words" x-text="option.name"></span>
                </label>
            </template>
            <p x-show="categoryQuery.trim() !== '' && ! categoryOptions.some((option) => option.name.toLowerCase().includes(categoryQuery.trim().toLowerCase()))"
               class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400">No category matches.</p>
        </div>
        <p x-show="{{ $model }}.length >= {{ $__max }}" x-cloak class="border-t border-gray-200 px-3 py-2 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
            At most {{ $__max }}: take one off to choose another.
        </p>
    </div>

    @unless ($__bar)
        {{-- The ones chosen, A to Z as the list is: a press takes one off, the keyboard put on the button first (the chip goes, and
             a $ref is not found from an element no longer on the page). --}}
        <div x-show="{{ $model }}.length > 0" x-cloak class="mt-2 flex flex-wrap gap-1.5" dusk="{{ $id }}-chosen">
            <template x-for="option in categoryOptions.filter((option) => {{ $model }}.includes(option.id))" :key="option.id">
                <button type="button" class="touch-target inline-flex items-center gap-1 whitespace-nowrap rounded-full bg-blue-50 px-2.5 text-xs font-medium text-blue-700 hover:bg-blue-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:bg-blue-900/40 dark:text-blue-200 dark:hover:bg-blue-900/60"
                        @click="focusButton(); {{ $model }} = {{ $model }}.filter((id) => id !== option.id); {{ $changed }}"
                        x-bind:aria-label="'Remove ' + option.name" x-bind:dusk="'{{ $id }}-chip-' + option.id">
                    <span x-text="option.name"></span>
                    <x-icon name="x-mark" class="h-3 w-3" />
                </button>
            </template>
        </div>
    @endunless
</div>
