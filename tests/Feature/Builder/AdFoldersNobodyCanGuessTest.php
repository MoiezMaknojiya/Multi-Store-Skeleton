<?php

use App\Models\BuilderAd;
use App\Models\Media;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| An ad's folder is nobody's to guess (the review of 2026-10-15, owner's yes)
|--------------------------------------------------------------------------
|
| The public disk is served to anybody with an address. An ad's page and posters lay at `builder/{organization}/ads/{id}`,
| so a stranger who counted through the ids read every organization's designs, unpublished drafts included — on live,
| without signing in. Every ad's folder is now named by its storage key, 128 random bits (BuilderAd::storageDirectory), and
| everything that writes or reads it — a save's poster, Publish, Discard changes, Duplicate, Use This Template, Delete,
| builder:recompile, builder:examples — goes there.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->designer = createOrganizationUser($this->organization, ['ad-view', 'ad-store', 'ad-update', 'ad-destroy'], 'Designer');
    $this->actingAs($this->designer)->withSession(['current_organization_id' => $this->organization->id]);
});

/** A poster the way the editor's canvas hands one over. */
function unguessablePoster(int $red = 30): string
{
    $image = imagecreatetruecolor(320, 180);
    imagefilledrectangle($image, 0, 0, 320, 180, imagecolorallocate($image, $red, 120, 220));

    ob_start();
    imagejpeg($image, null, 85);

    return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
}

/** An ad saved from the editor, with its poster. */
function savedWithItsPoster(object $test, string $name = 'Winter sale'): BuilderAd
{
    $id = $test->postJson('/builder', ['name' => $name, 'document' => BuilderAd::blankDocument(), 'thumbnail' => unguessablePoster()])
        ->assertOk()
        ->assertJsonMissingPath('ad.storage_key')
        ->json('ad.id');

    return BuilderAd::findOrFail($id);
}

/** Every address a stranger could make of the ad's ids: its own id and its page's, in every folder an ad may have. */
function addressesFromTheIds(BuilderAd $ad): array
{
    $addresses = [];

    foreach (array_filter([$ad->id, $ad->media_id]) as $id) {
        foreach (['builder/'.($ad->organization_id ?? 'platform'), 'builder/platform'] as $owner) {
            foreach (['index.html', 'poster.jpg', 'published.jpg'] as $file) {
                $addresses[] = "{$owner}/ads/{$id}/{$file}";
            }
        }
    }

    return $addresses;
}

/** The design's own poster: in its folder, by a name of its own (BuilderAd::posterPath). */
function ownPosterPattern(BuilderAd $ad): string
{
    return '#^'.preg_quote($ad->storageDirectory(), '#').'/poster-[0-9a-f]{32}\.jpg$#';
}

function assertNothingAtTheIds(BuilderAd $ad): void
{
    foreach (addressesFromTheIds($ad) as $address) {
        Storage::disk('public')->assertMissing($address);
    }

    expect(Storage::disk('public')->directoryExists('builder/'.($ad->organization_id ?? 'platform')."/ads/{$ad->id}"))->toBeFalse();
}

test('a new ad\'s folder is named by a key nobody can count to, never by its ids, and the key is never handed out', function () {
    $ad = savedWithItsPoster($this);
    $other = savedWithItsPoster($this, 'Spring sale');

    expect($ad->storage_key)->toMatch('/^[0-9a-f]{32}$/')
        ->and($other->storage_key)->toMatch('/^[0-9a-f]{32}$/')
        ->and($other->storage_key)->not->toBe($ad->storage_key)
        ->and($ad->storageDirectory())->toBe("builder/{$this->organization->id}/ads/{$ad->storage_key}")
        ->and($ad->thumbnail_path)->toMatch(ownPosterPattern($ad))
        ->and($ad->thumbnail_path)->not->toContain("/ads/{$ad->id}/");

    Storage::disk('public')->assertExists($ad->thumbnail_path);
    assertNothingAtTheIds($ad);

    // The listing shows the poster's address, which a person who may see the ad has anyway — never the key as a field.
    $rows = collect($this->getJson('/builder/data')->assertOk()->json('ads'));
    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn (array $row) => ! array_key_exists('storage_key', $row)))->toBeTrue();
});

