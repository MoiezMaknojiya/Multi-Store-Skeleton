<?php

namespace App\Models;

use App\Models\Concerns\HasClockTimes;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

/**
 * A network advertisement — the platform's own content, sold to a brand.
 *
 * Deliberately NOT a `media` row. That table is the store's library: `store_id` is
 * not nullable, every upload is stamped with a store, and `Media::visibleTo` scopes
 * on that store alone. A network ad belongs to no shop, so putting it there would
 * mean a nullable owner or a fake store — a hole in the wall the whole app rests
 * on, in exchange for saving one table. Only the upload plumbing is shared.
 *
 * A campaign has no store and therefore no `visibleTo`: nobody working inside a shop can see or reach one.
 * It sits behind the hand-written `campaign-manage` gate — the super admin's alone, never a permission row
 * (owner's decision).
 */
class Campaign extends Model
{
    use HasClockTimes, HasFactory;

    /**
     * How often a television breaks for advertising, in seconds, counted from the
     * moment the player started — not from the clock. The owner's choice: a set
     * switched on at 10:40 breaks at 11:40, and two shops that booted at different
     * times do not all cut to the same advert at once.
     */
    public static function breakEverySeconds(): int
    {
        return max(1, (int) config('signage.ad_break_seconds', 3600));
    }

    /**
     * The longest one break may run. Several brands share one break rather than
     * interrupting separately: every pause and resume of a long video is a SEEK, the
     * single most fragile thing a cheap television's browser does, and three
     * interruptions an hour is three times the risk of one.
     *
     * Anything sold beyond this simply does not fit, and the panel says so rather
     * than quietly dropping it.
     */
    public const MAX_BREAK_SECONDS = 60;

    protected $fillable = [
        'name', 'advertiser_name', 'type', 'mime_type', 'disk', 'path', 'thumbnail_path',
        'size', 'width', 'height', 'media_duration_seconds', 'duration_seconds',
        'starts_on', 'ends_on', 'start_time', 'end_time', 'is_active', 'created_by',
    ];

    protected $appends = ['url', 'thumbnail_url', 'play_seconds'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'media_duration_seconds' => 'integer',
            'duration_seconds' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /** The screens this campaign was pointed at. */
    public function screens(): BelongsToMany
    {
        return $this->belongsToMany(Screen::class, 'campaign_screen');
    }

    protected function startTime(): Attribute
    {
        return self::clockTime();
    }

    protected function endTime(): Attribute
    {
        return self::clockTime();
    }

    /**
     * Campaigns whose contract covers this date and which are switched on.
     *
     * The window inside a day is NOT applied here: it is wall clock, and has to be
     * read on each screen's own timezone. That happens per screen, in isDueAt().
     */
    public function scopeLiveOn(Builder $query, CarbonInterface $day): Builder
    {
        $date = CarbonImmutable::instance($day)->toDateString();

        // whereDate, not a plain comparison: the `date` cast writes these back as
        // "2026-03-20 00:00:00", and comparing that string against "2026-03-20" puts
        // the campaign's own first day outside its own contract.
        return $query
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_on')->orWhereDate('starts_on', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date));
    }

    /**
     * Is this campaign due at this moment, on this screen's clock?
     *
     * The dates were already checked by the query; this is the window inside a day.
     * No window means all day.
     *
     * The one expression covers both shapes: an ordinary window wants the time to be
     * inside BOTH ends, and one that crosses midnight — 22:00 to 02:00 — wants it
     * past the start OR before the end. Same rule a daypart uses, without the
     * per-weekday exceptions, which a network advert has no use for.
     */
    public function isDueAt(CarbonInterface $localMoment): bool
    {
        if (! $this->start_time || ! $this->end_time) {
            return true;
        }

        $time = CarbonImmutable::instance($localMoment)->format('H:i');

        return $this->end_time > $this->start_time
            ? ($time >= $this->start_time && $time < $this->end_time)
            : ($time >= $this->start_time || $time < $this->end_time);
    }

    /**
     * How long this advert occupies the break.
     *
     * A video runs to its own end, like a playlist item; an image needs to be told.
     * A video whose length was never measured falls back to the typed seconds, so a
     * break's length can always be worked out in advance.
     */
    public function getPlaySecondsAttribute(): int
    {
        return $this->type === Media::TYPE_VIDEO
            ? ($this->media_duration_seconds ?: $this->duration_seconds)
            : $this->duration_seconds;
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->thumbnail_path
            ? Storage::disk($this->disk)->url($this->thumbnail_path)
            : null;
    }

    /**
     * A cache key for the player, not a content digest — the same idea as Media's:
     * it changes whenever the bytes behind this row could have.
     */
    public function cacheKey(): string
    {
        return substr(hash('sha256', 'c'.$this->id.'|'.$this->size.'|'.$this->updated_at?->timestamp), 0, 20);
    }
}
