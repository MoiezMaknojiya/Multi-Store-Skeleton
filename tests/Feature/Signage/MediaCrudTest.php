<?php

use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('guests cannot access any media endpoint', function () {
    // Every route under /media, read from the route table — so one added later is asked too.
    $routes = routesUnder('media');

    expect($routes)->not->toBeEmpty();

    foreach ($routes as [$method, $uri]) {
        expect($this->json($method, $uri)->status())->toBe(401, "{$method} {$uri}");
    }
});

test('a store user only sees the media of the store they are working in', function () {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    $actor = createStoreUser($storeA, ['media-view']);

    $mine = Media::factory()->create(['store_id' => $storeA->id, 'title' => 'Alpha Menu']);
    $theirs = Media::factory()->create(['store_id' => $storeB->id, 'title' => 'Beta Menu']);

    $ids = collect($this->actingAs($actor)->withSession(['current_store_id' => $storeA->id])
        ->getJson('/media/data')->assertOk()->json('media'))->pluck('id');

    expect($ids)->toContain($mine->id);
    expect($ids)->not->toContain($theirs->id);
});

test('media belongs to the store, not the uploader — a colleague in the same store sees it', function () {
    $store = Store::factory()->create();
    $uploader = createStoreUser($store, ['media-view', 'media-store'], 'Uploader Role');
    $colleague = createStoreUser($store, ['media-view'], 'Colleague Role');

    $file = Media::factory()->create(['store_id' => $store->id, 'created_by' => $uploader->id]);

    $ids = collect($this->actingAs($colleague)->withSession(['current_store_id' => $store->id])
        ->getJson('/media/data')->assertOk()->json('media'))->pluck('id');

    expect($ids)->toContain($file->id);
});

test('another store\'s media is unreachable, not just hidden', function () {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    $actor = createStoreUser($storeA, ['media-view', 'media-update', 'media-destroy']);
    $theirs = Media::factory()->create(['store_id' => $storeB->id, 'title' => 'Beta Menu']);

    // 404, never 403: from this store that file does not exist.
    $this->actingAs($actor)->withSession(['current_store_id' => $storeA->id])
        ->putJson("/media/{$theirs->id}", ['title' => 'Hacked'])
        ->assertNotFound();

    $this->actingAs($actor)->withSession(['current_store_id' => $storeA->id])
        ->deleteJson("/media/{$theirs->id}")
        ->assertNotFound();

    $this->assertDatabaseHas('media', ['id' => $theirs->id, 'title' => 'Beta Menu']);
});

test('with no store selected a store user cannot reach the library at all', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['media-view']);
    Media::factory()->create(['store_id' => $store->id]);

    // Permissions resolve against the CURRENT store's role, so with no store in
    // context the gate denies before the scope is ever consulted.
    $this->actingAs($actor)->getJson('/media/data')->assertForbidden();

    // The scope agrees: no store, nothing visible.
    expect(Media::visibleTo($actor)->count())->toBe(0);
});

test('a super admin sees the media of every store', function () {
    $admin = createSuperAdmin(['media-view']);
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    Media::factory()->create(['store_id' => $storeA->id]);
    Media::factory()->create(['store_id' => $storeB->id]);

    $ids = collect($this->actingAs($admin)->getJson('/media/data')->assertOk()->json('media'))->pluck('id');

    expect($ids)->toHaveCount(2);
});

test('a user with media-store can upload an image, stamped with their store', function () {
    Storage::fake('public');
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['media-store']);

    $response = $this->actingAs($actor)
        ->withSession(['current_store_id' => $store->id])
        ->post('/media', ['file' => UploadedFile::fake()->image('menu.jpg', 1920, 1080)]);

    $response->assertOk();
    $this->assertDatabaseHas('media', [
        'store_id' => $store->id,
        'created_by' => $actor->id,
        'title' => 'menu',
        'type' => Media::TYPE_IMAGE,
        'orientation' => 'landscape',
    ]);

    $media = Media::firstOrFail();
    Storage::disk('public')->assertExists($media->path);
    expect($media->thumbnail_path)->not->toBeNull();
    Storage::disk('public')->assertExists($media->thumbnail_path);
});

test('a portrait upload is recorded as portrait', function () {
    Storage::fake('public');
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['media-store']);

    $this->actingAs($actor)
        ->withSession(['current_store_id' => $store->id])
        ->post('/media', ['file' => UploadedFile::fake()->image('poster.jpg', 1080, 1920)])
        ->assertOk();

    expect(Media::firstOrFail()->orientation)->toBe('portrait');
});

