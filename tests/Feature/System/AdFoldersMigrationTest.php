<?php

use App\Models\BuilderAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Screen;
use App\Services\AdPublisher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Every ad's folder moved to a name nobody can guess (the review of 2026-10-15)
|--------------------------------------------------------------------------
|
| `2026_10_15_220000_give_every_ad_a_folder_nobody_can_guess`: an ad's page and posters lay at
| `builder/{organization}/ads/{id}`, which a stranger could count through on the public disk. Every ad is given its
| storage key and every folder named by its id moves there — under whichever owner it was made, with the file its rows name
| in a gone ad's folder — the rows naming the files with them, the design's poster under a name of its own, and a page that
| still showed the design's own poster given one. Run again it does nothing, cut short it carries on with the same names, and
| `builder:move-ad-folders` runs it again after a deploy's switch for what the release before wrote meanwhile. `down()` puts
| it back.
|
*/

function adFoldersMigration(): object
{
    return require database_path('migrations/2026_10_15_220000_give_every_ad_a_folder_nobody_can_guess.php');
}

/** Every file on the public disk with what it holds. */
function everyFileOnTheDisk(): array
{
    $disk = Storage::disk('public');
    $files = [];

    foreach ($disk->allFiles() as $file) {
        $files[$file] = $disk->get($file);
    }

    ksort($files);

    return $files;
}

/** The rows this migration may touch, exactly as the database holds them. */
function adFolderRows(): array
{
    return [
        'builder_ads' => DB::table('builder_ads')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'media' => DB::table('media')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
    ];
}

/** The folder an ad had before: named by its id. */
function oldFolderOf(object $ad): string
{
    return 'builder/'.($ad->organization_id ?? 'platform')."/ads/{$ad->id}";
}

/** The design's poster in its folder, under the name of its own (BuilderAd::posterNameFor). */
function ownPosterOf(BuilderAd $ad): string
{
    return $ad->storageDirectory().'/poster-'.BuilderAd::posterNameFor((string) $ad->storage_key).'.jpg';
}

/** Every file still under a folder named by an ad's id. */
function filesByAnId(): array
{
    return array_values(array_filter(array_keys(everyFileOnTheDisk()), fn (string $file): bool => preg_match('#^builder/[^/]+/ads/[0-9]{1,19}/#', $file) === 1));
}

beforeEach(function () {
    Storage::fake('public');
    $disk = Storage::disk('public');

    $this->alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->beta = Organization::factory()->create(['name' => 'Beta Deli']);

    // Alpha's draft, its poster in its folder — and a library picture naming that poster.
    $this->draft = BuilderAd::factory()->create(['organization_id' => $this->alpha->id, 'name' => 'Alpha draft']);
    $this->draftPoster = oldFolderOf($this->draft).'/poster.jpg';
    $this->draft->forceFill(['thumbnail_path' => $this->draftPoster])->saveQuietly();
    $disk->put($this->draftPoster, 'draft poster');
    $this->oldLibraryRow = Media::factory()->create(['organization_id' => $this->alpha->id, 'path' => 'media/1/old.jpg', 'thumbnail_path' => $this->draftPoster]);

    // Alpha's published ad: its page, the page's poster, the design's poster, and a file nobody's row names.
    $this->live = BuilderAd::factory()->published()->create(['organization_id' => $this->alpha->id, 'name' => 'Alpha live']);
    $folder = oldFolderOf($this->live);
    $this->live->forceFill(['thumbnail_path' => "{$folder}/poster.jpg"])->saveQuietly();
    $this->live->media->forceFill(['path' => "{$folder}/index.html", 'thumbnail_path' => "{$folder}/published.jpg"])->saveQuietly();
    $disk->put("{$folder}/index.html", '<html>the live page</html>');
    $disk->put("{$folder}/published.jpg", 'published poster');
    $disk->put("{$folder}/poster.jpg", 'live draft poster');
    $disk->put("{$folder}/notes.txt", 'nobody names me');

    // The platform's template, and Beta's copy of it (its copied_from_id must come through untouched).
    $this->template = BuilderAd::factory()->published()->create(['organization_id' => null, 'name' => 'Burger menu']);
    $folder = oldFolderOf($this->template);
    $this->template->media->forceFill(['organization_id' => null, 'path' => "{$folder}/index.html", 'thumbnail_path' => "{$folder}/published.jpg"])->saveQuietly();
    $disk->put("{$folder}/index.html", '<html>the template</html>');
    $disk->put("{$folder}/published.jpg", 'template poster');

    // Beta's copy names a poster that is not on the disk.
    $this->copy = BuilderAd::factory()->create(['organization_id' => $this->beta->id, 'name' => 'Burger menu', 'copied_from_id' => $this->template->id]);
    $this->lostPoster = oldFolderOf($this->copy).'/poster.jpg';
    $this->copy->forceFill(['thumbnail_path' => $this->lostPoster])->saveQuietly();

    // A design with no poster of its own, whose folder still holds one no row names.
    $this->bare = BuilderAd::factory()->create(['organization_id' => $this->beta->id, 'name' => 'Beta bare']);
    $disk->put(oldFolderOf($this->bare).'/poster.jpg', 'a poster nobody names');

    // An ad deleted long ago left a file behind: no row names it.
    $this->leftBehind = "builder/{$this->alpha->id}/ads/999999/poster.jpg";
    $disk->put($this->leftBehind, 'an old poster');

    // An asset of the shelf, which is no ad's folder.
    $disk->put("builder/{$this->alpha->id}/assets/01HXYZ.webp", 'a shelf file');

    // The database as it was before the migration: no keys, every folder named by its id.
    adFoldersMigration()->down();
    expect(Schema::hasColumn('builder_ads', 'storage_key'))->toBeFalse();

    $this->rowsBefore = adFolderRows();
    $this->filesBefore = everyFileOnTheDisk();
});

