<?php

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Media;
use App\Models\Store;
use App\Services\AdPublisher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| The Ad Builder's own shelf
|--------------------------------------------------------------------------
|
| The pictures and videos that go INSIDE an ad (docs/AD-BUILDER-SPEC.md §3) — deliberately not the
| store's media library, which is what a shop plays. Same store wall, same formats, its own folder,
| and a file still used by a design cannot be taken away underneath it.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->store = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->other = Store::factory()->create(['name' => 'Beta Deli']);
    $this->designer = createStoreUser($this->store, ['ad-view', 'ad-store', 'ad-update', 'ad-destroy'], 'Designer');
    $this->actingAs($this->designer)->withSession(['current_store_id' => $this->store->id]);
});

test('a picture lands on the shelf, in its own store’s folder', function () {
    $this->postJson('/builder/assets', ['file' => UploadedFile::fake()->image('logo.png', 800, 600)])->assertOk();

    $asset = BuilderAsset::sole();

    expect($asset->store_id)->toBe($this->store->id)
        ->and($asset->kind)->toBe(BuilderAsset::KIND_IMAGE)
        ->and($asset->title)->toBe('logo')
        ->and($asset->path)->toStartWith("builder/{$this->store->id}/assets/")
        ->and($asset->width)->toBe(800);

    Storage::disk('public')->assertExists($asset->path);

    // It is the BUILDER's shelf: nothing was added to the store's media library.
    expect(Media::count())->toBe(0);
});

