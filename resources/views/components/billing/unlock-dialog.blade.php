{{-- The one dialog behind every Unlock (owner, 2026-10-07: "channel per bhi yehi dialog khule"; docs/BILLING-SPEC.md §6): Contact Us to
     Unlock on a Premium Template, Unlock on a platform channel. What the feature gives, its price, and whom to ask — nothing is
     bought here: the platform turns the feature on. --}}
@props(['name', 'feature', 'organization'])

@php
    $words = [
        'premium_templates' => [
            'Unlock Premium Templates',
            'Every premium template, and every new one we add, for $'.\App\Services\BillingSummary::PREMIUM_TEMPLATES_PRICE.' a month. Once unlocked, the ads you make from them are yours to keep.',
        ],
        'platform_channels' => [
            'Unlock Platform Channels',
            'Every platform channel, and every new one we add, on all your screens, for $'.\App\Services\BillingSummary::PLATFORM_CHANNELS_PRICE.' a month. Your own channels stay free.',
        ],
    ][$feature];
@endphp

<x-modal :name="$name" :show="false" maxWidth="md" focusable>
    <div class="p-6" dusk="{{ $name }}">
        <div class="flex items-center gap-2">
            <x-icon name="lock-closed" class="h-6 w-6 text-amber-600 dark:text-amber-400" />
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $words[0] }}</h2>
        </div>
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">{{ $words[1] }}</p>

        <div class="mt-4 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
            <x-billing.contact :contact="\App\Services\BillingSummary::contact()" :organization="$organization" />
        </div>

        <div class="mt-6 flex justify-end">
            <x-secondary-button x-on:click="$dispatch('close-modal', '{{ $name }}')" dusk="{{ $name }}-close">Close</x-secondary-button>
        </div>
    </div>
</x-modal>
