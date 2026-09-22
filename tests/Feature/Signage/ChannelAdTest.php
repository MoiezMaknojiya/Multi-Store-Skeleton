<?php

use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| The ads inside a channel
|--------------------------------------------------------------------------
|
| docs/CHANNEL-CONTENT-SPEC.md. A channel keeps no files of its own: every ad is a row of a media library,
| held by id — chosen from the library, chosen from the Ad Builder (a published ad IS a library row), or
| uploaded, which puts the file in the channel's library first. Taking an ad out leaves the file there.
|
| An image or an ad page is given its seconds; a video has none to give — it plays to its own end (owner's
| decision), so any seconds that arrive for one are thrown away.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->admin = createSuperAdmin(['channel-view', 'channel-update']);
    // The platform's channel (no store): its uploads join the platform's own library.
    $this->channel = Channel::factory()->create(['name' => 'GAMA']);
});

/** Add one ad to this test's channel through the real endpoint. */
function uploadChannelAd($test, array $fields)
{
    return $test->actingAs($test->admin)
        ->post("/channels/{$test->channel->id}/ads", $fields, ['Accept' => 'application/json']);
}

/** Edit one ad through the real endpoint. */
function editChannelAd($test, ChannelAd $ad, array $fields)
{
    return $test->actingAs($test->admin)
        ->post("/channels/{$test->channel->id}/ads/{$ad->id}", $fields, ['Accept' => 'application/json']);
}

/*
|--------------------------------------------------------------------------
| Adding — an upload joins the library
|--------------------------------------------------------------------------
*/

test('an image uploaded inside a channel joins the library, and the ad shows it from there', function () {
    ChannelAd::factory()->create(['channel_id' => $this->channel->id, 'position' => 5, 'title' => 'Already there']);

    $ads = uploadChannelAd($this, ['file' => UploadedFile::fake()->image('coke-2l.jpg', 640, 360), 'seconds' => 12])
        ->assertOk()->json('ads');

    expect(collect($ads)->pluck('title')->all())->toBe(['Already there', 'coke-2l']);

    $ad = ChannelAd::firstWhere('title', 'coke-2l');
    $media = $ad->media;

    // The platform's channel: the file is a row of the platform's own library, in its folder.
    expect($media->store_id)->toBeNull()
        ->and($media->title)->toBe('coke-2l')
        ->and($media->path)->toStartWith('media/platform/')
        ->and($ad->type)->toBe('image')
        ->and($ad->duration_seconds)->toBe(12)
        ->and($ad->play_seconds)->toBe(12)
        ->and(collect($ads)->firstWhere('title', 'coke-2l')['library'])->toBe('Platform');

    Storage::disk('public')->assertExists($media->path);
    Storage::disk('public')->assertExists($media->thumbnail_path);
    $this->assertDatabaseHas('activity_logs', ['action' => 'channel.ad_added']);
    $this->assertDatabaseHas('activity_logs', ['action' => 'media.uploaded', 'store_id' => null]);
});

test("an upload inside a shop's own channel joins that shop's library", function () {
    $store = Store::factory()->create();
    $keeper = createStoreUser($store, ['channel-view', 'channel-update']);
    $own = Channel::factory()->create(['store_id' => $store->id]);

    $this->actingAs($keeper)->withSession(['current_store_id' => $store->id])
        ->post("/channels/{$own->id}/ads", ['file' => UploadedFile::fake()->image('deal.jpg'), 'seconds' => 7], ['Accept' => 'application/json'])
        ->assertOk();

    $media = ChannelAd::where('channel_id', $own->id)->sole()->media;

    expect($media->store_id)->toBe($store->id)
        ->and($media->path)->toStartWith("media/{$store->id}/");

    // …and the shop sees it in its Media library afterwards.
    $keeper2 = createStoreUser($store, ['media-view'], 'Librarian');
    $titles = collect($this->actingAs($keeper2)->withSession(['current_store_id' => $store->id])
        ->getJson('/media/data')->assertOk()->json('media'))->pluck('title');

    expect($titles->all())->toBe(['deal']);
});

test('an image needs its seconds', function () {
    uploadChannelAd($this, ['file' => UploadedFile::fake()->image('coke.jpg')])
        ->assertStatus(422)->assertJsonValidationErrors('seconds');

    expect(ChannelAd::count())->toBe(0)
        ->and(Media::count())->toBe(0);
});

test('an image may not hold the screen longer than the ceiling', function () {
    uploadChannelAd($this, ['file' => UploadedFile::fake()->image('coke.jpg'), 'seconds' => ChannelAd::MAX_IMAGE_SECONDS + 1])
        ->assertStatus(422)->assertJsonValidationErrors('seconds');
});