test('Publish, a new poster and Discard changes write in the ad\'s own folder, and the ids find nothing', function () {
    $ad = savedWithItsPoster($this);

    $this->postJson("/builder/{$ad->id}/publish")->assertOk();
    $ad->refresh();
    $page = $ad->media;

    expect($page->path)->toBe($ad->storageDirectory().'/index.html')
        ->and($page->thumbnail_path)->toBe($ad->storageDirectory().'/published.jpg');
    Storage::disk('public')->assertExists($page->path);
    Storage::disk('public')->assertExists($page->thumbnail_path);

    // A changed draft with a new poster, then thrown away: back to the published poster, in the same folder.
    $document = BuilderAd::blankDocument();
    $document['stage']['background']['color'] = '#ff0000';
    $this->putJson("/builder/{$ad->id}", ['name' => 'Winter sale', 'document' => $document, 'thumbnail' => unguessablePoster(200)])->assertOk();
    $this->postJson("/builder/{$ad->id}/discard")->assertOk();
    $ad->refresh();

    expect($ad->thumbnail_path)->toMatch(ownPosterPattern($ad))
        ->and(Storage::disk('public')->get($ad->thumbnail_path))->toBe(Storage::disk('public')->get($page->thumbnail_path));

    // Nothing anywhere else, and no draft picture under a name worked out from the published page's.
    assertNothingAtTheIds($ad);
    expect(Storage::disk('public')->allFiles('builder'))->toEqualCanonicalizing([
        $ad->storageDirectory().'/index.html', $ad->thumbnail_path, $ad->storageDirectory().'/published.jpg',
    ]);
});

test('a poster under the old name gets a name of its own on the next save, and the old file goes unless a row still names it', function () {
    $ad = savedWithItsPoster($this);
    $legacy = $ad->storageDirectory().'/poster.jpg';
    Storage::disk('public')->move($ad->thumbnail_path, $legacy);
    $ad->forceFill(['thumbnail_path' => $legacy])->saveQuietly();

    $this->putJson("/builder/{$ad->id}", ['name' => $ad->name, 'document' => BuilderAd::blankDocument(), 'thumbnail' => unguessablePoster(90)])->assertOk();
    $ad->refresh();

    expect($ad->thumbnail_path)->toMatch(ownPosterPattern($ad));
    Storage::disk('public')->assertExists($ad->thumbnail_path);
    Storage::disk('public')->assertMissing($legacy);

    // A library row from before Publish kept its own copy still names it: it stays for that row.
    Storage::disk('public')->put($legacy, 'an old poster');
    $ad->forceFill(['thumbnail_path' => $legacy])->saveQuietly();
    Media::factory()->create(['organization_id' => $this->organization->id, 'thumbnail_path' => $legacy]);

    $this->putJson("/builder/{$ad->id}", ['name' => $ad->name, 'document' => BuilderAd::blankDocument(), 'thumbnail' => unguessablePoster(120)])->assertOk();

    expect($ad->fresh()->thumbnail_path)->toMatch(ownPosterPattern($ad));
    Storage::disk('public')->assertExists($legacy);
});

test('a copy has a folder of its own, and deleting the original leaves it whole', function () {
    $ad = savedWithItsPoster($this);

    $copy = BuilderAd::findOrFail($this->postJson("/builder/{$ad->id}/duplicate")->assertOk()->assertJsonMissingPath('ad.storage_key')->json('ad.id'));

    expect($copy->storage_key)->toMatch('/^[0-9a-f]{32}$/')
        ->and($copy->storage_key)->not->toBe($ad->storage_key)
        ->and($copy->thumbnail_path)->toMatch(ownPosterPattern($copy));
    assertNothingAtTheIds($copy);

    $this->deleteJson("/builder/{$ad->id}", ['password' => 'password'])->assertOk();

    Storage::disk('public')->assertMissing($ad->thumbnail_path);
    Storage::disk('public')->assertExists($copy->thumbnail_path);
});

test('deleting an ad takes every file its folder was given', function () {
    $ad = savedWithItsPoster($this);
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();
    $ad->refresh();
    $files = [$ad->thumbnail_path, $ad->media->path, $ad->media->thumbnail_path];

    $this->deleteJson("/builder/{$ad->id}", ['password' => 'password'])->assertOk();

    foreach ($files as $file) {
        Storage::disk('public')->assertMissing($file);
    }
    expect(Storage::disk('public')->allFiles('builder'))->toBe([]);
});

