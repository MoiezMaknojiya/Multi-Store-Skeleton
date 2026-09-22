<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-white leading-tight">{{ __('Dayparts') }}</h2>
    </x-slot>

    {{-- Js::from, not @json: @json leaves its quotes RAW, so the first string in the
         payload closes the x-data attribute and Alpine is handed half an expression
         (see .claude/rules/02-project-conventions.md). --}}
    <div x-data="daypartsTable({{ Js::from(['hasStore' => (bool) session('current_store_id'), 'weekdays' => $weekdays]) }})"
         class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="flex flex-wrap items-center gap-3 mb-6">
            @can('daypart-store')
            <x-crud.add-button label="Add Daypart" @click="openFormModal()" dusk="add-daypart" />
            @endcan

            {{-- A daypart on its own does nothing; it is a window a playlist's lines point at.
                 Saying so here saves the first-time reader a guess. --}}
            <p class="text-sm text-gray-500 dark:text-gray-400">
                A daypart is a named window of time &mdash; set it once, use it on any playlist.
            </p>
        </div>

        <x-crud.table-wrapper title="All Dayparts" searchPlaceholder="Search dayparts by name..." :columns="5">
            <x-slot name="head">
                <th class="px-5 py-3 text-left font-semibold">Name</th>
                <th class="px-5 py-3 text-left font-semibold">Hours</th>
                <th class="px-5 py-3 text-left font-semibold">Exceptions</th>
                <th class="px-5 py-3 text-left font-semibold">Status</th>
                <th class="px-5 py-3 text-right font-semibold">Actions</th>
            </x-slot>

            <x-slot name="body">
                <x-crud.table-empty :columns="5" itemsVar="items" message="No dayparts found." />

                <template x-for="item in items" :key="item.id">
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                        <td class="px-5 py-4">
                            <p class="font-medium text-gray-800 dark:text-white whitespace-nowrap"
                               x-bind:dusk="'daypart-name-' + item.id" x-text="item.name"></p>
                        </td>

                        <td class="px-5 py-4 text-gray-600 dark:text-gray-300 text-sm">
                            <span class="font-mono whitespace-nowrap" x-text="windowLabel(item)"></span>
                            {{-- The one thing about a window that is not obvious from
                                 reading it: 22:00 - 02:00 is not a mistake. --}}
                            <span x-show="crossesMidnight(item.start_time, item.end_time)" x-cloak
                                  class="block text-xs text-blue-600 dark:text-blue-400 whitespace-nowrap">
                                runs past midnight
                            </span>
                        </td>

                        <td class="px-5 py-4 text-gray-600 dark:text-gray-300 text-xs"
                            x-text="rowSummary(item)"></td>

                        <td class="px-5 py-4">
                            <span x-show="!item.is_retired" x-cloak class="badge-success">Active</span>
                            <span x-show="item.is_retired" x-cloak class="badge-neutral">Retired</span>
                        </td>

                        <td class="px-5 py-4">
                            <x-crud.table-actions editClick="openFormModal(item)" deleteClick="confirmDelete(item)"
                                editCan="daypart-update" deleteCan="daypart-destroy" dusk="daypart" />
                        </td>
                    </tr>
                </template>
            </x-slot>

            <x-slot name="footer">
                <x-crud.pagination itemsVar="items" />
            </x-slot>
        </x-crud.table-wrapper>

        {{-- Delete Confirmation --}}
        <x-crud.confirm-delete-modal
            name="confirm-daypart-deletion"
            entity="Daypart"
            nameExpression="selectedItem?.name"
            deleteAction="deleteItem()"
            disabledVar="deleting" />

        {{-- Add / Edit Daypart --}}
        <x-modal name="daypart-form-modal" :show="false" maxWidth="2xl">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100"
                    x-text="editingItem ? 'Edit Daypart' : 'Add Daypart'"></h2>

                <form @submit.prevent="saveItem" dusk="daypart-form" class="mt-4 space-y-4">

                    <div x-show="!hasStore && !editingItem" x-cloak
                         class="rounded-md bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
                        Select a store first &mdash; opening hours belong to the store they were set for.
                    </div>

                    <x-crud.form-field label="Name" field="name" :required="true">
                        <x-text-input x-model="form.name" dusk="daypart-name" class="block w-full"
                                      placeholder="Deli hours" autocomplete="off" maxlength="100" />
                    </x-crud.form-field>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-crud.form-field label="Start time" field="start_time" :required="true">
                            <x-text-input type="time" x-model="form.start_time" dusk="daypart-start" class="block w-full" />
                        </x-crud.form-field>

                        <x-crud.form-field label="End time" field="end_time" :required="true">
                            <x-text-input type="time" x-model="form.end_time" dusk="daypart-end" class="block w-full" />
                        </x-crud.form-field>
                    </div>

                    <p class="text-xs text-gray-500 dark:text-gray-400 -mt-2">
                        An end time <span class="font-semibold">earlier</span> than the start means the window runs
                        past midnight &mdash; 22:00 to 02:00 is one window, not two.
                    </p>

                    {{-- Exceptions: the days that do not follow the hours above. Each
                         one is either SHUT or on different hours, said in a dropdown —
                         the database stores "shut" as two empty times, but nobody
                         should have to know that, or work out that two blank boxes
                         mean anything at all. --}}
                    <div class="pt-2 border-t border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <label class="form-label mb-0">Exceptions</label>
                            <button type="button" @click="addException()" x-bind:disabled="!canAddException()"
                                    dusk="daypart-add-exception" class="btn-row-neutral">
                                Add exception
                            </button>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            Only for days that differ from the hours above &mdash; a day the shop is shut,
                            or opens at a different time.
                        </p>

                        <template x-if="form.exceptions.length === 0">
                            <p class="mt-3 text-sm text-gray-400 dark:text-gray-500" dusk="daypart-no-exceptions">
                                No exceptions &mdash; the hours above apply every day.
                            </p>
                        </template>

                        <div class="mt-3 space-y-3">
                            <template x-for="(row, index) in form.exceptions" :key="index">
                                <div>
                                    {{-- A row in error is painted like any other field in error (crud-field-error),
                                         with the message below it. --}}
                                    <div class="flex flex-wrap items-center gap-2"
                                         x-bind:class="exceptionError(index, 'weekday') || exceptionError(index, 'start_time') || exceptionError(index, 'end_time')
                                             ? 'crud-field-error' : ''">
                                        <select x-model="row.weekday" x-bind:dusk="'daypart-exception-day-' + index"
                                                class="form-select w-36">
                                            @foreach ($weekdays as $value => $label)
                                                <option value="{{ $value }}"
                                                        x-bind:disabled="weekdayTaken('{{ $value }}', index)">{{ $label }}</option>
                                            @endforeach
                                        </select>

                                        {{-- The whole point of this row, said plainly. --}}
                                        <select x-model="row.mode" @change="setExceptionMode(row)"
                                                x-bind:dusk="'daypart-exception-mode-' + index"
                                                class="form-select w-44">
                                            <option value="closed">is closed</option>
                                            <option value="hours">opens at other hours</option>
                                        </select>

                                        {{-- Only when there are hours to give. A closed day
                                             has no times, so it is not asked for any. --}}
                                        <template x-if="row.mode === 'hours'">
                                            <div class="flex items-center gap-2">
                                                <x-text-input type="time" x-model="row.start_time" class="w-32"
                                                              x-bind:dusk="'daypart-exception-start-' + index" />
                                                <span class="text-gray-400">&ndash;</span>
                                                <x-text-input type="time" x-model="row.end_time" class="w-32"
                                                              x-bind:dusk="'daypart-exception-end-' + index" />
                                            </div>
                                        </template>

                                        <button type="button" @click="removeException(index)"
                                                x-bind:dusk="'daypart-remove-exception-' + index"
                                                class="btn-row-danger ml-auto">Remove</button>
                                    </div>

                                    {{-- Laravel reports these as "exceptions.0.start_time", which the
                                         form-field component's dot notation cannot reach. --}}
                                    <template x-if="exceptionError(index, 'weekday') || exceptionError(index, 'start_time') || exceptionError(index, 'end_time')">
                                        <p class="form-error"
                                           x-text="exceptionError(index, 'weekday') || exceptionError(index, 'start_time') || exceptionError(index, 'end_time')"></p>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>

                    {{-- What the four inputs above actually add up to, in words. --}}
                    <div class="rounded-md bg-gray-50 dark:bg-gray-700/40 px-4 py-3">
                        <p class="text-xs uppercase tracking-wide text-gray-400">This daypart is open</p>
                        <p class="text-sm text-gray-700 dark:text-gray-200 mt-0.5" dusk="daypart-summary"
                           x-text="summary() || '—'"></p>
                    </div>

                    <div class="flex items-center">
                        <input type="checkbox" x-model="form.is_retired" id="daypart_is_retired"
                               dusk="daypart-retired" class="form-checkbox">
                        <label for="daypart_is_retired" class="ml-2 block text-sm text-gray-700 dark:text-gray-300">
                            Retired &mdash; hide it from the pickers, keep it working where it is already used
                        </label>
                    </div>

                    <x-crud.form-actions savingVar="saving" dusk="daypart-save" cancelDusk="daypart-cancel" />
                </form>
            </div>
        </x-modal>
    </div>
</x-app-layout>
