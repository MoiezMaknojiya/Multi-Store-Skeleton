<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlaylistItem extends Model
{
    /**
     * The shortest a picture stays on screen — and an Ad Builder ad (BuilderAd::MIN_SECONDS) — owner's rule,
     * 2026-09-28: "6 seconds minimum … us se kam nahi kar paye". Six seconds is the industry's shortest spot
     * (YouTube's bumper ad) and the least a roadside screen must hold one in much of the United States; anything
     * shorter is a flicker nobody can read. A video is never held to it: it runs to its own end.
     */
    public const MIN_IMAGE_SECONDS = 6;

    /** What an image shows for when nothing else says otherwise (owner's rule, 2026-09-28: six, like the least). */
    public const DEFAULT_IMAGE_SECONDS = 6;

    /**
     * The longest one picture holds a screen, on a playlist and in a channel alike (ChannelAd::MAX_IMAGE_SECONDS) — owner,
     * 2026-10-14: "5 minute (300 s), baaki app jaisa: ek hi qanoon har jagah, aur galti ka number ruk jayega". A playlist
     * took a whole day before, so 3600 typed for 36 kept every other line off that screen all day; a picture shown all
     * day is a playlist of that picture alone, and the hours it shows are its schedule's.
     */
    public const MAX_IMAGE_SECONDS = 300;

    /**
     * The most seconds a save may send for any file line: a bound on the number alone. Only a picture — and an Ad
     * Builder page with no length of its own, timed like one — is held to MAX_IMAGE_SECONDS (PlaylistController::
     * assertPicturesKeepTheirSeconds): a video's line keeps its file's length whatever is sent, and a video uploaded
     * before videos were held to five minutes is longer.
     */
    public const MAX_SENT_SECONDS = 86400;

    protected $fillable = ['screen_id', 'media_id', 'channel_id', 'position', 'duration_seconds'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'duration_seconds' => 'integer',
        ];
    }

    /**
     * How long a picture is on screen for the seconds it was given: the default when it says none, never under the
     * least and never over the most — a line, a channel ad or an advert saved before either rule keeps its place and
     * simply plays for it (a playlist's picture of 86400, saved before 2026-10-14, plays for five minutes).
     */
    public static function secondsForAPicture(?int $seconds): int
    {
        return min(self::MAX_IMAGE_SECONDS, max(self::MIN_IMAGE_SECONDS, $seconds ?: self::DEFAULT_IMAGE_SECONDS));
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