test('every ad gets a key, its folder moves there with every file in it, and the rows name the files where they are', function () {
    Log::spy();

    adFoldersMigration()->up();

    $keys = DB::table('builder_ads')->pluck('storage_key', 'id');
    expect($keys)->toHaveCount(5)
        ->and($keys->every(fn (?string $key): bool => is_string($key) && preg_match('/^[0-9a-f]{32}$/', $key) === 1))->toBeTrue()
        ->and($keys->unique())->toHaveCount(5);

    $disk = Storage::disk('public');
    $live = BuilderAd::findOrFail($this->live->id);
    $folder = $live->storageDirectory();

    // The published ad: its page and the page's poster under their names, the design's poster under the name of its own, the
    // file nobody names with them — and nothing left where the id was.
    expect($folder)->toBe("builder/{$this->alpha->id}/ads/{$keys[$live->id]}")
        ->and($live->media->path)->toBe("{$folder}/index.html")
        ->and($live->media->thumbnail_path)->toBe("{$folder}/published.jpg")
        ->and($live->thumbnail_path)->toBe(ownPosterOf($live))
        ->and($live->posterPath())->toBe($live->thumbnail_path)
        ->and($disk->get($live->media->path))->toBe('<html>the live page</html>')
        ->and($disk->get($live->media->thumbnail_path))->toBe('published poster')
        ->and($disk->get($live->thumbnail_path))->toBe('live draft poster')
        ->and($disk->get("{$folder}/notes.txt"))->toBe('nobody names me')
        ->and($disk->directoryExists(oldFolderOf($this->live)))->toBeFalse();
    $disk->assertMissing("{$folder}/poster.jpg");

    // The draft's poster, and the library row that named it, follow it.
    $draft = BuilderAd::findOrFail($this->draft->id);
    expect($draft->thumbnail_path)->toBe(ownPosterOf($draft))
        ->and($disk->get($draft->thumbnail_path))->toBe('draft poster')
        ->and(Media::findOrFail($this->oldLibraryRow->id)->thumbnail_path)->toBe($draft->thumbnail_path);

    // The platform's template, under the platform's folder.
    $template = BuilderAd::findOrFail($this->template->id);
    expect($template->media->path)->toBe("builder/platform/ads/{$keys[$template->id]}/index.html")
        ->and($disk->get($template->media->path))->toBe('<html>the template</html>')
        ->and($disk->directoryExists(oldFolderOf($this->template)))->toBeFalse();

    // A poster that was not there is named where it would be, and said.
    $copy = BuilderAd::findOrFail($this->copy->id);
    expect($copy->thumbnail_path)->toBe(ownPosterOf($copy))
        ->and($copy->copied_from_id)->toBe($this->template->id);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, "names {$this->lostPoster}, which is not there"))->once();

    // A poster no row names takes the name all the same: nothing at a name worked out from the folder's.
    $bare = BuilderAd::findOrFail($this->bare->id);
    expect($bare->thumbnail_path)->toBeNull()
        ->and($disk->get(ownPosterOf($bare)))->toBe('a poster nobody names');
    $disk->assertMissing($bare->storageDirectory().'/poster.jpg');

    // What no row names, under a folder no ad has, is left alone and said.
    expect($disk->get($this->leftBehind))->toBe('an old poster')
        ->and($disk->get("builder/{$this->alpha->id}/assets/01HXYZ.webp"))->toBe('a shelf file');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, "builder/{$this->alpha->id}/ads/999999 is still named by an id"))->once();

    // Nothing else of any row changed: not a design, not a clock (a published ad stays published, not "changed").
    $after = adFolderRows();
    $strip = fn (array $rows, array $columns): array => array_map(fn (array $row): array => array_diff_key($row, array_flip($columns)), $rows);
    expect($strip($after['builder_ads'], ['storage_key', 'thumbnail_path']))->toBe($strip($this->rowsBefore['builder_ads'], ['thumbnail_path']))
        ->and($strip($after['media'], ['path', 'thumbnail_path']))->toBe($strip($this->rowsBefore['media'], ['path', 'thumbnail_path']))
        ->and($live->status())->toBe('published');

    // And nothing anywhere is still named by an ad's id, but what nobody's row names.
    expect(filesByAnId())->toBe([$this->leftBehind]);
});

