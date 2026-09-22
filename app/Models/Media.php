<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A file in a media library. A library belongs to a shop (`store_id` set) — its inventory, which every
 * colleague may put on the shop's screens — or to the platform (`store_id` NULL, docs/CHANNEL-CONTENT-SPEC.md),
 * whose files reach a television only inside a channel. A shop never sees or plays the platform's rows, and
 * nothing here ever crosses from one shop to another.
 */
class Media extends Model
{
    use HasFactory;

    /** Laravel would guess "medias"; the table is the natural plural. */
    protected $table = 'media';

    public const TYPE_IMAGE = 'image';

    public const TYPE_VIDEO = 'video';

    /**
     * A page, not a file somebody uploaded: an advert designed in the Ad Builder and published into the
     * library (docs/AD-BUILDER-SPEC.md §9). It behaves like an image everywhere it matters — the playlist
     * line says how long it stays up, the schedule rules work the same — and only the player treats it
     * differently, by showing it in a frame of its own so its animations can run.
     */
    public const TYPE_HTML = 'html';

    protected $fillable = [
        'store_id', 'title', 'description', 'type', 'mime_type', 'disk', 'path',
        'thumbnail_path', 'size', 'width', 'height', 'duration_seconds',
        'orientation', 'starts_at', 'expires_at', 'created_by',
    ];

    protected $appends = ['url', 'thumbnail_url'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_seconds' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Media is scoped to the STORE, not to whoever uploaded it: a file is the store's
     * inventory, so everyone working in the store can put it on a screen, and it stays when
     * its uploader leaves. The platform team spans every store and its own library. With no
     * store selected a store member sees nothing — and a store member never matches the
     * platform's rows, whose `store_id` is NULL.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->globalRole() !== null) {
            return $query;
        }

        $currentStoreId = session('current_store_id');

        if (! $currentStoreId) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where('store_id', $currentStoreId);
    }

    /** The platform's own library: files that belong to no shop. */
    public function scopePlatformOwned(Builder $query): Builder
    {
        return $query->whereNull('store_id');
    }

    /**
     * Everything but an Ad Builder page taken off the screens (see isDraft()) — the files anybody may see in a
     * library or pick for a screen or a channel. Mirrors BuilderAd::isPublished() in SQL.
     */
    public function scopeWithoutDrafts(Builder $query): Builder
    {
        return $query->whereNotExists(fn (QueryBuilder $design) => $design->selectRaw('1')
            ->from('builder_ads')
            ->whereColumn('builder_ads.media_id', 'media.id')
            ->whereNull('builder_ads.published_at'));
    }

    /**
     * An Ad Builder page whose ad is a draft — taken off the screens with Unpublish (owner, 2026-09-21): nobody
     * sees it — no screen, no channel, no picker, not the library — until the ad is published again. The row
     * stays, so every playlist line and channel ad holding it plays it again from then on. A published ad that is
     * merely CHANGED is not one: the screens keep this page, its published version, until the changes are
     * published (BuilderAd::hasUnpublishedChanges()).
     */
    public function isDraft(): bool
    {
        return $this->type === self::TYPE_HTML
            && $this->builderAd !== null
            && ! $this->builderAd->isPublished();
    }

    /** The Ad Builder design this page was published from; null for every other file. */
    public function builderAd(): HasOne
    {
        return $this->hasOne(BuilderAd::class);
    }

    /** Does this file belong to the platform rather than to a shop? */
    public function isPlatformOwned(): bool
    {
        return $this->store_id === null;
    }

    /** The shop whose library this is; null for the platform's. */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** The channel ads that show this file. */
    public function channelAds(): HasMany
    {
        return $this->hasMany(ChannelAd::class);
    }

    /**
     * Why this file may not be deleted yet, or null when it may (owner, 2026-09-19: "pehle channel se
     * hatao"): a channel showing it would lose the ad without anybody having decided so. A screen's playlist
     * is different on purpose — the file simply leaves it — so only channels are counted here.
     */
    public function stillInAChannelMessage(): ?string
    {
        return self::inChannelsMessage(
            Channel::whereIn('id', $this->channelAds()->select('channel_id'))->orderBy('name')->pluck('name')
        );
    }

    /**
     * The same refusal for a whole page of files at once, keyed by id — one query, so a listing can carry it
     * and the panel can say it before anybody confirms a delete. Files no channel shows are left out.
     *
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    public static function stillInChannelsMessages(array $ids): array
    {
        return DB::table('channel_ads')
            ->join('channels', 'channels.id', '=', 'channel_ads.channel_id')
            ->whereIn('channel_ads.media_id', $ids)
            ->distinct()
            ->orderBy('channels.name')
            ->get(['channel_ads.media_id', 'channels.id', 'channels.name'])
            ->groupBy('media_id')
            ->map(fn (Collection $rows) => self::inChannelsMessage($rows->pluck('name')))
            ->all();
    }

    /** @param  Collection<int, string>  $names  the channels' names, in order */
    private static function inChannelsMessage(Collection $names): ?string
    {
        if ($names->isEmpty()) {
            return null;
        }

        $listed = $names->take(3)->join(', ').($names->count() > 3 ? ' and '.($names->count() - 3).' more' : '');

        return $names->count() === 1
            ? "Still used by the channel {$listed}. Take it out of that channel first."
            : "Still used by the channels {$listed}. Take it out of those channels first.";
    }

    /**
     * The file's address. A published ad's page is rewritten in place each time it is published, so — like
     * its poster — its address carries the version: a television told about a new page must not be handed
     * the old one from a cache.
     */
    public function getUrlAttribute(): string
    {
        $url = Storage::disk($this->disk)->url($this->path);

        return $this->type === self::TYPE_HTML
            ? $url.'?v='.($this->updated_at?->getTimestamp() ?? 0)
            : $url;
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        if (! $this->thumbnail_path) {
            return null;
        }

        $url = Storage::disk($this->disk)->url($this->thumbnail_path);

        // A published ad's poster is redrawn in place each time it is published, so its address carries
        // the version; every other thumbnail has a name of its own for the life of the file.
        return $this->type === self::TYPE_HTML
            ? $url.'?v='.($this->updated_at?->getTimestamp() ?? 0)
            : $url;
    }

    /**
     * May a television show this file now — inside its own schedule window, and not an Ad Builder page taken
     * off the screens (isDraft())?
     *
     * Takes an optional moment so the schedule resolver can ask about ONE instant
     * for the whole manifest — a file expiring mid-loop must not be in and out of
     * the same answer. These are absolute timestamps, not wall-clock times, so no
     * screen timezone comes into it.
     */
    public function isPlayableNow(?CarbonInterface $at = null): bool
    {
        if ($this->isDraft()) {
            return false;
        }

        $at ??= now();

        $started = $this->starts_at === null || $this->starts_at->lte($at);
        $ended = $this->expires_at !== null && $this->expires_at->lte($at);

        return $started && ! $ended;
    }

    /**
     * A cache key for the player, not a content digest: it changes whenever the
     * bytes behind this row could have changed (a replaced file bumps size and
     * updated_at), which is exactly what a caching shell needs to decide whether
     * to re-download. Cheap enough to compute on every manifest request.
     */
    public function cacheKey(): string
    {
        return substr(hash('sha256', $this->id.'|'.$this->size.'|'.$this->updated_at?->timestamp), 0, 20);
    }
}
