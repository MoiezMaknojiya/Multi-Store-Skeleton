<?php

use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Media;
use App\Models\Store;
use App\Models\Upload;
use App\Services\StoreStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Tus;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| The Ad Builder's shelf, shared with every shop (owner, 2026-09-29)
|--------------------------------------------------------------------------
|
| "Mujhe sub store k liya upload karna ho toh takay woo mere asset ko use kar sake." Above the stores, an upload with
| no shop chosen is the platform's, shared with every shop: every shop's designers see it on their shelf and in their
| editor and may use it in their ads; it counts to no shop's storage; and it is deleted above the stores alone (owner,
| 2026-10-01: a shop "srif delete nahi kar sakta ha") — from every shop at once, never while any shop's ad uses it,
| and without one shop ever learning the names of another's ads.
|
*/

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('uploads');

    $this->store = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->other = Store::factory()->create(['name' => 'Beta Deli']);
    $this->designer = createStoreUser($this->store, ['ad-view', 'ad-store', 'ad-update', 'ad-destroy'], 'Designer');
});

/** A document with one picture element naming $assetId. */
function documentWithPicture(int $assetId): array
{
    $document = BuilderAd::blankDocument();
    $document['elements'] = [[
        'id' => 'el_1', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 400, 'h' => 300,
        'assetId' => $assetId, 'style' => [], 'animations' => [],
    ]];

    return $document;
}

/** A file the platform shares with every shop, uploaded the way the Assets page does with no shop chosen. */
function shareAFile(TestCase $test, string $name = 'brand-logo.png'): BuilderAsset
{
    $test->actingAs(createSuperAdmin())->withSession([]);
    $test->postJson('/builder/assets', ['file' => UploadedFile::fake()->image($name, 400, 300)])->assertOk()->assertJsonPath('storage', null);

    return BuilderAsset::whereNull('store_id')->latest('id')->firstOrFail();
}

test('an upload above the stores with no shop chosen is shared with every shop, and counts to none', function () {
    $before = app(StoreStorage::class)->summary($this->store->id)['used'];

    $asset = shareAFile($this);

    expect($asset->store_id)->toBeNull()
        ->and($asset->isShared())->toBeTrue()
        ->and($asset->path)->toStartWith('builder/platform/assets/')
        ->and(app(StoreStorage::class)->summary($this->store->id)['used'])->toBe($before);

    Storage::disk('public')->assertExists($asset->path);

    $entry = ActivityLog::where('action', 'ad_asset.uploaded')->sole();
    expect($entry->store_id)->toBeNull()->and($entry->description)->toContain('shared with every organization');
});

test('the chunked uploader shares a file too when no shop is chosen', function () {
    $this->actingAs(createSuperAdmin())->withSession([]);

    // As the page sends it: every field, the shop's empty (no shop chosen).
    $image = UploadedFile::fake()->image('banner.png', 200, 100);
    $id = Tus::upload($this, (string) file_get_contents($image->getRealPath()), [
        'name' => 'banner.png', 'type' => 'image/png', 'purpose' => 'asset', 'library' => '', 'store' => '', 'channel' => '',
    ]);

    expect(Upload::find($id)->store_id)->toBeNull();

    $this->postJson('/builder/assets', ['upload' => $id])->assertOk();

    expect(BuilderAsset::whereNull('store_id')->sole()->title)->toBe('banner');
});

test('every shop sees a shared file on its shelf and in its editor, marked as the platform\'s', function () {
    $shared = shareAFile($this);
    $own = BuilderAsset::factory()->create(['store_id' => $this->store->id, 'title' => 'Our logo']);

    foreach ([$this->store, $this->other] as $store) {
        $person = createStoreUser($store, ['ad-view', 'ad-store', 'ad-update', 'ad-destroy'], 'Designer '.$store->id);
        $this->actingAs($person)->withSession(['current_store_id' => $store->id]);

        $row = collect($this->getJson('/builder/assets/data')->assertOk()->json('assets'))->firstWhere('id', $shared->id);

        expect($row)->not->toBeNull()
            ->and($row['shared'])->toBeTrue()
            ->and($row['owner_label'])->toBe('From the platform')
            // Deleting a shared file is a permission of its own: Delete Ads is not it.
            ->and($row['can_delete'])->toBeFalse();

        $this->get('/builder/create?orientation=landscape')->assertOk()
            ->assertViewHas('assets', fn (array $assets) => collect($assets)->pluck('id')->contains($shared->id));
    }

    // …and a shop's own file stays its own.
    $this->actingAs(createStoreUser($this->other, ['ad-view'], 'Viewer'))->withSession(['current_store_id' => $this->other->id]);
    expect(collect($this->getJson('/builder/assets/data')->json('assets'))->pluck('id')->all())->not->toContain($own->id);
});

test('a shop\'s ad uses a shared file, and its published page shows it', function () {
    $shared = shareAFile($this);

    $this->actingAs($this->designer)->withSession(['current_store_id' => $this->store->id]);

    $id = $this->postJson('/builder', ['name' => 'Brand week', 'document' => documentWithPicture($shared->id)])->assertOk()->json('ad.id');
    $this->postJson("/builder/{$id}/publish")->assertOk();

    expect(Storage::disk('public')->get(Media::sole()->path))->toContain(basename($shared->path));
});

