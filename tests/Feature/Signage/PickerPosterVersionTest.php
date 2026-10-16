<?php

use App\Models\BuilderAd;
use App\Models\Organization;
use App\Models\Screen;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| A picker's poster carries its page's version
|--------------------------------------------------------------------------
|
| A published ad's poster is drawn again under the same name on every publish, and the server lets a browser keep a
| picture for a week: only the version in its address (Media::getThumbnailUrlAttribute, from the row's type and
| updated_at) makes a browser fetch the new one. A picker that loads only some of a file's columns must load those too,
| or every ad page in it reads "?v=0" and shows last week's poster (the review round, 2026-10-15).
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->person = createOrganizationUser($this->organization, ['screen-view', 'screen-playlist'], 'Planner');
    $this->actingAs($this->person)->withSession(['current_organization_id' => $this->organization->id]);

    $this->screen = Screen::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Lobby TV']);
});

test("the Content Library's poster of an ad page carries the page's version, and a new publish gives it a new one", function () {
    $ad = BuilderAd::factory()->withText()->published()->create(['organization_id' => $this->organization->id, 'name' => 'Winter sale']);
    $page = $ad->media;
    $page->forceFill(['thumbnail_path' => "builder/{$this->organization->id}/ads/{$ad->id}/poster.jpg"])->save();

    $poster = fn (): string => collect($this->getJson("/screens/{$this->screen->id}/available-media")->assertOk()->json('media'))
        ->firstWhere('id', $page->id)['thumbnail_url'];

    $first = $poster();
    expect($first)->toBe($page->fresh()->thumbnail_url)
        ->toEndWith('?v='.$page->fresh()->updated_at->getTimestamp());

    // Published again: the poster is the same file name, redrawn — its address must move with it.
    $this->travel(1)->minutes();
    $page->touch();

    expect($poster())->not->toBe($first)->toBe($page->fresh()->thumbnail_url);
});

test('the holding picture list sends what an option says, never an address drawn from columns it did not read', function () {
    $this->person = createOrganizationUser($this->organization, ['screen-view', 'screen-update'], 'Editor');
    $this->actingAs($this->person)->withSession(['current_organization_id' => $this->organization->id]);
    $ad = BuilderAd::factory()->withText()->published()->create(['organization_id' => $this->organization->id, 'name' => 'Winter sale']);

    $option = collect($this->getJson("/screens/{$this->screen->id}/media-options")->assertOk()->json('media'))->firstWhere('id', $ad->media->id);

    expect($option)->toBe(['id' => $ad->media->id, 'title' => $ad->media->title, 'type' => 'html', 'orientation' => $ad->media->orientation]);
});
