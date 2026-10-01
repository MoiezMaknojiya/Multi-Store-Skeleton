<?php

use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\StoreStorage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Ads for every shop (owner, 2026-10-01)
|--------------------------------------------------------------------------
|
| "Mein all shop k liya ads kese banao? Jese asset mein ha woo ads sub ko dikhe aur woo copy kar sake ... sub khel
| permission ka honga, mein duga toh woo mera kaam bhi delete kar sakte ha." Above the stores an ad with no shop chosen
| — All shops — is the platform's, shared with every shop: it uses the shared files alone, publishes into the
| platform's own library, and every shop sees it once it is published ("publish ke baad") — as it was published,
| never its unfinished changes — and copies it into its own Ads. Changing it takes Update Shared Ads, deleting it
| Delete Shared Ads, and the shared files keep Delete Shared Assets ("teen alag"), wherever the person stands.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->store = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->other = Store::factory()->create(['name' => 'Beta Deli']);
    // A shop's designer: everything a shop's own ads need, nothing of the platform's.
    $this->designer = createStoreUser($this->store, ['ad-view', 'ad-store', 'ad-update', 'ad-destroy'], 'Designer');
});

/** A design with one headline — and, given an asset, a picture of it. */
function everyShopDocument(string $text = 'Winter sale', ?int $assetId = null): array
{
    $document = BuilderAd::blankDocument();
    $document['elements'] = [[
        'id' => 'el_1', 'type' => 'text', 'name' => 'Headline', 'x' => 160, 'y' => 240, 'w' => 1200, 'h' => 200,
        'rotation' => 0, 'opacity' => 1, 'z' => 0, 'locked' => false, 'visible' => true,
        'text' => $text, 'style' => ['fontSize' => 96, 'color' => '#ffffff'], 'animations' => [],
    ]];

    if ($assetId !== null) {
        $document['elements'][] = [
            'id' => 'el_2', 'type' => 'image', 'name' => 'Picture', 'x' => 0, 'y' => 0, 'w' => 400, 'h' => 300,
            'rotation' => 0, 'opacity' => 1, 'z' => 1, 'locked' => false, 'visible' => true,
            'assetId' => $assetId, 'style' => [], 'animations' => [],
        ];
    }

    return $document;
}

/** A poster the way the editor's canvas hands one over. */
function everyShopPoster(): string
{
    $image = imagecreatetruecolor(320, 180);
    imagefilledrectangle($image, 0, 0, 320, 180, imagecolorallocate($image, 200, 40, 40));

    ob_start();
    imagejpeg($image, null, 85);

    return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
}

/** A super admin makes an ad for every shop through the editor's own endpoints — and publishes it, unless told not to. */
function makeForEveryShop(TestCase $test, string $name = 'Winter sale', bool $publish = true): BuilderAd
{
    $test->actingAs(createSuperAdmin());
    $test->flushSession();

    $id = $test->postJson('/builder', ['name' => $name, 'document' => everyShopDocument($name), 'thumbnail' => everyShopPoster()])
        ->assertOk()->json('ad.id');

    if ($publish) {
        $test->postJson("/builder/{$id}/publish")->assertOk()->assertJsonPath('message', 'Published — every shop sees it now and can copy it');
    }

    return BuilderAd::findOrFail($id);
}

/** What a shop's person is shown on the Ads page, by id. */
function adsSeenIn(TestCase $test, User $person, Store $store): Collection
{
    return collect($test->actingAs($person)->withSession(['current_store_id' => $store->id])
        ->getJson('/builder/data')->assertOk()->json('ads'))->keyBy('id');
}

