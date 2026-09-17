<?php

use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\Screen;
use App\Models\Store;

/** A screen with a playlist of the given media, in order. */
function playlistOf(Screen $screen, array $media, int $seconds = 10): void
{
    foreach (array_values($media) as $position => $item) {
        PlaylistItem::create([
            'screen_id' => $screen->id,
            'media_id' => $item->id,
            'position' => $position,
            'duration_seconds' => $seconds,
        ]);
    }
}

test('guests cannot read or write a playlist', function () {
    $screen = Screen::factory()->create(['store_id' => Store::factory()]);

    $this->getJson("/screens/{$screen->id}/playlist")->assertUnauthorized();
    $this->putJson("/screens/{$screen->id}/playlist", ['items' => []])->assertUnauthorized();
});

test('the playlist page and its data are readable with screen-view', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['screen-view']);
    $screen = Screen::factory()->create(['store_id' => $store->id]);
    $media = Media::factory()->create(['store_id' => $store->id, 'title' => 'Breakfast Board']);
    playlistOf($screen, [$media]);

    $this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->get("/screens/{$screen->id}")->assertOk();

    $items = $this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->getJson("/screens/{$screen->id}/playlist")->assertOk()->json('items');

    expect($items)->toHaveCount(1);
    expect($items[0]['title'])->toBe('Breakfast Board');
    expect($items[0]['duration_seconds'])->toBe(10);
});

test('a screen in another store is unreachable', function () {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    $actor = createStoreUser($storeA, ['screen-view', 'screen-playlist']);
    $theirs = Screen::factory()->create(['store_id' => $storeB->id]);

    $this->actingAs($actor)->withSession(['current_store_id' => $storeA->id])
        ->get("/screens/{$theirs->id}")->assertNotFound();

    $this->actingAs($actor)->withSession(['current_store_id' => $storeA->id])
        ->putJson("/screens/{$theirs->id}/playlist", ['items' => []])->assertNotFound();
});

test('saving a playlist writes the order exactly as sent', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['screen-playlist']);
    $screen = Screen::factory()->create(['store_id' => $store->id]);

    $first = Media::factory()->create(['store_id' => $store->id, 'title' => 'One']);
    $second = Media::factory()->create(['store_id' => $store->id, 'title' => 'Two']);

    $this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->putJson("/screens/{$screen->id}/playlist", [
            'version' => $screen->playlistFingerprint(),
            'items' => [
                ['media_id' => $second->id, 'duration_seconds' => 15],
                ['media_id' => $first->id, 'duration_seconds' => 8],
            ],
        ])->assertOk();

    $items = $screen->playlistItems()->get();
    expect($items->pluck('media_id')->all())->toBe([$second->id, $first->id]);
    expect($items->pluck('position')->all())->toBe([0, 1]);
    expect($items->pluck('duration_seconds')->all())->toBe([15, 8]);
});

test('saving replaces the playlist rather than appending to it', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['screen-playlist']);
    $screen = Screen::factory()->create(['store_id' => $store->id]);
    $media = Media::factory()->count(3)->create(['store_id' => $store->id]);
    playlistOf($screen, $media->all());

    $this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->putJson("/screens/{$screen->id}/playlist", [
            'version' => $screen->playlistFingerprint(),
            'items' => [['media_id' => $media[0]->id, 'duration_seconds' => 12]],
        ])->assertOk();

    expect($screen->playlistItems()->count())->toBe(1);
});

test('an empty playlist is a valid save — that is how you clear a screen', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['screen-playlist']);
    $screen = Screen::factory()->create(['store_id' => $store->id]);
    playlistOf($screen, Media::factory()->count(2)->create(['store_id' => $store->id])->all());

    $this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->putJson("/screens/{$screen->id}/playlist", ['items' => [], 'version' => $screen->playlistFingerprint()])->assertOk();

    expect($screen->playlistItems()->count())->toBe(0);
});