test('a shared file is deleted above the stores alone — never by a shop, never while an ad of any shop uses it', function () {
    $shared = shareAFile($this);

    BuilderAd::factory()->create(['store_id' => $this->other->id, 'name' => 'Beta secret campaign', 'document' => documentWithPicture($shared->id)]);

    // A shop's people see it and use it, and never delete it, whatever their role holds (owner, 2026-10-01). Its
    // listing counts another shop's ad that uses it, never naming it.
    $this->actingAs($this->designer)->withSession(['current_store_id' => $this->store->id]);

    $row = collect($this->getJson('/builder/assets/data')->json('assets'))->firstWhere('id', $shared->id);
    expect($row['can_delete'])->toBeFalse()->and($row['used_by'])->toBe([])->and($row['used_elsewhere'])->toBe(1)
        ->and(json_encode($row))->not->toContain('Beta secret campaign');

    $this->deleteJson("/builder/assets/{$shared->id}")->assertForbidden()
        ->assertJsonPath('message', 'A file shared with every organization is the platform\'s: only the platform deletes it.');

    // Above the stores it waits for every shop's ad to let it go.
    $this->actingAs(createSuperAdmin());
    $this->flushSession();
    $this->deleteJson("/builder/assets/{$shared->id}")->assertStatus(422);

    BuilderAd::query()->delete();

    $this->deleteJson("/builder/assets/{$shared->id}")->assertOk();

    expect(BuilderAsset::find($shared->id))->toBeNull();
    Storage::disk('public')->assertMissing($shared->path);
    expect(ActivityLog::where('action', 'ad_asset.deleted')->sole()->store_id)->toBeNull();
});

test('Delete Ads deletes a shop\'s own file, and a shared one only above the stores', function () {
    $shared = shareAFile($this);
    $own = BuilderAsset::factory()->create(['store_id' => $this->store->id, 'title' => 'Our logo']);

    // Inside the shop: its own file, never the shared one.
    $this->actingAs($this->designer)->withSession(['current_store_id' => $this->store->id]);
    $this->deleteJson("/builder/assets/{$shared->id}")->assertForbidden();
    $this->deleteJson("/builder/assets/{$own->id}")->assertOk();

    // Without Delete Ads the route itself says no.
    $this->actingAs(createStoreUser($this->store, ['ad-view'], 'Viewer'))->withSession(['current_store_id' => $this->store->id]);
    $this->deleteJson("/builder/assets/{$shared->id}")->assertForbidden();

    // Above the stores a platform role holding Delete Ads takes the shared file off every shop's shelf.
    $this->actingAs(createPlatformUser(['ad-view', 'ad-destroy'], 'Platform shelf keeper'));
    $this->flushSession();
    $this->deleteJson("/builder/assets/{$shared->id}")->assertOk();

    expect(BuilderAsset::whereKey([$shared->id, $own->id])->count())->toBe(0);
});

test('above the stores the Shop list shows everything, what is shared, or one shop with the shared files', function () {
    $shared = shareAFile($this);
    $alpha = BuilderAsset::factory()->create(['store_id' => $this->store->id, 'title' => 'Alpha logo']);
    $beta = BuilderAsset::factory()->create(['store_id' => $this->other->id, 'title' => 'Beta logo']);

    $ids = fn (string $query) => collect($this->getJson('/builder/assets/data'.$query)->assertOk()->json('assets'))->pluck('id')->sort()->values()->all();

    expect($ids(''))->toBe(collect([$shared->id, $alpha->id, $beta->id])->sort()->values()->all())
        ->and($ids('?store_id='.$this->other->id))->toBe(collect([$shared->id, $beta->id])->sort()->values()->all());

    $labels = collect($this->getJson('/builder/assets/data')->json('assets'))->pluck('owner_label', 'id');
    expect($labels[$shared->id])->toBe('Every organization')->and($labels[$beta->id])->toBe('Beta Deli');

    // All shops is the one option for every shop: there is no "shared" to ask for besides.
    $this->getJson('/builder/assets/data?store_id=nope')->assertStatus(422);
    $this->getJson('/builder/assets/data?store_id=shared')->assertStatus(422);
});

test('above the stores a shared file in use says which shops\' ads use it, and stays', function () {
    $shared = shareAFile($this);

    BuilderAd::factory()->create(['store_id' => $this->other->id, 'name' => 'Beta week', 'document' => documentWithPicture($shared->id)]);

    // shareAFile left the super admin signed in.
    $row = collect($this->getJson('/builder/assets/data')->assertOk()->json('assets'))->firstWhere('id', $shared->id);

    expect($row['used_by'])->toBe(['Beta week (Beta Deli)'])
        ->and($row['used_elsewhere'])->toBe(0)
        ->and($row['can_delete'])->toBeTrue();

    expect($this->deleteJson("/builder/assets/{$shared->id}")->assertStatus(422)->json('errors.title.0'))
        ->toBe('Still used by Beta week (Beta Deli). Take it out of those ads first, and publish the ones whose screens still show it.');
});

test('a deleted shop takes its own files and leaves the shared ones', function () {
    $shared = shareAFile($this);

    $this->store->delete();

    expect(BuilderAsset::find($shared->id))->not->toBeNull();
    Storage::disk('public')->assertExists($shared->path);
});
