<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlaylistItem extends Model
{
    /** What an image shows for when nothing else says otherwise. */
    public const DEFAULT_IMAGE_SECONDS = 10;

    protected $fillable = ['screen_id', 'media_id', 'channel_id', 'position', 'duration_seconds'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'duration_seconds' => 'integer',
        ];
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /**
     * The channel this line plays, when it is a channel line rather than a file.
     * Exactly one of the two is set — see PlaylistController.
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function isChannel(): bool
    {
        return $this->channel_id !== null;
    }

    /**
     * When this item is allowed to play. None at all means "whenever the screen is
     * on", which is what almost every item wants and therefore what costs nothing.
     */
    public function scheduleRules(): HasMany
    {
        return $this->hasMany(ScheduleRule::class)->orderBy('position');
    }

    /** True when any rule says yes — or when there are no rules to say no. */
    public function isDueAt(CarbonInterface $localMoment): bool
    {
        if ($this->scheduleRules->isEmpty()) {
            return true;
        }

        return $this->scheduleRules->contains(
            fn (ScheduleRule $rule) => $rule->coversAt($localMoment)
        );
    }
}