test('a video has no seconds at all — any that arrive are thrown away, and its own length is kept', function () {
    uploadChannelAd($this, [
        'file' => UploadedFile::fake()->create('monster.mp4', 800, 'video/mp4'),
        'seconds' => 99,
        // What the browser measured.
        'duration_seconds' => 45,
    ])->assertOk();

    $ad = ChannelAd::firstWhere('title', 'monster');
    expect($ad->type)->toBe('video')
        ->and($ad->duration_seconds)->toBeNull()
        ->and($ad->media->duration_seconds)->toBe(45)
        ->and($ad->play_seconds)->toBe(45);
});

test('a video the browser could not measure still gets a safe backstop', function () {
    uploadChannelAd($this, ['file' => UploadedFile::fake()->create('monster.mp4', 800, 'video/mp4')])->assertOk();

    expect(ChannelAd::firstWhere('title', 'monster')->play_seconds)->toBe(ChannelAd::UNMEASURED_VIDEO_SECONDS);
});

test('only the formats a television can play are accepted', function () {
    uploadChannelAd($this, ['file' => UploadedFile::fake()->create('menu.pdf', 100, 'application/pdf'), 'seconds' => 10])
        ->assertStatus(422)->assertJsonValidationErrors('file');

    expect(Media::count())->toBe(0);
});

test('a typed title wins over the file name', function () {
    uploadChannelAd($this, ['file' => UploadedFile::fake()->image('img_0042.jpg'), 'seconds' => 10, 'title' => 'Coke 2L $1.99'])
        ->assertOk();

    expect(ChannelAd::first()->title)->toBe('Coke 2L $1.99')
        ->and(Media::first()->title)->toBe('Coke 2L $1.99');
});

test('the end date cannot come before the start', function () {
    uploadChannelAd($this, [
        'file' => UploadedFile::fake()->image('coke.jpg'), 'seconds' => 10,
        'starts_on' => '2026-10-15', 'ends_on' => '2026-10-01',
    ])->assertStatus(422)->assertJsonValidationErrors('ends_on');
});

/*
|--------------------------------------------------------------------------
| Adding — a file chosen from the library, or an Ad Builder ad
|--------------------------------------------------------------------------
*/

