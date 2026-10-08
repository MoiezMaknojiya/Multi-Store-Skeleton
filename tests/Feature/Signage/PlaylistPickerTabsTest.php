<?php

use App\Models\BuilderAd;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Screen;

/*
|--------------------------------------------------------------------------
| The card beside the playlist: Content Library and Channels as two tabs
|--------------------------------------------------------------------------
|
| Owner, 2026-10-07: "jo screen k ander 2 card arae ha "Content library" aur "channel" us ek card kar k tab bana du",
| then (a): the Channels tab is there only while the screen has a channel to carry. The library's tab counts every
| file it offers — `total` — whatever the search says and however many the list holds (100 at most), so the number
| stays put while somebody types. Which tab is open is the page's own; the browser tests press them.
|
*/

beforeEach(function () {
    $this->alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->beta = Organization::factory()->create(['name' => 'Beta Foods']);
    $this->manager = createOrganizationUser($this->alpha, ['screen-view', 'screen-playlist'], 'Manager');
    $this->actingAs($this->manager)->withSession(['current_organization_id' => $this->alpha->id]);

    $this->screen = Screen::factory()->create(['organization_id' => $this->alpha->id, 'name' => 'Counter TV']);
    $this->picker = "/screens/{$this->screen->id}/available-media";
});

test('the library counts every file it offers: its own alone, never the platform’s, another organization’s, a channel’s or a draft', function () {
    $own = Media::factory()->count(3)->create(['organization_id' => $this->alpha->id]);
    Media::factory()->platformOwned()->create(['title' => 'Platform promo']);
    Media::factory()->create(['organization_id' => $this->beta->id]);

    // A file a channel shows stays off every playlist (rule 6 of Channels), so it is not counted either.
    $inChannel = Media::factory()->create(['organization_id' => $this->alpha->id]);
    ChannelAd::factory()->create(['channel_id' => Channel::factory()->create(['organization_id' => $this->alpha->id])->id, 'media_id' => $inChannel->id]);

    // An Ad Builder page taken off the screens is offered nowhere until it is published again.
    $draftPage = Media::factory()->adPage()->create(['organization_id' => $this->alpha->id]);
    BuilderAd::factory()->create(['organization_id' => $this->alpha->id, 'media_id' => $draftPage->id, 'published_at' => null]);

    $answer = $this->getJson($this->picker)->assertOk();

    expect($answer->json('total'))->toBe(3)
        ->and($answer->json('media'))->toHaveCount(3);
});

test('the count stays put while a search narrows the list', function () {
    Media::factory()->create(['organization_id' => $this->alpha->id, 'title' => 'Burger deal']);
    Media::factory()->create(['organization_id' => $this->alpha->id, 'title' => 'Coffee morning']);
    Media::factory()->create(['organization_id' => $this->alpha->id, 'title' => 'Burger combo']);

    $answer = $this->getJson("{$this->picker}?search=burger")->assertOk();

    expect($answer->json('total'))->toBe(3)
        ->and(collect($answer->json('media'))->pluck('title')->all())->toEqualCanonicalizing(['Burger deal', 'Burger combo']);

    // A search that finds nothing still says how many the library holds.
    expect($this->getJson("{$this->picker}?search=pizza")->assertOk()->json('total'))->toBe(3);
});

test('the count is the whole library, not the hundred the list carries', function () {
    Media::factory()->count(105)->create(['organization_id' => $this->alpha->id]);

    $answer = $this->getJson($this->picker)->assertOk();

    expect($answer->json('total'))->toBe(105)
        ->and($answer->json('media'))->toHaveCount(100);
});

test('the page draws one card with both tabs and their panels for somebody who may change the playlist', function () {
    $this->get("/screens/{$this->screen->id}")->assertOk()
        ->assertSee('dusk="playlist-picker"', false)
        ->assertSee('role="tablist"', false)
        ->assertSee('id="picker-tab-library"', false)
        ->assertSee('id="picker-tab-channels"', false)
        ->assertSee('id="picker-panel-library"', false)
        ->assertSee('id="picker-panel-channels"', false)
        ->assertSee('Content Library')
        ->assertDontSee('Content library');
});

test('somebody who may only look at the playlist gets no card and no tabs', function () {
    $viewer = createOrganizationUser($this->alpha, ['screen-view'], 'Viewer');

    $this->actingAs($viewer)->withSession(['current_organization_id' => $this->alpha->id])
        ->get("/screens/{$this->screen->id}")->assertOk()
        ->assertDontSee('dusk="playlist-picker"', false)
        ->assertDontSee('picker-tab-channels', false);

    // And the lists behind the tabs refuse them, as before.
    $this->getJson($this->picker)->assertForbidden();
    $this->getJson("/screens/{$this->screen->id}/available-channels")->assertForbidden();
});

test('another organization’s screen answers nothing, its count included', function () {
    $theirs = Screen::factory()->create(['organization_id' => $this->beta->id]);
    Media::factory()->count(2)->create(['organization_id' => $this->beta->id]);

    $this->getJson("/screens/{$theirs->id}/available-media")->assertNotFound()->assertJsonMissingPath('total');
});