test('an ad for every shop is the platform\'s: no shop, its own folder, the platform\'s library, and no shop\'s storage', function () {
    $before = app(StoreStorage::class)->summary($this->store->id)['used'];

    $ad = makeForEveryShop($this);

    expect($ad->store_id)->toBeNull()
        ->and($ad->isShared())->toBeTrue()
        ->and($ad->thumbnail_path)->toBe("builder/platform/ads/{$ad->id}/poster.jpg")
        ->and($ad->media->store_id)->toBeNull()
        ->and($ad->media->path)->toBe("builder/platform/ads/{$ad->id}/index.html")
        ->and(app(StoreStorage::class)->summary($this->store->id)['used'])->toBe($before);

    Storage::disk('public')->assertExists([$ad->thumbnail_path, $ad->media->path, $ad->media->thumbnail_path]);

    $created = ActivityLog::where('action', 'ad.created')->sole();
    expect($created->store_id)->toBeNull()
        ->and($created->description)->toBe('Created landscape ad Winter sale for every shop')
        ->and(ActivityLog::where('action', 'ad.published')->sole()->description)->toBe('Published ad Winter sale for every shop');

    // Above the stores its card and its editor say whose it is.
    $row = collect($this->getJson('/builder/data')->assertOk()->json('ads'))->firstWhere('id', $ad->id);
    expect($row)->toMatchArray(['shared' => true, 'owner_label' => 'Every shop', 'can' => ['update' => true, 'copy' => true, 'delete' => true]]);
    $this->get("/builder/{$ad->id}")->assertOk()->assertSee('dusk="ad-owner"', false)->assertSee('Every shop')->assertDontSee('dusk="ad-store"', false);
});

test('a shop sees the platform\'s ad once it is published, as it was published, and with nobody\'s name from above', function () {
    $draft = makeForEveryShop($this, 'Spring sale', publish: false);
    $ad = makeForEveryShop($this, 'Winter sale');

    // The platform changes its ad after publishing it, and has not published that yet.
    $this->putJson("/builder/{$ad->id}", ['name' => 'Winter sale 2', 'document' => everyShopDocument('Half done')])->assertOk()
        ->assertJsonPath('message', 'Changes saved — shops keep the published version until you publish them');

    $seen = adsSeenIn($this, $this->designer, $this->store);

    expect($seen->keys()->all())->toBe([$ad->id])
        ->and($seen[$ad->id])->toMatchArray([
            'name' => 'Winter sale',
            'shared' => true,
            'owner_label' => 'From the platform',
            'updated_by_name' => null,
            'can' => ['update' => false, 'copy' => true, 'delete' => false],
        ])
        ->and($seen[$ad->id]['thumbnail_url'])->toContain("builder/platform/ads/{$ad->id}/published.jpg");

    foreach (['document', 'published_document', 'published_name', 'created_by', 'updated_by'] as $key) {
        expect($seen[$ad->id])->not->toHaveKey($key);
    }

    // The draft's words, and the draft ad itself, are nowhere in what the shop is sent; the page opens.
    expect(json_encode($this->getJson('/builder/data')->json()))->not->toContain('Winter sale 2')->not->toContain('Spring sale');
    $this->get('/builder')->assertOk();

    // Searched by the name its card shows, it is found.
    expect(collect($this->getJson('/builder/data?search=Winter')->json('ads'))->pluck('id')->all())->toBe([$ad->id]);

    // Every shop sees it — and the draft, still, none.
    expect(adsSeenIn($this, createStoreUser($this->other, ['ad-view'], 'Beta viewer'), $this->other)->keys()->all())->toBe([$ad->id])
        ->and($draft->fresh()->isPublished())->toBeFalse();
});