test('a screen cannot be made to play another store\'s file', function () {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    $actor = createStoreUser($storeA, ['screen-playlist']);
    $screen = Screen::factory()->create(['store_id' => $storeA->id]);
    $theirFile = Media::factory()->create(['store_id' => $storeB->id]);

    $this->actingAs($actor)->withSession(['current_store_id' => $storeA->id])
        ->putJson("/screens/{$screen->id}/playlist", [
            'version' => $screen->playlistFingerprint(),
            'items' => [['media_id' => $theirFile->id, 'duration_seconds' => 10]],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items']);

    expect($screen->playlistItems()->count())->toBe(0);
});

test('a duration must be a sane number of seconds', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['screen-playlist']);
    $screen = Screen::factory()->create(['store_id' => $store->id]);
    $media = Media::factory()->create(['store_id' => $store->id]);

    $this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->putJson("/screens/{$screen->id}/playlist", [
            'version' => $screen->playlistFingerprint(),
            'items' => [['media_id' => $media->id, 'duration_seconds' => 0]],
        ])->assertStatus(422)->assertJsonValidationErrors(['items.0.duration_seconds']);
});

test('changing a playlist needs screen-playlist, not just screen-view', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['screen-view']);
    $screen = Screen::factory()->create(['store_id' => $store->id]);

    $this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->putJson("/screens/{$screen->id}/playlist", ['items' => []])->assertForbidden();
});

test('the picker is self-sufficient: screen-playlist alone lists the library', function () {
    $store = Store::factory()->create();
    // Deliberately NO media-view — a permission must be enough for its own job.
    $actor = createStoreUser($store, ['screen-playlist']);
    $screen = Screen::factory()->create(['store_id' => $store->id]);
    Media::factory()->create(['store_id' => $store->id, 'title' => 'Breakfast Board']);
    Media::factory()->create(['store_id' => Store::factory(), 'title' => 'Someone Else']);

    $media = $this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->getJson("/screens/{$screen->id}/available-media")->assertOk()->json('media');

    expect(collect($media)->pluck('title')->all())->toBe(['Breakfast Board']);
});

test('the picker can be searched', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['screen-playlist']);
    $screen = Screen::factory()->create(['store_id' => $store->id]);
    Media::factory()->create(['store_id' => $store->id, 'title' => 'Breakfast Board']);
    Media::factory()->create(['store_id' => $store->id, 'title' => 'Lunch Poster']);

    $media = $this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->getJson("/screens/{$screen->id}/available-media?search=Lunch")->assertOk()->json('media');

    expect(collect($media)->pluck('title')->all())->toBe(['Lunch Poster']);
});

test('deleting a file takes it off every screen it was playing on', function () {
    $store = Store::factory()->create();
    $screen = Screen::factory()->create(['store_id' => $store->id]);
    $media = Media::factory()->create(['store_id' => $store->id]);
    playlistOf($screen, [$media]);

    $media->delete();

    expect($screen->playlistItems()->count())->toBe(0);
});

/* ── What the device actually receives ─────────────────────────────────── */

test('the manifest carries the playlist in order, with a cache key per item', function () {
    $store = Store::factory()->create();
    $screen = Screen::factory()->withToken('tok')->create(['store_id' => $store->id]);
    $image = Media::factory()->create(['store_id' => $store->id, 'title' => 'Poster']);
    $video = Media::factory()->video()->create(['store_id' => $store->id, 'title' => 'Clip']);
    playlistOf($screen, [$image, $video], 12);

    $response = $this->withHeader('Authorization', 'Bearer tok')
        ->getJson('/device/playlist')->assertOk();

    $items = $response->json('items');
    expect($items)->toHaveCount(2);
    expect($items[0]['type'])->toBe('image');
    expect($items[1]['type'])->toBe('video');
    expect($items[0]['duration'])->toBe(12);
    expect($items[0]['url'])->toContain($image->path);
    expect($items[0]['checksum'])->toBeString()->not->toBeEmpty();
    expect($items[0]['checksum'])->not->toBe($items[1]['checksum']);
});

