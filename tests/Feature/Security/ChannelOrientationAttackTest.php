<?php

use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Screen;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Breaking a channel's shape
|--------------------------------------------------------------------------
|
| A channel is landscape or portrait, fixed when it is made (owner, 2026-10-22). Shapes nobody offers, an edit that tries to turn a
| channel round, a copy that would carry a channel to a screen of the other shape among screens of its own — each refused in
| words, never a 500, and nothing written.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->actingAs(createSuperAdmin());
});

test('shapes nobody offers are refused in words, never a 500, and make no channel', function () {
    $shapes = ['landscаpe' /* a Cyrillic а */, 'Landscape', ' landscape', 'portrait ', ['portrait'], ['a' => 'landscape'], 0, -1, true, 'portrait_flipped', str_repeat('p', 5000)];

    foreach ($shapes as $shape) {
        $response = $this->postJson('/channels', ['name' => 'Bent '.md5(json_encode($shape)), 'orientation' => $shape]);

        // A space around a shape is trimmed away by the app, as every field is: that one is a real shape, and goes in.
        if (is_string($shape) && in_array(trim($shape), ['landscape', 'portrait'], true)) {
            $response->assertOk();

            continue;
        }

        $response->assertStatus(422)->assertJsonValidationErrors(['orientation' => 'Choose Landscape or Portrait.']);
    }

    expect(Channel::whereNotIn('orientation', ['landscape', 'portrait'])->exists())->toBeFalse();
});

test('an edit never turns a channel round, however the shape is posted', function () {
    $channel = Channel::factory()->create(['organization_id' => $this->alpha->id, 'name' => 'GAMA']);
    ChannelAd::factory()->create(['channel_id' => $channel->id, 'media_id' => Media::factory()->create(['organization_id' => $this->alpha->id])->id]);

    foreach (['portrait', ['portrait'], 'PORTRAIT'] as $shape) {
        $this->putJson("/channels/{$channel->id}", ['name' => 'GAMA', 'orientation' => $shape, 'is_active' => true])->assertOk();
    }
    $this->patchJson("/channels/{$channel->id}", ['orientation' => 'portrait'])->assertStatus(405);

    expect($channel->fresh()->orientation)->toBe('landscape');
});

test('a copy to several screens, one of the other shape, writes to none of them', function () {
    $wide = Channel::factory()->create(['organization_id' => $this->alpha->id, 'name' => 'GAMA']);
    $source = Screen::factory()->create(['organization_id' => $this->alpha->id, 'name' => 'Tv1']);
    $fits = Screen::factory()->create(['organization_id' => $this->alpha->id, 'name' => 'Tv2', 'orientation' => 'landscape_flipped']);
    $upright = Screen::factory()->create(['organization_id' => $this->alpha->id, 'name' => 'Kitchen TV', 'orientation' => 'portrait']);
    PlaylistItem::create(['screen_id' => $source->id, 'channel_id' => $wide->id, 'position' => 0]);

    $this->postJson("/screens/{$source->id}/playlist/copy", ['target_screen_ids' => [$fits->id, $upright->id]])->assertStatus(422)
        ->assertJsonValidationErrors(['items' => 'GAMA is a landscape channel, and Kitchen TV is a portrait screen. Add its portrait channel instead.']);

    expect(PlaylistItem::whereIn('screen_id', [$fits->id, $upright->id])->exists())->toBeFalse();
});

test('a channel of the other shape posted by hand among lines of the right one is refused, the playlist left as it was', function () {
    $wide = Channel::factory()->create(['organization_id' => $this->alpha->id, 'name' => 'GAMA']);
    $tall = Channel::factory()->portrait()->create(['organization_id' => $this->alpha->id, 'name' => 'GAMA Upright']);
    $screen = Screen::factory()->create(['organization_id' => $this->alpha->id, 'name' => 'Tv1']);
    PlaylistItem::create(['screen_id' => $screen->id, 'channel_id' => $wide->id, 'position' => 0]);
    $version = $this->getJson("/screens/{$screen->id}/playlist")->json('version');

    $this->putJson("/screens/{$screen->id}/playlist", ['version' => $version, 'items' => [['channel_id' => $wide->id], ['channel_id' => $tall->id]]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items' => 'GAMA Upright is a portrait channel, and Tv1 is a landscape screen. Add its landscape channel instead.']);

    expect(PlaylistItem::where('screen_id', $screen->id)->pluck('channel_id')->all())->toBe([$wide->id]);
});