test('a shop copies the platform\'s ad into its own Ads: the published version, under its name, its poster counted to the shop', function () {
    $ad = makeForEveryShop($this, 'Winter sale');
    $this->putJson("/builder/{$ad->id}", ['name' => 'Winter sale 2', 'document' => everyShopDocument('Half done')])->assertOk();

    $this->actingAs($this->designer)->withSession(['current_store_id' => $this->store->id]);
    $before = app(StoreStorage::class)->summary($this->store->id)['used'];

    $answer = $this->postJson("/builder/{$ad->id}/duplicate")->assertOk()->assertJsonPath('message', 'Copied to your ads');
    $copy = BuilderAd::findOrFail($answer->json('ad.id'));

    expect($copy->store_id)->toBe($this->store->id)
        ->and($copy->name)->toBe('Winter sale')
        ->and($copy->document['elements'][0]['text'])->toBe('Winter sale')
        ->and($copy->isPublished())->toBeFalse()
        ->and($copy->in_playlists)->toBeFalse()
        ->and($copy->thumbnail_path)->toBe("builder/{$this->store->id}/ads/{$copy->id}/poster.jpg")
        ->and(Storage::disk('public')->get($copy->thumbnail_path))->toBe(Storage::disk('public')->get($ad->media->thumbnail_path))
        ->and(app(StoreStorage::class)->summary($this->store->id)['used'])->toBeGreaterThan($before);

    expect(ActivityLog::where('action', 'ad.copied')->sole())
        ->store_id->toBe($this->store->id)
        ->description->toBe('Copied ad Winter sale from the platform');

    // A second copy is named apart; the log says under which name.
    expect($this->postJson("/builder/{$ad->id}/duplicate")->assertOk()->json('ad.name'))->toBe('Winter sale (copy)')
        ->and(ActivityLog::where('action', 'ad.copied')->latest('id')->first()->description)
        ->toBe('Copied ad Winter sale from the platform as Winter sale (copy)');

    // The shop's own now: changed with Update Ads, published into the shop's library — and the platform's is untouched.
    $this->putJson("/builder/{$copy->id}", ['name' => 'Our winter sale', 'document' => everyShopDocument('Ten percent off')])->assertOk();
    $this->postJson("/builder/{$copy->id}/publish")->assertOk();

    expect($copy->fresh()->media->store_id)->toBe($this->store->id)
        ->and($ad->fresh()->name)->toBe('Winter sale 2')
        ->and($ad->fresh()->published_name)->toBe('Winter sale');
});

test('without the shared permissions a shop\'s person changes nothing of the platform\'s ad, and never sees its draft', function () {
    $ad = makeForEveryShop($this);
    $draft = makeForEveryShop($this, 'Spring sale', publish: false);

    $this->actingAs($this->designer)->withSession(['current_store_id' => $this->store->id]);

    // Update Ads and Delete Ads are for the shop's own: the platform's ad says what it needs.
    $this->get("/builder/{$ad->id}")->assertForbidden();
    $this->putJson("/builder/{$ad->id}", ['name' => 'Mine', 'document' => everyShopDocument('Mine')])
        ->assertForbidden()->assertJsonPath('message', 'Changing an ad shared with every shop needs the Update Shared Ads permission.');
    $this->postJson("/builder/{$ad->id}/publish")->assertForbidden();
    $this->postJson("/builder/{$ad->id}/unpublish")->assertForbidden();
    $this->postJson("/builder/{$ad->id}/discard")->assertForbidden();
    $this->postJson("/builder/{$ad->id}/in-playlists", ['in_playlists' => true])->assertForbidden();
    $this->deleteJson("/builder/{$ad->id}", ['password' => 'password'])
        ->assertForbidden()->assertJsonPath('message', 'Deleting an ad shared with every shop needs the Delete Shared Ads permission.');

    // The draft is not there at all.
    $this->get("/builder/{$draft->id}")->assertNotFound();
    $this->get("/builder/{$draft->id}/preview")->assertNotFound();
    $this->putJson("/builder/{$draft->id}", ['name' => 'Mine', 'document' => everyShopDocument('Mine')])->assertNotFound();
    $this->postJson("/builder/{$draft->id}/duplicate")->assertNotFound();
    $this->deleteJson("/builder/{$draft->id}", ['password' => 'password'])->assertNotFound();

    $ad->refresh();
    expect($ad->name)->toBe('Winter sale')
        ->and($ad->isPublished())->toBeTrue()
        ->and(BuilderAd::where('store_id', $this->store->id)->count())->toBe(0);
});

