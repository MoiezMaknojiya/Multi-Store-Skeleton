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
 * One advert designed in the Ad Builder (docs/AD-BUILDER-SPEC.md): a stage the shape of a television —
 * 1920×1080, or 1080×1920 for a screen mounted upright (§12) — with layered backgrounds and absolutely
 * positioned text, pictures and video, each able to move on its own.
 *
 * The `document` is the design — what the editor reads and writes. Publishing compiles it into a
 * self-contained HTML file and writes a `media` row of type `html`, which is the only thing playlists,
 * schedules, the device manifest and the player ever see. That is why an ad always belongs to a store:
 * the media row it becomes cannot be store-less either.
 */
class BuilderAd extends Model
{
    use HasFactory;

    /** A television's frame the usual way round. Not a setting: a screen is 1920×1080 (owner's rule). */
    public const STAGE_WIDTH = 1920;

    public const STAGE_HEIGHT = 1080;

    /**
     * The two ways a screen can be mounted, which are the two shapes an ad can be (owner, 2026-09-23 —
     * docs/AD-BUILDER-SPEC.md §12). Chosen when the ad is made and never changed after: every element's box
     * is in stage pixels, so a design for the other shape is another design.
     */
    public const LANDSCAPE = 'landscape';

    public const PORTRAIT = 'portrait';

    /** Each orientation's label, in the order the chooser offers them. */
    public const ORIENTATIONS = [
        self::LANDSCAPE => 'Landscape',
        self::PORTRAIT => 'Portrait',
    ];

    /** The stage each orientation designs on: the panel's own pixels, turned with it. */
    public const STAGE_SIZES = [
        self::LANDSCAPE => [self::STAGE_WIDTH, self::STAGE_HEIGHT],
        self::PORTRAIT => [self::STAGE_HEIGHT, self::STAGE_WIDTH],
    ];

    protected $fillable = [
        'store_id', 'name', 'orientation', 'document', 'thumbnail_path', 'media_id', 'published_at',
        'in_playlists', 'created_by', 'updated_by',
    ];

    /** A row made before the column existed, or a model not yet saved, is landscape. */
    protected $attributes = [
        'orientation' => self::LANDSCAPE,
    ];

    protected $appends = ['thumbnail_url'];

    protected function casts(): array
    {
        return [
            'document' => 'array',
            'published_at' => 'datetime',
            'published_document' => 'array',
            // May a shop's own playlist play this ad, or is it for channels only (owner's rule, 2026-09-22)?
            // Off until somebody ticks it, so an ad written for a channel cannot also be added to the
            // playlist that carries that channel and play twice in one pass.
            'in_playlists' => 'boolean',
        ];
    }

    /**
     * Deleting an ad takes the copy a playlist plays with it — the media row (and with it, through the
     * foreign key, every playlist line carrying it) and the files both name. The rows go inside the
     * transaction; the files only once it has committed, so a rolled-back delete never leaves a media
     * row pointing at a file that is gone (the rule the store purge follows).
     */
    protected static function booted(): void
    {
        static::deleting(function (BuilderAd $ad) {
            $media = $ad->media;
            $disk = $media?->disk ?? 'public';
            $page = [$media?->path, $media?->thumbnail_path];
            $poster = $ad->thumbnail_path;

            $media?->delete();

            DB::afterCommit(function () use ($disk, $page, $poster) {
                $storage = app(MediaStorage::class);
                $storage->deleteFiles($disk, ...$page);
                $storage->deleteFiles($disk, $poster);
            });
        });
    }

    /**
     * The ads a person manages from where they stand: above the stores, every store's (each row saying
     * whose it is); inside a store, that store's own and no other. With no store selected, none at all.
     */
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

