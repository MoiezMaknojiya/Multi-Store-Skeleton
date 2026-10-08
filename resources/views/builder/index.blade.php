<x-app-layout>
    <x-slot name="header">
        <h1 class="page-title">{{ __('Ad Builder') }}</h1>
    </x-slot>

    <div x-data="adsTable()" class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        {{-- Create Ad, then the organization list (above the organizations; an organization sees its own only) and the search, on one line:
             the button first, as on every page (owner, 2026-09-30). --}}
        <div class="flex flex-wrap items-center justify-end gap-3">
            <div class="flex w-full flex-wrap items-center gap-3 sm:w-auto">
                @can('ad-store')
                    {{-- An ad's shape is chosen before the editor opens and fixed after (docs/AD-BUILDER-SPEC.md
                         §12), so Create Ad asks first. --}}
                    <x-crud.add-button label="Create Ad" dusk="new-ad" @click="startNewAd()" />
                @endcan

                @if ($organizations !== [])
                    <select x-model="filterOrganization" @change="applyFilters()" class="form-select sm:w-44"
                            dusk="ads-filter-organization" aria-label="Organization">
                        <option value="">All organizations</option>
                        @foreach ($organizations as $organization)
                            <option value="{{ $organization['id'] }}">{{ $organization['name'] }}</option>
                        @endforeach
                    </select>
                @endif

                <x-crud.search-input placeholder="Search ads..." />
            </div>
        </div>

        {{-- A gallery, not a table: an ad is a picture, and a picture is how a person finds it again. The same three
             "nothing to show" cases as every listing (x-crud.table-empty): a list that did not load, a search or an
             organization that matched nothing, and no ads at all. --}}
        <div class="card" data-list-card>
            <div class="p-5" x-bind:aria-busy="loading ? 'true' : 'false'">
                <template x-if="loading">
                    <p class="py-12 text-center text-sm text-muted-soft">
                        <span class="inline-flex items-center gap-2"><x-spinner class="text-blue-600 dark:text-blue-400" /> Loading...</span>
                    </p>
                </template>

                <template x-if="!loading && items.length === 0 && loadFailed">
                    <div class="mx-auto max-w-md space-y-3 py-12 text-center" role="alert" dusk="table-load-failed">
                        <p class="text-sm text-gray-700 dark:text-gray-200">Could not load the ads. Check the connection, then try again.</p>
                        <button type="button" class="btn-row-neutral" @click="fetchItems()" dusk="table-try-again">Try Again</button>
                    </div>
                </template>

                <template x-if="!loading && items.length === 0 && !loadFailed && (search || filterOrganization)">
                    <div class="mx-auto max-w-md space-y-3 py-12 text-center" dusk="table-no-match">
                        <p class="text-sm text-gray-700 dark:text-gray-200">
                            <span x-show="search">No ad matches &ldquo;<span class="font-medium" x-text="search"></span>&rdquo;.</span>
                            <span x-show="!search">This organization has no ads yet.</span>
                        </p>
                        <div class="flex flex-wrap justify-center gap-2">
                            <button type="button" x-show="search" class="btn-row-neutral" @click="search = ''" dusk="table-clear-search">Clear Search</button>
                            <button type="button" x-show="!search" class="btn-row-neutral" @click="filterOrganization = ''; applyFilters()" dusk="table-clear-filters">Show Every Organization</button>
                        </div>
                    </div>
                </template>

                <template x-if="!loading && items.length === 0 && !loadFailed && !search && !filterOrganization">
                    <div class="mx-auto max-w-md py-12 text-center" dusk="ads-empty">
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-200">No ads yet.</p>
                    </div>
                </template>

                <div x-show="!loading && items.length > 0" x-cloak
                     class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3" dusk="ads-grid">
                    <template x-for="item in items" :key="item.id">
                        <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700"
                             x-bind:dusk="'ad-card-' + item.id">

                            {{-- The poster the editor captured when the ad was saved. It opens the editor — which
                                 is Update Ads, and above the organizations alone for an ad made for every organization — so it is a
                                 link only for somebody who may change this ad (the row's `can`); a mouse's short cut
                                 only, since the name and Edit below lead to the same place (tabindex -1). --}}
                            {{-- The tile stays a television's shape; a portrait poster is drawn inside it whole
                                 (contained, never cropped) and the tile says which way the ad is (§12). --}}
                            <div class="relative">
                                @can('ad-update')
                                    <a x-bind:href="item.can?.update ? '/builder/' + item.id : null" tabindex="-1" aria-hidden="true" class="block aspect-video bg-gray-100 dark:bg-gray-900">
                                        <img x-show="item.thumbnail_url" x-cloak x-bind:src="item.thumbnail_url" alt=""
                                             class="h-full w-full" x-bind:class="item.orientation === 'portrait' ? 'object-contain' : 'object-cover'"
                                             x-bind:dusk="'ad-poster-' + item.id" />
                                        <span x-show="!item.thumbnail_url" x-cloak
                                              class="flex h-full w-full items-center justify-center text-xs text-muted-soft">No preview yet</span>
                                    </a>
                                @else
                                    <div class="aspect-video bg-gray-100 dark:bg-gray-900">
                                        <img x-show="item.thumbnail_url" x-cloak x-bind:src="item.thumbnail_url" alt=""
                                             class="h-full w-full" x-bind:class="item.orientation === 'portrait' ? 'object-contain' : 'object-cover'"
                                             x-bind:dusk="'ad-poster-' + item.id" />
                                        <span x-show="!item.thumbnail_url" x-cloak
                                              class="flex h-full w-full items-center justify-center text-xs text-muted-soft">No preview yet</span>
                                    </div>
                                @endcan

                                <span x-show="item.orientation === 'portrait'" x-cloak
                                      class="absolute left-2 top-2 rounded bg-black/60 px-2 py-1 text-xs font-medium text-white"
                                      title="For a screen mounted upright — 1080 × 1920"
                                      x-bind:dusk="'ad-orientation-' + item.id">Portrait</span>

                                {{-- The saved design, full screen, the way a television would show it — in a new tab,
                                     which its name says. --}}
                                <a x-bind:href="'/builder/' + item.id + '/preview'" target="_blank" rel="noopener"
                                   x-bind:aria-label="'Preview ' + item.name + ' (opens in a new tab)'"
                                   class="absolute right-2 top-2 inline-flex items-center gap-1 rounded bg-black/70 px-2 py-1 text-xs font-medium text-white hover:bg-black/85 focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
                                   x-bind:dusk="'preview-ad-' + item.id">
                                    <svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.3 2.84A1.5 1.5 0 0 0 4 4.11v11.78a1.5 1.5 0 0 0 2.3 1.27l9.34-5.89a1.5 1.5 0 0 0 0-2.54L6.3 2.84Z" /></svg>
                                    Preview
                                </a>
                            </div>

                            {{-- The buttons go under the name when the card is too narrow for both: side by
                                 side they once squeezed the name to nothing and pushed Delete out of the card. --}}
                            <div class="flex flex-wrap items-start justify-between gap-3 p-4">
                                <div class="min-w-[8rem] flex-1">
                                    @can('ad-update')
                                        <a x-bind:href="item.can?.update ? '/builder/' + item.id : null" x-bind:dusk="'ad-name-' + item.id" x-bind:title="item.name"
                                           class="block truncate font-medium text-gray-800 dark:text-white" x-bind:class="item.can?.update ? 'hover:underline' : ''"
                                           x-text="item.name"></a>
                                    @else
                                        <p x-bind:dusk="'ad-name-' + item.id" x-bind:title="item.name"
                                           class="truncate font-medium text-gray-800 dark:text-white" x-text="item.name"></p>
                                    @endcan

                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        <span x-show="item.organization_name" x-cloak x-text="item.organization_name + ' · '"></span>
                                        <span x-text="item.updated_by_name ? 'by ' + item.updated_by_name : ''"></span>
                                    </p>

                                    {{-- Made for every organization (owner, 2026-10-01) — a Premium Template once published: "Every organization",
                                         as the Assets page says it of the platform's file. Only the platform lists one. --}}
                                    <span x-show="item.shared" x-cloak class="badge-info mr-1 mt-2 inline-block"
                                          x-bind:dusk="'ad-owner-' + item.id" x-text="item.owner_label"></span>

                                    {{-- The industry's draft/publish model (docs/AD-BUILDER-SPEC.md §9): a changed ad
                                         stays on the screens as it was published until the changes are published. --}}
                                    <span class="mt-2 inline-block" x-show="!item.shared || item.can?.update" x-cloak
                                          x-bind:class="{ published: 'badge-success', changed: 'badge-warning' }[item.status] ?? 'badge-neutral'"
                                          x-bind:dusk="'ad-status-' + item.id"
                                          x-bind:title="{
                                              published: 'On the screens, exactly as designed',
                                              changed: 'On the screens as last published — the newer changes are not published yet',
                                          }[item.status] ?? 'Not on any screen'"
                                          x-text="{ published: 'Published', changed: 'Changes not published' }[item.status] ?? 'Draft'"></span>
                                </div>

                                {{-- Each names the ad it acts on. A design a channel shows is not deleted: said at once,
                                     before the password (askToDelete). --}}
                                {{-- What may be done to this ad comes with it (`can`): Update Ads and Delete Ads, and
                                     for one made for every organization only above the organizations. --}}
                                <div class="flex shrink-0 items-center gap-2">
                                    @can('ad-update')
                                        <a x-show="item.can?.update" x-bind:href="'/builder/' + item.id" class="btn-row-neutral"
                                           x-bind:aria-label="'Edit ' + item.name"
                                           x-bind:dusk="'edit-ad-' + item.id">Edit</a>
                                    @endcan
                                    @can('ad-store')
                                        <button type="button" class="btn-row-neutral" @click="duplicate(item, $event)" x-show="item.can?.copy"
                                                x-bind:disabled="busyId === item.id"
                                                x-bind:aria-label="'Copy ' + item.name"
                                                x-bind:dusk="'duplicate-ad-' + item.id">
                                            <x-spinner x-show="busyId === item.id" x-cloak class="h-3 w-3" />
                                            Copy
                                        </button>
                                    @endcan
                                    @can('ad-destroy')
                                        <button type="button" class="btn-row-danger" @click="askToDelete(item)" x-show="item.can?.delete"
                                                x-bind:aria-label="'Delete ' + item.name"
                                                x-bind:dusk="'delete-ad-' + item.id">Delete</button>
                                    @endcan
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <x-crud.pagination itemsVar="items" />
        </div>

        {{-- Which way is the screen mounted? Asked before the editor opens, because it cannot change after
             (docs/AD-BUILDER-SPEC.md §12): every element's box is in stage pixels, so a design for the other
             shape is another design. Above the organizations two links, so the editor opens on a stage of that shape; inside an
             organization the dialog asks next how to start (owner, 2026-10-07): Create Your Own, or Premium Template — the
             platform's designs of that shape, one of which becomes the organization's own ad. One heading, whose words follow the
             step, names the dialog. --}}
        @can('ad-store')
            <x-modal name="new-ad-orientation" :show="false" maxWidth="lg" focusable>
                <div class="p-6" dusk="new-ad-dialog">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100"
                        x-text="newAdStep === 'start' ? 'Create Ad — how do you want to start?' : 'Create Ad — which way is the screen?'">Create Ad — which way is the screen?</h2>

                    <div x-show="newAdStep === 'orientation'">
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                            Chosen once, for this ad: it cannot be changed after.
                        </p>

                        <div class="mt-5">
                            @include('builder.partials.orientation-choice', ['asksHowToStart' => ! $aboveTheOrganizations])
                        </div>
                    </div>

                    @unless ($aboveTheOrganizations)
                        <div x-show="newAdStep === 'start'" x-cloak>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400" dusk="new-ad-shape"
                               x-text="newAdOrientation === 'portrait' ? 'Portrait · 1080 × 1920' : 'Landscape · 1920 × 1080'"></p>

                            <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <a x-bind:href="'{{ route('builder.create') }}?orientation=' + newAdOrientation" @click="ignoreRepeatPress($event)" id="new-ad-own" dusk="new-ad-own"
                                   class="group rounded-lg border-2 border-gray-200 p-4 text-center transition hover:border-blue-500 hover:bg-blue-50 focus:outline-none focus-visible:border-blue-500 focus-visible:ring-2 focus-visible:ring-blue-500 dark:border-gray-700 dark:hover:border-blue-400 dark:hover:bg-gray-700/50">
                                    <x-icon name="plus" class="mx-auto h-8 w-8 text-gray-500 group-hover:text-blue-600 dark:text-gray-400" />
                                    <span class="mt-3 block font-semibold text-gray-900 dark:text-gray-100">Create Your Own</span>
                                    <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">Start from an empty stage.</span>
                                </a>

                                <button type="button" @click="openTemplates($event)" dusk="new-ad-premium"
                                        class="group rounded-lg border-2 border-gray-200 p-4 text-center transition hover:border-blue-500 hover:bg-blue-50 focus:outline-none focus-visible:border-blue-500 focus-visible:ring-2 focus-visible:ring-blue-500 dark:border-gray-700 dark:hover:border-blue-400 dark:hover:bg-gray-700/50">
                                    <x-icon name="sparkles" class="mx-auto h-8 w-8 text-gray-500 group-hover:text-blue-600 dark:text-gray-400" />
                                    <span class="mt-3 block font-semibold text-gray-900 dark:text-gray-100">Premium Template</span>
                                    <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">Start from a ready-made design and make it yours.</span>
                                </button>
                            </div>
                        </div>
                    @endunless

                    <div class="mt-6 flex flex-wrap justify-end gap-3">
                        @unless ($aboveTheOrganizations)
                            <x-secondary-button x-show="newAdStep === 'start'" x-cloak x-on:click="backToOrientation()" dusk="new-ad-back">Back</x-secondary-button>
                        @endunless
                        <x-secondary-button x-on:click="$dispatch('close-modal', 'new-ad-orientation')" dusk="new-ad-cancel">Cancel</x-secondary-button>
                    </div>
                </div>
            </x-modal>

            {{-- Premium Templates (owner, 2026-10-07): the platform's published designs of the shape chosen, as they were
                 published. Preview opens one full screen in a new tab; Use This Template makes it the organization's own ad,
                 with its own copy of every picture and video in it, and opens it in the editor. As wide as the window, like
                 a channel's Add Ad: only the tiles scroll, and Back and Cancel stay in sight. --}}
            @unless ($aboveTheOrganizations)
                <x-modal name="premium-templates" :show="false" maxWidth="6xl" focusable>
                    <div class="flex flex-col p-6 lg:max-h-[calc(100dvh-2rem)]" dusk="premium-templates">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Premium Templates</h2>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400"
                                   x-text="(newAdOrientation === 'portrait' ? 'Portrait' : 'Landscape') + ': the one you use becomes your own ad, with its pictures.'"></p>
                            </div>
                            <input type="search" x-model="templateSearch" @input.debounce.300ms="loadTemplates()" @keydown.enter.prevent="loadTemplates()"
                                   placeholder="Search templates..." aria-label="Search templates" maxlength="255" autocomplete="off"
                                   class="form-input sm:w-64" dusk="templates-search" />
                        </div>

                        {{-- Locked (docs/BILLING-SPEC.md §6): nothing hidden, shown with a lock. --}}
                        <div x-show="templatesLocked" x-cloak class="alert-warning mt-5 flex items-start gap-3" dusk="templates-locked">
                            <x-icon name="lock-closed" class="mt-0.5 h-4 w-4 shrink-0" />
                            <div>
                                <p class="font-semibold">Premium Templates are locked for {{ $organizationName }}.</p>
                                <p class="mt-0.5">Look at every template and preview it. To use them, unlock Premium Templates for ${{ \App\Services\BillingSummary::PREMIUM_TEMPLATES_PRICE }}: contact us and we unlock them for you.</p>
                            </div>
                        </div>

                        <div class="mt-5 min-h-0 flex-1 overflow-y-auto px-1 pb-1" x-bind:aria-busy="templatesState === 'loading' ? 'true' : 'false'">
                            <template x-if="templatesState === 'loading' && templates.length === 0">
                                <p class="py-12 text-center text-sm text-muted-soft">
                                    <span class="inline-flex items-center gap-2"><x-spinner class="text-blue-600 dark:text-blue-400" /> Loading...</span>
                                </p>
                            </template>

                            <template x-if="templatesState === 'failed'">
                                <div class="mx-auto max-w-md space-y-3 py-12 text-center" role="alert" dusk="templates-load-failed">
                                    <p class="text-sm text-gray-700 dark:text-gray-200">Could not load the templates. Check the connection, then try again.</p>
                                    <button type="button" class="btn-row-neutral" @click="loadTemplates()">Try Again</button>
                                </div>
                            </template>

                            <template x-if="templatesState === 'ready' && templates.length === 0">
                                <div class="mx-auto max-w-md space-y-3 py-12 text-center" dusk="templates-empty">
                                    <p class="text-sm text-gray-700 dark:text-gray-200" x-show="templateSearch">
                                        No template matches &ldquo;<span class="font-medium" x-text="templateSearch"></span>&rdquo;.
                                    </p>
                                    <button type="button" x-show="templateSearch" class="btn-row-neutral" @click="templateSearch = ''; loadTemplates()">Clear Search</button>
                                    <p class="text-sm text-gray-700 dark:text-gray-200" x-show="!templateSearch"
                                       x-text="'No premium templates for ' + newAdOrientation + ' screens yet.'"></p>
                                </div>
                            </template>

                            <div x-show="templates.length > 0" x-cloak class="grid gap-4"
                                 x-bind:class="newAdOrientation === 'portrait' ? 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-5' : 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3'"
                                 dusk="templates-grid">
                                <template x-for="template in templates" :key="template.id">
                                    <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700" x-bind:dusk="'template-' + template.id">
                                        <div class="relative bg-gray-100 dark:bg-gray-900"
                                             x-bind:class="template.orientation === 'portrait' ? 'aspect-[9/16]' : 'aspect-video'">
                                            <img x-show="template.thumbnail_url" x-bind:src="template.thumbnail_url" alt="" class="h-full w-full object-cover" />
                                            <span x-show="!template.thumbnail_url"
                                                  class="flex h-full w-full items-center justify-center text-xs text-muted-soft">No preview yet</span>
                                            <span x-show="templatesLocked" x-cloak
                                                  class="absolute left-2 top-2 inline-flex items-center gap-1 rounded bg-black/75 px-2 py-1 text-xs font-semibold text-amber-200"
                                                  x-bind:dusk="'template-premium-' + template.id">
                                                <x-icon name="lock-closed" class="h-3 w-3" />
                                                Premium
                                            </span>
                                            <a x-bind:href="template.preview_url" target="_blank" rel="noopener" @click="ignoreRepeatPress($event)"
                                               x-bind:aria-label="'Preview ' + template.name + ' (opens in a new tab)'"
                                               class="absolute right-2 top-2 inline-flex items-center gap-1 rounded bg-black/70 px-2 py-1 text-xs font-medium text-white hover:bg-black/85 focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
                                               x-bind:dusk="'template-preview-' + template.id">
                                                <x-icon name="play" class="h-3 w-3" />
                                                Preview
                                            </a>
                                        </div>
                                        <div class="space-y-2 p-3">
                                            <p class="truncate text-sm font-medium text-gray-800 dark:text-white" x-bind:title="template.name" x-text="template.name"></p>
                                            <button type="button" class="btn-primary w-full justify-center" @click="useTemplate(template, $event)"
                                                    x-show="!templatesLocked"
                                                    x-bind:disabled="usingTemplateId !== null"
                                                    x-bind:aria-label="'Use the template ' + template.name"
                                                    x-bind:dusk="'use-template-' + template.id">
                                                <x-spinner x-show="usingTemplateId === template.id" x-cloak />
                                                <span x-text="usingTemplateId === template.id ? 'Copying...' : 'Use This Template'"></span>
                                            </button>
                                            <button type="button" class="btn-secondary w-full justify-center" x-show="templatesLocked" x-cloak
                                                    @click="$dispatch('open-modal', 'unlock-premium-templates')"
                                                    x-bind:aria-label="'Contact us to unlock ' + template.name"
                                                    x-bind:dusk="'unlock-template-' + template.id">
                                                <x-icon name="lock-closed" class="h-4 w-4" />
                                                Contact Us to Unlock
                                            </button>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <div class="mt-6 flex flex-wrap justify-end gap-3">
                            <x-secondary-button x-on:click="backToStart()" x-bind:disabled="usingTemplateId !== null" dusk="templates-back">Back</x-secondary-button>
                            <x-secondary-button x-on:click="$dispatch('close-modal', 'premium-templates')" x-bind:disabled="usingTemplateId !== null" dusk="templates-cancel">Cancel</x-secondary-button>
                        </div>
                    </div>
                </x-modal>

                <x-billing.unlock-dialog name="unlock-premium-templates" feature="premium_templates" :organization="$organizationName" />
            @endunless
        @endcan

        {{-- Deleting an ad takes its published copy off every playlist, so it asks for the password. --}}
        <x-modal name="confirm-ad-deletion" :show="false" maxWidth="md" focusable>
            <form @submit.prevent="deleteItem()" class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Delete this ad?</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    <span class="font-medium" x-text="selectedItem?.name"></span> and its published copy go for good —
                    including from any playlist that plays it.
                    <span x-show="selectedItem?.shared" x-cloak dusk="confirm-ad-deletion-shared">It is no Premium Template any more; the copies organizations made stay theirs.</span>
                </p>

                <x-crud.password-confirm id="delete-ad-password" />

                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'confirm-ad-deletion')">Cancel</x-secondary-button>
                    <x-danger-button x-bind:disabled="deleting" dusk="confirm-ad-deletion-confirm">
                        <x-spinner x-show="deleting" x-cloak />
                        Delete Ad
                    </x-danger-button>
                </div>
            </form>
        </x-modal>
    </div>
</x-app-layout>
