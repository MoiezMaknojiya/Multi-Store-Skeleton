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
| The Content Library is the organization's own (owner, 2026-10-07)
|--------------------------------------------------------------------------
|
| "Super admin jo all organization select kar k Ad Builder Aur Media Library mein upload karta tha woo content playlist mein show
| honti thi right ? ab woo hata dena ha" — and, of what stays, "tum ne jo bataya woo theek ha": a screen's Content Library offers its
| organization's own files and published ads alone. What the platform makes for every organization reaches a screen as a Premium
| Template, copied, or inside a platform channel (docs/BILLING-SPEC.md §5). This replaces the rule of 2026-10-05, when the platform's
| library was offered to every playlist.
|
*/

beforeEach(function () {
    $this->alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->beta = Organization::factory()->create(['name' => 'Beta Foods']);
    $this->manager = createOrganizationUser($this->alpha, ['screen-view', 'screen-playlist'], 'Manager');
    $this->actingAs($this->manager)->withSession(['current_organization_id' => $this->alpha->id]);

    $this->screen = Screen::factory()->withToken('own-tok')->create(['organization_id' => $this->alpha->id, 'name' => 'Counter TV']);
    $this->platformFile = Media::factory()->platformOwned()->create(['title' => 'Platform promo']);
    $this->own = Media::factory()->create(['organization_id' => $this->alpha->id, 'title' => 'Menu board']);
    $this->picker = "/screens/{$this->screen->id}/available-media";
});

/** Save the screen's playlist as these lines, from the version the page holds. */
function saveOwnLibraryPlaylist(TestCase $test, Screen $screen, array $items): TestResponse
{
    $version = $test->getJson("/screens/{$screen->id}/playlist")->assertOk()->json('version');

    return $test->putJson("/screens/{$screen->id}/playlist", ['version' => $version, 'items' => $items]);
}

test('the picker offers the organization’s own files alone — never the platform’s, never another organization’s', function () {
    $theirs = Media::factory()->create(['organization_id' => $this->beta->id, 'title' => 'Beta poster']);

    $answer = $this->getJson($this->picker)->assertOk();

    expect(collect($answer->json('media'))->pluck('id')->all())->toBe([$this->own->id])
        ->and($answer->json('total'))->toBe(1)
        ->and($answer->json('media.0'))->not->toHaveKey('from_platform')
        ->and(collect($this->getJson("{$this->picker}?search=promo")->assertOk()->json('media'))->all())->toBe([])
        ->and(collect($answer->json('media'))->pluck('id'))->not->toContain($theirs->id);
});

test('a platform file posted by hand is refused by its name; another organization’s without one', function () {
    saveOwnLibraryPlaylist($this, $this->screen, [['media_id' => $this->platformFile->id, 'duration_seconds' => 10], ['media_id' => $this->own->id, 'duration_seconds' => 10]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items' => "Platform promo is the platform's, and the Content Library holds your own files alone. Take its line out."]);

    $theirs = Media::factory()->create(['organization_id' => $this->beta->id, 'title' => 'Beta poster']);
    saveOwnLibraryPlaylist($this, $this->screen, [['media_id' => $theirs->id, 'duration_seconds' => 10]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items' => "One of those files is not in this organization's library."]);

    expect(PlaylistItem::where('screen_id', $this->screen->id)->exists())->toBeFalse();
});

test('a platform line left from before is shown as the platform’s, still plays, and is taken out by the next save', function () {
    PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $this->platformFile->id, 'position' => 0, 'duration_seconds' => 10]);

    $this->getJson("/screens/{$this->screen->id}/playlist")->assertOk()->assertJsonPath('items.0.from_platform', true);

    // The television is not touched by the rule: it plays the line until somebody takes it out.
    $manifest = $this->withHeader('Authorization', 'Bearer own-tok')->getJson('/device/playlist')->assertOk()->json();
    expect(collect($manifest['items'])->pluck('url')->all())->toBe([$this->platformFile->url]);

    // Saved with it the page is told which line; saved without it the playlist is the organization's own again.
    saveOwnLibraryPlaylist($this, $this->screen, [['media_id' => $this->platformFile->id, 'duration_seconds' => 10]])->assertStatus(422);
    saveOwnLibraryPlaylist($this, $this->screen, [['media_id' => $this->own->id, 'duration_seconds' => 10]])->assertOk();

    expect(PlaylistItem::where('screen_id', $this->screen->id)->pluck('media_id')->all())->toBe([$this->own->id]);
});

test('Copy to Other Screens never spreads a platform line left from before', function () {
    $other = Screen::factory()->create(['organization_id' => $this->alpha->id, 'name' => 'Window TV']);
    PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $this->platformFile->id, 'position' => 0, 'duration_seconds' => 10]);

    $this->postJson("/screens/{$this->screen->id}/playlist/copy", ['target_screen_ids' => [$other->id]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items' => "Platform promo is the platform's, and the Content Library holds your own files alone. Take its line out."]);

    expect(PlaylistItem::where('screen_id', $other->id)->exists())->toBeFalse();
});

test('a platform file still reaches a television inside a platform channel', function () {
    $gama = Channel::factory()->create(['name' => 'GAMA']);
    ChannelAd::factory()->lasting(10)->create(['channel_id' => $gama->id, 'media_id' => $this->platformFile->id]);

    saveOwnLibraryPlaylist($this, $this->screen, [['media_id' => $this->own->id, 'duration_seconds' => 10], ['channel_id' => $gama->id]])->assertOk();

    $manifest = $this->withHeader('Authorization', 'Bearer own-tok')->getJson('/device/playlist')->assertOk()->json();
    $channel = collect($manifest['items'])->firstWhere('type', 'channel');

    expect($channel)->not->toBeNull()
        ->and(collect($channel['ads'])->pluck('url')->all())->toContain($this->platformFile->url);
});