test('a file outside its schedule window never reaches the TV', function () {
    $store = Store::factory()->create();
    $screen = Screen::factory()->withToken('tok')->create(['store_id' => $store->id]);

    $live = Media::factory()->create(['store_id' => $store->id, 'title' => 'Live']);
    $expired = Media::factory()->expired()->create(['store_id' => $store->id, 'title' => 'Expired']);
    $future = Media::factory()->create(['store_id' => $store->id, 'starts_at' => now()->addWeek()]);
    playlistOf($screen, [$live, $expired, $future]);

    $items = $this->withHeader('Authorization', 'Bearer tok')
        ->getJson('/device/playlist')->assertOk()->json('items');

    // The panel still shows all three; the device is only handed what may play now.
    expect($screen->playlistItems()->count())->toBe(3);
    expect($items)->toHaveCount(1);
});

test('the manifest version changes when the playlist changes, and not otherwise', function () {
    $store = Store::factory()->create();
    $screen = Screen::factory()->withToken('tok')->create(['store_id' => $store->id]);
    $media = Media::factory()->create(['store_id' => $store->id]);

    $before = $this->withHeader('Authorization', 'Bearer tok')->getJson('/device/playlist')->json('version');
    expect($this->withHeader('Authorization', 'Bearer tok')->getJson('/device/playlist')->json('version'))->toBe($before);

    playlistOf($screen, [$media]);

    $after = $this->withHeader('Authorization', 'Bearer tok')->getJson('/device/playlist')->json('version');
    expect($after)->not->toBe($before);
});

test('the manifest carries the server clock so a wrong TV clock cannot matter', function () {
    Screen::factory()->withToken('tok')->create(['store_id' => Store::factory()]);

    $serverTime = $this->withHeader('Authorization', 'Bearer tok')
        ->getJson('/device/playlist')->assertOk()->json('server_time');

    expect(now()->diffInSeconds($serverTime))->toBeLessThan(5);
});

test('a screen only ever receives its own playlist', function () {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    $mine = Screen::factory()->withToken('mine')->create(['store_id' => $storeA->id]);
    $theirs = Screen::factory()->withToken('theirs')->create(['store_id' => $storeB->id]);

    playlistOf($mine, [Media::factory()->create(['store_id' => $storeA->id])]);
    playlistOf($theirs, Media::factory()->count(2)->create(['store_id' => $storeB->id])->all());

    expect($this->withHeader('Authorization', 'Bearer mine')->getJson('/device/playlist')->json('items'))->toHaveCount(1);
    expect($this->withHeader('Authorization', 'Bearer theirs')->getJson('/device/playlist')->json('items'))->toHaveCount(2);
});

/* ── Mixed playlists: images and videos together ───────────────────────── */

test('a mixed playlist reaches the device in order, with each type intact', function () {
    $store = Store::factory()->create();
    $screen = Screen::factory()->withToken('tok')->create(['store_id' => $store->id]);

    $poster = Media::factory()->create(['store_id' => $store->id, 'title' => 'Poster']);
    $clip = Media::factory()->video()->create([
        'store_id' => $store->id, 'title' => 'Clip', 'duration_seconds' => 30, 'mime_type' => 'video/mp4',
    ]);
    $second = Media::factory()->create(['store_id' => $store->id, 'title' => 'Second Poster']);
    $webm = Media::factory()->video()->create([
        'store_id' => $store->id, 'title' => 'Webm Clip', 'duration_seconds' => 12, 'mime_type' => 'video/webm',
    ]);

    // Deliberately interleaved: image, video, image, video.
    foreach ([[$poster, 8], [$clip, 30], [$second, 15], [$webm, 12]] as $position => [$media, $seconds]) {
        PlaylistItem::create([
            'screen_id' => $screen->id,
            'media_id' => $media->id,
            'position' => $position,
            'duration_seconds' => $seconds,
        ]);
    }

    $items = $this->withHeader('Authorization', 'Bearer tok')
        ->getJson('/device/playlist')->assertOk()->json('items');

    expect($items)->toHaveCount(4);
    expect(collect($items)->pluck('type')->all())->toBe(['image', 'video', 'image', 'video']);
    expect(collect($items)->pluck('duration')->all())->toBe([8, 30, 15, 12]);
    expect(collect($items)->pluck('mime')->all())->toBe(['image/jpeg', 'video/mp4', 'image/jpeg', 'video/webm']);

    // Every item is individually addressable and cacheable.
    expect(collect($items)->pluck('checksum')->unique())->toHaveCount(4);
    expect(collect($items)->pluck('url')->unique())->toHaveCount(4);
});

