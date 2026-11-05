<?php

use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Screen;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Every file a television is handed says how big its picture is
|--------------------------------------------------------------------------
|
| A video bigger than full HD — a 4K one — is never set up beside another on the set (player.js, oneAtATime): most
| televisions decode only one such video at a time, and the owner's FB 24 Deli TVs fell every fifty seconds when the
| player made a second ready behind the first (2026-11-05). So the set must know a file's size before it asks for it: a
| playlist's file, a channel's ad and a network advert each carry the width and height the server measured.
|
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 17:00:00', 'UTC'));
    $this->organization = Organization::factory()->create(['accepts_network_ads' => true]);
    $this->screen = Screen::factory()->withToken('tok')->create([
        'organization_id' => $this->organization->id, 'timezone' => 'America/Chicago', 'accepts_network_ads' => true,
    ]);
});

function sizedManifest($test): array
{
    return $test->withHeader('Authorization', 'Bearer tok')->getJson('/device/playlist')->assertOk()->json();
}

test('a playlist file carries the width and height of its picture', function () {
    $video = Media::factory()->video()->create(['organization_id' => $this->organization->id, 'width' => 3840, 'height' => 2160]);
    PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $video->id, 'position' => 0, 'duration_seconds' => null]);

    expect(sizedManifest($this)['items'][0])->toMatchArray(['type' => 'video', 'width' => 3840, 'height' => 2160]);
});

test('a channel ad carries the width and height of its file', function () {
    $channel = Channel::factory()->create(['name' => 'GAMA']);
    $ad = ChannelAd::factory()->video(10)->create(['channel_id' => $channel->id, 'position' => 0]);
    $ad->media->update(['width' => 3840, 'height' => 2160]);
    $poster = Media::factory()->create(['organization_id' => $this->organization->id]);
    PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $poster->id, 'position' => 0, 'duration_seconds' => 10]);
    PlaylistItem::create(['screen_id' => $this->screen->id, 'channel_id' => $channel->id, 'position' => 1, 'duration_seconds' => null]);

    $channelEntry = collect(sizedManifest($this)['items'])->firstWhere('type', 'channel');

    expect($channelEntry['ads'][0])->toMatchArray(['type' => 'video', 'width' => 3840, 'height' => 2160]);
});

test('a network advert carries the width and height of its file', function () {
    $poster = Media::factory()->create(['organization_id' => $this->organization->id]);
    PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $poster->id, 'position' => 0, 'duration_seconds' => 10]);
    $campaign = Campaign::factory()->lasting(20)->create(['width' => 3840, 'height' => 2160]);
    $campaign->screens()->attach($this->screen);

    expect(sizedManifest($this)['ad_break']['items'][0])->toMatchArray(['width' => 3840, 'height' => 2160]);
});

test('a file the server never measured says so with no size rather than a wrong one', function () {
    $video = Media::factory()->video()->create(['organization_id' => $this->organization->id, 'width' => null, 'height' => null]);
    PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $video->id, 'position' => 0, 'duration_seconds' => null]);

    expect(sizedManifest($this)['items'][0])->toMatchArray(['width' => null, 'height' => null]);
});
