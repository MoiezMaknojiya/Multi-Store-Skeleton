<?php

use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| The ads inside a channel
|--------------------------------------------------------------------------
|
| An image is given its seconds; a video has none to give — it plays to its own end
| (owner's decision), so any seconds that arrive for one are thrown away. A file and its
| row are one thing: replacing or removing an ad takes the old file off the disk too.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->admin = createSuperAdmin(['channel-view', 'channel-update']);
    $this->channel = Channel::factory()->create(['name' => 'GAMA']);
});

/** Upload one ad to this test's channel through the real endpoint. */
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
| Adding
|--------------------------------------------------------------------------
*/

test('an image ad is uploaded with its seconds and joins the end of the channel', function () {
    ChannelAd::factory()->create(['channel_id' => $this->channel->id, 'position' => 5, 'title' => 'Already there']);

    $ads = uploadChannelAd($this, ['file' => UploadedFile::fake()->image('coke-2l.jpg', 640, 360), 'seconds' => 12])
        ->assertOk()->json('ads');

    expect(collect($ads)->pluck('title')->all())->toBe(['Already there', 'coke-2l']);

    $ad = ChannelAd::firstWhere('title', 'coke-2l');
    expect($ad->type)->toBe('image');
    expect($ad->duration_seconds)->toBe(12);
    expect($ad->media_duration_seconds)->toBeNull();
    expect($ad->play_seconds)->toBe(12);
    expect($ad->path)->toStartWith("channels/{$this->channel->id}/");

    Storage::disk('public')->assertExists($ad->path);
    Storage::disk('public')->assertExists($ad->thumbnail_path);
    $this->assertDatabaseHas('activity_logs', ['action' => 'channel.ad_added']);
});

test('an image needs its seconds', function () {
    uploadChannelAd($this, ['file' => UploadedFile::fake()->image('coke.jpg')])
        ->assertStatus(422)->assertJsonValidationErrors('seconds');

    expect(ChannelAd::count())->toBe(0);
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
    expect($ad->type)->toBe('video');
    expect($ad->duration_seconds)->toBeNull();
    expect($ad->media_duration_seconds)->toBe(45);
    expect($ad->play_seconds)->toBe(45);
});

test('a video the browser could not measure still gets a safe backstop', function () {
    uploadChannelAd($this, ['file' => UploadedFile::fake()->create('monster.mp4', 800, 'video/mp4')])->assertOk();

    expect(ChannelAd::firstWhere('title', 'monster')->play_seconds)->toBe(ChannelAd::UNMEASURED_VIDEO_SECONDS);
});

test('only the formats a television can play are accepted', function () {
    uploadChannelAd($this, ['file' => UploadedFile::fake()->create('menu.pdf', 100, 'application/pdf'), 'seconds' => 10])
        ->assertStatus(422)->assertJsonValidationErrors('file');
});

test('a typed title wins over the file name', function () {
    uploadChannelAd($this, ['file' => UploadedFile::fake()->image('img_0042.jpg'), 'seconds' => 10, 'title' => 'Coke 2L $1.99'])
        ->assertOk();

    expect(ChannelAd::first()->title)->toBe('Coke 2L $1.99');
});

test('the end date cannot come before the start', function () {
    uploadChannelAd($this, [
        'file' => UploadedFile::fake()->image('coke.jpg'), 'seconds' => 10,
        'starts_on' => '2026-10-15', 'ends_on' => '2026-10-01',
    ])->assertStatus(422)->assertJsonValidationErrors('ends_on');
});

/*
|--------------------------------------------------------------------------
| Editing
|--------------------------------------------------------------------------
*/

