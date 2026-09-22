<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Screen extends Model
{
    use HasFactory;

    /** A TV mounted upside down to hide the cables is a real thing, so all four
     *  rotations are offered. The panel stays 1920x1080; the player rotates. */
    public const ORIENTATIONS = [
        'landscape' => 'Landscape',
        'landscape_flipped' => 'Landscape (flipped 180°)',
        'portrait' => 'Portrait (rotated 90°)',
        'portrait_flipped' => 'Portrait (rotated 270°)',
    ];

    /** A screen that has not checked in for this long is shown as offline.
     *  The player beats every 60s, so three minutes tolerates two missed beats. */
    public const OFFLINE_AFTER_MINUTES = 3;

    /** What a newly paired television keeps until the shop says otherwise. An IANA
     *  zone, so it follows the daylight-saving switch on its own. */
    public const DEFAULT_TIMEZONE = 'America/Chicago';

    protected $fillable = [
        'store_id', 'name', 'orientation', 'timezone', 'default_media_id',
        'accepts_network_ads', 'token_hash', 'device_uuid',
        'paired_at', 'paired_by', 'last_seen_at', 'created_by',
    ];

    /** token_hash never leaves the server. */
    protected $hidden = ['token_hash'];

    protected $appends = ['is_online'];

    protected function casts(): array
    {
        return [
            'paired_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'accepts_network_ads' => 'boolean',
        ];
    }

    /**
     * Screens are scoped to the STORE, like media: a screen is shop equipment, so everyone working in that
     * shop manages it. Global users and super admins span every store; with no store selected there is nothing.
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

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** The ordered list this screen plays. */
    public function playlistItems(): HasMany
    {
        return $this->hasMany(PlaylistItem::class)->orderBy('position');
    }

    /** The network advertising campaigns pointed at this screen. */
    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(Campaign::class, 'campaign_screen');
    }

    /** What plays when nothing on the playlist is due. */
    public function defaultMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'default_media_id');
    }

    /**
     * The moment, on this screen's own clock face.
     *
     * Every schedule rule is wall clock — "Fridays, 11:00 to 15:00" means eleven in
     * the morning where this television is standing — so this is what they are all
     * read against, never the server's timezone.
     */
    public function localTime(?CarbonInterface $at = null): CarbonImmutable
    {
        return CarbonImmutable::instance($at ?? now())
            ->setTimezone($this->timezone ?: self::DEFAULT_TIMEZONE);
    }

    /**
     * A fingerprint of what this screen's playlist currently is.
     *
     * The panel is handed this when it loads a playlist and sends it back when it
     * saves, so the server can tell a fresh edit from one built on a stale copy.
     * A save replaces the WHOLE list, so without it a colleague's additions are
     * wiped with nothing to show for it.
     *
     * Content-based on purpose, not a timestamp or a counter: saving a list that
     * happens to be identical is not a conflict, because nothing was lost. Only a
     * genuine difference produces a different value.
     *
     * It lives here rather than in the controller so tests measure the same thing
     * the API does, instead of a copy of the formula that can drift away from it.
     * Not to be confused with DeviceController's manifest version, which is a
     * different fingerprint for a different reader — that one covers orientation
     * and only the items currently inside their schedule window.
     */
    public function playlistFingerprint(): string
    {
        $rows = $this->playlistItems()
            ->with('scheduleRules')
            ->get()
            ->map(fn (PlaylistItem $item) => implode(':', [
                // A line is a file or a channel. The prefix keeps channel 7 from reading
                // the same as file 7, while a file line fingerprints exactly as before.
                $item->channel_id !== null ? 'ch'.$item->channel_id : $item->media_id,
                $item->position,
                $item->duration_seconds,
                // The schedule travels with the save, so it has to be part of what a
                // stale copy is measured against — otherwise two people editing the
                // same item's hours would overwrite each other with no 409 and no
                // sign that anything was lost.
                $item->scheduleRules->map(fn (ScheduleRule $rule) => $rule->fingerprint())->implode(';'),
            ]))
            ->implode('|');

        return substr(hash('sha256', $this->id.'#'.$rows), 0, 16);
    }

    /** Heard from recently enough to call it alive. */
    public function getIsOnlineAttribute(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subMinutes(self::OFFLINE_AFTER_MINUTES));
    }

    /** True once a device has collected its token. */
    public function isPaired(): bool
    {
        return $this->token_hash !== null;
    }
}