test('a long playlist keeps its exact order', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['screen-playlist']);
    $screen = Screen::factory()->withToken('tok')->create(['store_id' => $store->id]);

    // Twelve files, alternating type, saved in one call.
    $media = collect(range(1, 12))->map(fn ($n) => $n % 2 === 0
        ? Media::factory()->video()->create(['store_id' => $store->id, 'title' => "Clip {$n}"])
        : Media::factory()->create(['store_id' => $store->id, 'title' => "Poster {$n}"]));

    $this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->putJson("/screens/{$screen->id}/playlist", [
            'version' => $screen->playlistFingerprint(),
            'items' => $media->map(fn ($m) => ['media_id' => $m->id, 'duration_seconds' => 7])->all(),
        ])->assertOk();

    expect($screen->playlistItems()->pluck('media_id')->all())->toBe($media->pluck('id')->all());
    expect($screen->playlistItems()->pluck('position')->all())->toBe(range(0, 11));

    $this->flushSession();
    $items = $this->withHeader('Authorization', 'Bearer tok')->getJson('/device/playlist')->json('items');
    expect(collect($items)->pluck('type')->all())
        ->toBe(['image', 'video', 'image', 'video', 'image', 'video', 'image', 'video', 'image', 'video', 'image', 'video']);
});

test('the same file can appear more than once in a playlist, at different lengths', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['screen-playlist']);
    $screen = Screen::factory()->withToken('tok')->create(['store_id' => $store->id]);
    $logo = Media::factory()->create(['store_id' => $store->id, 'title' => 'Logo']);
    $offer = Media::factory()->create(['store_id' => $store->id, 'title' => 'Offer']);

    // A shop that shows its logo between every promo.
    $this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->putJson("/screens/{$screen->id}/playlist", [
            'version' => $screen->playlistFingerprint(),
            'items' => [
                ['media_id' => $logo->id, 'duration_seconds' => 3],
                ['media_id' => $offer->id, 'duration_seconds' => 20],
                ['media_id' => $logo->id, 'duration_seconds' => 5],
            ],
        ])->assertOk();

    expect($screen->playlistItems()->count())->toBe(3);
    expect($screen->playlistItems()->pluck('duration_seconds')->all())->toBe([3, 20, 5]);

    $this->flushSession();
    $items = $this->withHeader('Authorization', 'Bearer tok')->getJson('/device/playlist')->json('items');
    // Same file twice: identical url and checksum, different durations and ids.
    expect($items[0]['url'])->toBe($items[2]['url']);
    expect($items[0]['checksum'])->toBe($items[2]['checksum']);
    expect($items[0]['id'])->not->toBe($items[2]['id']);
});

test('an expired video drops out while the images around it keep playing', function () {
    $store = Store::factory()->create();
    $screen = Screen::factory()->withToken('tok')->create(['store_id' => $store->id]);

    $before = Media::factory()->create(['store_id' => $store->id, 'title' => 'Before']);
    $deadClip = Media::factory()->video()->expired()->create(['store_id' => $store->id, 'title' => 'Old Promo']);
    $after = Media::factory()->create(['store_id' => $store->id, 'title' => 'After']);
    playlistOf($screen, [$before, $deadClip, $after]);

    $items = $this->withHeader('Authorization', 'Bearer tok')->getJson('/device/playlist')->json('items');

    expect($items)->toHaveCount(2);
    expect(collect($items)->pluck('type')->all())->toBe(['image', 'image']);
    // The panel still shows all three, so the owner can see what expired.
    expect($screen->playlistItems()->count())->toBe(3);
});

test('a playlist longer than the cap is refused whole', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['screen-playlist']);
    $screen = Screen::factory()->create(['store_id' => $store->id]);
    $media = Media::factory()->create(['store_id' => $store->id]);

    $this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->putJson("/screens/{$screen->id}/playlist", [
            'version' => $screen->playlistFingerprint(),
            'items' => array_fill(0, 201, ['media_id' => $media->id, 'duration_seconds' => 5]),
        ])->assertStatus(422)->assertJsonValidationErrors(['items']);

    expect($screen->playlistItems()->count())->toBe(0);
});
