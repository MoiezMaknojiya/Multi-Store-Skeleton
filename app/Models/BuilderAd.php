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
 * self-contained HTML file and writes a `media` row of type `html` into the ad's library, which is the only
 * thing playlists, schedules, the device manifest and the player ever see.
 *
 * An ad belongs to an organization, or — with no organization, `organization_id` NULL — to the platform, made for every organization (owner,
 * 2026-10-01: "all shop k liya ads ... woo ads sub ko dikhe aur woo copy kar sake"). A shared ad uses the files the
 * platform shares (BuilderAsset::onShelfOf), lives under `builder/platform/ads/`, publishes into the platform's own
 * library — so only the platform's channels play it — and every organization sees it once it is published and copies it into
 * its own Ads. Only above the organizations is it changed or deleted (owner, 2026-10-01: an organization's people see, use and copy
 * it, "srif delete nahi kar sakta ha"), with Update Ads and Delete Ads there.
 *
 * @property int|null $organization_id
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

    /**
     * How long an ad is on screen, as its design says (`document.duration`, owner 2026-09-28: "8 seconds ki ad aur
     * background 20 seconds, toh 8 seconds k bad change honi chahiye"). The industry's way — Xibo's layout
     * duration, Canva's page duration: every playlist and channel plays the ad this long, a video inside it
     * (its background's or one on its stage) repeats when it is shorter and is cut when the ad ends. Published
     * with the page, as the media row's duration_seconds (Media::ownLength()); a design with none says six, a
     * picture's default (owner's rule, 2026-09-28).
     */
    public const DEFAULT_SECONDS = PlaylistItem::DEFAULT_IMAGE_SECONDS;

    /** The shortest an ad may be: the six seconds a picture is held to (PlaylistItem::MIN_IMAGE_SECONDS). */
    public const MIN_SECONDS = PlaylistItem::MIN_IMAGE_SECONDS;

    /** The longest an ad may be — as long as a picture may hold a channel's screen (ChannelAd::MAX_IMAGE_SECONDS). */
    public const MAX_SECONDS = 300;

    protected $fillable = [
        'organization_id', 'name', 'orientation', 'document', 'thumbnail_path', 'media_id', 'published_at',
        'created_by', 'updated_by',
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
        ];
    }

    /**
     * Deleting an ad takes the copy a playlist plays with it — the media row (and with it, through the
     * foreign key, every playlist line carrying it) and the files both name. The rows go inside the
     * transaction; the files only once it has committed, so a rolled-back delete never leaves a media
     * row pointing at a file that is gone (the rule the organization purge follows).
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
     * The ads a person sees from where they stand: above the organizations, every organization's and the shared ones (each row
     * saying whose it is); inside an organization, that organization's own alone (owner, 2026-10-07: the platform's ads for every
     * organization are Premium Templates, reached through Create Ad — premiumTemplates — and used as a copy of the organization's
     * own). Never another organization's; with no organization selected, none at all. What may be DONE to one is the
     * controller's to ask.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->globalRole() !== null) {
            return $query;
        }

        $organizationId = (int) session('current_organization_id');

        return $organizationId > 0 ? $query->where('organization_id', $organizationId) : $query->whereRaw('0 = 1');
    }

    /**
     * The Premium Templates: the platform's ads for every organization, once published (owner, 2026-10-01: an unfinished
     * design stays the platform's) — what Create Ad's Premium Template offers an organization, as it was published.
     */
    public function scopePremiumTemplates(Builder $query): Builder
    {
        return $query->whereNull('organization_id')->whereNotNull('media_id')->whereNotNull('published_at')->whereNotNull('published_document');
    }

    /** The platform's, made for every organization — no organization's own. */
    public function isShared(): bool
    {
        return $this->organization_id === null;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
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

    /** Where this ad's published file and poster live: its organization's folder, or the platform's for a shared one. */
    public function storageDirectory(): string
    {
        return 'builder/'.($this->organization_id ?? 'platform')."/ads/{$this->id}";
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
            'duration' => self::DEFAULT_SECONDS,
            'stage' => [
                'width' => $width,
                'height' => $height,
                'background' => ['color' => '#0f172a', 'layers' => []],
            ],
            'elements' => [],
        ];
    }

    /**
     * "Winter sale" → "Winter sale (copy)", and "(copy 2)" after that, among the ads of the place the copy goes to. A
     * Premium Template's copy keeps the name while the organization has no ad called so: it is the organization's first.
     */
    public static function nameForCopy(string $name, ?int $organizationId, bool $keepItIfFree = false): string
    {
        $base = preg_replace('/ \(copy( \d+)?\)$/', '', $name) ?? $name;
        $taken = BuilderAd::query()
            ->when($organizationId === null, fn (Builder $query) => $query->whereNull('organization_id'), fn (Builder $query) => $query->where('organization_id', $organizationId))
            ->pluck('name')
            ->all();

        if ($keepItIfFree && ! in_array($name, $taken, true)) {
            return mb_substr($name, 0, 120);
        }

        if (! in_array("{$base} (copy)", $taken, true)) {
            return mb_substr("{$base} (copy)", 0, 120);
        }

        for ($i = 2; $i < 100; $i++) {
            if (! in_array("{$base} (copy {$i})", $taken, true)) {
                return mb_substr("{$base} (copy {$i})", 0, 120);
            }
        }

        return mb_substr("{$base} (copy)", 0, 120);
    }

    /**
     * Every shelf file a design names: an element's `assetId` and a background layer's, as the compiler reads them.
     *
     * @return list<int>
     */
    public static function assetIdsIn(?array $document): array
    {
        $ids = [];

        foreach (self::assetHolders($document ?? []) as $holder) {
            $id = $holder['assetId'] ?? null;

            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[(int) $id] = (int) $id;
            }
        }

        return array_values($ids);
    }

    /**
     * The design with each file it names swapped for the one $swaps gives (old id => new id); a file $swaps does not
     * name is taken off its element or layer, so a copy never points at a file outside its own shelf.
     *
     * @param  array<int, int>  $swaps
     */
    public static function withAssetsSwapped(array $document, array $swaps): array
    {
        $swap = function (mixed $holder) use ($swaps): mixed {
            if (! is_array($holder) || ! array_key_exists('assetId', $holder) || $holder['assetId'] === null) {
                return $holder;
            }

            $id = $holder['assetId'];
            $holder['assetId'] = (is_int($id) || (is_string($id) && ctype_digit($id))) ? ($swaps[(int) $id] ?? null) : null;

            return $holder;
        };

        if (is_array($document['elements'] ?? null)) {
            $document['elements'] = array_map($swap, $document['elements']);
        }

        if (is_array($document['stage']['background']['layers'] ?? null)) {
            $document['stage']['background']['layers'] = array_map($swap, $document['stage']['background']['layers']);
        }

        return $document;
    }

    /** @return list<array<string, mixed>> the elements and the background layers, where a design names its files */
    private static function assetHolders(array $document): array
    {
        $elements = is_array($document['elements'] ?? null) ? $document['elements'] : [];
        $layers = is_array($document['stage']['background']['layers'] ?? null) ? $document['stage']['background']['layers'] : [];

        return array_values(array_filter([...$elements, ...$layers], 'is_array'));
    }

    /**
     * Does the design say its own length? Every new design does (blankDocument). One made before designs had a length
     * (2026-09-28) does not until its designer types one: until then publishing it — again, or after Unpublish —
     * writes no length onto its page's row, and every playlist line and channel ad keeps the seconds it was given
     * there (the brute-force round, 2026-09-29: a typo fixed and published re-timed a 15 s ad to 6 on every screen).
     */
    public static function hasOwnLength(?array $document): bool
    {
        $seconds = $document['duration'] ?? null;

        return is_int($seconds) && $seconds >= 1;
    }

    /**
     * How long a design says its ad is on screen, read as the editor reads it (normaliseDocument, adSeconds): a
     * whole number from one up, held inside what the rules allow — anything else, or nothing, says the default.
     */
    public static function lengthOf(?array $document): int
    {
        $seconds = $document['duration'] ?? null;

        return is_int($seconds) && $seconds >= 1
            ? max(self::MIN_SECONDS, min(self::MAX_SECONDS, $seconds))
            : self::DEFAULT_SECONDS;
    }
}
