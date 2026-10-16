<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Every Ad Builder design's folder gets a name nobody can guess (the review of 2026-10-15, owner's yes): an ad's page and posters
 * lay on the public disk at `builder/{organization}/ads/{id}`, and the web server hands out whatever has an address — so a
 * stranger counting through the ids read every organization's designs, unpublished drafts included. From now on the folder is
 * named by `builder_ads.storage_key`, 128 random bits (BuilderAd::storageDirectory), and this moves every folder there.
 *
 * For each ad, in this order:
 * 1. Every folder named by its id — under its organization, or under whichever owner it had when the folder was made — moves to
 *    its new one, file by file, and every row naming a file there names it where it is now.
 * 2. A file its own rows name in the folder of an ad that is gone moves to its folder too.
 * 3. The design's poster, `poster.jpg`, takes the name of its own that BuilderAd::posterNameFor gives (drawn from the key with
 *    APP_KEY), so nobody handed the published page can work out the draft's picture beside it.
 * 4. A page whose row still names the design's own poster — published before Publish kept a copy of its own (2026-09-22), so
 *    every save of the draft redrew the page's picture — gets its own `published.jpg`; when the design has changed since it was
 *    published, that poster may be the draft's, so the page has none until it is published again.
 *
 * A file whose new place is taken: when no row names it, it is deleted (what a row names is what is shown); when a row names it
 * but none the one in its place, it takes that place; when rows name both, it stays where it is, said in the log, its rows
 * naming it still. A row naming a file that is not there names its new place, said in the log. A folder still named by an id
 * that holds a file afterwards — an ad deleted long ago — is left alone and said in the log. Nothing in a page names its own
 * folder, so no page is written again; each television downloads each page once more, its address having moved.
 *
 * Everything is worked out from what is on the disk and in the rows, so a run cut short carries on when it is run again with
 * the same keys and the same names, and a run with nothing left to do changes nothing — which is why `builder:move-ad-folders`
 * runs it again on every deploy after the site has switched over (deploy/server/release.sh): the release before it went on
 * writing into folders named by an id until then. `down()` gives the poster its old name, moves every folder back to its id and
 * drops the column — every folder to the id of its organization of today, as the code before the key looks for it. A page given
 * its own `published.jpg` keeps it (the very file Publish wrote before the key), and one left with none has none until it is
 * published: going back never shows a draft again. Written with the query builder, so it says what it did on the day it ran.
 */