test('above the stores, an upload with no shop chosen joins the platform\'s own library', function () {
    // docs/CHANNEL-CONTENT-SPEC.md: the platform keeps a library of its own (store_id NULL), which is
    // where its channels' files live.
    Storage::fake('public');
    $admin = createSuperAdmin(['media-store']);

    $this->actingAs($admin)
        ->postJson('/media', ['file' => UploadedFile::fake()->image('menu.jpg')])
        ->assertOk();

    $media = Media::firstOrFail();
    expect($media->store_id)->toBeNull();
    expect($media->path)->toStartWith('media/platform/');
    Storage::disk('public')->assertExists($media->path);
    $this->assertDatabaseHas('activity_logs', [
        'action' => 'media.uploaded', 'store_id' => null, 'description' => "Uploaded image menu to the platform's library",
    ]);
});

test('above the stores, an upload may go straight into a shop\'s library', function () {
    Storage::fake('public');
    $store = Store::factory()->create();
    $admin = createSuperAdmin(['media-store']);

    $this->actingAs($admin)
        ->postJson('/media', ['file' => UploadedFile::fake()->image('menu.jpg'), 'store_id' => $store->id])
        ->assertOk();

    $media = Media::firstOrFail();
    expect($media->store_id)->toBe($store->id);
    expect($media->path)->toStartWith("media/{$store->id}/");
    $this->assertDatabaseHas('activity_logs', ['action' => 'media.uploaded', 'store_id' => $store->id]);
});

test('an upload into a shop that no longer exists is refused, and nothing is kept', function (mixed $storeId, int $status) {
    Storage::fake('public');
    $admin = createSuperAdmin(['media-store']);

    $this->actingAs($admin)
        ->postJson('/media', ['file' => UploadedFile::fake()->image('menu.jpg'), 'store_id' => $storeId])
        ->assertStatus($status);

    expect(Media::count())->toBe(0);
    expect(Storage::disk('public')->allFiles())->toBe([]);
})->with([
    'a deleted shop' => [999999, 422],
    'no id at all' => [0, 422],
    'a word' => ['alpha', 422],
    'a list' => [[1], 422],
]);

test('a store member with no store selected cannot upload at all', function () {
    // Their permissions are read against the store they work in; with none chosen they hold none — and a
    // shop's person has no library of their own to fall back on.
    Storage::fake('public');
    $store = Store::factory()->create();
    $member = createStoreUser($store, ['media-store']);

    $this->actingAs($member)
        ->postJson('/media', ['file' => UploadedFile::fake()->image('menu.jpg')])
        ->assertForbidden();

    expect(Media::count())->toBe(0);
});

test('a file type the player cannot render is rejected', function () {
    Storage::fake('public');
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['media-store']);

    $this->actingAs($actor)
        ->withSession(['current_store_id' => $store->id])
        ->postJson('/media', ['file' => UploadedFile::fake()->create('prices.pdf', 100, 'application/pdf')])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file']);

    expect(Media::count())->toBe(0);
});

test('audio is not signage: an mp3 is rejected', function () {
    Storage::fake('public');
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['media-store']);

    // A screen has no sound, so audio has nothing to show. Rejected at the door
    // rather than sitting silently in a library forever.
    $this->actingAs($actor)
        ->withSession(['current_store_id' => $store->id])
        ->postJson('/media', ['file' => UploadedFile::fake()->create('jingle.mp3', 500, 'audio/mpeg')])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file']);

    expect(Media::count())->toBe(0);
});

test('the formats a player can actually render are accepted', function () {
    Storage::fake('public');
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['media-store']);

    foreach (['menu.jpg', 'menu.jpeg', 'menu.png', 'menu.gif', 'menu.webp'] as $name) {
        $this->actingAs($actor)
            ->withSession(['current_store_id' => $store->id])
            ->post('/media', ['file' => UploadedFile::fake()->image($name)])
            ->assertOk();
        $this->flushSession();
    }

    // Both video formats too, so the list in ALLOWED_MIMES is covered end to end
    // and not just its image half.
    foreach ([['clip.mp4', 'video/mp4'], ['clip.webm', 'video/webm']] as [$name, $mime]) {
        $this->actingAs($actor)
            ->withSession(['current_store_id' => $store->id])
            ->post('/media', ['file' => UploadedFile::fake()->create($name, 2048, $mime)])
            ->assertOk();
        $this->flushSession();
    }

    expect(Media::count())->toBe(7);
    expect(Media::where('type', Media::TYPE_IMAGE)->count())->toBe(5);
    expect(Media::where('type', Media::TYPE_VIDEO)->count())->toBe(2);
});

