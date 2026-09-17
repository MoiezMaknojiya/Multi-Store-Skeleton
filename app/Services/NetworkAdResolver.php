<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Screen;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Which network advertisements one screen should carry right now.
 *
 * Four things must all be true before a brand's advert reaches a television, and
 * they are deliberately separate questions:
 *
 *   1. the SHOP agreed to carry advertising at all          stores.accepts_network_ads
 *   2. and THIS television does                             screens.accepts_network_ads
 *   3. and this campaign chose this screen                  campaign_screen
 *   4. and the campaign is live at this moment              its dates, and its own start/end time
 *
 * Consent (1 and 2) is a standing fact about the shop; targeting (3) is a decision
 * about one campaign. Both are off until somebody says otherwise, so a shop that was
 * never asked never carries an advert.
 *
 * The result is ONE break, not several. Each pause and resume of a long video is a
 * seek, which is the most fragile thing a cheap television's browser does — three
 * interruptions an hour is three times the risk of one, for the same airtime.
 */
class NetworkAdResolver
{
    /**
     * The adverts due on this screen, in the order they will play, already trimmed
     * to fit one break.
     *
     * @return Collection<int, Campaign>
     */
    public function breakFor(Screen $screen, ?CarbonInterface $at = null): Collection
    {
        $instant = CarbonImmutable::instance($at ?? now());
        $local = $screen->localTime($instant);

        if (! $this->screenCarriesAds($screen)) {
            return collect();
        }

        return $screen->campaigns()
            ->liveOn($local)
            ->orderBy('campaigns.id')          // stable order: same break every hour
            ->get()
            // The window inside a day is wall clock, so it can only be read once the
            // screen's own timezone is known — which is why it is not part of the query.
            ->filter(fn (Campaign $campaign) => $campaign->isDueAt($local))
            ->pipe(fn (Collection $due) => $this->trimToBreak($due));
    }

    /**
     * Has this television been cleared to carry advertising?
     *
     * The shop's answer and the screen's answer are both required. A shop may agree
     * in principle and still keep the set above the children's tables clean.
     */
    public function screenCarriesAds(Screen $screen): bool
    {
        return $screen->accepts_network_ads && (bool) $screen->store?->accepts_network_ads;
    }

    /**
     * Cut the break at the ceiling.
     *
     * Selling more than fits is a commercial mistake, not a technical one, so the
     * ones that do not fit are simply not sent — and the panel warns about it while
     * the campaign is being set up, rather than a television silently running a
     * three-minute advert break at somebody's lunch counter.
     *
     * @param  Collection<int, Campaign>  $due
     * @return Collection<int, Campaign>
     */
    private function trimToBreak(Collection $due): Collection
    {
        $spent = 0;

        return $due->filter(function (Campaign $campaign) use (&$spent) {
            // Never let one over-long advert block the whole break: if nothing has
            // played yet, the first one goes out whatever its length.
            if ($spent > 0 && $spent + $campaign->play_seconds > Campaign::MAX_BREAK_SECONDS) {
                return false;
            }

            $spent += $campaign->play_seconds;

            return true;
        })->values();
    }
}
