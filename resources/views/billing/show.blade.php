{{-- Settings → Billing (owner, 2026-10-08 — "organization mein billing profile k ander ho jese sub ki honti ha professional kaam chiya";
     docs/BILLING-SPEC.md §3): what the organization uses and what it will cost once billing starts, from BillingSummary — the screens
     (the one paired first free), the two features and their switches' state, its own work free, the invoices to come and whom to
     ask. A page to read: nothing is charged yet, and only the platform turns a feature on or off. --}}
<x-app-layout>
    <x-slot name="header">
        <h1 class="page-title">{{ __('Settings') }}</h1>
    </x-slot>

    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6" dusk="billing-page">
        <x-settings-tabs active="billing" />

        <p class="alert-info" dusk="billing-not-started">
            Billing has not started yet: nothing is charged, and every feature is open for now. This page shows what
            {{ $billing['organization']['name'] }} will pay once it starts.
        </p>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 items-start">
            {{-- The month, in one number and its sum. --}}
            <section class="card p-6" aria-labelledby="billing-month-title">
                <h2 id="billing-month-title" class="text-sm font-medium text-gray-500 dark:text-gray-400">Estimated every month</h2>
                <p class="mt-2 text-4xl font-semibold text-gray-900 dark:text-white" dusk="billing-monthly-total">${{ $billing['monthly_total'] }}</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">once billing starts</p>

                {{-- The sum: the screens, then each feature while it is unlocked — a subscription of its own (owner, 2026-10-10). --}}
                <dl class="mt-5 space-y-2 border-t border-gray-100 pt-4 text-sm dark:border-gray-700" dusk="billing-month-lines">
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-600 dark:text-gray-300">First screen</dt>
                        <dd class="font-medium text-gray-900 dark:text-white">Free</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-600 dark:text-gray-300">
                            {{ $billing['paid_screens'] }} more {{ Str::plural('screen', $billing['paid_screens']) }} × ${{ $billing['screen_price'] }}
                        </dt>
                        <dd class="font-medium text-gray-900 dark:text-white">${{ $billing['screens_monthly'] }}</dd>
                    </div>
                    @foreach (['premium_templates' => 'Premium Templates', 'platform_channels' => 'Platform Channels'] as $key => $title)
                        @if ($billing['features'][$key]['unlocked'])
                            <div class="flex justify-between gap-3">
                                <dt class="text-gray-600 dark:text-gray-300">{{ $title }}</dt>
                                <dd class="font-medium text-gray-900 dark:text-white">${{ $billing['features'][$key]['price'] }}</dd>
                            </div>
                        @endif
                    @endforeach
                </dl>
            </section>

            {{-- What may be unlocked, and what is always free. --}}
            <section class="card p-6 lg:col-span-2" aria-labelledby="billing-features-title">
                <h2 id="billing-features-title" class="text-subheading">Features</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">A monthly subscription each, for the whole organization.</p>

                <ul class="mt-3 divide-y divide-gray-100 border-t border-gray-100 dark:divide-gray-700 dark:border-gray-700">
                    @foreach ([
                        'premium_templates' => ['Premium Templates', 'Every ready-made design in the Ad Builder. The ads you make from them stay yours.'],
                        'platform_channels' => ['Platform Channels', 'Every platform channel, such as GAMA ads, on all your screens.'],
                    ] as $key => [$title, $description])
                        <li class="flex flex-wrap items-center justify-between gap-3 py-4" dusk="billing-feature-{{ $key }}">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $title }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $description }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                <span class="text-sm text-gray-600 dark:text-gray-300">${{ $billing['features'][$key]['price'] }} a month</span>
                                @if ($billing['features'][$key]['unlocked'])
                                    <span class="badge-success">Unlocked</span>
                                @else
                                    <span class="badge-warning inline-flex items-center gap-1"><x-icon name="lock-closed" class="h-3 w-3" /> Locked</span>
                                @endif
                            </div>
                        </li>
                    @endforeach
                    <li class="flex flex-wrap items-center justify-between gap-3 py-4" dusk="billing-feature-own">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">Your own ads and uploads</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Designs you make in the Ad Builder, and files in your Media Library.</p>
                        </div>
                        <span class="badge-neutral shrink-0">Free</span>
                    </li>
                </ul>
            </section>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 items-start">
            {{-- Every screen and what it costs, the one paired first free. --}}
            <section class="card lg:col-span-2" aria-labelledby="billing-screens-title">
                <div class="card-header">
                    <div>
                        <h2 id="billing-screens-title" class="text-subheading">Screens</h2>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">The screen paired first is the free one.</p>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="table-base" dusk="billing-screens">
                        <thead>
                            <tr>
                                <th class="px-5 py-3 text-left font-semibold">Screen</th>
                                <th class="px-5 py-3 text-left font-semibold">Paired</th>
                                <th class="px-5 py-3 text-right font-semibold">Every month</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($billing['screens'] as $screen)
                                <tr dusk="billing-screen-{{ $screen['id'] }}">
                                    <td class="px-5 py-3 font-medium text-gray-800 dark:text-white">{{ $screen['name'] }}</td>
                                    <td class="px-5 py-3 whitespace-nowrap text-gray-500 dark:text-gray-400">
                                        {{ $screen['paired_at'] ? \Illuminate\Support\Carbon::parse($screen['paired_at'])->format('M j, Y') : '—' }}
                                    </td>
                                    <td class="px-5 py-3 text-right">
                                        @if ($screen['free'])
                                            <span class="badge-neutral">Free</span>
                                        @else
                                            <span class="text-gray-900 dark:text-white">${{ $screen['monthly'] }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-5 py-8 text-center text-sm text-muted-soft">No screens yet. The first one you pair is free.</td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if ($billing['screen_count'] > 0)
                            <tfoot>
                                <tr class="border-t border-gray-200 dark:border-gray-700">
                                    <td colspan="2" class="px-5 py-3 font-semibold text-gray-900 dark:text-white">Screens total</td>
                                    <td class="px-5 py-3 text-right font-semibold text-gray-900 dark:text-white">${{ $billing['screens_monthly'] }}</td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </section>

            <div class="space-y-6">
                <section class="card p-6" aria-labelledby="billing-invoices-title">
                    <h2 id="billing-invoices-title" class="text-subheading">Invoices</h2>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No invoices yet. They appear here once billing starts.</p>
                </section>

                <section class="card p-6" aria-labelledby="billing-contact-title" dusk="billing-contact">
                    <h2 id="billing-contact-title" class="text-subheading">Questions or unlocking</h2>
                    <x-billing.contact :contact="$billing['contact']" :organization="$billing['organization']['name']" class="mt-3" />
                </section>
            </div>
        </div>
    </div>
</x-app-layout>