test('an ad for every organization lives in the platform\'s folder by its key, and Use This Template gives the copy one of its own', function () {
    $this->actingAs(createSuperAdmin());
    $this->flushSession();
    $templateId = $this->postJson('/builder', ['name' => 'Burger menu', 'document' => BuilderAd::blankDocument(), 'thumbnail' => unguessablePoster()])
        ->assertOk()->json('ad.id');
    $this->postJson("/builder/{$templateId}/publish")->assertOk();
    $template = BuilderAd::findOrFail($templateId);

    expect($template->storageDirectory())->toBe("builder/platform/ads/{$template->storage_key}")
        ->and($template->thumbnail_path)->toMatch(ownPosterPattern($template))
        ->and($template->media->path)->toBe($template->storageDirectory().'/index.html')
        ->and($template->media->thumbnail_path)->toBe($template->storageDirectory().'/published.jpg');
    assertNothingAtTheIds($template);

    // The gallery hands every organization the published poster's address; the template's draft picture beside it has a name
    // nobody can work out from that one.
    $this->actingAs($this->designer)->withSession(['current_organization_id' => $this->organization->id]);
    $row = collect($this->getJson('/builder/templates?orientation=landscape')->assertOk()->json('templates'))->firstWhere('id', $template->id);
    expect($row['thumbnail_url'])->toContain($template->storageDirectory().'/published.jpg')
        ->and($row)->not->toHaveKey('storage_key');
    Storage::disk('public')->assertMissing($template->storageDirectory().'/poster.jpg');

    $copy = BuilderAd::findOrFail($this->postJson("/builder/templates/{$template->id}")->assertOk()->json('ad.id'));

    expect($copy->storage_key)->toMatch('/^[0-9a-f]{32}$/')
        ->and($copy->storage_key)->not->toBe($template->storage_key)
        ->and($copy->storageDirectory())->toBe("builder/{$this->organization->id}/ads/{$copy->storage_key}")
        ->and($copy->thumbnail_path)->toMatch(ownPosterPattern($copy));
    Storage::disk('public')->assertExists($copy->thumbnail_path);
    assertNothingAtTheIds($copy);
});

test('builder:recompile writes each page where its row names it, and makes no folder by the id', function () {
    $ad = savedWithItsPoster($this);
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();
    $ad->refresh();
    Storage::disk('public')->put($ad->media->path, '<html>an older compiler</html>');

    $this->artisan('builder:recompile')->assertSuccessful();

    expect(Storage::disk('public')->get($ad->media->path))->toContain('<meta name="ad-compiler"');
    assertNothingAtTheIds($ad);
});

test('builder:examples publishes every example into a folder of its own', function () {
    Http::preventStrayRequests();

    $this->artisan('builder:examples', ['organization' => $this->organization->id, '--no-fonts' => true])->assertSuccessful();

    $ads = BuilderAd::where('organization_id', $this->organization->id)->with('media')->get();
    expect($ads)->toHaveCount(4)
        ->and($ads->pluck('storage_key')->unique())->toHaveCount(4);

    $ads->each(function (BuilderAd $ad) {
        expect($ad->media->path)->toBe($ad->storageDirectory().'/index.html');
        Storage::disk('public')->assertExists($ad->media->path);
        assertNothingAtTheIds($ad);
    });
});

test('a replica is given a key of its own, and an ad with no key, or a key that is not one, has no folder at all', function () {
    $ad = BuilderAd::factory()->create(['organization_id' => $this->organization->id]);
    $made = BuilderAd::factory()->make(['organization_id' => $this->organization->id]);

    expect($ad->replicate()->storage_key)->toMatch('/^[0-9a-f]{32}$/')->not->toBe($ad->storage_key)
        ->and($made->storage_key)->toMatch('/^[0-9a-f]{32}$/');

    // Never a folder shared by every key-less ad (`builder/1/ads/`), nor one a key could climb out of.
    expect(fn () => (new BuilderAd(['organization_id' => $this->organization->id]))->storageDirectory())->toThrow(LogicException::class);
    expect(fn () => $ad->forceFill(['storage_key' => '../../../media'])->storageDirectory())->toThrow(LogicException::class);
});

test('a saved ad with no key — written by the code before, while the migration ran — is given one, without moving its clock', function () {
    $ad = savedWithItsPoster($this);
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();
    DB::table('builder_ads')->where('id', $ad->id)->update(['storage_key' => null]);
    $ad = BuilderAd::findOrFail($ad->id);
    $stamp = $ad->updated_at?->toIso8601String();

    $this->travel(5)->seconds();
    $folder = $ad->storageDirectory();

    $fresh = BuilderAd::findOrFail($ad->id);
    expect($fresh->storage_key)->toMatch('/^[0-9a-f]{32}$/')
        ->and($folder)->toBe("builder/{$this->organization->id}/ads/{$fresh->storage_key}")
        ->and($fresh->updated_at?->toIso8601String())->toBe($stamp)
        ->and($fresh->status())->toBe('published')
        ->and($ad->isDirty())->toBeFalse();

    // Asked again, from another copy of the row, the same folder: the key is given once.
    expect(BuilderAd::findOrFail($ad->id)->storageDirectory())->toBe($folder);

    // And it publishes there.
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();
    expect(BuilderAd::findOrFail($ad->id)->media->path)->toBe("{$folder}/index.html");
});