return new class extends Migration
{
    /**
     * Files cannot be rolled back with the rows: each key is kept the moment it is given, so a run cut short carries on from
     * where it stopped, with the same keys, when it is run again.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasTable('builder_ads')) {
            return;
        }

        if (! Schema::hasColumn('builder_ads', 'storage_key')) {
            Schema::table('builder_ads', function (Blueprint $table): void {
                $table->string('storage_key', 32)->nullable()->unique()->after('id');
            });
        }

        $ads = DB::table('builder_ads')->orderBy('id')
            ->get(['id', 'organization_id', 'storage_key', 'media_id', 'name', 'document', 'published_name', 'published_document', 'published_at', 'updated_at']);

        // A new installation has no ad, and its disk nothing of one to look at.
        if ($ads->isEmpty()) {
            return;
        }

        $folders = [];

        foreach ($ads as $ad) {
            $key = $this->keyOf($ad);

            if ($key !== null) {
                $folders[(int) $ad->id] = $this->folder($ad->organization_id, $key);
            }
        }

        $byId = $this->foldersNamedByAnId();

        foreach ($ads as $ad) {
            if (! isset($folders[$id = (int) $ad->id])) {
                continue;
            }

            $from = [...($byId[$id] ?? []), $this->folder($ad->organization_id, (string) $id)];

            foreach ($this->pathsNamedBy($ad) as $path) {
                if (preg_match('#^(builder/[^/]+/ads/'.$id.')/[^/]+$#', $path, $match) === 1) {
                    $from[] = $match[1];
                }
            }

            foreach (array_unique($from) as $folder) {
                $this->move($folder, $folders[$id], isFolder: true, sayMissing: true);
            }
        }

        foreach ($ads as $ad) {
            foreach (isset($folders[(int) $ad->id]) ? $this->pathsNamedBy($ad) : [] as $path) {
                if (preg_match('#^builder/[^/]+/ads/([0-9]{1,19})/[^/]+$#', $path, $match) === 1 && ! $ads->contains('id', (int) $match[1])) {
                    $this->move($path, $folders[(int) $ad->id].'/'.basename($path), isFolder: false, sayMissing: true);
                }
            }
        }

        foreach ($ads as $ad) {
            if (! isset($folders[(int) $ad->id])) {
                continue;
            }

            $folder = $folders[(int) $ad->id];

            if (Storage::disk('public')->exists("{$folder}/poster.jpg") || $this->rowsNaming("{$folder}/poster.jpg", false) !== []) {
                $this->move("{$folder}/poster.jpg", $this->posterOf($folder), isFolder: false, sayMissing: false);
            }

            $this->giveThePageAPosterOfItsOwn($ad, $folder);
        }

        $this->sayWhatIsLeft();
    }

    public function down(): void
    {
        if (! Schema::hasTable('builder_ads') || ! Schema::hasColumn('builder_ads', 'storage_key')) {
            return;
        }

        $ads = DB::table('builder_ads')->whereNotNull('storage_key')->orderBy('id')->get(['id', 'organization_id', 'storage_key', 'thumbnail_path']);

        foreach ($ads as $ad) {
            if (preg_match('/^[0-9a-f]{32}$/', (string) $ad->storage_key) !== 1) {
                continue;
            }

            $folder = $this->folder($ad->organization_id, (string) $ad->storage_key);
            $named = (string) $ad->thumbnail_path;

            // The poster its row names, else the one up() named, whether or not a row names it: the exact way back.
            $poster = preg_match('#^'.preg_quote($folder, '#').'/poster-[0-9a-f]{32}\.jpg$#', $named) === 1 ? $named : $this->posterOf($folder);

            if (Storage::disk('public')->exists($poster) || $this->rowsNaming($poster, false) !== []) {
                $this->move($poster, "{$folder}/poster.jpg", isFolder: false, sayMissing: false);
            }

            $this->move($folder, $this->folder($ad->organization_id, (string) $ad->id), isFolder: true, sayMissing: false);
        }

        Schema::table('builder_ads', function (Blueprint $table): void {
            $table->dropUnique(['storage_key']);
        });

        Schema::table('builder_ads', function (Blueprint $table): void {
            $table->dropColumn('storage_key');
        });
    }

    /**
     * Every folder still named by an id that holds a file, with how many — an ad's deleted long ago, or a file that could not
     * move. `builder:move-ad-folders` says them after its run.
     *
     * @return array<string, int>
     */
    public function foldersStillNamedByAnId(): array
    {
        $left = [];

        foreach ($this->foldersNamedByAnId() as $folders) {
            foreach ($folders as $folder) {
                if (($files = count(Storage::disk('public')->allFiles($folder))) > 0) {
                    $left[$folder] = $files;
                }
            }
        }

        ksort($left);

        return $left;
    }

    /** The ad's key, given now when it has none (never over one somebody gave meanwhile); null for one that is no key. */
    private function keyOf(object $ad): ?string
    {
        if ($ad->storage_key === null) {
            DB::table('builder_ads')->where('id', $ad->id)->whereNull('storage_key')->update(['storage_key' => bin2hex(random_bytes(16))]);
            $ad->storage_key = DB::table('builder_ads')->where('id', $ad->id)->value('storage_key');
        }

        if (preg_match('/^[0-9a-f]{32}$/', (string) $ad->storage_key) !== 1) {
            Log::warning("Ad folders: builder_ads #{$ad->id} has a storage key that is no key, so its folder is left as it is.");

            return null;
        }

        return (string) $ad->storage_key;
    }

    /** `builder/{organization}/ads/{name}`, the platform's under `builder/platform/`. */
    private function folder(int|string|null $organizationId, string $name): string
    {
        return 'builder/'.($organizationId ?? 'platform')."/ads/{$name}";
    }

    /**
     * The design's poster in $folder under the name of its own, as BuilderAd::posterNameFor gave it on the day: drawn from the
     * folder's key with APP_KEY, so a run cut short gives the same name again and nobody who knows the folder can work it out.
     */
    private function posterOf(string $folder): string
    {
        $secret = (string) config('app.key');

        if ($secret === '') {
            throw new MissingAppKeyException;
        }

        return "{$folder}/poster-".substr(hash_hmac('sha256', 'ad-poster|'.basename($folder), $secret), 0, 32).'.jpg';
    }

    /**
     * What the ad's own rows name now: its poster, its page and the page's poster.
     *
     * @return list<string>
     */
    private function pathsNamedBy(object $ad): array
    {
        $row = DB::table('builder_ads')->where('id', $ad->id)->first(['thumbnail_path', 'media_id']);
        $page = $row?->media_id === null ? null : DB::table('media')->where('id', $row->media_id)->where('disk', 'public')->first(['path', 'thumbnail_path']);

        return array_values(array_filter([$row?->thumbnail_path, $page?->path, $page?->thumbnail_path], fn (?string $path): bool => $path !== null));
    }

    /**
     * A folder's every file ($isFolder) — or one file — to $to, and every row naming one of them to name it there. A row
     * naming a file that is not there is said in the log ($sayMissing) once, when its folder moves forward; when the poster
     * takes its own name, and going back, the row simply follows.
     */
    private function move(string $from, string $to, bool $isFolder, bool $sayMissing): void
    {
        $disk = Storage::disk('public');
        $files = $isFolder ? ($disk->directoryExists($from) ? $disk->allFiles($from) : []) : ($disk->exists($from) ? [$from] : []);

        foreach ($files as $file) {
            $target = $isFolder ? $to.substr($file, strlen($from)) : $to;

            if ($disk->exists($target)) {
                if ($this->rowsNaming($file, false) === []) {
                    $disk->delete($file);
                    Log::warning("Ad folders: {$file} was named by no row and {$target} is there already, so it was deleted.");

                    continue;
                }

                if ($this->rowsNaming($target, false) !== []) {
                    Log::warning("Ad folders: {$target} is there already, so {$file} stays where it is.");

                    continue;
                }

                // What is there is named by no row; the file a row names takes its place.
                $disk->delete($target);
            }

            $disk->move($file, $target);
        }

        foreach ($this->rowsNaming($from, $isFolder) as [$table, $column, $id, $path]) {
            // Still there: it could not move, and the row goes on naming it.
            if ($disk->exists($path)) {
                continue;
            }

            $moved = $isFolder ? $to.substr($path, strlen($from)) : $to;

            if ($sayMissing && ! $disk->exists($moved)) {
                Log::warning("Ad folders: {$table} #{$id} names {$path}, which is not there; it names {$moved} now.");
            }

            DB::table($table)->where('id', $id)->update([$column => $moved]);
        }

        if ($isFolder && $disk->directoryExists($from) && $disk->allFiles($from) === []) {
            $disk->deleteDirectory($from);
        }
    }

    /**
     * A page whose row names the design's own poster gets a copy of its own, `published.jpg`, as Publish writes it — while the
     * design is the version on the page. With changes since, the poster may show the draft: the page has none until it is
     * published again, which writes it.
     */
    private function giveThePageAPosterOfItsOwn(object $ad, string $folder): void
    {
        $page = $ad->media_id === null ? null : DB::table('media')->where('id', $ad->media_id)->first(['id', 'disk', 'thumbnail_path']);

        if ($page === null || $page->disk !== 'public' || ! is_string($page->thumbnail_path)
            || preg_match('#^'.preg_quote($folder, '#').'/poster(-[0-9a-f]{32})?\.jpg$#', $page->thumbnail_path) !== 1) {
            return;
        }

        $disk = Storage::disk('public');
        $copy = "{$folder}/published.jpg";

        if (! $this->showsItsPublishedVersion($ad) || ! $disk->exists($page->thumbnail_path)) {
            DB::table('media')->where('id', $page->id)->update(['thumbnail_path' => null]);
            Log::info("Ad folders: media #{$page->id} showed the design's own poster, which may show its draft, so it has none until it is published again.");

            return;
        }

        if ($disk->exists($copy) && $this->rowsNaming($copy, false) !== []) {
            Log::warning("Ad folders: {$copy} is another row's, so media #{$page->id} goes on naming {$page->thumbnail_path}.");

            return;
        }

        $disk->put($copy, (string) $disk->get($page->thumbnail_path));
        DB::table('media')->where('id', $page->id)->update(['thumbnail_path' => $copy]);
    }

    /** Is the design what its page shows — nothing saved since it was published? (BuilderAd::hasUnpublishedChanges, on the day.) */
    private function showsItsPublishedVersion(object $ad): bool
    {
        if ($ad->published_document === null) {
            return $ad->published_at !== null && $ad->updated_at !== null && Carbon::parse($ad->updated_at)->lte(Carbon::parse($ad->published_at));
        }

        return $ad->name === ($ad->published_name ?? $ad->name)
            && json_decode((string) $ad->document, true) == json_decode((string) $ad->published_document, true);
    }

    /**
     * Every row naming $path — or, for a folder, a file inside it: an ad's poster, a page's file and its poster.
     *
     * @return list<array{0: string, 1: string, 2: int, 3: string}> [table, column, id, the path it names]
     */
    private function rowsNaming(string $path, bool $isFolder): array
    {
        $rows = [];

        foreach ([['builder_ads', 'thumbnail_path'], ['media', 'path'], ['media', 'thumbnail_path']] as [$table, $column]) {
            $found = DB::table($table)
                ->when($isFolder,
                    fn (Builder $query): Builder => $query->where($column, 'like', $path.'/%'),
                    fn (Builder $query): Builder => $query->where($column, $path))
                ->when($table === 'media', fn (Builder $query): Builder => $query->where('disk', 'public'))
                ->orderBy('id')
                ->get(['id', $column]);

            foreach ($found as $row) {
                $rows[] = [$table, $column, (int) $row->id, (string) $row->{$column}];
            }
        }

        return $rows;
    }

    /**
     * Every folder `builder/{owner}/ads/{id}` on the disk, by the id that names it.
     *
     * @return array<int, list<string>>
     */
    private function foldersNamedByAnId(): array
    {
        $disk = Storage::disk('public');
        $folders = [];

        foreach ($disk->directoryExists('builder') ? $disk->directories('builder') : [] as $owner) {
            foreach ($disk->directoryExists("{$owner}/ads") ? $disk->directories("{$owner}/ads") : [] as $folder) {
                // An id, never a key: a key is 32 characters, every one of which may be a digit.
                if (preg_match('/^[0-9]{1,19}$/', basename($folder)) === 1) {
                    $folders[(int) basename($folder)][] = $folder;
                }
            }
        }

        return $folders;
    }

    /**
     * Every folder still named by an id that holds a file said, never touched: what nobody's row names is for a person to look
     * at, not for a migration to delete.
     */
    private function sayWhatIsLeft(): void
    {
        foreach ($this->foldersStillNamedByAnId() as $folder => $files) {
            Log::warning("Ad folders: {$folder} is still named by an id and holds {$files} file(s), at an address anybody can guess.");
        }
    }
};