test('a file chosen from the library is held by id, and nothing is uploaded or copied', function () {
    $promo = Media::factory()->platformOwned()->create(['title' => 'Summer promo']);

    uploadChannelAd($this, ['media_id' => $promo->id, 'seconds' => 8])->assertOk();

    $ad = ChannelAd::sole();

    expect($ad->media_id)->toBe($promo->id)
        ->and($ad->title)->toBe('Summer promo')      // a blank title takes the file's own
        ->and($ad->play_seconds)->toBe(8)
        ->and(Media::count())->toBe(1)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('a published Ad Builder ad is chosen the same way, and runs on seconds like an image', function () {
    $page = Media::factory()->adPage()->platformOwned()->create(['title' => 'Grand opening']);

    uploadChannelAd($this, ['media_id' => $page->id])->assertStatus(422)->assertJsonValidationErrors('seconds');

    uploadChannelAd($this, ['media_id' => $page->id, 'seconds' => 15])->assertOk();

    $ad = ChannelAd::sole();

    expect($ad->type)->toBe(Media::TYPE_HTML)
        ->and($ad->play_seconds)->toBe(15)
        // The page's own address, with the version a re-publish moves.
        ->and($ad->url)->toContain('/index.html?v=');
});

test('a file and a choice together, or neither, are refused', function () {
    $promo = Media::factory()->platformOwned()->create();

    uploadChannelAd($this, ['media_id' => $promo->id, 'file' => UploadedFile::fake()->image('x.jpg'), 'seconds' => 5])
        ->assertStatus(422)->assertJsonValidationErrors('file');

    uploadChannelAd($this, ['seconds' => 5])
        ->assertStatus(422)->assertJsonValidationErrors(['media_id', 'file']);

    expect(ChannelAd::count())->toBe(0);
});

test("a shop's channel takes that shop's files only; the platform's channel takes its own or any shop's", function () {
    $store = Store::factory()->create();
    $rival = Store::factory()->create();
    $ours = Media::factory()->create(['store_id' => $store->id]);
    $theirs = Media::factory()->create(['store_id' => $rival->id]);
    $platform = Media::factory()->platformOwned()->create();

    $keeper = createStoreUser($store, ['channel-view', 'channel-update']);
    $own = Channel::factory()->create(['store_id' => $store->id]);

    $this->actingAs($keeper)->withSession(['current_store_id' => $store->id]);

    foreach ([$theirs, $platform] as $foreign) {
        $this->postJson("/channels/{$own->id}/ads", ['media_id' => $foreign->id, 'seconds' => 5])
            ->assertStatus(422)->assertJsonValidationErrors('media_id');
    }

    $this->postJson("/channels/{$own->id}/ads", ['media_id' => $ours->id, 'seconds' => 5])->assertOk();

    // The platform's channel, from above the stores: its own library and any shop's.
    foreach ([$platform, $theirs] as $allowed) {
        uploadChannelAd($this, ['media_id' => $allowed->id, 'seconds' => 5])->assertOk();
    }

    expect(ChannelAd::where('channel_id', $this->channel->id)->pluck('media_id')->all())->toBe([$platform->id, $theirs->id]);
});

test('an id that is not an id is refused, never a 500', function () {
    foreach ([['x'], 'abc', 0, -3, 99999999] as $id) {
        uploadChannelAd($this, ['media_id' => $id, 'seconds' => 5])->assertStatus(422);
    }

    expect(ChannelAd::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The pickers' own list
|--------------------------------------------------------------------------
*/

test("a shop's channel lists that shop's library to choose from, and nothing else", function () {
    $store = Store::factory()->create();
    $keeper = createStoreUser($store, ['channel-view', 'channel-update']);
    $own = Channel::factory()->create(['store_id' => $store->id]);

    Media::factory()->create(['store_id' => $store->id, 'title' => 'Ours']);
    Media::factory()->adPage()->create(['store_id' => $store->id, 'title' => 'Our ad']);
    Media::factory()->create(['store_id' => Store::factory()->create()->id, 'title' => 'Theirs']);
    Media::factory()->platformOwned()->create(['title' => 'Platform']);

    $this->actingAs($keeper)->withSession(['current_store_id' => $store->id]);

    $all = collect($this->getJson("/channels/{$own->id}/library")->assertOk()->json('media'))->pluck('title')->sort()->values();
    $ads = collect($this->getJson("/channels/{$own->id}/library?type=html")->assertOk()->json('media'))->pluck('title');

    expect($all->all())->toBe(['Our ad', 'Ours'])
        ->and($ads->all())->toBe(['Our ad']);

    // Asking for another library changes nothing for a shop's channel.
    $asked = collect($this->getJson("/channels/{$own->id}/library?library=platform")->assertOk()->json('media'))->pluck('title');
    expect($asked->sort()->values()->all())->toBe(['Our ad', 'Ours']);

    // What a tile shows and nothing more: the permission that reads this is not media-view.
    expect(array_keys($this->getJson("/channels/{$own->id}/library?type=html")->json('media.0')))
        ->toBe(['id', 'title', 'type', 'orientation', 'duration_seconds', 'thumbnail_url']);
});

test("the platform's channel lists its own library by default, or the shop it is asked for", function () {
    $store = Store::factory()->create();
    Media::factory()->create(['store_id' => $store->id, 'title' => 'Shop file']);
    Media::factory()->platformOwned()->create(['title' => 'Platform file']);

    $this->actingAs($this->admin);

    $default = collect($this->getJson("/channels/{$this->channel->id}/library")->assertOk()->json('media'))->pluck('title');
    $shop = collect($this->getJson("/channels/{$this->channel->id}/library?library={$store->id}")->assertOk()->json('media'))->pluck('title');

    expect($default->all())->toBe(['Platform file'])
        ->and($shop->all())->toBe(['Shop file']);

    $this->getJson("/channels/{$this->channel->id}/library?library[]=1")->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| Editing
|--------------------------------------------------------------------------
*/

test('an image is retimed and redated without choosing it again', function () {
    $ad = ChannelAd::factory()->lasting(10)->create(['channel_id' => $this->channel->id]);
    $mediaId = $ad->media_id;

    editChannelAd($this, $ad, ['seconds' => 20, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31'])->assertOk();

    $ad->refresh();
    expect($ad->duration_seconds)->toBe(20)
        ->and($ad->starts_on->toDateString())->toBe('2026-10-01')
        ->and($ad->ends_on->toDateString())->toBe('2026-10-31')
        ->and($ad->media_id)->toBe($mediaId);
});

test('seconds sent for a video on an edit are ignored', function () {
    $ad = ChannelAd::factory()->video(40)->create(['channel_id' => $this->channel->id]);

    editChannelAd($this, $ad, ['seconds' => 5])->assertOk();

    expect($ad->fresh()->duration_seconds)->toBeNull()
        ->and($ad->fresh()->play_seconds)->toBe(40);
});

test('showing another file leaves the one before in its library', function () {
    uploadChannelAd($this, ['file' => UploadedFile::fake()->image('old.jpg'), 'seconds' => 10])->assertOk();
    $ad = ChannelAd::first();
    $old = $ad->media;

    editChannelAd($this, $ad, ['file' => UploadedFile::fake()->image('new.jpg'), 'seconds' => 10])->assertOk();

    $ad->refresh();
    expect($ad->media_id)->not->toBe($old->id)
        ->and($ad->media->title)->toBe('new')
        // The title it was given stays; only the file changed.
        ->and($ad->title)->toBe('old')
        // The library keeps both: the file before was never the channel's to delete.
        ->and(Media::whereKey($old->id)->exists())->toBeTrue();

    Storage::disk('public')->assertExists($old->path);
    Storage::disk('public')->assertExists($ad->media->path);
});

test('an ad can be pointed at another library file by id', function () {
    $ad = ChannelAd::factory()->create(['channel_id' => $this->channel->id]);
    $other = Media::factory()->platformOwned()->create(['title' => 'Other']);

    editChannelAd($this, $ad, ['media_id' => $other->id, 'seconds' => 9])->assertOk();

    expect($ad->fresh()->media_id)->toBe($other->id)
        ->and($ad->fresh()->play_seconds)->toBe(9);
});

test('an image replaced by a video loses its seconds', function () {
    uploadChannelAd($this, ['file' => UploadedFile::fake()->image('coke.jpg'), 'seconds' => 10])->assertOk();
    $ad = ChannelAd::first();

    editChannelAd($this, $ad, [
        'file' => UploadedFile::fake()->create('coke.mp4', 500, 'video/mp4'), 'duration_seconds' => 30,
    ])->assertOk();

    $ad->refresh();
    expect($ad->type)->toBe('video')
        ->and($ad->duration_seconds)->toBeNull()
        ->and($ad->media->duration_seconds)->toBe(30);
});

/*
|--------------------------------------------------------------------------
| Removing and reordering
|--------------------------------------------------------------------------
*/

test('taking an ad out of a channel leaves its file in the library', function () {
    uploadChannelAd($this, ['file' => UploadedFile::fake()->image('coke.jpg'), 'seconds' => 10])->assertOk();
    $ad = ChannelAd::first();
    $media = $ad->media;

    $this->actingAs($this->admin)->deleteJson("/channels/{$this->channel->id}/ads/{$ad->id}")->assertOk();

    expect(ChannelAd::find($ad->id))->toBeNull()
        ->and(Media::whereKey($media->id)->exists())->toBeTrue();
    Storage::disk('public')->assertExists($media->path);
    Storage::disk('public')->assertExists($media->thumbnail_path);
});

test('an ad in another channel cannot be reached through this one', function () {
    $theirs = ChannelAd::factory()->create(['channel_id' => Channel::factory()->create()->id]);

    editChannelAd($this, $theirs, ['seconds' => 5])->assertNotFound();
    $this->deleteJson("/channels/{$this->channel->id}/ads/{$theirs->id}")->assertNotFound();

    expect(ChannelAd::find($theirs->id))->not->toBeNull();
});

test("the order is sent whole, and only as exactly this channel's own ads", function () {
    [$a, $b, $c] = ChannelAd::factory()->count(3)->sequence(
        ['position' => 0, 'title' => 'A'],
        ['position' => 1, 'title' => 'B'],
        ['position' => 2, 'title' => 'C'],
    )->create(['channel_id' => $this->channel->id]);

    $this->actingAs($this->admin);

    $ads = $this->putJson("/channels/{$this->channel->id}/ads/order", ['ad_ids' => [$c->id, $a->id, $b->id]])
        ->assertOk()->json('ads');
    expect(collect($ads)->pluck('title')->all())->toBe(['C', 'A', 'B']);

    // Part of the list, a stranger, or the same ad twice: all refused, order untouched.
    $stranger = ChannelAd::factory()->create(['channel_id' => Channel::factory()->create()->id]);

    foreach ([[$a->id, $b->id], [$c->id, $a->id, $stranger->id], [$c->id, $c->id, $a->id]] as $bad) {
        $this->putJson("/channels/{$this->channel->id}/ads/order", ['ad_ids' => $bad])->assertStatus(422);
    }

    expect($this->channel->ads()->pluck('title')->all())->toBe(['C', 'A', 'B']);
});

test('each ad says whether it is running, starts later, or has ended', function () {
    $this->travelTo('2026-10-10 12:00:00');

    ChannelAd::factory()->create(['channel_id' => $this->channel->id, 'title' => 'Now', 'position' => 0]);
    ChannelAd::factory()->running('2026-10-20', null)->create(['channel_id' => $this->channel->id, 'title' => 'Later', 'position' => 1]);
    ChannelAd::factory()->running(null, '2026-10-09')->create(['channel_id' => $this->channel->id, 'title' => 'Over', 'position' => 2]);

    $ads = $this->actingAs($this->admin)->getJson("/channels/{$this->channel->id}/ads")->assertOk()->json('ads');

    expect(collect($ads)->pluck('status', 'title')->all())
        ->toBe(['Now' => 'running', 'Later' => 'scheduled', 'Over' => 'ended']);
});

/*
|--------------------------------------------------------------------------
| Who may
|--------------------------------------------------------------------------
*/

test('seeing the ads needs channel-view; changing them needs channel-update', function () {
    $viewer = createPlatformUser(['channel-view'], 'Viewer');

    $this->actingAs($viewer)->getJson("/channels/{$this->channel->id}/ads")->assertOk();

    $this->post("/channels/{$this->channel->id}/ads", [
        'file' => UploadedFile::fake()->image('x.jpg'), 'seconds' => 5,
    ], ['Accept' => 'application/json'])->assertForbidden();

    $this->putJson("/channels/{$this->channel->id}/ads/order", ['ad_ids' => []])->assertForbidden();
    $this->getJson("/channels/{$this->channel->id}/library")->assertForbidden();
});

test("inside a store the platform's channel is read, never changed", function () {
    // Inside a store the channel permissions CHANGE the store's own channels alone; the platform's may be
    // looked at (owner, 2026-09-19) and nothing more.
    $store = Store::factory()->create();
    $keeper = createStoreUser($store, ['channel-view', 'channel-update']);
    $ad = ChannelAd::factory()->create(['channel_id' => $this->channel->id, 'title' => 'Platform promo']);

    $this->actingAs($keeper)->withSession(['current_store_id' => $store->id]);

    $this->getJson("/channels/{$this->channel->id}/ads")->assertOk()->assertJsonPath('ads.0.title', 'Platform promo');
    $this->get("/channels/{$this->channel->id}")->assertOk()->assertDontSee('dusk="add-channel-ad"', false);

    $this->post("/channels/{$this->channel->id}/ads", ['file' => UploadedFile::fake()->image('x.jpg'), 'seconds' => 5], ['Accept' => 'application/json'])
        ->assertNotFound();
    $this->getJson("/channels/{$this->channel->id}/library")->assertNotFound();
    $this->putJson("/channels/{$this->channel->id}/ads/order", ['ad_ids' => [$ad->id]])->assertNotFound();
    $this->postJson("/channels/{$this->channel->id}/ads/{$ad->id}", ['seconds' => 5])->assertNotFound();
    $this->deleteJson("/channels/{$this->channel->id}/ads/{$ad->id}")->assertNotFound();

    expect(ChannelAd::where('channel_id', $this->channel->id)->pluck('title')->all())->toBe(['Platform promo'])
        ->and(Media::count())->toBe(1);
});

test("a store's own channel takes its ads from inside that store — and from no other store", function () {
    $store = Store::factory()->create();
    $keeper = createStoreUser($store, ['channel-view', 'channel-update']);
    $own = Channel::factory()->create(['name' => 'Our Specials', 'store_id' => $store->id]);

    $this->actingAs($keeper)->withSession(['current_store_id' => $store->id])
        ->post("/channels/{$own->id}/ads", ['file' => UploadedFile::fake()->image('deal.jpg'), 'seconds' => 7], ['Accept' => 'application/json'])
        ->assertOk();

    expect(ChannelAd::where('channel_id', $own->id)->value('title'))->toBe('deal');

    $rival = Store::factory()->create();
    $stranger = createStoreUser($rival, ['channel-view', 'channel-update'], 'Rival Keeper');
    $this->actingAs($stranger)->withSession(['current_store_id' => $rival->id])
        ->getJson("/channels/{$own->id}/ads")->assertNotFound();
    $this->getJson("/channels/{$own->id}/library")->assertNotFound();
});

test('the channel page opens for the super admin', function () {
    $this->actingAs($this->admin)->get("/channels/{$this->channel->id}")->assertOk()->assertSee('GAMA');
});