test('a user without media-store cannot upload', function () {
    Storage::fake('public');
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['media-view']);

    $this->actingAs($actor)
        ->withSession(['current_store_id' => $store->id])
        ->postJson('/media', ['file' => UploadedFile::fake()->image('menu.jpg')])
        ->assertForbidden();
});

test('a user with media-update can rename a file and set its schedule', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['media-update']);
    $media = Media::factory()->create(['store_id' => $store->id]);

    $this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->putJson("/media/{$media->id}", [
            'title' => 'Breakfast Menu',
            'description' => 'Shown until 11am',
            'starts_at' => '2026-10-01T06:00:00Z',
            'expires_at' => '2026-10-31T11:00:00Z',
        ])->assertOk();

    $media->refresh();
    expect($media->title)->toBe('Breakfast Menu');
    expect($media->description)->toBe('Shown until 11am');
    expect($media->starts_at)->not->toBeNull();
    expect($media->expires_at)->not->toBeNull();
});

test('an expiry before the start date is rejected', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['media-update']);
    $media = Media::factory()->create(['store_id' => $store->id]);

    $this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->putJson("/media/{$media->id}", [
            'title' => 'Breakfast Menu',
            'starts_at' => '2026-10-31T06:00:00Z',
            'expires_at' => '2026-10-01T06:00:00Z',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['expires_at']);
});

test('a user with media-destroy deletes the row and the files on disk', function () {
    Storage::fake('public');
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['media-store', 'media-destroy']);

    $this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->post('/media', ['file' => UploadedFile::fake()->image('menu.jpg')])->assertOk();

    $media = Media::firstOrFail();

    $this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->deleteJson("/media/{$media->id}")->assertOk();

    $this->assertDatabaseMissing('media', ['id' => $media->id]);
    Storage::disk('public')->assertMissing($media->path);
    Storage::disk('public')->assertMissing($media->thumbnail_path);
});

test('a file a channel shows is not deleted until it is taken out of the channel, which the refusal names', function () {
    // docs/CHANNEL-CONTENT-SPEC.md, owner 2026-09-19: "pehle channel se hatao". A playlist line is not a
    // reason to refuse — deleting a file still takes it off the playlists, as before.
    Storage::fake('public');
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['media-view', 'media-destroy']);
    $media = Media::factory()->create(['store_id' => $store->id]);
    Storage::disk('public')->put($media->path, 'image');
    $channel = Channel::factory()->create(['store_id' => $store->id, 'name' => 'Weekly Deals']);
    $ad = ChannelAd::factory()->create(['channel_id' => $channel->id, 'media_id' => $media->id]);

    $this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->deleteJson("/media/{$media->id}")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'Still used by the channel Weekly Deals. Take it out of that channel first.']);

    expect(Media::find($media->id))->not->toBeNull()
        ->and(ChannelAd::find($ad->id))->not->toBeNull();
    Storage::disk('public')->assertExists($media->path);

    // Out of the channel, it deletes like any file.
    $ad->delete();
    $this->deleteJson("/media/{$media->id}")->assertOk();

    expect(Media::find($media->id))->toBeNull();
    Storage::disk('public')->assertMissing($media->path);
});

test('the listing carries the refusal with each file a channel shows, so the page says it before any confirmation', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['media-view']);
    [$held, $free] = Media::factory()->count(2)->create(['store_id' => $store->id]);
    $channel = Channel::factory()->create(['store_id' => $store->id, 'name' => 'Weekly Deals']);
    ChannelAd::factory()->count(2)->create(['channel_id' => $channel->id, 'media_id' => $held->id]);

    $rows = collect($this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->getJson('/media/data')->assertOk()->json('media'))->keyBy('id');

    // The same words the delete itself would answer with — one channel, however many of its ads show the file.
    expect($rows[$held->id]['in_channels_message'])->toBe('Still used by the channel Weekly Deals. Take it out of that channel first.')
        ->and($rows[$held->id]['in_channels_message'])->toBe($held->stillInAChannelMessage())
        ->and($rows[$free->id]['in_channels_message'])->toBeNull();
});

test('the library chooser is offered above the stores, and nothing of the sort inside a store', function () {
    $alpha = Store::factory()->create(['name' => 'Alpha Mart']);

    $this->actingAs(createSuperAdmin(['media-view', 'media-store']))->get('/media')->assertOk()
        ->assertSee('dusk="media-filter-library"', false)
        ->assertSee('<option value="platform">Platform library</option>', false)
        ->assertSee('<option value="'.$alpha->id.'">Alpha Mart</option>', false);

    $this->actingAs(createStoreUser($alpha, ['media-view', 'media-store']))->withSession(['current_store_id' => $alpha->id])
        ->get('/media')->assertOk()
        ->assertDontSee('dusk="media-filter-library"', false)
        ->assertDontSee('Platform library');
});

