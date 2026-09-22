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
 * The Builder keeps its own shelf rather than borrowing the store's media library (owner's decision,
 * 2026-09-17): the library is what a shop PLAYS, while this is raw material — a logo, a texture, a
 * background loop — that only means anything inside a design. Same store wall, same disk, its own folder
 * (`builder/{store}/assets/…`).
 */
class BuilderAsset extends Model
{
    use HasFactory;

    public const KIND_IMAGE = 'image';

    public const KIND_VIDEO = 'video';

    protected $fillable = [
        'store_id', 'title', 'kind', 'mime_type', 'disk', 'path', 'thumbnail_path',
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
    public static function fromStoredFile(int $storeId, string $title, array $stored, ?int $createdBy): self
    {
        return static::create([
            'store_id' => $storeId,
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

    /** Above the stores every store's; inside a store its own; with no store selected, none. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->globalRole() !== null) {
            return $query;
        }

        $storeId = (int) session('current_store_id');

        return $storeId > 0 ? $query->where('store_id', $storeId) : $query->whereRaw('0 = 1');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
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
