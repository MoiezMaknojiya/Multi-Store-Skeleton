<?php

use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Media;
use App\Models\Permission;
use App\Models\Role;
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
| "Mujhe sub store k liya upload karna ho toh takay woo mere asset ko use kar sake aur agar permission du toh woo
| delete bhi kar sake." Above the stores, an upload with no shop chosen is the platform's, shared with every shop:
| every shop's designers see it on their shelf and in their editor and may use it in their ads; it counts to no
| shop's storage; and it is deleted with Delete Shared Assets alone — from every shop at once, never while any
| shop's ad uses it, and without one shop ever learning the names of another's ads.
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
    expect($entry->store_id)->toBeNull()->and($entry->description)->toContain('shared with every shop');
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

test('with Delete Shared Assets a shop\'s person deletes a shared file from every shop — never one an ad of any shop uses', function () {
    $shared = shareAFile($this);

    BuilderAd::factory()->create(['store_id' => $this->other->id, 'name' => 'Beta secret campaign', 'document' => documentWithPicture($shared->id)]);

    $keeper = createStoreUser($this->store, ['ad-view', 'ad-shared-asset-destroy'], 'Shelf keeper');
    $this->actingAs($keeper)->withSession(['current_store_id' => $this->store->id]);

    // The listing says this person may, and the delete is refused all the same while another shop's ad uses it —
    // counted, never named.
    $row = collect($this->getJson('/builder/assets/data')->json('assets'))->firstWhere('id', $shared->id);
    expect($row['can_delete'])->toBeTrue()->and($row['used_by'])->toBe([])->and($row['used_elsewhere'])->toBe(1);

    $refusal = $this->deleteJson("/builder/assets/{$shared->id}")->assertStatus(422)->json('errors.title.0');
    expect($refusal)->toBe("Still used by an ad of another shop, so it stays: it can go once no shop's ad uses it.")
        ->not->toContain('Beta secret campaign');

    // Its own shop's ad is named; the other shop's is counted.
    BuilderAd::factory()->create(['store_id' => $this->store->id, 'name' => 'Alpha week', 'document' => documentWithPicture($shared->id)]);
    expect($this->deleteJson("/builder/assets/{$shared->id}")->assertStatus(422)->json('errors.title.0'))
        ->toBe("Still used by Alpha week, and by an ad of another shop, so it stays: it can go once no shop's ad uses it.");

    BuilderAd::query()->delete();

    $this->deleteJson("/builder/assets/{$shared->id}")->assertOk();

    expect(BuilderAsset::find($shared->id))->toBeNull();
    Storage::disk('public')->assertMissing($shared->path);
    expect(ActivityLog::where('action', 'ad_asset.deleted')->sole()->store_id)->toBe($this->store->id);
});

test('Delete Shared Assets and Delete Ads are two permissions: each deletes only its own kind', function () {
    $shared = shareAFile($this);
    $own = BuilderAsset::factory()->create(['store_id' => $this->store->id, 'title' => 'Our logo']);

    // Delete Ads: the shop's own file, not the shared one.
    $this->actingAs($this->designer)->withSession(['current_store_id' => $this->store->id]);
    $this->deleteJson("/builder/assets/{$shared->id}")->assertForbidden();

    // Delete Shared Assets: the shared file, not the shop's own.
    $this->actingAs(createStoreUser($this->store, ['ad-view', 'ad-shared-asset-destroy'], 'Shelf keeper'))->withSession(['current_store_id' => $this->store->id]);
    $this->deleteJson("/builder/assets/{$own->id}")->assertForbidden();

    // Neither: the route itself says no.
    $this->actingAs(createStoreUser($this->store, ['ad-view'], 'Viewer'))->withSession(['current_store_id' => $this->store->id]);
    $this->deleteJson("/builder/assets/{$shared->id}")->assertForbidden();

    expect(BuilderAsset::whereKey([$shared->id, $own->id])->count())->toBe(2);
});

test('above the stores the Shop list shows everything, what is shared, or one shop with the shared files', function () {
    $shared = shareAFile($this);
    $alpha = BuilderAsset::factory()->create(['store_id' => $this->store->id, 'title' => 'Alpha logo']);
    $beta = BuilderAsset::factory()->create(['store_id' => $this->other->id, 'title' => 'Beta logo']);

    $ids = fn (string $query) => collect($this->getJson('/builder/assets/data'.$query)->assertOk()->json('assets'))->pluck('id')->sort()->values()->all();

    expect($ids(''))->toBe(collect([$shared->id, $alpha->id, $beta->id])->sort()->values()->all())
        ->and($ids('?store_id='.$this->other->id))->toBe(collect([$shared->id, $beta->id])->sort()->values()->all());

    $labels = collect($this->getJson('/builder/assets/data')->json('assets'))->pluck('owner_label', 'id');
    expect($labels[$shared->id])->toBe('Every shop')->and($labels[$beta->id])->toBe('Beta Deli');

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

test('Delete Shared Assets is a platform permission a shop\'s role may carry, and only Super-Admin starts with it', function () {
    expect(Permission::belongsToStores('ad-shared-asset-destroy'))->toBeTrue()
        ->and(Permission::PLATFORM)->toContain('ad-shared-asset-destroy')
        ->and(Permission::LABELS['ad-shared-asset-destroy'])->toBe('Delete Shared Assets');

    // A test's database has no Super-Admin while its migrations run (the seeder makes it), so the migration is run
    // again once there is one — it is written to be run twice, as a real installation's may be. The permission was
    // `ad-shared-destroy` until ads for every shop came, and the migration that brought them renamed the same row.
    $superAdmin = Role::firstOrCreate(['name' => Role::SUPER_ADMIN], ['is_global' => true]);
    (require database_path('migrations/2026_10_01_110100_insert_the_shared_ads_permissions.php'))->up();
    (require database_path('migrations/2026_10_01_110100_insert_the_shared_ads_permissions.php'))->up();

    $permission = Permission::where('name', 'ad-shared-asset-destroy')->sole();
    $holders = $permission->roles()->get();

    expect($holders->pluck('id')->all())->toBe([$superAdmin->id])
        ->and($holders->contains(fn (Role $role) => $role->key === 'owner'))->toBeFalse();
});
