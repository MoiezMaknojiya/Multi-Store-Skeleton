<?php

use App\Models\BuilderAd;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Screen;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| A file plays from playlists or from channels, never both
|--------------------------------------------------------------------------
|
| Owner, 2026-09-26: "agar koi bhi file channel k ander assign ha toh woo playlist mein nahi dikhe warna woo
| 2 bar ho jayegi" — then "add naah ho sake nahi, dikhao hi nahi". A file in a channel AND on the playlist that
| carries the channel plays twice in one pass. So the playlist's picker never shows a file any channel holds —
| the organization's own channel or the platform's, paused or not — and a channel's pickers never show a file a
| playlist holds. Out of every channel (or off every playlist), a file is the other side's to choose again.
| InputAbuseAttackTest holds the walls behind the two pickers.
|
*/

beforeEach(function () {
    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->manager = createOrganizationUser($this->organization, [
        'screen-view', 'screen-playlist', 'channel-view', 'channel-update',
    ], 'Manager');
    $this->actingAs($this->manager)->withSession(['current_organization_id' => $this->organization->id]);

    $this->screen = Screen::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Counter TV']);
    $this->channel = Channel::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Our Deals']);
});

/** The ids a picker offers. */
function offeredIn(TestCase $test, string $uri): array
{
    return collect($test->getJson($uri)->assertOk()->json('media'))->pluck('id')->all();
}

test("the playlist's picker leaves out every file a channel shows, and offers it again once it leaves the channel", function () {
    $free = Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Menu board']);
    $inOwnChannel = Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Deal poster']);
    $inPlatformChannel = Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'GAMA promo']);
    $inPausedChannel = Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Old promo']);

    $ownAd = ChannelAd::factory()->create(['channel_id' => $this->channel->id, 'media_id' => $inOwnChannel->id]);
    // The platform's channel may show an organization's file; that organization's playlists leave it out all the same.
    ChannelAd::factory()->create([
        'channel_id' => Channel::factory()->create(['organization_id' => null, 'name' => 'GAMA'])->id,
        'media_id' => $inPlatformChannel->id,
    ]);
    // Paused, the channel still holds it — and plays it again the moment it is switched back on.
    ChannelAd::factory()->create([
        'channel_id' => Channel::factory()->paused()->create(['organization_id' => $this->organization->id, 'name' => 'Summer'])->id,
        'media_id' => $inPausedChannel->id,
    ]);

    $picker = "/screens/{$this->screen->id}/available-media";
    expect(offeredIn($this, $picker))->toBe([$free->id]);

    $this->deleteJson("/channels/{$this->channel->id}/ads/{$ownAd->id}")->assertOk();

    expect(offeredIn($this, $picker))->toEqualCanonicalizing([$free->id, $inOwnChannel->id]);
});

test('an Ad Builder ad a channel shows stays off the playlist like any other file', function () {
    $ad = BuilderAd::factory()->withText()->published()->create(['organization_id' => $this->organization->id]);
    $picker = "/screens/{$this->screen->id}/available-media";

    expect(offeredIn($this, $picker))->toContain($ad->media_id);

    ChannelAd::factory()->create(['channel_id' => $this->channel->id, 'media_id' => $ad->media_id]);

    expect(offeredIn($this, $picker))->not->toContain($ad->media_id);
});

test("a channel's pickers leave out every file a playlist holds, and offer it again once it leaves the playlist", function () {
    $free = Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Deal poster']);
    $onPlaylist = Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Menu board']);
    PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $onPlaylist->id, 'position' => 0, 'duration_seconds' => 10]);

    // The organization's own channel…
    expect(offeredIn($this, "/channels/{$this->channel->id}/library?type=files"))->toBe([$free->id]);

    // …and, above the organizations, the platform's channel looking into this organization's library.
    $gama = Channel::factory()->create(['organization_id' => null, 'name' => 'GAMA']);
    $this->actingAs(createSuperAdmin())->withSession([]);
    $platformPicker = "/channels/{$gama->id}/library?type=files&library={$this->organization->id}";
    expect(offeredIn($this, $platformPicker))->toBe([$free->id]);

    PlaylistItem::where('media_id', $onPlaylist->id)->delete();

    expect(offeredIn($this, $platformPicker))->toEqualCanonicalizing([$free->id, $onPlaylist->id]);
});

test('an ad keeps its own file when it is saved, even one a playlist came to hold before the rule', function () {
    // A channel ad and a playlist line sharing a file, as they could before 2026-09-26: re-timing the ad keeps
    // its file — the wall stops only a file coming INTO a channel from a playlist.
    $shared = Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Menu board']);
    $ad = ChannelAd::factory()->create(['channel_id' => $this->channel->id, 'media_id' => $shared->id, 'duration_seconds' => 10]);
    PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $shared->id, 'position' => 0, 'duration_seconds' => 10]);

    $this->postJson("/channels/{$this->channel->id}/ads/{$ad->id}", ['media_id' => $shared->id, 'seconds' => 20])->assertOk();
    $this->postJson("/channels/{$this->channel->id}/ads/{$ad->id}", ['seconds' => 25])->assertOk();

    expect($ad->fresh()->duration_seconds)->toBe(25)->and($ad->fresh()->media_id)->toBe($shared->id);
});

