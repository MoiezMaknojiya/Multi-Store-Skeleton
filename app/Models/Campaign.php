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
 * Deliberately NOT a `media` row. That table is the organization's library: `organization_id` is
 * not nullable, every upload is stamped with an organization, and `Media::visibleTo` scopes
 * on that organization alone. A network ad belongs to no organization, so putting it there would
 * mean a nullable owner or a fake organization — a hole in the wall the whole app rests
 * on, in exchange for saving one table. Only the upload plumbing is shared.
 *
 * A campaign has no organization and therefore no `visibleTo`: nobody working inside an organization can see or reach one.
 * It sits behind the hand-written `campaign-manage` gate — the super admin's alone, never a permission row
 * (owner's decision).
 */
class Campaign extends Model
{
    use HasClockTimes, HasFactory;

    /**
     * How often a television breaks for advertising, in seconds, counted from the
     * moment the player started — not from the clock. The owner's choice: a set
     * switched on at 10:40 breaks at 11:40, and two organizations that booted at different
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

    /**
     * The longest one advert runs, a picture's seconds or a video's own length (owner's rule, 2026-09-28): one
     * whole break. A longer one could only ever play alone and hold the break past its ceiling (trimToBreak lets
     * the first advert through whatever its length), and 15 seconds is what the industry sells.
     */
    public const MAX_AD_SECONDS = self::MAX_BREAK_SECONDS;

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
     * Campaigns switched on whose contract could reach this moment on a screen's clock: live on its date, or on the day
     * before, whose hours may run past midnight into this morning — the night of the 15th, 22:00 to 02:00, still plays at
     * one on the 16th. Which of them is due is isDueAt()'s, per screen: the hours are wall clock.
     */
    public function scopeLiveAround(Builder $query, CarbonInterface $localMoment): Builder
    {
        $today = CarbonImmutable::instance($localMoment)->toDateString();
        $yesterday = CarbonImmutable::instance($localMoment)->subDay()->toDateString();

        // whereDate, not a plain comparison: the `date` cast writes these back as
        // "2026-03-20 00:00:00", and comparing that string against "2026-03-20" puts
        // the campaign's own first day outside its own contract.
        return $query
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_on')->orWhereDate('starts_on', '<=', $today))
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $yesterday));
    }

    /**
     * Is this campaign due at this moment, on this screen's clock?
     *
     * Its hours first — none means all day — and then its contract, read against the day those hours OPENED on, as a
     * playlist line's schedule is (ScheduleRule::coversAt): a campaign of the nights of the 10th to the 15th, 22:00 to
     * 02:00, plays until two on the morning of the 16th, and not at one on the morning of the 10th, which is the night
     * of the 9th (the review of 2026-10-15: it was cut on its last night and spilled into the night before its first).
     */
    public function isDueAt(CarbonInterface $localMoment): bool
    {
        $day = $this->dayTheHoursOpenedOn(CarbonImmutable::instance($localMoment));

        if ($day === null || ! $this->is_active) {
            return false;
        }

        $date = $day->toDateString();

        return ($this->starts_on === null || $this->starts_on->toDateString() <= $date)
            && ($this->ends_on === null || $this->ends_on->toDateString() >= $date);
    }

    /**
     * Which day the hours open at this moment began on, or null when the clock is outside them. An ordinary window wants
     * the time inside both ends; one that crosses midnight — 22:00 to 02:00 — opened today once past its start, and
     * yesterday while before its end.
     */
    private function dayTheHoursOpenedOn(CarbonImmutable $at): ?CarbonImmutable
    {
        if (! $this->start_time || ! $this->end_time) {
            return $at->startOfDay();
        }

        $time = $at->format('H:i');

        if ($this->end_time > $this->start_time) {
            return $time >= $this->start_time && $time < $this->end_time ? $at->startOfDay() : null;
        }

        if ($time >= $this->start_time) {
            return $at->startOfDay();
        }

        return $time < $this->end_time ? $at->subDay()->startOfDay() : null;
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
        // A picture saved before the ten-second rule plays for ten, and one saved before the one-break ceiling
        // (2026-09-28) for sixty: never under the least, never past a break.
        return $this->type === Media::TYPE_VIDEO
            ? ($this->media_duration_seconds ?: $this->duration_seconds)
            : min(self::MAX_AD_SECONDS, PlaylistItem::secondsForAPicture($this->duration_seconds));
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
     * A cache key for the player, not a content digest — the same idea as Media's: a replaced file is a new
     * file under a new name, so the key is the file itself, and a campaign renamed or given new dates sends no
     * television to download its advert again.
     */
    public function cacheKey(): string
    {
        return substr(hash('sha256', 'c'.$this->id.'|'.$this->size.'|'.$this->path), 0, 20);
    }
}
