<?php

use App\Models\BuilderAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Screen;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Guessing an ad's folder (the review of 2026-10-15)
|--------------------------------------------------------------------------
|
| The web server hands out whatever on the public disk has an address, to anybody. On live,
| `/storage/builder/18/ads/13/poster.jpg` and its `index.html` answered a stranger who had only counted: every
| organization's designs, drafts included. A stranger knows the ids — an ad's in the editor's address, a page's in every
| answer — and the folder is named by neither. Somebody handed a published address (a television, an organization shown a
| Premium Template) finds only what is published beside it: the draft's picture has a name of its own.
|
*/

/** A picture the way the editor's canvas hands one over. */
function guessingPoster(int $red): string
{
    $image = imagecreatetruecolor(160, 90);
    imagefilledrectangle($image, 0, 0, 160, 90, imagecolorallocate($image, $red, 20, 20));

    ob_start();
    imagejpeg($image, null, 80);

    return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
}

beforeEach(function () {
    Storage::fake('public');

    $this->alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
    $designer = createOrganizationUser($this->alpha, ['ad-view', 'ad-store', 'ad-update', 'ad-destroy'], 'Designer');
    $this->actingAs($designer)->withSession(['current_organization_id' => $this->alpha->id]);

    // A published ad whose draft has moved on, and a draft never published.
    $id = $this->postJson('/builder', ['name' => 'Winter sale', 'document' => BuilderAd::blankDocument(), 'thumbnail' => guessingPoster(10)])->json('ad.id');
    $this->postJson("/builder/{$id}/publish")->assertOk();
    $document = BuilderAd::blankDocument();
    $document['stage']['background']['color'] = '#ff0000';
    $this->putJson("/builder/{$id}", ['name' => 'Winter sale', 'document' => $document, 'thumbnail' => guessingPoster(250)])->assertOk();
    $this->live = BuilderAd::findOrFail($id);

    $this->draft = BuilderAd::findOrFail(
        $this->postJson('/builder', ['name' => 'Secret launch', 'document' => BuilderAd::blankDocument(), 'thumbnail' => guessingPoster(120)])->json('ad.id'),
    );
});

test('counting through every id finds nothing on the disk: not a page, not a poster, not a draft', function () {
    $disk = Storage::disk('public');
    $found = [];

    foreach ([(string) $this->alpha->id, 'platform', '0', '1'] as $owner) {
        foreach (range(0, 40) as $id) {
            foreach (['', 'index.html', 'poster.jpg', 'published.jpg', 'poster.png', 'thumb.jpg'] as $file) {
                $guess = rtrim("builder/{$owner}/ads/{$id}/{$file}", '/');

                if ($disk->exists($guess) || $disk->directoryExists($guess)) {
                    $found[] = $guess;
                }
            }
        }
    }

    expect($found)->toBe([])
        // The ads are there all the same: the guesses missed, nothing was hidden by deleting it.
        ->and($disk->exists($this->live->media->path))->toBeTrue()
        ->and($disk->exists($this->draft->thumbnail_path))->toBeTrue();
});

test('a television handed the published page finds only what is published beside it', function () {
    $screen = Screen::factory()->withToken('guessing-token')->create(['organization_id' => $this->alpha->id]);
    PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $this->live->media_id, 'position' => 0, 'duration_seconds' => 12]);

    $url = $this->getJson('/device/playlist', ['Authorization' => 'Bearer guessing-token'])->assertOk()->json('items.0.url');
    $handed = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
    $folder = dirname(preg_replace('#^storage/#', '', $handed));

    expect($folder)->toBe($this->live->storageDirectory());

    // Next to the page: its own poster copy — and no draft picture under any name worked out from them.
    $disk = Storage::disk('public');
    expect($disk->exists("{$folder}/published.jpg"))->toBeTrue()
        ->and($disk->exists("{$folder}/poster.jpg"))->toBeFalse()
        ->and($disk->get("{$folder}/published.jpg"))->not->toBe($disk->get($this->live->thumbnail_path))
        ->and(basename($this->live->thumbnail_path))->toMatch('/^poster-[0-9a-f]{32}\.jpg$/');
});

test('no answer hands out the key on its own, and a key posted by hand is never taken', function () {
    $rows = collect($this->getJson('/builder/data')->assertOk()->json('ads'));
    expect($rows->every(fn (array $row) => ! array_key_exists('storage_key', $row)))->toBeTrue();

    // A save naming a key of its own — another ad's, or one that climbs out of the folder — keeps the ad's own.
    $key = $this->live->storage_key;
    $this->putJson("/builder/{$this->draft->id}", [
        'name' => 'Secret launch', 'document' => BuilderAd::blankDocument(), 'storage_key' => $key,
    ])->assertOk();
    $this->postJson('/builder', ['name' => 'Third', 'document' => BuilderAd::blankDocument(), 'storage_key' => '../../media'])->assertOk();

    expect($this->draft->fresh()->storage_key)->not->toBe($key)
        ->and(BuilderAd::where('name', 'Third')->sole()->storage_key)->toMatch('/^[0-9a-f]{32}$/')
        ->and(BuilderAd::pluck('storage_key')->unique())->toHaveCount(3)
        ->and(Media::where('path', 'like', '%/ads/'.$this->live->id.'/%')->exists())->toBeFalse();
});

test('what the release before wrote by the id while a deploy switched over is out of reach once the deploy has moved it', function () {
    $disk = Storage::disk('public');
    $old = "builder/{$this->alpha->id}/ads/{$this->live->id}";
    $made = "builder/{$this->alpha->id}/ads/{$this->draft->id}";

    // The release before goes on serving while the new one migrates: a draft saved with its poster, a page published.
    $disk->put("{$old}/index.html", '<html>published in the window</html>');
    $disk->put("{$old}/published.jpg", 'the published picture');
    $disk->put("{$made}/poster.jpg", 'a secret draft');
    $this->live->media->forceFill(['path' => "{$old}/index.html", 'thumbnail_path' => "{$old}/published.jpg"])->saveQuietly();
    $this->draft->forceFill(['thumbnail_path' => "{$made}/poster.jpg"])->saveQuietly();

    $this->artisan('builder:move-ad-folders')->assertSuccessful();

    foreach (['index.html', 'published.jpg', 'poster.jpg'] as $file) {
        $disk->assertMissing("{$old}/{$file}");
        $disk->assertMissing("{$made}/{$file}");
    }

    // Moved, not lost.
    expect($disk->get($this->live->fresh()->media->path))->toBe('<html>published in the window</html>')
        ->and($disk->get($this->draft->fresh()->thumbnail_path))->toBe('a secret draft')
        ->and($this->draft->fresh()->thumbnail_path)->not->toContain("/ads/{$this->draft->id}/");
});