test('the refusal names at most three channels, in order, and counts the rest', function () {
    $media = Media::factory()->platformOwned()->create();
    foreach (['Echo', 'Alpha', 'Delta', 'Bravo', 'Charlie'] as $name) {
        ChannelAd::factory()->create(['channel_id' => Channel::factory()->create(['name' => $name])->id, 'media_id' => $media->id]);
    }
    // The same channel twice is still one channel.
    ChannelAd::factory()->create(['channel_id' => Channel::firstWhere('name', 'Alpha')->id, 'media_id' => $media->id]);

    expect($media->stillInAChannelMessage())
        ->toBe('Still used by the channels Alpha, Bravo, Charlie and 2 more. Take it out of those channels first.')
        ->and(Media::factory()->create()->stillInAChannelMessage())->toBeNull();
});

test('above the stores the page reads one library at a time — the platform\'s, or a shop\'s — or all of them', function () {
    $alpha = Store::factory()->create(['name' => 'Alpha Mart']);
    $beta = Store::factory()->create(['name' => 'Beta Deli']);
    Media::factory()->platformOwned()->create(['title' => 'Platform promo']);
    Media::factory()->create(['store_id' => $alpha->id, 'title' => 'Alpha poster']);
    Media::factory()->create(['store_id' => $beta->id, 'title' => 'Beta poster']);
    $admin = createSuperAdmin(['media-view']);

    $titles = fn (string $query = '') => collect($this->actingAs($admin)->getJson("/media/data{$query}")->assertOk()->json('media'))
        ->pluck('title')->sort()->values()->all();

    expect($titles('?library=platform'))->toBe(['Platform promo'])
        ->and($titles("?library={$alpha->id}"))->toBe(['Alpha poster'])
        ->and($titles())->toBe(['Alpha poster', 'Beta poster', 'Platform promo']);

    // Each row says whose library it is in.
    $rows = collect($this->getJson('/media/data')->json('media'))->keyBy('title');
    expect($rows['Platform promo']['store'])->toBeNull()
        ->and($rows['Alpha poster']['store']['name'])->toBe('Alpha Mart');
});

test('a user without media-destroy cannot delete a file', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['media-view']);
    $media = Media::factory()->create(['store_id' => $store->id]);

    $this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->deleteJson("/media/{$media->id}")->assertForbidden();

    $this->assertDatabaseHas('media', ['id' => $media->id]);
});

test('the listing can be filtered by type and orientation', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['media-view']);

    $image = Media::factory()->create(['store_id' => $store->id]);
    $video = Media::factory()->video()->create(['store_id' => $store->id]);
    $portrait = Media::factory()->create(['store_id' => $store->id, 'orientation' => 'portrait']);

    $videoIds = collect($this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->getJson('/media/data?type=video')->assertOk()->json('media'))->pluck('id');
    expect($videoIds->all())->toBe([$video->id]);

    $this->flushSession();
    $portraitIds = collect($this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->getJson('/media/data?orientation=portrait')->assertOk()->json('media'))->pluck('id');
    expect($portraitIds->all())->toBe([$portrait->id]);
    expect($portraitIds)->not->toContain($image->id);
});

test('deleting the uploader keeps the store\'s media — the file belongs to the store', function () {
    $admin = createSuperAdmin(['user-view', 'user-destroy']);
    $store = Store::factory()->create();
    $uploader = createStoreUser($store, ['media-store']);
    $media = Media::factory()->create(['store_id' => $store->id, 'created_by' => $uploader->id]);

    $this->actingAs($admin)->deleteJson("/users/{$uploader->id}", ['password' => 'password'])->assertOk();

    // The person is gone; the shop's menu is not.
    $this->assertDatabaseMissing('users', ['id' => $uploader->id]);
    $this->assertDatabaseHas('media', ['id' => $media->id, 'created_by' => null]);
});

test('the listing can be narrowed to the ad pages the Ad Builder published', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['media-view']);

    Media::factory()->create(['store_id' => $store->id]);
    $page = Media::factory()->create(['store_id' => $store->id, 'type' => Media::TYPE_HTML, 'mime_type' => 'text/html']);

    $ids = collect($this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->getJson('/media/data?type=html')->assertOk()->json('media'))->pluck('id');

    expect($ids->all())->toBe([$page->id]);

    // Any other word is still refused, as it always was.
    $this->getJson('/media/data?type=audio')->assertStatus(422);
});
