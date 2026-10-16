<?php

namespace App\Models;

use App\Services\AdAnimations;
use App\Services\MediaStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use LogicException;

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
 * @property string|null $storage_key the name of the ad's folder, nobody's to guess (storageDirectory)
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

    /** The shortest an ad may be: the ten seconds a picture is held to (PlaylistItem::MIN_IMAGE_SECONDS). */
    public const MIN_SECONDS = PlaylistItem::MIN_IMAGE_SECONDS;

    /** The longest an ad may be — as long as a picture may hold a channel's screen (ChannelAd::MAX_IMAGE_SECONDS). */
    public const MAX_SECONDS = 300;

    protected $fillable = [
        'organization_id', 'copied_from_id', 'name', 'orientation', 'document', 'thumbnail_path', 'media_id', 'published_at',
        'created_by', 'updated_by',
    ];

    /** A row made before the column existed, or a model not yet saved, is landscape. */
    protected $attributes = [
        'orientation' => self::LANDSCAPE,
    ];

    protected $appends = ['thumbnail_url'];

    /** The folder's name is for the server to know: a listing hands out a poster's address, never the key itself. */
    protected $hidden = ['storage_key'];

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
        // Every ad gets a folder nobody can guess the moment it is made (storageDirectory) — unless the table has no key yet: a
        // migration of before it (2026_10_08_100200) makes ads through this model.
        static::creating(function (BuilderAd $ad): void {
            if ($ad->storage_key === null && self::tableHas('storage_key')) {
                $ad->storage_key = self::newStorageKey();
            }
        });

        // And a replica one of its own: two ads sharing a folder would overwrite each other's page and poster.
        static::replicating(function (BuilderAd $ad): void {
            if (array_key_exists('storage_key', $ad->getAttributes())) {
                $ad->storage_key = self::newStorageKey();
            }
        });

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

    /**
     * Where this ad's published page and posters live: under its organization's folder, or the platform's for a shared one, in a
     * folder named by the ad's storage key. The public disk is served to anybody who has an address, so the address must be
     * nobody's to guess (the review of 2026-10-15: `builder/{organization}/ads/{id}` let a stranger count through every organization's
     * designs, drafts included). Never named after the ad's id — but on a database whose table has no key yet: a migration of
     * before it (2026_10_08_100200) makes and publishes ads through this model, in the folder of that day, which
     * 2026_10_15_220000 then moves.
     */
    public function storageDirectory(): string
    {
        if ($this->storage_key === null && $this->exists) {
            if (! self::tableHas('storage_key')) {
                return 'builder/'.($this->organization_id ?? 'platform')."/ads/{$this->id}";
            }

            $this->giveStorageKey();
        }

        // Never a folder every key-less ad would share (`builder/1/ads/`), nor one a key could climb out of.
        if (! is_string($this->storage_key) || preg_match('/^[0-9a-f]{32}$/', $this->storage_key) !== 1) {
            throw new LogicException("Ad #{$this->id} has no storage key, so it has no folder of its own.");
        }

        return 'builder/'.($this->organization_id ?? 'platform')."/ads/{$this->storage_key}";
    }

    /** Whether `builder_ads` has $column yet: the migrations of before it (2026_10_08_100200) make and publish ads through this model. */
    public static function tableHas(string $column): bool
    {
        return Schema::hasColumn((new self)->getTable(), $column);
    }

    /**
     * A key for a saved ad that has none — a row the code before the key wrote while a deploy switched over. Given once,
     * whoever asks first (a second asker reads the first one's), and never as a change to the design: the clock that says
     * whether a published ad shows its latest changes is not moved.
     */
    private function giveStorageKey(): void
    {
        $row = self::query()->toBase()->where('id', $this->getKey());

        (clone $row)->whereNull('storage_key')->update(['storage_key' => self::newStorageKey()]);

        $this->storage_key = $row->value('storage_key');
        $this->syncOriginalAttribute('storage_key');
    }

    /** 128 random bits, as 32 lowercase hex digits: a folder's name nobody can count to. */
    public static function newStorageKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Where the design's own poster is kept: the file it has, or its folder's poster name (posterNameFor). The name is its own,
     * never `poster.jpg`: the published page and its poster's copy (`index.html`, `published.jpg`) are handed to every television
     * and every organization a Premium Template or a platform channel reaches, and a draft's picture beside them under a name
     * anybody could work out from theirs would show what is not published yet. A folder of before the key keeps the name of
     * its day, which 2026_10_15_220000 changes as it moves the folder.
     */
    public function posterPath(): string
    {
        $directory = $this->storageDirectory();

        if ($this->storage_key === null) {
            return "{$directory}/poster.jpg";
        }

        if (is_string($this->thumbnail_path) && preg_match('#^'.preg_quote($directory, '#').'/poster-[0-9a-f]{32}\.jpg$#', $this->thumbnail_path) === 1) {
            return $this->thumbnail_path;
        }

        return "{$directory}/poster-".self::posterNameFor($this->storage_key).'.jpg';
    }

    /**
     * The poster's name in the folder $key names: drawn from the key with the app's own secret (APP_KEY, as a signed link is),
     * so nobody who knows the folder can work it out, and it is the same whoever asks — two saves at the same moment write one
     * file, never two with one of them named by no row, and 2026_10_15_220000 cut short carries on with the names it gave.
     */
    public static function posterNameFor(string $key): string
    {
        $secret = (string) config('app.key');

        if ($secret === '') {
            throw new MissingAppKeyException;
        }

        return substr(hash_hmac('sha256', "ad-poster|{$key}", $secret), 0, 32);
    }

    /**
     * A file this design named before ($previous) and names no longer — a poster it replaced, a page or a poster's copy written
     * somewhere else — taken away when it is the design's own (ownsFile) and no row names it any more. Decided once the
     * transaction it is in has committed, as every file is deleted (Organization::purgeMedia).
     */
    public function forgetFile(?string $previous): void
    {
        if ($previous === null || ! $this->ownsFile($previous)) {
            return;
        }

        DB::afterCommit(function () use ($previous): void {
            $named = Media::where(fn (Builder $query): Builder => $query->where('path', $previous)->orWhere('thumbnail_path', $previous))->exists()
                || self::where('thumbnail_path', $previous)->exists();

            if ($named) {
                return;
            }

            $disk = Storage::disk('public');
            $disk->delete($previous);

            // A folder named by the id, emptied, goes with its last file.
            $folder = dirname($previous);

            if ($folder !== $this->storageDirectory() && $disk->directoryExists($folder) && $disk->allFiles($folder) === []) {
                $disk->deleteDirectory($folder);
            }
        });
    }

    /**
     * A file of this design's folder, or of a folder named by its id — the folder of before the key, which the code of before it
     * may still write into while a deploy switches over, under whichever owner the ad had then.
     */
    public function ownsFile(string $path): bool
    {
        return preg_match('#^'.preg_quote($this->storageDirectory(), '#').'/[^/]+$#', $path) === 1
            || ($this->id !== null && preg_match('#^builder/[^/]+/ads/'.(int) $this->id.'/[^/]+$#', $path) === 1);
    }

    /**
     * The design's poster brought into its own folder when its row names one in a folder named by its id — saved by the code of
     * before the key while a deploy switched over — and the file left there forgotten (forgetFile). Publish asks it first, so
     * a page is never published from a poster anybody could count to. Not a change to the design: its clock does not move.
     */
    public function bringItsPosterHome(): void
    {
        $from = $this->thumbnail_path;
        $disk = Storage::disk('public');

        if (! is_string($from) || ! $this->ownsFile($from) || str_starts_with($from, $this->storageDirectory().'/') || ! $disk->exists($from)) {
            return;
        }

        $home = $this->posterPath();
        $disk->put($home, (string) $disk->get($from));

        self::query()->toBase()->where('id', $this->getKey())->update(['thumbnail_path' => $home]);
        $this->thumbnail_path = $home;
        $this->syncOriginalAttribute('thumbnail_path');

        $this->forgetFile($from);
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

    /**
     * The least this design may last: the moment its last shown animation is over — an entrance landed, an exit gone,
     * a loop round once after its wait — rounded up to a whole second, never under MIN_SECONDS nor past MAX_SECONDS
     * (owner, 2026-10-14: "agar ad builder mein animation 10 seconds se ziyada ho toh minimum second wahi dikhaye aur
     * max toh 5 minutes ha"; the editor raises the length to it by itself, editor.js leastAdSeconds). An element that
     * is hidden, or inside a hidden group, is on no screen and does not count, nor does an effect AdAnimations does
     * not know; the numbers are read as AdAnimations keeps them, so a hand-made one cannot reach past what it allows.
     */
    public static function leastLengthFor(?array $document): int
    {
        $elements = collect(is_array($document['elements'] ?? null) ? $document['elements'] : [])->filter(fn (mixed $element) => is_array($element));
        // An id that is no word is nobody's parent (a list posted by hand is read, never turned into text: a 500).
        $byId = $elements->keyBy(fn (array $element) => is_string($element['id'] ?? null) ? $element['id'] : '');

        // Shown: itself and every group around it visible. A parent that is not there, or a circle, is shown nowhere.
        $shown = function (array $element) use ($byId): bool {
            $at = $element;

            for ($steps = 0; $steps <= 10; $steps++) {
                if (($at['visible'] ?? true) === false) {
                    return false;
                }

                $parent = $at['parentId'] ?? null;

                if ($parent === null || $parent === '') {
                    return true;
                }

                if (! is_string($parent)) {
                    return false;
                }

                $at = $byId->get($parent);

                if (! is_array($at)) {
                    return false;
                }
            }

            return false;
        };

        $animations = app(AdAnimations::class);
        $end = 0.0;

        foreach ($elements as $element) {
            if (! $shown($element)) {
                continue;
            }

            $slots = $animations->sanitize($element['animations'] ?? null);
            $landed = isset($slots['in']) ? $slots['in']['delay'] + $slots['in']['duration'] : 0.0;
            $end = max($end, $landed);

            if (isset($slots['loop'])) {
                $end = max($end, $landed + $slots['loop']['delay'] + $slots['loop']['duration']);
            }

            if (isset($slots['out'])) {
                $end = max($end, $slots['out']['at'] + $slots['out']['duration']);
            }
        }

        // Rounded to the thousandth first, so 8 + 1 + 2 + 3 is fourteen and not a hair past it.
        return (int) min(self::MAX_SECONDS, max(self::MIN_SECONDS, ceil(round($end, 3))));
    }
}