test('an image is retimed and redated without uploading it again', function () {
    $ad = ChannelAd::factory()->lasting(10)->create(['channel_id' => $this->channel->id]);
    $path = $ad->path;

    editChannelAd($this, $ad, ['seconds' => 20, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31'])->assertOk();

    $ad->refresh();
    expect($ad->duration_seconds)->toBe(20);
    expect($ad->starts_on->toDateString())->toBe('2026-10-01');
    expect($ad->ends_on->toDateString())->toBe('2026-10-31');
    expect($ad->path)->toBe($path);
});

test('seconds sent for a video on an edit are ignored', function () {
    $ad = ChannelAd::factory()->video(40)->create(['channel_id' => $this->channel->id]);

    editChannelAd($this, $ad, ['seconds' => 5])->assertOk();

    expect($ad->fresh()->duration_seconds)->toBeNull();
    expect($ad->fresh()->play_seconds)->toBe(40);
});

test('a replaced file is removed from disk, and the row points at the new one', function () {
    uploadChannelAd($this, ['file' => UploadedFile::fake()->image('old.jpg'), 'seconds' => 10])->assertOk();
    $ad = ChannelAd::first();
    [$oldPath, $oldThumb] = [$ad->path, $ad->thumbnail_path];

    editChannelAd($this, $ad, ['file' => UploadedFile::fake()->image('new.jpg'), 'seconds' => 10])->assertOk();

    $ad->refresh();
    expect($ad->path)->not->toBe($oldPath);
    Storage::disk('public')->assertExists($ad->path);
    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertMissing($oldThumb);
    // The title it was given stays; only the file changed.
    expect($ad->title)->toBe('old');
});

test('an image replaced by a video loses its seconds', function () {
    uploadChannelAd($this, ['file' => UploadedFile::fake()->image('coke.jpg'), 'seconds' => 10])->assertOk();
    $ad = ChannelAd::first();

    editChannelAd($this, $ad, [
        'file' => UploadedFile::fake()->create('coke.mp4', 500, 'video/mp4'), 'duration_seconds' => 30,
    ])->assertOk();

    $ad->refresh();
    expect($ad->type)->toBe('video');
    expect($ad->duration_seconds)->toBeNull();
    expect($ad->media_duration_seconds)->toBe(30);
});

/*
|--------------------------------------------------------------------------
| Removing and reordering
|--------------------------------------------------------------------------
*/

test('removing an ad deletes its file and thumbnail with it', function () {
    uploadChannelAd($this, ['file' => UploadedFile::fake()->image('coke.jpg'), 'seconds' => 10])->assertOk();
    $ad = ChannelAd::first();

    $this->actingAs($this->admin)->deleteJson("/channels/{$this->channel->id}/ads/{$ad->id}")->assertOk();

    expect(ChannelAd::find($ad->id))->toBeNull();
    Storage::disk('public')->assertMissing($ad->path);
    Storage::disk('public')->assertMissing($ad->thumbnail_path);
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
    $role = Role::create(['name' => 'Viewer', 'is_global' => true]);
    $role->permissions()->sync(grantPermissions(['channel-view'])->pluck('id'));
    $viewer = User::factory()->create();
    $viewer->stores()->attach(0, ['role_id' => $role->id]);

    $this->actingAs($viewer)->getJson("/channels/{$this->channel->id}/ads")->assertOk();

    $this->post("/channels/{$this->channel->id}/ads", [
        'file' => UploadedFile::fake()->image('x.jpg'), 'seconds' => 5,
    ], ['Accept' => 'application/json'])->assertForbidden();

    $this->putJson("/channels/{$this->channel->id}/ads/order", ['ad_ids' => []])->assertForbidden();
});

test("a store user holding the channel permissions cannot find the platform's channel — nor touch its ads", function () {
    // Inside a store the channel permissions reach the store's own channels alone.
    $store = Store::factory()->create();
    $keeper = createStoreUser($store, ['channel-view', 'channel-update']);
    $ad = ChannelAd::factory()->create(['channel_id' => $this->channel->id, 'title' => 'Platform promo']);

    $this->actingAs($keeper)->withSession(['current_store_id' => $store->id]);

    $this->getJson("/channels/{$this->channel->id}/ads")->assertNotFound();
    $this->get("/channels/{$this->channel->id}")->assertNotFound();
    $this->post("/channels/{$this->channel->id}/ads", ['file' => UploadedFile::fake()->image('x.jpg'), 'seconds' => 5], ['Accept' => 'application/json'])
        ->assertNotFound();
    $this->putJson("/channels/{$this->channel->id}/ads/order", ['ad_ids' => [$ad->id]])->assertNotFound();
    $this->deleteJson("/channels/{$this->channel->id}/ads/{$ad->id}")->assertNotFound();

    expect(ChannelAd::where('channel_id', $this->channel->id)->pluck('title')->all())->toBe(['Platform promo']);
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
});

test('the channel page opens for the super admin', function () {
    $this->actingAs($this->admin)->get("/channels/{$this->channel->id}")->assertOk()->assertSee('GAMA');
});
