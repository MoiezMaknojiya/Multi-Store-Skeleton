<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ad inside a channel: WHICH file (a row of a media library, by id — docs/CHANNEL-CONTENT-SPEC.md), how
 * long it shows, and the dates it runs.
 *
 * The file belongs to a library — the channel's shop's, or the platform's — never to the channel: taking the
 * ad out leaves the file where it is, and a published Ad Builder ad re-published later is shown new here
 * without a second copy anywhere. Everything about the file (its kind, its address, a video's own length) is
 * read through the media row, under the names this model always had, so nothing that reads an ad changes.
 */
class ChannelAd extends Model
{
    use HasFactory;

    /**
     * The longest an IMAGE (or an ad page) may stay up in one go. A channel line sits inside a shop's own
     * loop, and one still holding the wall for ten minutes is the channel swallowing the shop.
     */
    public const MAX_IMAGE_SECONDS = 300;

    /**
     * How long a video is allowed when the browser could not measure it.
     *
     * A video runs to its own end — the player moves on at `ended` — so this is only
     * the backstop for a file that stalls. It has to be generous: too short, and a
     * perfectly good video is cut off part-way on every screen, which is far worse
     * than a rare stall waiting a little longer.
     */
    public const UNMEASURED_VIDEO_SECONDS = 120;

    protected $fillable = [
        'channel_id', 'media_id', 'title', 'duration_seconds', 'position', 'starts_on', 'ends_on', 'created_by',
    ];

    protected $appends = ['url', 'thumbnail_url', 'play_seconds'];

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'position' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** The library row this ad shows. */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /** Does this ad run on this local date? */
    public function isLiveOn(CarbonInterface $localDay): bool
    {
        return $this->statusOn($localDay) === 'running';
    }

    /**
     * draft | scheduled | running | ended, on one local date.
     *
     * A draft is an Ad Builder page whose ad was taken off the screens (Media::isDraft()): off the air, whatever
     * its dates say, until it is published again.
     *
     * Whole dates with both ends included, read on each SCREEN's own calendar — an ad
     * ending on the 15th runs all of the 15th wherever the television stands. Compared
     * as Y-m-d strings: the `date` cast hands the ends back as midnight Carbons, and
     * comparing those against a moment in the afternoon would end an ad at the very
     * start of its own last day.
     */
    public function statusOn(CarbonInterface $localDay): string
    {
        if ($this->media?->isDraft()) {
            return 'draft';
        }

        $date = CarbonImmutable::instance($localDay)->toDateString();

        if ($this->starts_on !== null && $this->starts_on->toDateString() > $date) {
            return 'scheduled';
        }

        if ($this->ends_on !== null && $this->ends_on->toDateString() < $date) {
            return 'ended';
        }

        return 'running';
    }

    /** image | video | html — the file's own kind. */
    public function getTypeAttribute(): ?string
    {
        return $this->media?->type;
    }

    public function getMimeTypeAttribute(): ?string
    {
        return $this->media?->mime_type;
    }

    public function getOrientationAttribute(): ?string
    {
        return $this->media?->orientation;
    }

    /**
     * How long this ad holds the screen: an image or an ad page for the seconds the channel gave it, a video
     * to its own end as measured at upload.
     */
    public function getPlaySecondsAttribute(): int
    {
        if ($this->type === Media::TYPE_VIDEO) {
            return $this->media?->duration_seconds ?: self::UNMEASURED_VIDEO_SECONDS;
        }

        return $this->duration_seconds ?: PlaylistItem::DEFAULT_IMAGE_SECONDS;
    }

    public function getUrlAttribute(): ?string
    {
        return $this->media?->url;
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->media?->thumbnail_url;
    }

    /**
     * The file's own cache key: an ad plays its library row's file, so the copy a screen keeps is that file's
     * — the very copy a playlist line or another channel showing the same file keeps, never a second one of
     * the same bytes. It moves when the file does (Media::cacheKey()); pointing the ad at another file moves
     * its address anyway.
     */
    public function cacheKey(): string
    {
        return $this->media?->cacheKey() ?? '';
    }
}
