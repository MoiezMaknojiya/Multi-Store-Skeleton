<?php

use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\Screen;
use App\Models\Store;

/*
|--------------------------------------------------------------------------
| Two people editing one playlist
|--------------------------------------------------------------------------
|
| A save replaces the WHOLE list rather than patching it, which is what keeps a
| half-finished rearrangement off a TV. The cost is that a client working from a
| stale copy would erase whatever changed since it loaded — a colleague adds a
| poster, you retime one item, and their poster is gone with nothing to show for
| it.
|
| So the panel is handed a fingerprint of the playlist when it loads and sends it
| back when it saves. Nothing polls; the fingerprint simply travels with the save
| that was already happening.
|
*/

test('a save built on a stale copy is refused instead of wiping the other person\'s work', function () {
    $store = Store::factory()->create();
    $ali = createStoreUser($store, ['screen-view', 'screen-playlist'], 'Ali Role');
    $sana = createStoreUser($store, ['screen-view', 'screen-playlist'], 'Sana Role');
    $screen = Screen::factory()->create(['store_id' => $store->id]);

    $poster = Media::factory()->create(['store_id' => $store->id, 'title' => 'Poster']);
    $menu = Media::factory()->create(['store_id' => $store->id, 'title' => 'Menu']);
    $eid = Media::factory()->create(['store_id' => $store->id, 'title' => 'Eid Offer']);

    foreach ([[$poster, 0], [$menu, 1]] as [$media, $position]) {
        PlaylistItem::create([
            'screen_id' => $screen->id, 'media_id' => $media->id,
            'position' => $position, 'duration_seconds' => 10,
        ]);
    }

    // Both open the page and are handed the same version of the same two items.
    $versionBothSaw = $screen->playlistFingerprint();

    // Ali adds a third file and saves.
    $this->actingAs($ali)->withSession(['current_store_id' => $store->id])
        ->putJson("/screens/{$screen->id}/playlist", [
            'version' => $versionBothSaw,
            'items' => [
                ['media_id' => $poster->id, 'duration_seconds' => 10],
                ['media_id' => $menu->id, 'duration_seconds' => 10],
                ['media_id' => $eid->id, 'duration_seconds' => 10],
            ],
        ])->assertOk();

    $this->flushSession();

    // Sana's page still shows the old two. She only retimes one — but her save
    // carries the whole list as she knows it, which is how Ali's file would go.
    $response = $this->actingAs($sana)->withSession(['current_store_id' => $store->id])
        ->putJson("/screens/{$screen->id}/playlist", [
            'version' => $versionBothSaw,
            'items' => [
                ['media_id' => $poster->id, 'duration_seconds' => 10],
                ['media_id' => $menu->id, 'duration_seconds' => 20],
            ],
        ])->assertStatus(409);

    expect($response->json('message'))->toContain('Someone else changed this playlist');

    // Ali's work is still there, and Sana's retime did not land.
    $saved = PlaylistItem::where('screen_id', $screen->id)->orderBy('position')->get();
    expect($saved->pluck('media_id')->all())->toBe([$poster->id, $menu->id, $eid->id]);
    expect($saved->pluck('duration_seconds')->all())->toBe([10, 10, 10]);
});

test('reloading after a conflict lets the same person save for real', function () {
    $store = Store::factory()->create();
    $sana = createStoreUser($store, ['screen-view', 'screen-playlist']);
    $screen = Screen::factory()->create(['store_id' => $store->id]);
    $media = Media::factory()->create(['store_id' => $store->id]);

    PlaylistItem::create([
        'screen_id' => $screen->id, 'media_id' => $media->id,
        'position' => 0, 'duration_seconds' => 10,
    ]);

    $this->actingAs($sana)->withSession(['current_store_id' => $store->id])
        ->putJson("/screens/{$screen->id}/playlist", [
            'version' => 'stale-and-wrong',
            'items' => [['media_id' => $media->id, 'duration_seconds' => 25]],
        ])->assertStatus(409);

    $this->flushSession();

    // Reload — the GET hands over the current version — and the same edit lands.
    $current = $this->actingAs($sana)->withSession(['current_store_id' => $store->id])
        ->getJson("/screens/{$screen->id}/playlist")->assertOk()->json('version');

    $this->flushSession();

    $this->actingAs($sana)->withSession(['current_store_id' => $store->id])
        ->putJson("/screens/{$screen->id}/playlist", [
            'version' => $current,
            'items' => [['media_id' => $media->id, 'duration_seconds' => 25]],
        ])->assertOk();

    expect(PlaylistItem::where('screen_id', $screen->id)->value('duration_seconds'))->toBe(25);
});

test('saving twice from one page works — the save hands back a fresh version', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['screen-view', 'screen-playlist']);
    $screen = Screen::factory()->create(['store_id' => $store->id]);
    $media = Media::factory()->create(['store_id' => $store->id]);

    // Without the new version travelling back in the response, a page would
    // collide with its OWN previous save the second time Save was pressed.
    $version = $screen->playlistFingerprint();

    $next = $this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->putJson("/screens/{$screen->id}/playlist", [
            'version' => $version,
            'items' => [['media_id' => $media->id, 'duration_seconds' => 10]],
        ])->assertOk()->json('version');

    expect($next)->not->toBe($version);

    $this->flushSession();

    $this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->putJson("/screens/{$screen->id}/playlist", [
            'version' => $next,
            'items' => [['media_id' => $media->id, 'duration_seconds' => 30]],
        ])->assertOk();

    expect(PlaylistItem::where('screen_id', $screen->id)->value('duration_seconds'))->toBe(30);
});

test('an identical save is not a conflict — nothing was lost', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['screen-view', 'screen-playlist']);
    $screen = Screen::factory()->create(['store_id' => $store->id]);
    $media = Media::factory()->create(['store_id' => $store->id]);

    PlaylistItem::create([
        'screen_id' => $screen->id, 'media_id' => $media->id,
        'position' => 0, 'duration_seconds' => 10,
    ]);

    // Somebody else saved the very same list in between. The content did not
    // change, so there is nothing to warn anyone about.
    $this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->putJson("/screens/{$screen->id}/playlist", [
            'version' => $screen->playlistFingerprint(),
            'items' => [['media_id' => $media->id, 'duration_seconds' => 10]],
        ])->assertOk();
});

test('the version the API reports is the one the model computes', function () {
    // Pins the two together: the panel reads the version from the API and the
    // tests read it from the model. If the formula ever moves, this fails loudly
    // instead of both sides quietly agreeing with a different answer.
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['screen-view']);
    $screen = Screen::factory()->create(['store_id' => $store->id]);
    $media = Media::factory()->create(['store_id' => $store->id]);

    PlaylistItem::create([
        'screen_id' => $screen->id, 'media_id' => $media->id,
        'position' => 0, 'duration_seconds' => 15,
    ]);

    $fromApi = $this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->getJson("/screens/{$screen->id}/playlist")->assertOk()->json('version');

    expect($fromApi)->toBe($screen->playlistFingerprint());
});

test('a version is required — a client cannot opt out of the check', function () {
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['screen-view', 'screen-playlist']);
    $screen = Screen::factory()->create(['store_id' => $store->id]);
    $media = Media::factory()->create(['store_id' => $store->id]);

    // Otherwise the protection is decoration: anyone could omit the field and go
    // back to overwriting whatever was there.
    $this->actingAs($actor)->withSession(['current_store_id' => $store->id])
        ->putJson("/screens/{$screen->id}/playlist", [
            'items' => [['media_id' => $media->id, 'duration_seconds' => 10]],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['version']);
});