test('with Update Shared Ads a shop\'s person works on the platform\'s ads, drafts included, and the log names the shop', function () {
    $ad = makeForEveryShop($this);
    $draft = makeForEveryShop($this, 'Spring sale', publish: false);

    $editor = createStoreUser($this->store, ['ad-view', 'ad-shared-update'], 'Template editor');
    $seen = adsSeenIn($this, $editor, $this->store);

    expect($seen->keys()->sort()->values()->all())->toBe(collect([$ad->id, $draft->id])->sort()->values()->all())
        ->and($seen[$ad->id]['can'])->toBe(['update' => true, 'copy' => false, 'delete' => false]);

    $this->get("/builder/{$ad->id}")->assertOk()->assertSee('From the platform');
    $this->putJson("/builder/{$ad->id}", ['name' => 'Winter sale', 'document' => everyShopDocument('Twenty percent off')])->assertOk();
    $this->postJson("/builder/{$ad->id}/publish")->assertOk()->assertJsonPath('message', 'Published — every shop sees it now and can copy it');

    $ad->refresh();
    expect($ad->store_id)->toBeNull()
        ->and($ad->published_document['elements'][0]['text'])->toBe('Twenty percent off')
        ->and(ActivityLog::where('action', 'ad.updated')->sole()->store_id)->toBe($this->store->id)
        ->and(ActivityLog::where('action', 'ad.published')->latest('id')->first()->store_id)->toBe($this->store->id);

    // Its page plays only in the platform's channels: no tick opens it to a shop's playlist.
    $this->postJson("/builder/{$ad->id}/in-playlists", ['in_playlists' => true])->assertStatus(422)
        ->assertJsonValidationErrors(['in_playlists' => 'An ad for every shop plays only in the platform\'s channels. A shop copies it to put it on its playlists.']);

    // Taking it off says so, and shops stop seeing it.
    $this->postJson("/builder/{$ad->id}/unpublish")->assertOk()->assertJsonPath('message', 'Unpublished — it is a draft again. Shops no longer see it');
    expect(adsSeenIn($this, $this->designer, $this->store)->all())->toBe([]);

    // Update Shared Ads is not Update Ads: the shop's own ads stay shut to it.
    $own = BuilderAd::factory()->create(['store_id' => $this->store->id]);
    $this->actingAs($editor)->withSession(['current_store_id' => $this->store->id]);
    $this->putJson("/builder/{$own->id}", ['name' => 'X', 'document' => everyShopDocument('X')])
        ->assertForbidden()->assertJsonPath('message', 'Changing an ad needs the Update Ads permission.');
});

test('with Delete Shared Ads a shop\'s person deletes the platform\'s ad from every shop — never while a channel shows it — and copies stay', function () {
    $ad = makeForEveryShop($this);

    // Beta Deli copied it before.
    $this->actingAs(createStoreUser($this->other, ['ad-view', 'ad-store'], 'Beta designer'))->withSession(['current_store_id' => $this->other->id]);
    $copyId = $this->postJson("/builder/{$ad->id}/duplicate")->assertOk()->json('ad.id');

    // The platform's channel shows it.
    $channelAd = ChannelAd::factory()->create(['channel_id' => Channel::factory()->create()->id, 'media_id' => $ad->media_id]);

    $keeper = createStoreUser($this->store, ['ad-view', 'ad-shared-destroy'], 'Ad keeper');
    $this->actingAs($keeper)->withSession(['current_store_id' => $this->store->id]);

    $this->deleteJson("/builder/{$ad->id}", ['password' => 'password'])->assertStatus(422)->assertJsonValidationErrors('name');
    $channelAd->delete();

    $this->deleteJson("/builder/{$ad->id}", ['password' => 'password'])->assertOk();

    expect(BuilderAd::find($ad->id))->toBeNull()
        ->and(Media::find($ad->media_id))->toBeNull()
        ->and(BuilderAd::find($copyId)?->store_id)->toBe($this->other->id);
    Storage::disk('public')->assertMissing("builder/platform/ads/{$ad->id}/index.html");

    $entry = ActivityLog::where('action', 'ad.deleted')->sole();
    expect($entry->store_id)->toBe($this->store->id)->and($entry->description)->toBe('Deleted ad Winter sale, shared with every shop');

    // Delete Shared Ads is not Delete Ads, nor Delete Shared Assets.
    $own = BuilderAd::factory()->create(['store_id' => $this->store->id]);
    $sharedFile = BuilderAsset::factory()->create(['store_id' => null]);
    $this->deleteJson("/builder/{$own->id}", ['password' => 'password'])->assertForbidden();
    $this->deleteJson("/builder/assets/{$sharedFile->id}")->assertForbidden();
});

