<?php

namespace App\Models;

use App\Services\MediaStorage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Store extends Model
{
    use HasFactory;

    /** The 50 US states, keyed by their 2-letter USPS abbreviation. */
    public const US_STATES = [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
        'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
        'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho',
        'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
        'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
        'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
        'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
        'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
        'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
        'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina',
        'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah',
        'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia',
        'WI' => 'Wisconsin', 'WY' => 'Wyoming',
    ];

    protected $fillable = [
        'name',
        'street',
        'suite',
        'city',
        'state',
        'zip_code',
        'country',
        'is_active',
        // Whether this shop carries network advertising. Off until the deal is made —
        // set by the platform owner, never by the shop.
        'accepts_network_ads',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'accepts_network_ads' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Store $store) {
            $store->slug = self::generateUniqueIntegerSlug();
        });

        // A deleted store takes everything it owns with it (docs/STORE-ORGANIZATION-SPEC.md
        // rule 22). Hung on the model event rather than written into each place a store is
        // deleted — Settings → Stores and the platform's Stores page — so a third
        // path could never forget part of it. BEFORE the row goes: the store is deleted for
        // good, and its foreign keys would otherwise take the media and channel-ad rows first,
        // leaving their files on disk with no row to name them.
        static::deleting(fn (Store $store) => $store->purgeContents());
    }

    public static function generateUniqueIntegerSlug(): int
    {
        do {
            $microtime = microtime(true);
            $base = (int) ($microtime * 1_000_000);
            $suffix = random_int(10, 999);
            $slug = (int) ($base.$suffix);
        } while (self::where('slug', $slug)->exists());

        return $slug;
    }

    public function users(): BelongsToMany
    {
        // The pivot's created_at comes with withTimestamps(), which reads and stamps both timestamps.
        return $this->belongsToMany(User::class, 'store_user')
            ->withPivot('role_id')
            ->withTimestamps();
    }

    /** The televisions in this shop. Used to count them. */
    public function screens(): HasMany
    {
        return $this->hasMany(Screen::class);
    }

    /** This shop's media library. */
    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
    }

    /**
     * Everything this store owns, gone: its team (memberships and open invitations), its
     * custom roles, its screens — which takes their playlists, schedule rules, campaign links
     * and pairing requests through the foreign keys, and locks every device out on its next
     * poll — its dayparts, its own channels, its whole media library, and its Ad Builder
     * designs with the shelf of pictures and videos they were built from, files included.
     *
     * Accounts are not the store's: the people stay, and may be members elsewhere. The store
     * row goes too, for good (owner's rule, 2026-09-17: "A to Z"); the activity log keeps its
     * entries, which carry the store's name in their words.
     */
    public function purgeContents(): void
    {
        DB::table('store_user')->where('store_id', $this->id)->delete();
        Invitation::where('store_id', $this->id)->delete();
        Screen::where('store_id', $this->id)->delete();
        Daypart::where('store_id', $this->id)->delete();
        Role::where('store_id', $this->id)->delete();

        $this->purgeChannels();
        $this->purgeMedia();
        $this->purgeBuilder();
    }

    /**
     * Remove this shop's own channels: the rows, which take their ads and every playlist line
     * carrying them through the foreign keys. An ad's file is a row of the shop's library, so
     * purgeMedia() deals with it — and a platform channel that borrowed one of this shop's
     * files loses that ad there, by the same foreign keys. The platform's channels are not the
     * shop's and are not touched.
     */
    public function purgeChannels(): void
    {
        Channel::where('store_id', $this->id)->delete();
    }

    /**
     * Remove this shop's whole media library: the rows, and the files behind them.
     *
     * Rows first, which also takes each file off every playlist and clears it as any
     * screen's holding picture, through the foreign keys. The files are unlinked only
     * once that is committed — a delete that rolls back must never leave rows pointing
     * at files that are already gone.
     *
     * Only the files these rows name are touched, never the shop's folder as a whole.
     * A folder can hold something no row accounts for, and deleting on a guess in that
     * folder is exactly how real uploads were once lost.
     */
    public function purgeMedia(): void
    {
        $files = Media::where('store_id', $this->id)->get(['id', 'disk', 'path', 'thumbnail_path']);

        if ($files->isEmpty()) {
            return;
        }

        // In slices, so a very large library never becomes one enormous IN list.
        $files->pluck('id')->chunk(500)->each(fn (Collection $ids) => Media::whereIn('id', $ids)->delete());

        DB::afterCommit(function () use ($files) {
            $storage = app(MediaStorage::class);
            $files->each(fn (Media $media) => $storage->delete($media));
        });
    }

    /**
     * Remove this shop's ad designs and the shelf they were built from (docs/AD-BUILDER-SPEC.md).
     *
     * The rows would cascade through their foreign keys on their own; what they cannot do is take the
     * FILES — each ad's poster and every picture on the shelf — so those are collected first and
     * unlinked once the delete has committed. A published ad's page is a media row of the library's,
     * and purgeMedia() has already dealt with it. Only the paths the rows name are unlinked, never an
     * ad's folder: removing folders by their number once took the owner's own published ads with a
     * test's store that happened to share them.
     */
    public function purgeBuilder(): void
    {
        $ads = BuilderAd::where('store_id', $this->id)->get(['id', 'store_id', 'thumbnail_path']);
        $assets = BuilderAsset::where('store_id', $this->id)->get(['id', 'disk', 'path', 'thumbnail_path']);

        if ($ads->isEmpty() && $assets->isEmpty()) {
            return;
        }

        BuilderAsset::whereIn('id', $assets->pluck('id'))->delete();
        BuilderAd::whereIn('id', $ads->pluck('id'))->delete();

        DB::afterCommit(function () use ($ads, $assets) {
            $storage = app(MediaStorage::class);
            $assets->each(fn (BuilderAsset $asset) => $storage->deleteFiles($asset->disk, $asset->path, $asset->thumbnail_path));
            $ads->each(fn (BuilderAd $ad) => $storage->deleteFiles('public', $ad->thumbnail_path));
        });
    }
}