test('the poster\'s name is its folder\'s own, the same whoever asks, so two saves at the same moment write one file', function () {
    $ad = BuilderAd::factory()->create(['organization_id' => $this->organization->id]);
    $expected = $ad->storageDirectory().'/poster-'.BuilderAd::posterNameFor($ad->storage_key).'.jpg';

    expect(BuilderAd::findOrFail($ad->id)->posterPath())->toBe($expected)
        ->and(BuilderAd::findOrFail($ad->id)->posterPath())->toBe($expected);

    // Drawn with the app's secret: nobody who knows the folder can work it out from the key.
    $secret = config('app.key');
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    expect('poster-'.BuilderAd::posterNameFor($ad->storage_key))->not->toBe(basename($expected, '.jpg'));
    config(['app.key' => $secret]);

    // The second save read the row before the first one stored its poster.
    $this->putJson("/builder/{$ad->id}", ['name' => $ad->name, 'document' => BuilderAd::blankDocument(), 'thumbnail' => unguessablePoster(40)])->assertOk();
    DB::table('builder_ads')->where('id', $ad->id)->update(['thumbnail_path' => null]);
    $this->putJson("/builder/{$ad->id}", ['name' => $ad->name, 'document' => BuilderAd::blankDocument(), 'thumbnail' => unguessablePoster(80)])->assertOk();

    expect(BuilderAd::findOrFail($ad->id)->thumbnail_path)->toBe($expected)
        ->and(Storage::disk('public')->allFiles($ad->storageDirectory()))->toBe([$expected]);
});

test('what the release before the key wrote by the id while a deploy switched over goes the next time the ad is saved, published or put back', function () {
    $ad = savedWithItsPoster($this);
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();
    $ad->refresh();
    $old = "builder/{$this->organization->id}/ads/{$ad->id}";
    $disk = Storage::disk('public');

    // The release before publishes the ad, then saves a change to it with a new poster, by the id.
    $theReleaseBefore = function () use ($ad, $old, $disk): void {
        $changed = BuilderAd::blankDocument();
        $changed['stage']['background']['color'] = '#00ff00';
        $disk->put("{$old}/index.html", '<html>published in the window</html>');
        $disk->put("{$old}/published.jpg", 'published in the window');
        $disk->put("{$old}/poster.jpg", 'a draft from the window');
        DB::table('media')->where('id', $ad->media_id)->update(['path' => "{$old}/index.html", 'thumbnail_path' => "{$old}/published.jpg"]);
        DB::table('builder_ads')->where('id', $ad->id)->update(['thumbnail_path' => "{$old}/poster.jpg", 'document' => json_encode($changed)]);
    };
    $theReleaseBefore();

    $this->putJson("/builder/{$ad->id}", ['name' => $ad->name, 'document' => BuilderAd::blankDocument(), 'thumbnail' => unguessablePoster(150)])->assertOk();
    expect(BuilderAd::findOrFail($ad->id)->thumbnail_path)->toBe($ad->posterPath());
    $disk->assertMissing("{$old}/poster.jpg");

    $this->postJson("/builder/{$ad->id}/publish")->assertOk();
    $ad->refresh();
    expect($ad->media->path)->toBe($ad->storageDirectory().'/index.html')
        ->and($ad->media->thumbnail_path)->toBe($ad->storageDirectory().'/published.jpg');
    assertNothingAtTheIds($ad);
    expect($disk->allFiles('builder'))->toEqualCanonicalizing([$ad->media->path, $ad->media->thumbnail_path, $ad->thumbnail_path]);

    // Discard changes puts back the poster the release before published, and forgets the draft's it wrote.
    $theReleaseBefore();
    $this->postJson("/builder/{$ad->id}/discard")->assertOk();
    $ad->refresh();
    expect($ad->thumbnail_path)->toBe($ad->posterPath())
        ->and($disk->get($ad->thumbnail_path))->toBe('published in the window');
    $disk->assertMissing("{$old}/poster.jpg");

    // A file there that another row still names is kept for it.
    $theReleaseBefore();
    Media::factory()->create(['organization_id' => $this->organization->id, 'thumbnail_path' => "{$old}/poster.jpg"]);
    $this->putJson("/builder/{$ad->id}", ['name' => $ad->name, 'document' => BuilderAd::blankDocument(), 'thumbnail' => unguessablePoster(170)])->assertOk();
    $disk->assertExists("{$old}/poster.jpg");
});