test('above the stores a copy of the platform\'s ad stays the platform\'s, and every step asks for the shared permissions', function () {
    $ad = makeForEveryShop($this);

    $copyId = $this->postJson("/builder/{$ad->id}/duplicate")->assertOk()->assertJsonPath('message', 'Ad duplicated')->json('ad.id');
    expect(BuilderAd::find($copyId))->store_id->toBeNull()->name->toBe('Winter sale (copy)');

    $this->actingAs(createPlatformUser(['ad-view', 'ad-store', 'ad-update', 'ad-destroy'], 'Platform designer'));

    expect(collect($this->getJson('/builder/data')->assertOk()->json('ads'))->firstWhere('id', $ad->id))
        ->toMatchArray(['owner_label' => 'Every shop', 'can' => ['update' => false, 'copy' => false, 'delete' => false]]);

    $this->postJson("/builder/{$ad->id}/duplicate")->assertForbidden()
        ->assertJsonPath('message', 'Making an ad for every shop needs the Update Shared Ads permission.');
    $this->putJson("/builder/{$ad->id}", ['name' => 'X', 'document' => everyShopDocument('X')])->assertForbidden();
    $this->deleteJson("/builder/{$ad->id}", ['password' => 'password'])->assertForbidden();

    expect(BuilderAd::whereNull('store_id')->count())->toBe(2);
});

test('an ad for every shop uses the shared files alone: its editor offers no shop\'s own, and its page shows none', function () {
    $shared = BuilderAsset::factory()->create(['store_id' => null, 'title' => 'Brand logo', 'path' => 'builder/platform/assets/brand-logo.jpg']);
    $own = BuilderAsset::factory()->create(['store_id' => $this->store->id, 'title' => 'Alpha logo', 'path' => "builder/{$this->store->id}/assets/alpha-logo.jpg"]);

    $document = everyShopDocument('Winter sale', $shared->id);
    $document['elements'][] = [...$document['elements'][1], 'id' => 'el_3', 'assetId' => $own->id];
    $ad = BuilderAd::factory()->create(['store_id' => null, 'name' => 'Every shop', 'document' => $document]);

    $this->actingAs(createSuperAdmin());

    $this->get("/builder/{$ad->id}")->assertOk()->assertSee('Brand logo')->assertDontSee('Alpha logo');
    $this->get("/builder/{$ad->id}/preview")->assertOk()
        ->assertSee('builder/platform/assets/brand-logo.jpg', false)
        ->assertDontSee('alpha-logo.jpg', false);
});

test('the platform\'s channel takes a published ad for every shop from the platform\'s library', function () {
    $ad = makeForEveryShop($this);
    $channel = Channel::factory()->create();

    $ids = collect($this->getJson("/channels/{$channel->id}/library?type=html")->assertOk()->json('media'))->pluck('id')->all();

    expect($ids)->toBe([$ad->media_id]);
});

test('a shop is previewed the platform\'s ad as it was published; the platform its draft', function () {
    $ad = makeForEveryShop($this);
    $this->putJson("/builder/{$ad->id}", ['name' => 'Winter sale', 'document' => everyShopDocument('Half done')])->assertOk();

    $this->get("/builder/{$ad->id}/preview")->assertOk()->assertSee('Half done');

    $this->actingAs($this->designer)->withSession(['current_store_id' => $this->store->id]);
    $this->get("/builder/{$ad->id}/preview")->assertOk()->assertSee('Winter sale')->assertDontSee('Half done');
});

test('a shared file an ad for every shop uses stays, and a shop is told so without its name', function () {
    $file = BuilderAsset::factory()->create(['store_id' => null, 'title' => 'Brand logo']);
    BuilderAd::factory()->create(['store_id' => null, 'name' => 'Secret launch', 'document' => everyShopDocument('Soon', $file->id)]);

    $this->actingAs(createStoreUser($this->store, ['ad-view', 'ad-shared-asset-destroy'], 'Shelf keeper'))->withSession(['current_store_id' => $this->store->id]);

    $answer = $this->deleteJson("/builder/assets/{$file->id}")->assertStatus(422)
        ->assertJsonValidationErrors(['title' => 'Still used by an ad the platform shares, so it stays: it can go once no shop\'s ad uses it.']);
    expect((string) $answer->getContent())->not->toContain('Secret launch');

    $row = collect($this->getJson('/builder/assets/data')->json('assets'))->firstWhere('id', $file->id);
    expect($row)->toMatchArray(['used_by' => [], 'used_elsewhere' => 0, 'used_by_platform' => 1]);

    // Above the stores the ad is named, as an ad for every shop.
    $this->actingAs(createSuperAdmin());
    $this->flushSession();
    expect(collect($this->getJson('/builder/assets/data')->json('assets'))->firstWhere('id', $file->id)['used_by'])->toBe(['Secret launch (every shop)']);
});