    /** The published copy a playlist points at — NULL while the ad has never been published. */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The poster's address. The file keeps one name for the life of the ad (so its media row can point at
     * it), and every save may redraw it — so the address carries the ad's last change, and no browser
     * cache shows yesterday's picture.
     */
    public function getThumbnailUrlAttribute(): ?string
    {
        if (! $this->thumbnail_path) {
            return null;
        }

        return Storage::disk('public')->url($this->thumbnail_path).'?v='.($this->updated_at?->getTimestamp() ?? 0);
    }

    /**
     * On the screens? Published, and not taken off them since (Unpublish clears `published_at`).
     *
     * The draft/publish model is the industry's (owner, 2026-09-21 — Xibo, Contentful, Strapi): changing a
     * published ad does NOT take it off the screens. They keep playing the published version, and nobody sees
     * the changes until they are published (hasUnpublishedChanges()). While this is false the page is nobody's:
     * no screen, channel, picker or library shows it (Media::isDraft(), Media::scopeWithoutDrafts()).
     */
    public function isPublished(): bool
    {
        return $this->media_id !== null && $this->published_at !== null;
    }

    /**
     * Published, with saved changes the screens do not show yet — Contentful's "Changed", Strapi's "Modified".
     * Compared with the version Publish kept; an ad published before versions were kept, and changed since,
     * has none, so its clock says it.
     */
    public function hasUnpublishedChanges(): bool
    {
        if (! $this->isPublished()) {
            return false;
        }

        if ($this->published_document === null) {
            return $this->updated_at !== null && $this->updated_at->gt($this->published_at);
        }

        return $this->wouldChangeWith($this->published_name ?? $this->name, $this->published_document);
    }

    /** On the screens, with the version they show kept (every publish since 2026-09-21 keeps it)? */
    public function hasPublishedVersion(): bool
    {
        return $this->isPublished() && $this->published_document !== null;
    }

    /** Is there a published version to go back to, and anything saved to go back from? */
    public function canDiscardChanges(): bool
    {
        return $this->hasPublishedVersion() && $this->hasUnpublishedChanges();
    }

    /** draft | published | changed — what the listing and the editor say about it. */
    public function status(): string
    {
        return match (true) {
            ! $this->isPublished() => 'draft',
            $this->hasUnpublishedChanges() => 'changed',
            default => 'published',
        };
    }

    /**
     * Would saving this name and design change the ad? Compared as content, never as JSON text: the same design
     * can arrive with its keys in another order (validation rebuilds nested data rule by rule), a number written
     * as 1 where it was stored as 1.0, or an empty setting left out that was stored empty — and a save that
     * changes nothing must not mark a published ad as changed, nor a design as differing from what is on the
     * screens.
     *
     * @param  array<string, mixed>  $document
     */
    public function wouldChangeWith(string $name, array $document): bool
    {
        return $name !== $this->name || self::asContent($document) !== self::asContent($this->document ?? []);
    }

    /**
     * The value as content: every number a float, every map's keys sorted and its empty settings (null, or an
     * empty list or map) dropped — validation leaves those out, so "none" and "left out" read the same. A list
     * keeps its order, which is the design's.
     */
    private static function asContent(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::asContent(...), $value);

        if (array_is_list($value)) {
            return $value;
        }

        $value = array_filter($value, fn (mixed $setting) => $setting !== null && $setting !== []);
        ksort($value);

        return $value;
    }

    /** Where this ad's published file and poster live. */
    public function storageDirectory(): string
    {
        return "builder/{$this->store_id}/ads/{$this->id}";
    }

    /** Mounted upright: a 1080 × 1920 stage. */
    public function isPortrait(): bool
    {
        return $this->orientation === self::PORTRAIT;
    }

    /** The stage's width in pixels, from the ad's orientation — never from the document, which only mirrors it. */
    public function stageWidth(): int
    {
        return self::stageSize($this->orientation)[0];
    }

    public function stageHeight(): int
    {
        return self::stageSize($this->orientation)[1];
    }

    /**
     * [width, height] for an orientation; anything that is not one reads as landscape, the way every ad
     * was before orientations existed.
     *
     * @return array{0: int, 1: int}
     */
    public static function stageSize(?string $orientation): array
    {
        return self::STAGE_SIZES[$orientation] ?? self::STAGE_SIZES[self::LANDSCAPE];
    }

    /** An empty stage of the given shape: a dark background and nothing on it. */
    public static function blankDocument(string $orientation = self::LANDSCAPE): array
    {
        [$width, $height] = self::stageSize($orientation);

        return [
            'version' => 1,
            'stage' => [
                'width' => $width,
                'height' => $height,
                'background' => ['color' => '#0f172a', 'layers' => []],
            ],
            'elements' => [],
        ];
    }
}