test('Publish first, after the release before saved a poster by the id: the poster goes home and the page is made from it there', function () {
    $ad = savedWithItsPoster($this);
    $old = "builder/{$this->organization->id}/ads/{$ad->id}";
    $disk = Storage::disk('public');

    // The release before saves the design with a new poster, by the id, and nothing saves it again before Publish.
    $disk->delete($ad->thumbnail_path);
    $disk->put("{$old}/poster.jpg", 'saved in the window');
    DB::table('builder_ads')->where('id', $ad->id)->update(['thumbnail_path' => "{$old}/poster.jpg"]);

    $this->postJson("/builder/{$ad->id}/publish")->assertOk();
    $ad = BuilderAd::findOrFail($ad->id);

    expect($ad->thumbnail_path)->toBe($ad->posterPath())
        ->and($ad->thumbnail_path)->toMatch(ownPosterPattern($ad))
        ->and($disk->get($ad->thumbnail_path))->toBe('saved in the window')
        ->and($disk->get($ad->media->thumbnail_path))->toBe('saved in the window')
        ->and($ad->status())->toBe('published');
    assertNothingAtTheIds($ad);
    expect($disk->allFiles('builder'))->toEqualCanonicalizing([$ad->media->path, $ad->media->thumbnail_path, $ad->thumbnail_path]);
});

test('Discard changes on an ad published with no picture takes the draft\'s poster away, and nothing is left beside the page', function () {
    $id = $this->postJson('/builder', ['name' => 'No picture', 'document' => BuilderAd::blankDocument()])->assertOk()->json('ad.id');
    $this->postJson("/builder/{$id}/publish")->assertOk();
    $ad = BuilderAd::findOrFail($id);
    expect($ad->thumbnail_path)->toBeNull()
        ->and($ad->media->thumbnail_path)->toBeNull();

    // A changed draft with a poster of its own, thrown away.
    $document = BuilderAd::blankDocument();
    $document['stage']['background']['color'] = '#123456';
    $this->putJson("/builder/{$id}", ['name' => 'No picture', 'document' => $document, 'thumbnail' => unguessablePoster(60)])->assertOk();
    $poster = BuilderAd::findOrFail($id)->thumbnail_path;
    Storage::disk('public')->assertExists($poster);

    $this->postJson("/builder/{$id}/discard")->assertOk();
    $ad = BuilderAd::findOrFail($id);

    expect($ad->thumbnail_path)->toBeNull()
        ->and(Storage::disk('public')->allFiles('builder'))->toBe([$ad->media->path]);
    Storage::disk('public')->assertMissing($poster);
});

test('an ad forgets only its own files: its folder\'s, and its id\'s under any owner, never another ad\'s', function () {
    $ad = BuilderAd::factory()->create(['organization_id' => $this->organization->id]);
    $other = BuilderAd::factory()->create(['organization_id' => $this->organization->id]);
    $disk = Storage::disk('public');

    expect($ad->ownsFile($ad->storageDirectory().'/index.html'))->toBeTrue()
        ->and($ad->ownsFile("builder/{$this->organization->id}/ads/{$ad->id}/poster.jpg"))->toBeTrue()
        ->and($ad->ownsFile("builder/platform/ads/{$ad->id}/poster.jpg"))->toBeTrue()
        ->and($ad->ownsFile($other->storageDirectory().'/index.html'))->toBeFalse()
        ->and($ad->ownsFile("builder/{$this->organization->id}/ads/{$other->id}/poster.jpg"))->toBeFalse()
        ->and($ad->ownsFile("builder/{$this->organization->id}/ads/{$ad->id}1/poster.jpg"))->toBeFalse()
        ->and($ad->ownsFile($ad->storageDirectory().'/sub/poster.jpg'))->toBeFalse()
        ->and($ad->ownsFile("media/{$this->organization->id}/poster.jpg"))->toBeFalse();

    $theirs = "builder/{$this->organization->id}/ads/{$other->id}/poster.jpg";
    $disk->put($theirs, 'not mine');
    $ad->forgetFile($theirs);
    $disk->assertExists($theirs);
});
