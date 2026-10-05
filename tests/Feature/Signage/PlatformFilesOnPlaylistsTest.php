<?php

use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Screen;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| The platform's library on every organization's playlists
|--------------------------------------------------------------------------
|
| Owner, 2026-10-05: "platform library mein jo bhi kuch upload karu woo har screen ki content playlist mein ani
| chahiye ... aur agar woo channel mein use ho rae toh nahi ayegi". Every screen's Content library offers the
| platform's files beside the organization's own, marked as the platform's — except a file a channel holds, which
| stays off every playlist (rule 6 of Channels). Another organization's files are offered nowhere, and an
| organization's people still never list, change or delete the platform's.
|
*/

beforeEach(function () {
    $this->alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->beta = Organization::factory()->create(['name' => 'Beta Foods']);
    $this->manager = createOrganizationUser($this->alpha, ['screen-view', 'screen-playlist'], 'Manager');
    $this->actingAs($this->manager)->withSession(['current_organization_id' => $this->alpha->id]);

    $this->screen = Screen::factory()->withToken('platform-tok')->create(['organization_id' => $this->alpha->id, 'name' => 'Counter TV']);
    $this->platformFile = Media::factory()->platformOwned()->create(['title' => 'Platform promo']);
    $this->picker = "/screens/{$this->screen->id}/available-media";
});

/** Save the screen's playlist as these files, from the version the page holds. */
function savePlatformTestPlaylist(TestCase $test, Screen $screen, array $mediaIds): TestResponse
{
    $version = $test->getJson("/screens/{$screen->id}/playlist")->assertOk()->json('version');

    return $test->putJson("/screens/{$screen->id}/playlist", [
        'version' => $version,
        'items' => array_map(fn (int $id) => ['media_id' => $id, 'duration_seconds' => 10], $mediaIds),
    ]);
}

test('every organization’s screen is offered the platform’s files, marked, beside its own — never another organization’s', function () {
    $own = Media::factory()->create(['organization_id' => $this->alpha->id, 'title' => 'Menu board']);
    $theirs = Media::factory()->create(['organization_id' => $this->beta->id, 'title' => 'Beta poster']);

    $offered = collect($this->getJson($this->picker)->assertOk()->json('media'))->keyBy('id');

    expect($offered->keys()->all())->toEqualCanonicalizing([$own->id, $this->platformFile->id])
        ->and($offered[$this->platformFile->id]['from_platform'])->toBeTrue()
        ->and($offered[$own->id]['from_platform'])->toBeFalse()
        ->and($offered->has($theirs->id))->toBeFalse();

    // A search finds the platform's file by its name too.
    expect(collect($this->getJson("{$this->picker}?search=promo")->assertOk()->json('media'))->pluck('id')->all())
        ->toBe([$this->platformFile->id]);

    // The other organization's screens are offered it as well — and only their own beside it.
    $betaScreen = Screen::factory()->create(['organization_id' => $this->beta->id]);
    $betaManager = createOrganizationUser($this->beta, ['screen-view', 'screen-playlist'], 'Manager');
    $betaOffered = collect($this->actingAs($betaManager)->withSession(['current_organization_id' => $this->beta->id])
        ->getJson("/screens/{$betaScreen->id}/available-media")->assertOk()->json('media'))->pluck('id')->all();

    expect($betaOffered)->toEqualCanonicalizing([$theirs->id, $this->platformFile->id]);
});

test('a platform file a channel holds is offered to no playlist and refused by id, until it leaves the channel', function () {
    $gama = Channel::factory()->create(['organization_id' => null, 'name' => 'GAMA']);
    $ad = ChannelAd::factory()->create(['channel_id' => $gama->id, 'media_id' => $this->platformFile->id]);

    expect(collect($this->getJson($this->picker)->assertOk()->json('media'))->pluck('id')->all())
        ->not->toContain($this->platformFile->id);

    savePlatformTestPlaylist($this, $this->screen, [$this->platformFile->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items' => 'Platform promo plays in a channel, so it stays off playlists: it would play twice. Take its line out.']);
    expect(PlaylistItem::where('screen_id', $this->screen->id)->exists())->toBeFalse();

    // Out of the channel, it is the playlists' to choose again.
    $ad->delete();
    expect(collect($this->getJson($this->picker)->assertOk()->json('media'))->pluck('id')->all())
        ->toContain($this->platformFile->id);
});

test('a platform file on a playlist plays on the television, and leaves every screen when the platform deletes it', function () {
    $own = Media::factory()->create(['organization_id' => $this->alpha->id, 'title' => 'Menu board']);

    // The saved lines say whose each file is: the organization's Media page never lists the platform's.
    savePlatformTestPlaylist($this, $this->screen, [$this->platformFile->id, $own->id])
        ->assertOk()
        ->assertJsonPath('items.0.from_platform', true)
        ->assertJsonPath('items.1.from_platform', false);

    $manifest = $this->withHeader('Authorization', 'Bearer platform-tok')->getJson('/device/playlist')->assertOk()->json();
    expect(collect($manifest['items'])->pluck('url')->all())->toBe([$this->platformFile->url, $own->url]);

    // Another organization's screen carries it too.
    $betaScreen = Screen::factory()->create(['organization_id' => $this->beta->id]);
    PlaylistItem::create(['screen_id' => $betaScreen->id, 'media_id' => $this->platformFile->id, 'position' => 0, 'duration_seconds' => 10]);

    // The platform deletes its file: a playlist line is no reason to refuse, and the file leaves every screen —
    // the organization's own file stays where it was.
    $admin = createSuperAdmin(['media-destroy']);
    $this->actingAs($admin)->deleteJson("/media/{$this->platformFile->id}")->assertOk();

    expect(PlaylistItem::whereIn('screen_id', [$this->screen->id, $betaScreen->id])->pluck('media_id')->all())->toBe([$own->id]);
});

test('another organization’s file is still refused on a playlist, beside the platform’s', function () {
    $theirs = Media::factory()->create(['organization_id' => $this->beta->id, 'title' => 'Beta poster']);

    savePlatformTestPlaylist($this, $this->screen, [$this->platformFile->id, $theirs->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items' => "One of those files is not in this organization's library or the platform's."]);

    expect(PlaylistItem::where('screen_id', $this->screen->id)->exists())->toBeFalse();
});
