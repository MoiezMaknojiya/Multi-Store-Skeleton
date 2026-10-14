<?php

use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Screen;

/*
|--------------------------------------------------------------------------
| A picture holds a playlist's screen for five minutes at most
|--------------------------------------------------------------------------
|
| The owner, 2026-10-14 ("5 minute (300 s), baaki app jaisa: ek hi qanoon har jagah, aur galti ka number ruk jayega"):
| a playlist's picture took up to 24 hours, so 3600 typed for 36 kept every other line off that screen all day. Now it
| is the channel's five minutes. A video and an Ad Builder page with a length of their own are not asked — their lines
| keep the file's length whatever a save sends, and a video uploaded before videos were held to five minutes is longer.
| A line saved before the rule keeps its place and plays for five minutes, as one saved under the six-second least
| plays for six: nothing is rewritten, every place a picture is timed reads it through secondsForAPicture.
|
*/

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->actor = createOrganizationUser($this->organization, ['screen-view', 'screen-playlist']);
    $this->actingAs($this->actor)->withSession(['current_organization_id' => $this->organization->id]);
    $this->screen = Screen::factory()->withToken('pic-tok')->create(['organization_id' => $this->organization->id]);
    $this->menu = Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Breakfast Menu']);
});

/** Saves the playlist as the page does, with a fresh version. */
function savePicturesPlaylist(Screen $screen, array $items)
{
    $version = test()->getJson("/screens/{$screen->id}/playlist")->json('version');

    return test()->putJson("/screens/{$screen->id}/playlist", ['version' => $version, 'items' => $items]);
}

test('a picture is on screen for five minutes at most, and the line over it is named', function () {
    savePicturesPlaylist($this->screen, [['media_id' => $this->menu->id, 'duration_seconds' => 300]])->assertOk();
    expect($this->screen->playlistItems()->value('duration_seconds'))->toBe(300);

    savePicturesPlaylist($this->screen, [['media_id' => $this->menu->id, 'duration_seconds' => 301]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items.0.duration_seconds' => 'Breakfast Menu: a picture stays on screen for at most 5 minutes.']);
    expect($this->screen->playlistItems()->value('duration_seconds'))->toBe(300);
});

test('a video longer than five minutes, uploaded before videos were held to it, still saves with its own length', function () {
    $film = Media::factory()->create(['organization_id' => $this->organization->id, 'type' => Media::TYPE_VIDEO, 'title' => 'Store Tour', 'duration_seconds' => 600]);

    savePicturesPlaylist($this->screen, [['media_id' => $film->id, 'duration_seconds' => 600]])->assertOk();
    expect($this->screen->playlistItems()->value('duration_seconds'))->toBe(600);
});

test('a line saved before the rule plays for five minutes, shows five minutes, and is copied as five minutes', function () {
    PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $this->menu->id, 'position' => 0, 'duration_seconds' => 86400]);

    $sent = $this->withHeader('Authorization', 'Bearer pic-tok')->getJson('/device/playlist')->assertOk()->json('items.0.duration');
    $shown = $this->getJson("/screens/{$this->screen->id}/playlist")->assertOk()->json('items.0.duration_seconds');

    $other = Screen::factory()->create(['organization_id' => $this->organization->id]);
    $this->postJson("/screens/{$this->screen->id}/playlist/copy", ['target_screen_ids' => [$other->id]])->assertOk();

    expect($sent)->toBe(300)
        ->and($shown)->toBe(300)
        ->and($other->playlistItems()->value('duration_seconds'))->toBe(300)
        // Nothing rewritten where it stands: the line keeps what it was saved with until somebody saves it again.
        ->and($this->screen->playlistItems()->value('duration_seconds'))->toBe(86400);
});

test('one law for a picture, on a playlist and in a channel', function () {
    expect(PlaylistItem::MAX_IMAGE_SECONDS)->toBe(300)
        ->and(ChannelAd::MAX_IMAGE_SECONDS)->toBe(PlaylistItem::MAX_IMAGE_SECONDS);
});
