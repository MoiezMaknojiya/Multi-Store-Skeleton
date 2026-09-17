<?php

use App\Models\Media;
use App\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('guests cannot access any media endpoint', function () {
    $this->getJson('/media/data')->assertUnauthorized();
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

test('uploading without a store selected is refused with a helpful message', function () {
    Storage::fake('public');
    $admin = createSuperAdmin(['media-store']);

    $this->actingAs($admin)
        ->postJson('/media', ['file' => UploadedFile::fake()->image('menu.jpg')])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file']);

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

    foreach (['menu.jpg', 'menu.jpeg', 'menu.png', 'menu.gif'] as $name) {
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

    expect(Media::count())->toBe(6);
    expect(Media::where('type', Media::TYPE_IMAGE)->count())->toBe(4);
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