test('a deleted shop leaves the platform\'s ads', function () {
    $ad = makeForEveryShop($this);

    $this->store->delete();

    expect(BuilderAd::find($ad->id))->not->toBeNull();
    Storage::disk('public')->assertExists($ad->media->path);
});

test('Update Shared Ads, Delete Shared Ads and Delete Shared Assets are three platform permissions a shop\'s role may carry', function () {
    $permissions = ['ad-shared-update' => 'Update Shared Ads', 'ad-shared-destroy' => 'Delete Shared Ads', 'ad-shared-asset-destroy' => 'Delete Shared Assets'];

    foreach ($permissions as $name => $label) {
        expect(Permission::belongsToStores($name))->toBeTrue()
            ->and(Permission::PLATFORM)->toContain($name)
            ->and(Permission::STORE_SCOPED)->toContain($name)
            ->and(Permission::LABELS[$name])->toBe($label)
            ->and(DB::table('permissions')->where('name', $name)->value('label'))->toBe($label);
    }

    // A test's database has no Super-Admin while its migrations run, so the migration runs again once there is one —
    // it is written to be run twice. The new two go to Super-Admin alone; no starter role holds any of the three.
    $superAdmin = Role::firstOrCreate(['name' => Role::SUPER_ADMIN], ['is_global' => true]);
    $migration = require database_path('migrations/2026_10_01_110100_insert_the_shared_ads_permissions.php');
    $migration->up();
    $migration->up();

    foreach (['ad-shared-update', 'ad-shared-destroy'] as $name) {
        expect(Permission::where('name', $name)->sole()->roles()->pluck('roles.id')->all())->toBe([$superAdmin->id]);
    }

    expect(Role::owner()->permissions()->whereIn('name', array_keys($permissions))->count())->toBe(0);

    // Going back gives the files' delete its old name and takes the new two away; forward again, the same row.
    $files = Permission::where('name', 'ad-shared-asset-destroy')->value('id');
    $migration->down();

    expect(Permission::whereIn('name', ['ad-shared-update', 'ad-shared-asset-destroy'])->count())->toBe(0)
        ->and(Permission::where('name', 'ad-shared-destroy')->value('id'))->toBe($files);

    $migration->up();

    expect(Permission::where('name', 'ad-shared-asset-destroy')->value('id'))->toBe($files)
        ->and(Permission::where('name', 'ad-shared-destroy')->value('label'))->toBe('Delete Shared Ads');
});

test('the migration that lets an ad be shared goes back down, taking the shared ads, their pages and their files', function () {
    $ad = makeForEveryShop($this);
    $own = BuilderAd::factory()->create(['store_id' => $this->store->id]);
    $files = [$ad->thumbnail_path, $ad->media->path, $ad->media->thumbnail_path];

    $migration = require database_path('migrations/2026_10_01_110000_share_builder_ads_with_every_shop.php');
    $migration->down();

    expect(BuilderAd::find($ad->id))->toBeNull()
        ->and(Media::find($ad->media_id))->toBeNull()
        ->and(BuilderAd::find($own->id))->not->toBeNull()
        ->and(collect(Schema::getColumns('builder_ads'))->firstWhere('name', 'store_id')['nullable'])->toBeFalse();

    foreach ($files as $file) {
        Storage::disk('public')->assertMissing($file);
    }

    $migration->up();

    expect(collect(Schema::getColumns('builder_ads'))->firstWhere('name', 'store_id')['nullable'])->toBeTrue()
        ->and(BuilderAd::factory()->create(['store_id' => null])->isShared())->toBeTrue();
});
