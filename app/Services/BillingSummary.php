<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Screen;

/**
 * What an organization uses and what it will cost once billing starts (owner, 2026-10-07 and 2026-10-08; docs/BILLING-SPEC.md §1):
 * the first screen free and $5 a month for each further one, Premium Templates and Platform Channels $10 each, its own ads and
 * uploads free. The prices live here and nowhere else; the Billing tab inside an organization and the platform's Billing dialog both
 * read this one summary. Nothing is charged yet: the amounts are what billing will ask.
 */
class BillingSummary
{
    /** Screens free of charge, the ones paired first. */
    public const FREE_SCREENS = 1;

    /** Every further screen, in dollars a month. */
    public const SCREEN_PRICE = 5;

    /** Premium Templates unlocked, in dollars. */
    public const PREMIUM_TEMPLATES_PRICE = 10;

    /** Platform Channels unlocked, in dollars. */
    public const PLATFORM_CHANNELS_PRICE = 10;

    /**
     * @return array{
     *     organization: array{id: int, name: string},
     *     screens: list<array{id: int, name: string, paired_at: ?string, free: bool, monthly: int}>,
     *     screen_count: int, free_screens: int, paid_screens: int, screen_price: int, monthly_total: int,
     *     features: array{premium_templates: array{unlocked: bool, price: int}, platform_channels: array{unlocked: bool, price: int}},
     *     contact: array{email: ?string, phone: ?string}
     * }
     */
    public function for(Organization $organization): array
    {
        // The screen paired first is the free one (owner, 2026-10-08); the id settles two paired at the same moment.
        $screens = Screen::where('organization_id', $organization->id)
            ->orderBy('paired_at')
            ->orderBy('id')
            ->get(['id', 'name', 'paired_at'])
            ->values()
            ->map(fn (Screen $screen, int $place) => [
                'id' => $screen->id,
                'name' => $screen->name,
                'paired_at' => $screen->paired_at?->toIso8601String(),
                'free' => $place < self::FREE_SCREENS,
                'monthly' => $place < self::FREE_SCREENS ? 0 : self::SCREEN_PRICE,
            ]);

        $paid = max(0, $screens->count() - self::FREE_SCREENS);

        return [
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'screens' => $screens->all(),
            'screen_count' => $screens->count(),
            'free_screens' => min(self::FREE_SCREENS, $screens->count()),
            'paid_screens' => $paid,
            'screen_price' => self::SCREEN_PRICE,
            'monthly_total' => $paid * self::SCREEN_PRICE,
            'features' => [
                'premium_templates' => ['unlocked' => (bool) $organization->premium_templates_unlocked, 'price' => self::PREMIUM_TEMPLATES_PRICE],
                'platform_channels' => ['unlocked' => (bool) $organization->platform_channels_unlocked, 'price' => self::PLATFORM_CHANNELS_PRICE],
            ],
            'contact' => self::contact(),
        ];
    }

    /**
     * Where an organization asks to unlock or pay (SIGNAGE_BILLING_EMAIL, SIGNAGE_BILLING_PHONE): empty until the owner gives them,
     * and then the pages say "Contact us" alone.
     *
     * @return array{email: ?string, phone: ?string}
     */
    public static function contact(): array
    {
        $email = trim((string) config('signage.billing_contact_email'));
        $phone = trim((string) config('signage.billing_contact_phone'));

        return ['email' => $email !== '' ? $email : null, 'phone' => $phone !== '' ? $phone : null];
    }
}