test('a playlist still holding a channel file from before the rule is not copied onto other screens', function () {
    $shared = Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Deal poster']);
    ChannelAd::factory()->create(['channel_id' => $this->channel->id, 'media_id' => $shared->id]);
    PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $shared->id, 'position' => 0, 'duration_seconds' => 10]);
    $window = Screen::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Window TV']);

    $this->postJson("/screens/{$this->screen->id}/playlist/copy", ['target_screen_ids' => [$window->id]])
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'items' => 'Deal poster plays in a channel, so it stays off playlists: it would play twice. Take its line out.',
        ]);

    expect($window->playlistItems()->count())->toBe(0);
});

/*
| Two people at the same moment — one putting a file on a playlist, one putting it into a channel — would each
| pass the first look on what they read before the other wrote. Both writes lock the file's row and look again
| inside the lock. One PHP process cannot race itself, so the other person's write lands the moment this
| request takes its lock: after the first look, before the second.
*/

test('a file put into a channel while a playlist is being saved is seen under the lock, and the save refused', function () {
    $poster = Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Deal poster']);
    $version = $this->getJson("/screens/{$this->screen->id}/playlist")->json('version');

    $landed = false;
    DB::listen(function (QueryExecuted $query) use (&$landed, $poster) {
        if (! $landed && str_starts_with($query->sql, 'select "id" from "media" where "id" in')) {
            $landed = true;
            ChannelAd::factory()->create(['channel_id' => $this->channel->id, 'media_id' => $poster->id]);
        }
    });

    $this->putJson("/screens/{$this->screen->id}/playlist", ['version' => $version, 'items' => [
        ['media_id' => $poster->id, 'duration_seconds' => 10],
    ]])->assertStatus(422)->assertJsonValidationErrors([
        'items' => 'Deal poster plays in a channel, so it stays off playlists: it would play twice. Take its line out.',
    ]);

    expect($landed)->toBeTrue()->and($this->screen->playlistItems()->count())->toBe(0);
});

test('a file put on a playlist while it is being added to a channel is seen under the lock, and the ad refused', function () {
    $poster = Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Deal poster']);

    $landed = false;
    DB::listen(function (QueryExecuted $query) use (&$landed, $poster) {
        if (! $landed && str_starts_with($query->sql, 'select "id" from "media" where "media"."id" =')) {
            $landed = true;
            PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $poster->id, 'position' => 0, 'duration_seconds' => 10]);
        }
    });

    $this->postJson("/channels/{$this->channel->id}/ads", ['media_id' => $poster->id, 'seconds' => 10])
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'media_id' => 'Deal poster plays on a playlist, so it stays out of channels: it would play twice. Still on the screen Counter TV. Take it off that screen first.',
        ]);

    expect($landed)->toBeTrue()->and(ChannelAd::count())->toBe(0);
});

test('both pickers say why a file is not there', function () {
    $this->get("/screens/{$this->screen->id}")
        ->assertOk()
        ->assertSee('Files in a channel are not listed, so nothing plays twice.');

    // The channel's pickers count what they leave out, for the same search, so an Ad Builder tab whose every ad is
    // on a playlist says so instead of looking empty (owner, 2026-10-01: "khali q araha ha").
    $onAPlaylist = BuilderAd::factory()->published()->create(['organization_id' => $this->organization->id, 'name' => 'Winter sale'])->media;
    $free = BuilderAd::factory()->published()->create(['organization_id' => $this->organization->id, 'name' => 'Fresh coffee'])->media;
    PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $onAPlaylist->id, 'position' => 0]);

    $answer = $this->getJson("/channels/{$this->channel->id}/library?type=html")->assertOk();
    expect(collect($answer->json('media'))->pluck('id')->all())->toBe([$free->id])
        ->and($answer->json('on_playlists'))->toBe(1);

    // The search counts too: the one it would have found is on a playlist.
    expect($this->getJson("/channels/{$this->channel->id}/library?type=html&search=Winter")->json())
        ->media->toBe([])
        ->on_playlists->toBe(1);
    expect($this->getJson("/channels/{$this->channel->id}/library?type=html&search=Nothing")->json('on_playlists'))->toBe(0);

    // The page carries the words the picker says it in.
    $this->get("/channels/{$this->channel->id}")->assertOk()
        ->assertSee('so not listed: nothing plays twice.', false);
});