test('run twice, it changes nothing', function () {
    adFoldersMigration()->up();
    $rows = adFolderRows();
    $files = everyFileOnTheDisk();

    adFoldersMigration()->up();

    expect(adFolderRows())->toBe($rows)
        ->and(everyFileOnTheDisk())->toBe($files);
});

test('cut short anywhere, it carries on with the same keys and the same names', function () {
    adFoldersMigration()->up();
    $done = [adFolderRows(), everyFileOnTheDisk()];
    $disk = Storage::disk('public');
    $live = BuilderAd::findOrFail($this->live->id);
    $draft = BuilderAd::findOrFail($this->draft->id);

    // Cut after the live ad's poster took its name but before its row said so, and after its page moved but before its
    // row said so; and cut before the draft's folder moved at all, its key given.
    DB::table('builder_ads')->where('id', $live->id)->update(['thumbnail_path' => $live->storageDirectory().'/poster.jpg']);
    DB::table('media')->where('id', $live->media_id)->update(['path' => oldFolderOf($live).'/index.html']);
    $disk->move($draft->thumbnail_path, $this->draftPoster);
    DB::table('builder_ads')->where('id', $draft->id)->update(['thumbnail_path' => $this->draftPoster]);
    DB::table('media')->where('id', $this->oldLibraryRow->id)->update(['thumbnail_path' => $this->draftPoster]);

    adFoldersMigration()->up();

    expect([adFolderRows(), everyFileOnTheDisk()])->toBe($done);
});

test('down() moves every folder back to its id, gives the poster its old name, and drops the column', function () {
    adFoldersMigration()->up();
    adFoldersMigration()->down();

    expect(Schema::hasColumn('builder_ads', 'storage_key'))->toBeFalse()
        ->and(adFolderRows())->toBe($this->rowsBefore)
        ->and(everyFileOnTheDisk())->toBe($this->filesBefore);

    // And forward again, with keys of their own.
    adFoldersMigration()->up();
    expect(DB::table('builder_ads')->whereNull('storage_key')->count())->toBe(0);
});

