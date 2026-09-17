<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * One ad inside a channel: a file, how long it shows, and the dates it runs.
 */
class ChannelAd extends Model
{
    use HasFactory;

    /**
     * The longest an IMAGE may stay up in one go. A channel line sits inside a shop's
     * own loop, and one still holding the wall for ten minutes is the channel
     * swallowing the shop.
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
        'channel_id', 'title', 'type', 'mime_type', 'disk', 'path', 'thumbnail_path',
        'size', 'width', 'height', 'orientation', 'media_duration_seconds',
        'duration_seconds', 'position', 'starts_on', 'ends_on', 'created_by',
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
            'position' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /** Does this ad run on this local date? */
    public function isLiveOn(CarbonInterface $localDay): bool
    {
        return $this->statusOn($localDay) === 'running';
    }

    /**
     * scheduled | running | ended, on one local date.
     *
     * Whole dates with both ends included, read on each SCREEN's own calendar — an ad
     * ending on the 15th runs all of the 15th wherever the television stands. Compared
     * as Y-m-d strings: the `date` cast hands the ends back as midnight Carbons, and
     * comparing those against a moment in the afternoon would end an ad at the very
     * start of its own last day.
     */
    public function statusOn(CarbonInterface $localDay): string
    {
        $date = CarbonImmutable::instance($localDay)->toDateString();

        if ($this->starts_on !== null && $this->starts_on->toDateString() > $date) {
            return 'scheduled';
        }

        if ($this->ends_on !== null && $this->ends_on->toDateString() < $date) {
            return 'ended';
        }

        return 'running';
    }

    /**
     * How long this ad holds the screen: an image for its seconds, a video to its own
     * end as measured at upload.
     */
    public function getPlaySecondsAttribute(): int
    {
        if ($this->type === Media::TYPE_VIDEO) {
            return $this->media_duration_seconds ?: self::UNMEASURED_VIDEO_SECONDS;
        }

        return $this->duration_seconds ?: PlaylistItem::DEFAULT_IMAGE_SECONDS;
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

    /** A cache key for the player, the same idea as Media's: it changes whenever the
     *  bytes behind this row could have. */
    public function cacheKey(): string
    {
        return substr(hash('sha256', 'h'.$this->id.'|'.$this->size.'|'.$this->updated_at?->timestamp), 0, 20);
    }
}
