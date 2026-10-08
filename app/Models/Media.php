<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A file in a media library. A library belongs to an organization (`organization_id` set) — its inventory, which every
 * colleague may put on the organization's screens — or to the platform (`organization_id` NULL, docs/CHANNEL-CONTENT-SPEC.md),
 * whose files reach a television inside a platform channel alone — a screen's playlist holds its own organization's files (playableOn,
 * owner 2026-10-07; `copied_from_id` names the platform row an organization's copy came from). An organization never
 * lists, changes or deletes the platform's rows, and nothing here ever crosses from one organization to another.
 */
class Media extends Model
{
    use HasFactory;

    /** Laravel would guess "medias"; the table is the natural plural. */
    protected $table = 'media';

    public const TYPE_IMAGE = 'image';

    public const TYPE_VIDEO = 'video';

    /**
     * A page, not a file somebody uploaded: an advert designed in the Ad Builder and published into the
     * library (docs/AD-BUILDER-SPEC.md §9). It is placed like an image — the schedule rules work the same —
     * but plays for the length its design says (ownLength()), and the player shows it in a frame of its own
     * so its animations can run.
     */
    public const TYPE_HTML = 'html';

    /**
     * The longest video a library or a channel takes (owner's rule, 2026-09-28): five minutes, measured from the
     * file (App\Rules\VideoLength). An organization's screen is watched for seconds by people walking past, and 250 MB of
     * 1080p runs out near seven minutes anyway. The Ad Builder's shelf keeps 30 seconds
     * (BuilderAsset::MAX_VIDEO_SECONDS). Mirrored by MAX_VIDEO_SECONDS in resources/js/core/media-file.js.
     */
    public const MAX_VIDEO_SECONDS = 300;

    protected $fillable = [
        'organization_id', 'title', 'type', 'mime_type', 'disk', 'path',
        'thumbnail_path', 'size', 'width', 'height', 'duration_seconds',
        'orientation', 'created_by',
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

    /**
     * Media is scoped to the ORGANIZATION, not to whoever uploaded it: a file is the organization's
     * inventory, so everyone working in the organization can put it on a screen, and it stays when
     * its uploader leaves. The platform team spans every organization and its own library. With no
     * organization selected an organization member sees nothing — and an organization member never matches the
     * platform's rows, whose `organization_id` is NULL.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->globalRole() !== null) {
            return $query;
        }

        $currentOrganizationId = session('current_organization_id');

        if (! $currentOrganizationId) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where('organization_id', $currentOrganizationId);
    }

    /** The platform's own library: files that belong to no organization. */
    public function scopePlatformOwned(Builder $query): Builder
    {
        return $query->whereNull('organization_id');
    }

    /**
     * The files a screen's playlist may hold: its own organization's library alone (owner, 2026-10-07; docs/BILLING-SPEC.md §5 —
     * what the platform makes for every organization reaches it as a Premium Template, copied, or in a platform channel). Never the
     * platform's, never another organization's; and a file a channel holds is kept off every playlist (scopeInNoChannel).
     */
    public function scopePlayableOn(Builder $query, Screen $screen): Builder
    {
        return $query->where('organization_id', $screen->organization_id);
    }

    /**
     * Everything but an Ad Builder page taken off the screens (see isDraft()) — the files anybody may see in a
     * library or pick for a screen or a channel. Mirrors BuilderAd::isPublished() in SQL.
     */
    public function scopeWithoutDrafts(Builder $query): Builder
    {
        return $query->whereNotExists(fn (QueryBuilder $design) => $design->selectRaw('1')
            ->from('builder_ads')
            ->whereColumn('builder_ads.media_id', 'media.id')
            ->whereNull('builder_ads.published_at'));
    }

    /**
     * Photographs and videos alone: the Media page's own list (owner, 2026-10-05: "media library mein show mat karo
     * list lambi ho jayegi"). An Ad Builder page is looked after in the Ad Builder, and chosen where it plays — a
     * screen's Content library (while no channel shows it), its holding picture, a channel's pickers — each of which
     * keeps a list of its own. Every draft is an ad page, so none is listed either.
     */
    public function scopeWithoutAdPages(Builder $query): Builder
    {
        return $query->where('type', '!=', self::TYPE_HTML);
    }

    /**
     * Without the files a channel shows (owner's rule, 2026-09-26: "agar koi bhi file channel k ander assign ha
     * toh woo playlist mein nahi dikhe warna woo 2 bar ho jayegi"): a file in a channel AND on the playlist that
     * carries that channel plays twice in one pass. So a file plays from playlists or from channels, never both:
     * the playlist's picker leaves out every file any channel holds — paused, out of its dates or not — and a
     * channel's pickers leave out every file a playlist holds (scopeOnNoPlaylist). Not shown, rather than shown
     * and refused (owner: "dikhao hi nahi"); the walls behind both pickers refuse the same by id.
     */
    public function scopeInNoChannel(Builder $query): Builder
    {
        return $query->whereNotExists(fn (QueryBuilder $ad) => $ad->selectRaw('1')
            ->from('channel_ads')
            ->whereColumn('channel_ads.media_id', 'media.id'));
    }

    /** Without the files a screen's playlist holds: the channels' side of scopeInNoChannel(). */
    public function scopeOnNoPlaylist(Builder $query): Builder
    {
        return $query->whereNotExists(fn (QueryBuilder $line) => $line->selectRaw('1')
            ->from('playlist_items')
            ->whereColumn('playlist_items.media_id', 'media.id'));
    }

    /** Only the files a screen's playlist holds — the ones scopeOnNoPlaylist() leaves out, counted to say why. */
    public function scopeOnSomePlaylist(Builder $query): Builder
    {
        return $query->whereExists(fn (QueryBuilder $line) => $line->selectRaw('1')
            ->from('playlist_items')
            ->whereColumn('playlist_items.media_id', 'media.id'));
    }

    /**
     * An Ad Builder page whose ad is a draft — taken off the screens with Unpublish (owner, 2026-09-21): nobody
     * sees it — no screen, no channel, no picker, not the library — until the ad is published again. The row
     * stays, so every playlist line and channel ad holding it plays it again from then on. A published ad that is
     * merely CHANGED is not one: the screens keep this page, its published version, until the changes are
     * published (BuilderAd::hasUnpublishedChanges()).
     */
    public function isDraft(): bool
    {
        return $this->type === self::TYPE_HTML
            && $this->builderAd !== null
            && ! $this->builderAd->isPublished();
    }

    /** The Ad Builder design this page was published from; null for every other file. */
    public function builderAd(): HasOne
    {
        return $this->hasOne(BuilderAd::class);
    }

    /** Does this file belong to the platform rather than to an organization? */
    public function isPlatformOwned(): bool
    {
        return $this->organization_id === null;
    }

    /**
     * How long this file plays by itself, or null when whoever places it says: a video runs to its own end, and
     * an Ad Builder page for the length its design says (owner, 2026-09-28, the industry's way — Xibo's layout
     * duration, Canva's page duration: a video inside is cut at the end, or repeats to fill it). A picture has
     * none, and neither has a page published before designs had a length: those keep the seconds they were given.
     */
    public function ownLength(): ?int
    {
        if ((int) $this->duration_seconds <= 0) {
            return null;
        }

        return match ($this->type) {
            self::TYPE_VIDEO => (int) $this->duration_seconds,
            // Never under the least an ad may be — which a page published before the six-second rule may still carry.
            self::TYPE_HTML => max(BuilderAd::MIN_SECONDS, (int) $this->duration_seconds),
            default => null,
        };
    }

    /**
     * How long this file holds a screen when it is on: its own length when it has one; for a video with none
     * recorded (uploaded before the server measured videos) the unmeasured backstop, never a picture's seconds —
     * the player moves on at its real end anyway; and for a picture the seconds it was given, never under the least.
     */
    public function playSeconds(?int $given = null): int
    {
        return $this->ownLength()
            ?? ($this->type === self::TYPE_VIDEO ? ChannelAd::UNMEASURED_VIDEO_SECONDS : PlaylistItem::secondsForAPicture($given));
    }

    /** The organization whose library this is; null for the platform's. */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** The channel ads that show this file. */
    public function channelAds(): HasMany
    {
        return $this->hasMany(ChannelAd::class);
    }

    /**
     * Why this file may not be deleted yet, or null when it may (owner, 2026-09-19: "pehle channel se
     * hatao"): a channel showing it would lose the ad without anybody having decided so. A screen's playlist
     * is different on purpose — the file simply leaves it — so only channels are counted here.
     */
    public function stillInAChannelMessage(): ?string
    {
        return self::inChannelsMessage(
            Channel::whereIn('id', $this->channelAds()->select('channel_id'))->orderBy('name')->pluck('name')
        );
    }

    /**
     * The same refusal for a whole page of files at once, keyed by id — one query, so a listing can carry it
     * and the panel can say it before anybody confirms a delete. Files no channel shows are left out.
     *
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    public static function stillInChannelsMessages(array $ids): array
    {
        return DB::table('channel_ads')
            ->join('channels', 'channels.id', '=', 'channel_ads.channel_id')
            ->whereIn('channel_ads.media_id', $ids)
            ->distinct()
            ->orderBy('channels.name')
            ->get(['channel_ads.media_id', 'channels.id', 'channels.name'])
            ->groupBy('media_id')
            ->map(fn (Collection $rows) => self::inChannelsMessage($rows->pluck('name')))
            ->all();
    }

    /** @param  Collection<int, string>  $names  the channels' names, in order */
    private static function inChannelsMessage(Collection $names): ?string
    {
        if ($names->isEmpty()) {
            return null;
        }

        $listed = self::listOfNames($names);

        return $names->count() === 1
            ? "Still used by the channel {$listed}. Take it out of that channel first."
            : "Still used by the channels {$listed}. Take it out of those channels first.";
    }

    /** The screens whose playlist still carries this file, worded the same way — what keptOutOfChannelsMessage() says. */
    public function stillOnScreensMessage(): ?string
    {
        $names = Screen::whereIn('id', PlaylistItem::where('media_id', $this->id)->select('screen_id'))
            ->orderBy('name')
            ->pluck('name');

        if ($names->isEmpty()) {
            return null;
        }

        $listed = self::listOfNames($names);

        return $names->count() === 1
            ? "Still on the screen {$listed}. Take it off that screen first."
            : "Still on the screens {$listed}. Take it off those screens first.";
    }

    /**
     * Why this file may not go into a channel, or null when it may (owner's rule, 2026-09-26): a screen's playlist
     * holds it, and in a channel too it would play twice on a screen that carries the channel. The Add-ad request
     * says it, and the channel's write says it again under the file's lock (ChannelAdController).
     */
    public function keptOutOfChannelsMessage(): ?string
    {
        $onScreens = $this->stillOnScreensMessage();

        return $onScreens === null
            ? null
            : "{$this->title} plays on a playlist, so it stays out of channels: it would play twice. {$onScreens}";
    }

    /** "A, B, C and 2 more" — the same shortening wherever a refusal names what still uses a file. */
    private static function listOfNames(Collection $names): string
    {
        return $names->take(3)->join(', ').($names->count() > 3 ? ' and '.($names->count() - 3).' more' : '');
    }

    /**
     * The file's address. A published ad's page is rewritten in place each time it is published, so — like
     * its poster — its address carries the version: a television told about a new page must not be handed
     * the old one from a cache.
     */
    public function getUrlAttribute(): string
    {
        $url = Storage::disk($this->disk)->url($this->path);

        return $this->type === self::TYPE_HTML
            ? $url.'?v='.($this->updated_at?->getTimestamp() ?? 0)
            : $url;
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        if (! $this->thumbnail_path) {
            return null;
        }

        $url = Storage::disk($this->disk)->url($this->thumbnail_path);

        // A published ad's poster is redrawn in place each time it is published, so its address carries
        // the version; every other thumbnail has a name of its own for the life of the file.
        return $this->type === self::TYPE_HTML
            ? $url.'?v='.($this->updated_at?->getTimestamp() ?? 0)
            : $url;
    }

    /**
     * May a television show this file — any file but an Ad Builder page taken off the screens (isDraft())? A file
     * keeps no dates of its own (owner, 2026-10-01): when it plays is its playlist line's to say, and the
     * schedule resolver asks that.
     */
    public function isPlayableNow(): bool
    {
        return ! $this->isDraft();
    }

    /**
     * A cache key for the player, not a content digest: it changes whenever the bytes behind this row could
     * have (docs/AD-BUILDER-SPEC.md §15), and only then — every screen downloads the file again when it does.
     * A picture or a video never changes under its name (every upload is a new file with a name of its own),
     * so its key is the file itself: a new title or new dates send no television to fetch it again. An ad
     * page is rewritten in place on every publish, which stamps the row, so that moment is part of its key.
     * Cheap enough to compute on every manifest request.
     */
    public function cacheKey(): string
    {
        $version = $this->type === self::TYPE_HTML ? (string) $this->updated_at?->timestamp : (string) $this->path;

        return substr(hash('sha256', $this->id.'|'.$this->size.'|'.$version), 0, 20);
    }
}