test('an ad\'s files under the owner it had before, and a file its row names in a gone ad\'s folder, move to its folder too', function () {
    Log::spy();
    $disk = Storage::disk('public');

    // Alpha's draft was Beta's when its poster was saved (before 2026-10-09 the editor could hand an ad to another owner) ...
    $before = "builder/{$this->beta->id}/ads/{$this->draft->id}";
    $disk->move($this->draftPoster, "{$before}/poster.jpg");
    $disk->put("{$before}/index.html", '<html>an old page</html>');
    DB::table('builder_ads')->where('id', $this->draft->id)->update(['thumbnail_path' => "{$before}/poster.jpg"]);
    DB::table('media')->where('id', $this->oldLibraryRow->id)->update(['thumbnail_path' => "{$before}/poster.jpg"]);

    // ... and Beta's copy names the poster of an ad deleted long ago, beside a file nobody names.
    $gone = "builder/{$this->beta->id}/ads/777777";
    $disk->put("{$gone}/poster.jpg", "a gone ad's poster");
    $disk->put("{$gone}/index.html", 'nobody names me');
    DB::table('builder_ads')->where('id', $this->copy->id)->update(['thumbnail_path' => "{$gone}/poster.jpg"]);

    adFoldersMigration()->up();

    $draft = BuilderAd::findOrFail($this->draft->id);
    expect($draft->thumbnail_path)->toBe(ownPosterOf($draft))
        ->and($draft->storageDirectory())->toStartWith("builder/{$this->alpha->id}/ads/")
        ->and($disk->get($draft->thumbnail_path))->toBe('draft poster')
        ->and(Media::findOrFail($this->oldLibraryRow->id)->thumbnail_path)->toBe($draft->thumbnail_path)
        ->and($disk->get($draft->storageDirectory().'/index.html'))->toBe('<html>an old page</html>')
        ->and($disk->directoryExists($before))->toBeFalse();

    $copy = BuilderAd::findOrFail($this->copy->id);
    expect($copy->thumbnail_path)->toBe(ownPosterOf($copy))
        ->and($disk->get($copy->thumbnail_path))->toBe("a gone ad's poster");

    // The rest of the gone ad's folder is nobody's: left, and said.
    expect(filesByAnId())->toEqualCanonicalizing([$this->leftBehind, "{$gone}/index.html"]);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, "{$gone} is still named by an id"))->once();
});

test('a page published before Publish kept a poster of its own gets one, and none while its design has changed since', function () {
    adFoldersMigration()->up();
    $disk = Storage::disk('public');

    // Two ads published before 2026-09-22: no version kept, the page's row naming the design's own poster, which every save of
    // the draft redrew. One has not changed since; the other has.
    $make = function (string $name, int $editedLater) use ($disk): BuilderAd {
        $ad = BuilderAd::factory()->published()->create(['organization_id' => $this->alpha->id, 'name' => $name]);
        $poster = $ad->storageDirectory().'/poster.jpg';
        $disk->put($poster, "{$name} poster");
        $disk->put($ad->storageDirectory().'/index.html', "<html>{$name}</html>");
        DB::table('builder_ads')->where('id', $ad->id)->update([
            'thumbnail_path' => $poster, 'published_document' => null, 'published_name' => null,
            'published_at' => '2026-09-20 10:00:00', 'updated_at' => '2026-09-20 10:00:'.str_pad((string) $editedLater, 2, '0', STR_PAD_LEFT),
        ]);
        DB::table('media')->where('id', $ad->media_id)->update(['path' => $ad->storageDirectory().'/index.html', 'thumbnail_path' => $poster]);

        return $ad;
    };
    $kept = $make('Old menu', 0);
    $changed = $make('Old offer', 30);
    adFoldersMigration()->down();
    Log::spy();

    adFoldersMigration()->up();

    // Unchanged: the page keeps the picture as its own copy, which a save of the draft no longer redraws.
    $kept = BuilderAd::findOrFail($kept->id);
    expect($kept->media->thumbnail_path)->toBe($kept->storageDirectory().'/published.jpg')
        ->and($disk->get($kept->media->thumbnail_path))->toBe('Old menu poster')
        ->and($kept->thumbnail_path)->toBe(ownPosterOf($kept))
        ->and($disk->get($kept->thumbnail_path))->toBe('Old menu poster');

    // Changed since: its poster may be the draft's, so the page shows none until it is published again.
    $changed = BuilderAd::findOrFail($changed->id);
    expect($changed->media->thumbnail_path)->toBeNull()
        ->and($changed->thumbnail_path)->toBe(ownPosterOf($changed))
        ->and($disk->get($changed->thumbnail_path))->toBe('Old offer poster');
    Log::shouldHaveReceived('info')->withArgs(fn (string $message): bool => str_contains($message, "media #{$changed->media_id} showed the design's own poster"))->once();

    // Run again, nothing; and Publish gives the changed one its picture.
    $rows = adFolderRows();
    adFoldersMigration()->up();
    expect(adFolderRows())->toBe($rows);

    app(AdPublisher::class)->publish($changed);
    expect($changed->fresh()->media->thumbnail_path)->toBe($changed->storageDirectory().'/published.jpg');
});