test('only what a television can render is accepted', function () {
    $attempts = [
        'shell.php' => UploadedFile::fake()->create('shell.php', 4, 'text/x-php'),
        'sheet.pdf' => UploadedFile::fake()->create('sheet.pdf', 10, 'application/pdf'),
        'sound.mp3' => UploadedFile::fake()->create('sound.mp3', 10, 'audio/mpeg'),
    ];

    foreach ($attempts as $name => $file) {
        $status = $this->postJson('/builder/assets', ['file' => $file])->status();
        expect($status)->toBe(422, "{$name} answered {$status}");
    }

    expect(BuilderAsset::count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('the shelf shows this store’s files and says which ads use them', function () {
    $used = BuilderAsset::factory()->create(['store_id' => $this->store->id, 'title' => 'Logo']);
    $spare = BuilderAsset::factory()->create(['store_id' => $this->store->id, 'title' => 'Texture']);
    BuilderAsset::factory()->create(['store_id' => $this->other->id, 'title' => 'Their logo']);

    $document = BuilderAd::blankDocument();
    $document['elements'] = [[
        'id' => 'el_1', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 400, 'h' => 300,
        'assetId' => $used->id, 'style' => [], 'animations' => [],
    ]];
    BuilderAd::factory()->create(['store_id' => $this->store->id, 'name' => 'Winter sale', 'document' => $document]);

    $rows = collect($this->getJson('/builder/assets/data')->assertOk()->json('assets'));

    expect($rows->pluck('title'))->toContain('Logo', 'Texture')
        ->and($rows->pluck('title'))->not->toContain('Their logo')
        ->and($rows->firstWhere('id', $used->id)['used_by'])->toBe(['Winter sale'])
        ->and($rows->firstWhere('id', $spare->id)['used_by'])->toBe([]);
});

test('a file an ad still uses cannot be pulled out from under it', function () {
    $asset = BuilderAsset::factory()->create(['store_id' => $this->store->id, 'title' => 'Logo']);

    $document = BuilderAd::blankDocument();
    $document['elements'] = [[
        'id' => 'el_1', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 400, 'h' => 300,
        'assetId' => $asset->id, 'style' => [], 'animations' => [],
    ]];
    BuilderAd::factory()->create(['store_id' => $this->store->id, 'name' => 'Winter sale', 'document' => $document]);

    $this->deleteJson("/builder/assets/{$asset->id}")
        ->assertStatus(422)
        ->assertJsonValidationErrors('title');

    expect(BuilderAsset::find($asset->id))->not->toBeNull();
});

test('an unused file goes, and takes its bytes with it', function () {
    $this->postJson('/builder/assets', ['file' => UploadedFile::fake()->image('spare.jpg')])->assertOk();

    $asset = BuilderAsset::sole();
    $path = $asset->path;
    $thumb = $asset->thumbnail_path;

    // A JPEG always gets a thumbnail, so there really are two files for the delete to take.
    expect($thumb)->not->toBeNull();
    Storage::disk('public')->assertExists([$path, $thumb]);

    $this->deleteJson("/builder/assets/{$asset->id}")->assertOk();

    expect(BuilderAsset::find($asset->id))->toBeNull();
    Storage::disk('public')->assertMissing($path);
    Storage::disk('public')->assertMissing($thumb);
});

test('deleting a store clears its shelf', function () {
    BuilderAsset::factory()->count(2)->create(['store_id' => $this->store->id]);
    $keep = BuilderAsset::factory()->create(['store_id' => $this->other->id]);

    $this->store->delete();

    expect(BuilderAsset::where('store_id', $this->store->id)->count())->toBe(0)
        ->and(BuilderAsset::find($keep->id))->not->toBeNull();
});

test('a deleted store leaves no ad files behind either — and takes no file its rows do not name', function () {
    $this->postJson('/builder/assets', ['file' => UploadedFile::fake()->image('logo.png')])->assertOk();
    $asset = BuilderAsset::sole();

    // A draft with a poster, and a published ad: its page and its poster are named by the library's row.
    [$draft, $published] = BuilderAd::factory()->withText()->count(2)->create(['store_id' => $this->store->id])->all();

    foreach ([$draft, $published] as $ad) {
        $ad->forceFill(['thumbnail_path' => $ad->storageDirectory().'/poster.jpg'])->save();
        Storage::disk('public')->put($ad->thumbnail_path, 'bytes');
    }

    $page = app(AdPublisher::class)->publish($published)->path;

    // A file nobody's row names, in an ad's folder: a folder delete would have taken it on a guess.
    Storage::disk('public')->put($draft->storageDirectory().'/not-ours.txt', 'bytes');

    $this->store->delete();

    // The rows would have cascaded on their own; the point of purgeBuilder is the bytes.
    Storage::disk('public')->assertMissing($asset->path);
    Storage::disk('public')->assertMissing($draft->thumbnail_path);
    Storage::disk('public')->assertMissing($published->thumbnail_path);
    Storage::disk('public')->assertMissing($page);
    Storage::disk('public')->assertExists($draft->storageDirectory().'/not-ours.txt');
});

test('the platform uploads to the shop it chose, and must choose one', function () {
    // The platform team stands in no store: the page sends the shop picked in its Shop list.
    $admin = createSuperAdmin();
    $this->actingAs($admin);
    $this->flushSession();

    $this->postJson('/builder/assets', ['file' => UploadedFile::fake()->image('logo.png')])
        ->assertStatus(422)->assertJsonValidationErrors('file');
    $this->postJson('/builder/assets', ['file' => UploadedFile::fake()->image('logo.png'), 'store_id' => 999999])
        ->assertStatus(422)->assertJsonValidationErrors('file');
    $this->postJson('/builder/assets', ['file' => UploadedFile::fake()->image('logo.png'), 'store_id' => [$this->other->id]])
        ->assertStatus(422)->assertJsonValidationErrors('store_id');

    expect(BuilderAsset::count())->toBe(0);

    $this->postJson('/builder/assets', ['file' => UploadedFile::fake()->image('logo.png'), 'store_id' => $this->other->id])
        ->assertOk();

    $asset = BuilderAsset::sole();

    expect($asset->store_id)->toBe($this->other->id)
        ->and($asset->path)->toStartWith("builder/{$this->other->id}/assets/");
});

test('a store’s person uploads to the store they work in, whatever shop the request names', function () {
    $this->postJson('/builder/assets', ['file' => UploadedFile::fake()->image('logo.png'), 'store_id' => $this->other->id])
        ->assertOk();

    expect(BuilderAsset::sole()->store_id)->toBe($this->store->id);
});
