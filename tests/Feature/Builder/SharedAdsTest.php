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
| "Mein all shop k liya ads kese banao? Jese asset mein ha woo ads sub ko dikhe aur woo copy kar sake." Above the
| stores an ad with no shop chosen — All shops — is the platform's, shared with every shop: it uses the shared files
| alone, publishes into the platform's own library, and every shop sees it once it is published ("publish ke baad") —
| as it was published, never its unfinished changes — and copies it into its own Ads. A shop's people see it, use it
| and copy it, and nothing more, whatever their role holds ("srif delete nahi kar sakta ha ... permission hata do"):
| only above the stores is it changed (Update Ads) or deleted (Delete Ads).
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

test('a shop\'s people see, use and copy the platform\'s ad, and nothing more — whatever their role holds', function () {
    $ad = makeForEveryShop($this);
    $draft = makeForEveryShop($this, 'Spring sale', publish: false);

    // Every permission a shop's role may carry for its ads (owner, 2026-10-01: "srif delete nahi kar sakta ha").
    $this->actingAs($this->designer)->withSession(['current_store_id' => $this->store->id]);

    $this->get("/builder/{$ad->id}")->assertForbidden();
    $this->putJson("/builder/{$ad->id}", ['name' => 'Mine', 'document' => everyShopDocument('Mine')])
        ->assertForbidden()->assertJsonPath('message', 'An ad for every shop is the platform\'s: copy it to change it.');
    $this->postJson("/builder/{$ad->id}/publish")->assertForbidden();
    $this->postJson("/builder/{$ad->id}/unpublish")->assertForbidden();
    $this->postJson("/builder/{$ad->id}/discard")->assertForbidden();
    $this->deleteJson("/builder/{$ad->id}", ['password' => 'password'])
        ->assertForbidden()->assertJsonPath('message', 'An ad for every shop is the platform\'s: only the platform deletes it.');

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

test('above the stores the platform\'s ads are changed with Update Ads and deleted with Delete Ads, and copies stay', function () {
    $ad = makeForEveryShop($this);
    $draft = makeForEveryShop($this, 'Spring sale', publish: false);

    // Beta Deli copied it before.
    $this->actingAs(createStoreUser($this->other, ['ad-view', 'ad-store'], 'Beta designer'))->withSession(['current_store_id' => $this->other->id]);
    $copyId = $this->postJson("/builder/{$ad->id}/duplicate")->assertOk()->json('ad.id');

    $platform = createPlatformUser(['ad-view', 'ad-store', 'ad-update', 'ad-destroy'], 'Platform designer');
    $this->actingAs($platform);
    $this->flushSession();

    $seen = collect($this->getJson('/builder/data')->assertOk()->json('ads'))->keyBy('id');
    expect($seen[$ad->id])->toMatchArray(['owner_label' => 'Every shop', 'can' => ['update' => true, 'copy' => true, 'delete' => true]])
        ->and($seen->has($draft->id))->toBeTrue();

    $this->get("/builder/{$ad->id}")->assertOk()->assertSee('Every shop');
    $this->putJson("/builder/{$ad->id}", ['name' => 'Winter sale', 'document' => everyShopDocument('Twenty percent off')])->assertOk()
        ->assertJsonPath('message', 'Changes saved — shops keep the published version until you publish them');
    $this->postJson("/builder/{$ad->id}/publish")->assertOk()->assertJsonPath('message', 'Published — every shop sees it now and can copy it');

    expect($ad->fresh()->published_document['elements'][0]['text'])->toBe('Twenty percent off')
        ->and(ActivityLog::where('action', 'ad.updated')->sole()->store_id)->toBeNull();

    // Taken off, it leaves every shop's Ads until it is published again.
    $this->postJson("/builder/{$ad->id}/unpublish")->assertOk()->assertJsonPath('message', 'Unpublished — it is a draft again. Shops no longer see it');
    expect(adsSeenIn($this, $this->designer, $this->store)->all())->toBe([]);

    // A channel showing it keeps it; out of the channel it goes — and Beta Deli's copy stays theirs.
    $this->actingAs($platform);
    $this->flushSession();
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();
    $channelAd = ChannelAd::factory()->create(['channel_id' => Channel::factory()->create()->id, 'media_id' => $ad->media_id]);

    $this->deleteJson("/builder/{$ad->id}", ['password' => 'password'])->assertStatus(422)->assertJsonValidationErrors('name');
    $channelAd->delete();
    $this->deleteJson("/builder/{$ad->id}", ['password' => 'password'])->assertOk();

    expect(BuilderAd::find($ad->id))->toBeNull()
        ->and(Media::find($ad->media_id))->toBeNull()
        ->and(BuilderAd::find($copyId)?->store_id)->toBe($this->other->id)
        ->and(ActivityLog::where('action', 'ad.deleted')->sole()->description)->toBe('Deleted ad Winter sale, shared with every shop');
    Storage::disk('public')->assertMissing("builder/platform/ads/{$ad->id}/index.html");
});

test('above the stores a copy of the platform\'s ad stays the platform\'s; without Update Ads a platform role only looks', function () {
    $ad = makeForEveryShop($this);

    $copyId = $this->postJson("/builder/{$ad->id}/duplicate")->assertOk()->assertJsonPath('message', 'Ad duplicated')->json('ad.id');
    expect(BuilderAd::find($copyId))->store_id->toBeNull()->name->toBe('Winter sale (copy)');

    $this->actingAs(createPlatformUser(['ad-view'], 'Platform viewer'));
    $this->flushSession();

    expect(collect($this->getJson('/builder/data')->assertOk()->json('ads'))->firstWhere('id', $ad->id))
        ->toMatchArray(['owner_label' => 'Every shop', 'can' => ['update' => false, 'copy' => false, 'delete' => false]]);

    $this->postJson("/builder/{$ad->id}/duplicate")->assertForbidden();
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

    // A shop's shelf counts the platform's ad that uses it, never naming it — and offers no delete.
    $this->actingAs($this->designer)->withSession(['current_store_id' => $this->store->id]);

    $row = collect($this->getJson('/builder/assets/data')->json('assets'))->firstWhere('id', $file->id);
    expect($row)->toMatchArray(['used_by' => [], 'used_elsewhere' => 0, 'used_by_platform' => 1, 'can_delete' => false])
        ->and(json_encode($row))->not->toContain('Secret launch');

    // Above the stores the ad is named, as an ad for every shop, and the file stays while it uses it.
    $this->actingAs(createSuperAdmin());
    $this->flushSession();

    expect(collect($this->getJson('/builder/assets/data')->json('assets'))->firstWhere('id', $file->id)['used_by'])->toBe(['Secret launch (every shop)']);
    $this->deleteJson("/builder/assets/{$file->id}")->assertStatus(422)
        ->assertJsonValidationErrors(['title' => 'Still used by Secret launch (every shop). Take it out of those ads first, and publish the ones whose screens still show it.']);
});

test('a deleted shop leaves the platform\'s ads', function () {
    $ad = makeForEveryShop($this);

    $this->store->delete();

    expect(BuilderAd::find($ad->id))->not->toBeNull();
    Storage::disk('public')->assertExists($ad->media->path);
});

test('no permission lets a shop change or delete what the platform shares: the three shared permissions are gone', function () {
    $removed = ['ad-shared-update' => 'Update Shared Ads', 'ad-shared-destroy' => 'Delete Shared Ads', 'ad-shared-asset-destroy' => 'Delete Shared Assets'];

    foreach (array_keys($removed) as $name) {
        expect(Permission::LABELS)->not->toHaveKey($name)
            ->and(Permission::PLATFORM)->not->toContain($name)
            ->and(Permission::STORE_SCOPED)->not->toContain($name)
            ->and(DB::table('permissions')->where('name', $name)->exists())->toBeFalse();
    }

    // Going back brings the three, held by Super-Admin alone, as the migration before it left them; forward again,
    // they are gone with every hold on them.
    $superAdmin = Role::firstOrCreate(['name' => Role::SUPER_ADMIN], ['is_global' => true]);
    $migration = require database_path('migrations/2026_10_01_120000_remove_the_shared_permissions.php');
    $migration->down();

    expect(Permission::whereIn('name', array_keys($removed))->pluck('label', 'name')->sortKeys()->all())->toBe(collect($removed)->sortKeys()->all());

    foreach (array_keys($removed) as $name) {
        expect(Permission::where('name', $name)->sole()->roles()->pluck('roles.id')->all())->toBe([$superAdmin->id]);
    }

    $migration->up();

    expect(Permission::whereIn('name', array_keys($removed))->count())->toBe(0)
        ->and(DB::table('role_has_permissions')->where('role_id', $superAdmin->id)->count())->toBe(0);
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