test('a file whose new place is taken: nobody\'s goes, a row\'s takes a place nobody\'s, and rows naming both keep both', function () {
    Log::spy();
    // A key given and some files written at the new place before the run (a run cut short, or a deploy's switch-over).
    Schema::table('builder_ads', fn (Blueprint $table) => $table->string('storage_key', 32)->nullable()->unique()->after('id'));
    $key = str_repeat('a', 32);
    DB::table('builder_ads')->where('id', $this->live->id)->update(['storage_key' => $key]);
    $new = "builder/{$this->alpha->id}/ads/{$key}";
    $old = oldFolderOf($this->live);
    $disk = Storage::disk('public');

    // The page's row names the old index.html; nobody the new one.
    $disk->put("{$new}/index.html", '<html>nobody names me</html>');
    // Nobody names the old notes.txt, and its place is taken.
    $disk->put("{$new}/notes.txt", 'newer notes');
    // The page's row names the old published.jpg, and another row the new one.
    $disk->put("{$new}/published.jpg", "another row's picture");
    Media::factory()->create(['organization_id' => $this->alpha->id, 'path' => 'media/1/other.jpg', 'thumbnail_path' => "{$new}/published.jpg"]);

    adFoldersMigration()->up();

    expect($disk->get("{$new}/index.html"))->toBe('<html>the live page</html>')
        ->and(Media::findOrFail($this->live->media_id)->path)->toBe("{$new}/index.html")
        ->and($disk->get("{$new}/notes.txt"))->toBe('newer notes')
        ->and($disk->exists("{$old}/notes.txt"))->toBeFalse()
        ->and($disk->get("{$old}/published.jpg"))->toBe('published poster')
        ->and(Media::findOrFail($this->live->media_id)->thumbnail_path)->toBe("{$old}/published.jpg")
        ->and($disk->get("{$new}/published.jpg"))->toBe("another row's picture")
        ->and(DB::table('builder_ads')->where('id', $this->live->id)->value('storage_key'))->toBe($key);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, "{$new}/published.jpg is there already, so {$old}/published.jpg stays where it is"))->once();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, "{$old}/notes.txt was named by no row"))->once();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, "{$old} is still named by an id"))->once();
});

