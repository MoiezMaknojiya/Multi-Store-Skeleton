<?php

namespace App\Models;

use App\Services\MediaStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A picture or a video uploaded for use INSIDE an ad (docs/AD-BUILDER-SPEC.md §3).
 *
 * The Builder keeps its own shelf rather than borrowing the organization's media library (owner's decision,
 * 2026-09-17): the library is what an organization PLAYS, while this is raw material — a logo, a texture, a
 * background loop — that only means anything inside a design. Same organization wall, same disk, its own folder
 * (`builder/{organization}/assets/…`).
 *
 * An asset with no organization (`organization_id` NULL, `builder/platform/assets/…`) is the platform's, shared with every organization
 * (owner, 2026-09-29): every organization's designs may use it, it counts to no organization's 512 MB, and it is deleted above the
 * organizations alone, with Delete Ads (owner, 2026-10-01: an organization sees and uses it, "srif delete nahi kar sakta ha") — from
 * every organization's shelf at once, and never while any organization's ad, or the platform's, uses it.
 *
 * @property int|null $organization_id
 */
class BuilderAsset extends Model
{
    use HasFactory;

    public const KIND_IMAGE = 'image';

    public const KIND_VIDEO = 'video';

    /**
     * The longest video on the shelf (owner's rule, 2026-09-28: "max 30 seconds"). A video in a design repeats for
     * as long as the ad is on screen, as a background layer or on the stage alike — one stretched over the whole
     * stage does a background's job, so both are held to it — and a short loop is all a design needs, and a light
     * file for every television. The library and a channel keep five minutes (Media::MAX_VIDEO_SECONDS). The shelf's
     * uploader reads this very constant (builder/assets.blade.php, :max-video-seconds), so the browser needs no copy.
     */
    public const MAX_VIDEO_SECONDS = 30;

    protected $fillable = [
        'organization_id', 'title', 'kind', 'mime_type', 'disk', 'path', 'thumbnail_path',
        'size', 'width', 'height', 'duration_seconds', 'created_by',
    ];

    protected $appends = ['url', 'thumbnail_url'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_seconds' => 'integer',
        ];
    }

    /** The row goes inside the transaction, its files only once that has committed. */
    protected static function booted(): void
    {
        static::deleting(function (BuilderAsset $asset) {
            $files = [$asset->disk, $asset->path, $asset->thumbnail_path];

            DB::afterCommit(fn () => app(MediaStorage::class)->deleteFiles(...$files));
        });
    }

    /**
     * The shelf's row for a file MediaStorage::storeBuilderAsset() has just put on disk — the one way an
     * asset row is made, from an upload and from `builder:examples` alike.
     *
     * @param  array<string, mixed>  $stored  what storeBuilderAsset() returned
     */
    public static function fromStoredFile(?int $organizationId, string $title, array $stored, ?int $createdBy): self
    {
        return static::create([
            'organization_id' => $organizationId,
            'title' => $title,
            'kind' => $stored['type'] === Media::TYPE_VIDEO ? self::KIND_VIDEO : self::KIND_IMAGE,
            'mime_type' => $stored['mime_type'],
            'disk' => $stored['disk'],
            'path' => $stored['path'],
            'thumbnail_path' => $stored['thumbnail_path'],
            'size' => $stored['size'],
            'width' => $stored['width'],
            'height' => $stored['height'],
            'duration_seconds' => $stored['duration_seconds'],
            'created_by' => $createdBy,
        ]);
    }

    /**
     * Above the organizations every organization's and the shared ones; inside an organization its own and the shared ones; with no organization
     * selected, none. What may be DONE to one is the controller's to ask (a shared one is deleted with its own
     * permission).
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->globalRole() !== null) {
            return $query;
        }

        $organizationId = (int) session('current_organization_id');

        return $organizationId > 0 ? $query->onShelfOf($organizationId) : $query->whereRaw('0 = 1');
    }

    /**
     * What an organization's designs may use: its own and what the platform shares with every organization. An ad shared with every
     * organization ($organizationId null) uses the shared files alone: an organization's own file would show in no other organization's copy.
     */
    public function scopeOnShelfOf(Builder $query, ?int $organizationId): Builder
    {
        return $organizationId === null
            ? $query->whereNull('organization_id')
            : $query->where(fn (Builder $query) => $query->where('organization_id', $organizationId)->orWhereNull('organization_id'));
    }

    /** The platform's, shared with every organization — no organization's own. */
    public function isShared(): bool
    {
        return $this->organization_id === null;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->thumbnail_path ? Storage::disk($this->disk)->url($this->thumbnail_path) : null;
    }

    public function isVideo(): bool
    {
        return $this->kind === self::KIND_VIDEO;
    }
}
