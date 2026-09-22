<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A channel: a stream of ads — a wholesaler's promotions, a season, a notice — that a shop
 * may put on any of its screens as ONE line of the playlist. The line expands, exactly where
 * it stands, into whatever the channel is running that day, so an ad changed once reaches
 * every screen carrying the channel without a shop lifting a finger.
 *
 * Two kinds (owner's rules):
 *  - The platform's channel (`store_id` NULL), made above the stores and offered to every
 *    shop. A shop chooses whether to add it and where in its loop it sits.
 *  - A store's own channel (`store_id` set), made inside that store by a member whose role
 *    carries the channel permissions, and offered to that store's screens alone.
 *
 * It keeps no files of its own (docs/CHANNEL-CONTENT-SPEC.md, owner 2026-09-19): each ad is a row of a
 * media library, held by id — the shop's library for a shop's channel, the platform's (or any shop's, on
 * purpose) for the platform's channel.
 */
class Channel extends Model
{
    use HasFactory;

    /** The most "ads each time" a channel may ask for — a guard, not a use case. */
    public const MAX_ADS_PER_PASS = 50;

    protected $fillable = ['name', 'ads_per_pass', 'is_active', 'created_by', 'store_id'];

    protected function casts(): array
    {
        return [
            'ads_per_pass' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The channels a person manages from where they stand: above the stores, every channel — the
     * platform's and each store's; inside a store, that store's own and no other. With no store
     * selected, none.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->globalRole() !== null) {
            return $query;
        }

        $storeId = (int) session('current_store_id');

        return $storeId > 0 ? $query->where('store_id', $storeId) : $query->whereRaw('0 = 1');
    }

    /**
     * The channels a person may LOOK at from where they stand — what the Channels page lists and opens: above
     * the stores, every channel; inside a store, its own and the platform's, the platform's to read only (owner,
     * 2026-09-19). Every write still goes through visibleTo, so a platform channel changed from inside a shop
     * is not found.
     */
    public function scopeListableIn(Builder $query, User $user): Builder
    {
        if ($user->globalRole() !== null) {
            return $query;
        }

        $storeId = (int) session('current_store_id');

        return $storeId > 0
            ? $query->where(fn (Builder $q) => $q->whereNull('store_id')->orWhere('store_id', $storeId))
            : $query->whereRaw('0 = 1');
    }

    /** The channels a screen may carry: the platform's, and its own store's — never another store's. */
    public function scopeAvailableTo(Builder $query, Screen $screen): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('store_id')->orWhere('store_id', $screen->store_id));
    }

    /** The platform's channel, offered to every shop — as opposed to one shop's own. */
    public function isPlatformChannel(): bool
    {
        return $this->store_id === null;
    }

    /** The store whose own channel this is; null for the platform's. */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * The channel's ads, in the order they play — each with its library row, which says everything about its
     * file: every reader of an ad needs it, so it is never a query per ad.
     */
    public function ads(): HasMany
    {
        // With the design behind an Ad Builder page, which says whether that page is on the screens.
        return $this->hasMany(ChannelAd::class)->with('media.builderAd')->orderBy('position')->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * What a screen is sent on one local date: the ads inside their dates, in order —
     * and nothing at all while the channel is paused.
     *
     * Pausing is how a channel comes off the air without coming off every shop's
     * playlist, which is what deleting it does.
     *
     * @return Collection<int, ChannelAd>
     */
    public function liveAdsOn(CarbonInterface $localDay): Collection
    {
        return $this->is_active ? $this->runningAdsOn($localDay) : collect();
    }

    /**
     * The ads inside their dates on one local date, paused or not — what the panel
     * lists, as opposed to what a television is sent.
     *
     * @return Collection<int, ChannelAd>
     */
    public function runningAdsOn(CarbonInterface $localDay): Collection
    {
        return $this->ads->filter(fn (ChannelAd $ad) => $ad->isLiveOn($localDay))->values();
    }

    /**
     * How many of these ads one pass plays.
     *
     * @param  Collection<int, ChannelAd>  $ads
     */
    public function adsPerPassOf(Collection $ads): int
    {
        return $this->ads_per_pass ? min($this->ads_per_pass, $ads->count()) : $ads->count();
    }

    /**
     * About how long one pass takes: the first pass's worth of these ads. When a pass
     * plays only some of them they rotate, so later passes differ by a few seconds —
     * which is why the panel says "about".
     *
     * @param  Collection<int, ChannelAd>  $ads
     */
    public function passSeconds(Collection $ads): int
    {
        return (int) $ads->take($this->adsPerPassOf($ads))->sum(fn (ChannelAd $ad) => $ad->play_seconds);
    }
}