test('builder:move-ad-folders, run after a deploy\'s switch, takes what the release before wrote by the id to each ad\'s own folder', function () {
    // Before the migration has run, there is nothing for it to do.
    $this->artisan('builder:move-ad-folders')->expectsOutputToContain('Run the migrations first')->assertFailed();

    adFoldersMigration()->up();
    $disk = Storage::disk('public');
    $live = BuilderAd::findOrFail($this->live->id);
    $folder = $live->storageDirectory();
    $old = oldFolderOf($live);

    // The release before goes on serving while this one migrates: it saves the live ad with a new poster and publishes it, by
    // the id ...
    $disk->put("{$old}/poster.jpg", 'saved in the window');
    $disk->put("{$old}/index.html", '<html>published in the window</html>');
    $disk->put("{$old}/published.jpg", 'saved in the window');
    DB::table('builder_ads')->where('id', $live->id)->update(['thumbnail_path' => "{$old}/poster.jpg"]);
    DB::table('media')->where('id', $live->media_id)->update(['path' => "{$old}/index.html", 'thumbnail_path' => "{$old}/published.jpg"]);

    // ... and makes an ad, which it gives no key.
    $madeId = DB::table('builder_ads')->insertGetId([
        'organization_id' => $this->beta->id, 'name' => 'Made in the window', 'orientation' => 'landscape',
        'document' => json_encode(BuilderAd::blankDocument()), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $madePoster = "builder/{$this->beta->id}/ads/{$madeId}/poster.jpg";
    $disk->put($madePoster, 'a new design');
    DB::table('builder_ads')->where('id', $madeId)->update(['thumbnail_path' => $madePoster]);

    $this->artisan('builder:move-ad-folders')
        ->expectsOutputToContain("builder/{$this->alpha->id}/ads/999999 is still named by an id and holds 1 file(s).")
        ->assertSuccessful();

    $live = BuilderAd::findOrFail($live->id);
    expect($live->thumbnail_path)->toBe(ownPosterOf($live))
        ->and($disk->get($live->thumbnail_path))->toBe('saved in the window')
        ->and($live->media->path)->toBe("{$folder}/index.html")
        ->and($disk->get($live->media->path))->toBe('<html>published in the window</html>')
        ->and($live->media->thumbnail_path)->toBe("{$folder}/published.jpg")
        ->and($disk->get($live->media->thumbnail_path))->toBe('saved in the window')
        ->and($disk->files($folder))->toEqualCanonicalizing(["{$folder}/index.html", "{$folder}/published.jpg", $live->thumbnail_path, "{$folder}/notes.txt"]);

    $made = BuilderAd::findOrFail($madeId);
    expect($made->storage_key)->toMatch('/^[0-9a-f]{32}$/')
        ->and($made->thumbnail_path)->toBe(ownPosterOf($made))
        ->and($disk->get($made->thumbnail_path))->toBe('a new design');

    expect(filesByAnId())->toBe([$this->leftBehind]);

    // With nothing left by an id, it says so.
    $disk->delete($this->leftBehind);
    $this->artisan('builder:move-ad-folders')->expectsOutput("Every ad's files are in its own folder.")->assertSuccessful();
});

test('after it, Publish writes the page in the new folder and nothing comes back to the old one', function () {
    adFoldersMigration()->up();
    $live = BuilderAd::findOrFail($this->live->id);
    $before = $live->media->path;

    app(AdPublisher::class)->publish($live);

    $live->refresh();
    expect($live->media->path)->toBe($before)
        ->and(Storage::disk('public')->get($live->media->path))->toContain('<meta name="ad-compiler"')
        ->and(Storage::disk('public')->directoryExists(oldFolderOf($live)))->toBeFalse();
});

test('2026_10_08_100200 still makes and publishes ads on a database that has neither the key nor copied_from_id yet', function () {
    // The platform's template on an organization's screen, as a database brought forward from before 2026-10-08 has it.
    $tv = Screen::factory()->create(['organization_id' => $this->alpha->id, 'name' => 'Counter TV']);
    $line = PlaylistItem::create(['screen_id' => $tv->id, 'media_id' => $this->template->media_id, 'position' => 0, 'duration_seconds' => 30]);
    $copiedFrom = require database_path('migrations/2026_10_08_100400_add_copied_from_id_to_builder_ads_table.php');
    $copiedFrom->down();

    (require database_path('migrations/2026_10_08_100200_give_every_organization_its_own_copy_of_the_platform_files_on_its_screens.php'))->up();

    // Made in the folder of its day ...
    $id = (int) DB::table('builder_ads')->where('organization_id', $this->alpha->id)->where('name', 'Burger menu')->value('id');
    $page = DB::table('media')->where('id', $line->fresh()->media_id)->first();
    expect($page->path)->toBe("builder/{$this->alpha->id}/ads/{$id}/index.html");
    Storage::disk('public')->assertExists($page->path);

    // ... and moved, with the rest, by the migrations after it.
    $copiedFrom->up();
    adFoldersMigration()->up();

    $copy = BuilderAd::findOrFail($id);
    expect($copy->storage_key)->toMatch('/^[0-9a-f]{32}$/')
        ->and($copy->copied_from_id)->toBe($this->template->id)
        ->and($copy->media_id)->toBe($line->fresh()->media_id)
        ->and($copy->media->path)->toBe($copy->storageDirectory().'/index.html')
        ->and(Storage::disk('public')->get($copy->media->path))->toContain('<meta name="ad-compiler"')
        ->and($copy->thumbnail_path)->toBe(ownPosterOf($copy))
        ->and(filesByAnId())->toBe([$this->leftBehind]);
});
