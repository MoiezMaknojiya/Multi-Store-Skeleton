<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    use HasFactory;

    /** Laravel would guess "medias"; the table is the natural plural. */
    protected $table = 'media';

    public const TYPE_IMAGE = 'image';

    public const TYPE_VIDEO = 'video';

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
     * its uploader leaves. The platform team spans every store. With no store selected a
     * store member sees nothing.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin() || $user->globalRole()) {
            return $query;
        }

        $currentStoreId = session('current_store_id');

        if (! $currentStoreId) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where('store_id', $currentStoreId);
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
     * Is this file inside its own schedule window?
     *
     * Takes an optional moment so the schedule resolver can ask about ONE instant
     * for the whole manifest — a file expiring mid-loop must not be in and out of
     * the same answer. These are absolute timestamps, not wall-clock times, so no
     * screen timezone comes into it.
     */
    public function isPlayableNow(?CarbonInterface $at = null): bool
    {
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
