{{-- The activity log: above the stores every store's history; inside a store ($store) that store's own
     entries. Yearly maintenance drops a year for every store at once, so its panel is the platform's alone
     (global-tier + activity-destroy, the same pair as its routes). --}}
@php($canMaintain = ! $store && auth()->user()->can('global-tier') && auth()->user()->can('activity-destroy'))

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-white leading-tight">{{ __('Activity Log') }}</h2>
    </x-slot>

    <div x-data="activityTable({ canMaintain: @json($canMaintain) })" class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        @if ($store)
        <p class="text-sm text-gray-500 dark:text-gray-400" dusk="activity-scope-note">
            What happened in {{ $store->name }} — by its people, and by the platform on its behalf.
        </p>
        @endif

        {{-- Yearly storage panel (activity-destroy, above the stores): partition status + one-click maintenance --}}
        @if ($canMaintain)
        <div class="bg-white dark:bg-gray-800 rounded-xs border border-gray-100 dark:border-gray-700 p-5">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-white">Yearly Storage</h3>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Logs are split into yearly partitions. Maintenance opens partitions 3 years ahead and deletes everything older than 2 years — data included.
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <template x-for="p in partitions" :key="p.name">
                            <span class="badge-info" x-text="(p.year ?? 'future') + ' — ' + p.rows + (p.rows === 1 ? ' row' : ' rows')"></span>
                        </template>
                        <span x-show="partitions.length === 0" class="text-xs text-gray-400">No data yet.</span>
                    </div>
                </div>
                <button @click="$dispatch('open-modal', 'confirm-activity-maintenance')"
                    x-bind:disabled="maintaining"
                    dusk="activity-maintain-button"
                    class="btn-secondary whitespace-nowrap self-start sm:self-center">
                    Run Yearly Maintenance
                </button>
            </div>
        </div>

        {{-- Maintenance confirmation (destructive: drops old years with data) --}}
        <x-modal name="confirm-activity-maintenance" :show="false" maxWidth="md">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Run yearly maintenance?</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    This will open partitions 3 years ahead if missing, and
                    <span class="font-semibold text-red-600 dark:text-red-400">permanently delete every log older than 2 years, data included.</span>
                    This cannot be undone.
                </p>
                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close-modal', 'confirm-activity-maintenance')">
                        Cancel
                    </x-secondary-button>
                    <x-danger-button x-on:click="runMaintenance()" x-bind:disabled="maintaining" dusk="activity-maintain-confirm">
                        Run Maintenance
                    </x-danger-button>
                </div>
            </div>
        </x-modal>
        @endif

        {{-- Time range filter: every listing/search is bounded by it so MySQL
             only scans the matching yearly partitions (partition pruning) --}}
        <div class="bg-white dark:bg-gray-800 rounded-xs border border-gray-100 dark:border-gray-700 p-4">
            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <x-input-label value="Time Range" class="text-xs" />
                    <select x-model="preset" @change="applyPreset(preset)" dusk="activity-range-preset" class="form-select mt-1">
                        <option value="today">Today</option>
                        <option value="7d">Last 7 days</option>
                        <option value="30d">Last 30 days</option>
                        <option value="year">This year</option>
                        <option value="custom">Custom</option>
                    </select>
                </div>
                <div>
                    <x-input-label value="From" class="text-xs" />
                    <input type="date" x-model="from" @change="onDateChange()" dusk="activity-range-from" class="form-input mt-1">
                </div>
                <div>
                    <x-input-label value="To" class="text-xs" />
                    <input type="date" x-model="to" @change="onDateChange()" dusk="activity-range-to" class="form-input mt-1">
                </div>
            </div>
        </div>

        {{-- Activity Data Table (read-only audit trail). A bound :title — the wrapper echoes it itself, and an
             echo here as well escaped a store's name twice. --}}
        <x-crud.table-wrapper :title="$store ? 'Activity in '.$store->name : 'All Activity'" searchPlaceholder="Search activity (action, user, details...)" :columns="4">
            <x-slot name="head">
                <th class="px-5 py-3 text-left font-semibold">When</th>
                <th class="px-5 py-3 text-left font-semibold">Who</th>
                <th class="px-5 py-3 text-left font-semibold">Action</th>
                <th class="px-5 py-3 text-left font-semibold">Details</th>
            </x-slot>

            <x-slot name="body">
                <x-crud.table-empty :columns="4" itemsVar="items" message="No activity yet." />

                <template x-for="item in items" :key="item.id">
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                        <td class="px-5 py-4 text-sm text-gray-600 dark:text-gray-300 whitespace-nowrap"
                            x-text="new Date(item.created_at).toLocaleString()"></td>
                        <td class="px-5 py-4 text-gray-800 dark:text-white whitespace-nowrap" x-text="item.actor_name"></td>
                        <td class="px-5 py-4">
                            <span class="badge-info" x-text="item.action"></span>
                        </td>
                        <td class="cell-prose px-5 py-4 text-gray-600 dark:text-gray-300 text-sm" x-text="item.description"></td>
                    </tr>
                </template>
            </x-slot>

            <x-slot name="footer">
                <x-crud.pagination itemsVar="items" />
            </x-slot>
        </x-crud.table-wrapper>
    </div>
</x-app-layout>
