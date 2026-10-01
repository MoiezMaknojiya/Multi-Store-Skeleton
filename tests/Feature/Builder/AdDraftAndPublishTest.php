<?php

use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Screen;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Draft and publish — the industry's model
|--------------------------------------------------------------------------
|
| Owner, 2026-09-21: "publish wala kaam jo industry standard k hisab se best". Xibo, Contentful and Strapi
| all work one way, and so does this: a published ad that is changed stays on the screens as it was
| published, and nobody sees the changes until they are published. Discard changes goes back to the version
| on the screens; Unpublish takes the ad off every screen, channel, picker and library until it is published
| again — and deletes nothing: every playlist line and channel ad keeps its place for it.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->designer = createOrganizationUser($this->organization, [
        'ad-view', 'ad-store', 'ad-update', 'screen-view', 'screen-update', 'screen-playlist', 'media-view',
        'channel-view', 'channel-update',
    ], 'Designer');
    $this->actingAs($this->designer)->withSession(['current_organization_id' => $this->organization->id]);
});

/** Every address a television would be sent right now — its playlist's files and its channels' ads. */
function addressesOnScreen(TestCase $test, string $token): array
{
    $items = $test->getJson('/device/playlist', ['Authorization' => "Bearer {$token}"])->assertOk()->json('items');

    return collect($items)
        ->flatMap(fn (array $item) => $item['type'] === 'channel' ? array_column($item['ads'], 'url') : [$item['url']])
        ->all();
}

/** The ids a listing or picker answers with. */
function idsFrom(TestCase $test, string $uri, string $key = 'media'): array
{
    return collect($test->getJson($uri)->assertOk()->json($key))->pluck('id')->all();
}

/** A poster the way the editor's canvas hands one over, in one colour. */
function posterDataUri(int $red, int $green, int $blue): string
{
    $image = imagecreatetruecolor(320, 180);
    imagefilledrectangle($image, 0, 0, 320, 180, imagecolorallocate($image, $red, $green, $blue));

    ob_start();
    imagejpeg($image, null, 85);

    return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
}

/** The ad's own design with its first words changed. */
function changedDesign(BuilderAd $ad, string $text): array
{
    $document = $ad->fresh()->document;
    $document['elements'][0]['text'] = $text;

    return $document;
}

test('a changed published ad keeps its published version on its screens until the changes are published', function (string $from) {
    $ad = BuilderAd::factory()->withText('Winter sale')->create(['organization_id' => $this->organization->id, 'name' => 'Winter sale']);
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();
    $page = Media::sole();

    // It plays from a playlist line or from a channel's ad — never both, or it would play twice (2026-09-26).
    $screen = Screen::factory()->withToken('live-token')->create(['organization_id' => $this->organization->id]);
    $channel = Channel::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Deals']);
    if ($from === 'a channel') {
        ChannelAd::factory()->create(['channel_id' => $channel->id, 'media_id' => $page->id]);
        PlaylistItem::create(['screen_id' => $screen->id, 'channel_id' => $channel->id, 'position' => 0]);
    } else {
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $page->id, 'position' => 0, 'duration_seconds' => 12]);
    }
    $pageOnScreen = fn () => collect(addressesOnScreen($this, 'live-token'))->filter(fn (string $url) => str_contains($url, "/ads/{$ad->id}/"));
    // The picker of where it plays offers it; the other side's leaves it out.
    [$offering, $leavingOut] = $from === 'a channel'
        ? ["/channels/{$channel->id}/library?type=html", "/screens/{$screen->id}/available-media"]
        : ["/screens/{$screen->id}/available-media", "/channels/{$channel->id}/library?type=html"];

    // -- Changed and saved: the draft only ---------------------------------------------------------
    $this->putJson("/builder/{$ad->id}", ['name' => 'Spring sale', 'document' => changedDesign($ad, 'Spring sale')])
        ->assertOk()
        ->assertJsonPath('message', 'Changes saved — the screens keep the published version until you publish them')
        ->assertJsonPath('ad.is_published', true)
        ->assertJsonPath('ad.status', 'changed');

    // The screens, the pickers and the library still have the published version: its page, words and name.
    expect($pageOnScreen())->toHaveCount(1)
        ->and(Storage::disk('public')->get($page->path))->toContain('Winter sale')->not->toContain('Spring sale')
        ->and($page->fresh()->title)->toBe('Winter sale')
        ->and(idsFrom($this, '/media/data'))->toContain($page->id)
        ->and(idsFrom($this, $offering))->toContain($page->id)
        ->and(idsFrom($this, $leavingOut))->not->toContain($page->id);

    // The listing says so — and never hands out a design, drafted or published.
    $row = collect($this->getJson('/builder/data')->assertOk()->json('ads'))->firstWhere('id', $ad->id);
    expect($row['status'])->toBe('changed')
        ->and($row)->not->toHaveKey('document')
        ->and($row)->not->toHaveKey('published_document');

    // -- Published: the changes reach the same screens, through the same row -----------------------
    $this->postJson("/builder/{$ad->id}/publish")->assertOk()->assertJsonPath('ad.status', 'published');

    expect(Media::count())->toBe(1)
        ->and(Storage::disk('public')->get($page->path))->toContain('Spring sale')
        ->and($page->fresh()->title)->toBe('Spring sale')
        ->and($pageOnScreen())->toHaveCount(1);
})->with(['from a playlist' => 'a playlist', 'from a channel' => 'a channel']);

test('discarding the changes brings back the published design, name and poster', function () {
    $ad = BuilderAd::factory()->withText('Winter sale')->create(['organization_id' => $this->organization->id, 'name' => 'Winter sale']);
    $this->putJson("/builder/{$ad->id}", ['name' => 'Winter sale', 'document' => $ad->document, 'thumbnail' => posterDataUri(200, 40, 40)])->assertOk();
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();
    $published = Storage::disk('public')->get($ad->fresh()->thumbnail_path);

    // Changed: the design, the name and the picture of it.
    $this->putJson("/builder/{$ad->id}", [
        'name' => 'Spring sale', 'document' => changedDesign($ad, 'Spring sale'), 'thumbnail' => posterDataUri(40, 40, 200),
    ])->assertOk();
    expect(Storage::disk('public')->get($ad->fresh()->thumbnail_path))->not->toBe($published);

    $this->postJson("/builder/{$ad->id}/discard")
        ->assertOk()
        ->assertJsonPath('message', 'Changes discarded — back to the version on the screens')
        ->assertJsonPath('ad.status', 'published');

    $ad->refresh();
    expect($ad->name)->toBe('Winter sale')
        ->and($ad->document['elements'][0]['text'])->toBe('Winter sale')
        ->and($ad->hasUnpublishedChanges())->toBeFalse()
        ->and(Storage::disk('public')->get($ad->thumbnail_path))->toBe($published)
        ->and(ActivityLog::where('action', 'ad.changes_discarded')->value('description'))
        ->toBe('Discarded the unpublished changes of ad Winter sale');
});

test('there is nothing to discard on an ad never published, up to date, or published before its version was kept', function () {
    $never = BuilderAd::factory()->withText()->create(['organization_id' => $this->organization->id]);
    $upToDate = BuilderAd::factory()->withText()->published()->create(['organization_id' => $this->organization->id]);
    $legacy = BuilderAd::factory()->withText()->published()->create([
        'organization_id' => $this->organization->id, 'published_document' => null, 'published_name' => null,
    ]);
    DB::table('builder_ads')->where('id', $legacy->id)->update(['updated_at' => now()->addMinute()]);

    $refusals = [
        $never->id => 'This ad is not published, so there is no published version to go back to.',
        $upToDate->id => 'There are no changes to discard: the screens show this very design.',
        $legacy->id => 'This ad was published before its published version was kept, so there is none to go back to. Publish it to keep one.',
    ];

    foreach ($refusals as $id => $message) {
        $this->postJson("/builder/{$id}/discard")->assertStatus(422)->assertJsonValidationErrors(['ad' => $message]);
    }

    expect(ActivityLog::where('action', 'ad.changes_discarded')->exists())->toBeFalse();
});

test('unpublishing takes the page off its screens, channel, pickers and library — and publishing brings it back', function (string $from) {
    $ad = BuilderAd::factory()->withText('Winter sale')->create(['organization_id' => $this->organization->id, 'name' => 'Winter sale']);
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();
    $page = Media::sole();

    // On a screen from a playlist line, or from a channel the screen carries — never both, or it would play
    // twice (2026-09-26) — beside a poster of the organization's own and a channel ad of the channel's own.
    $screen = Screen::factory()->withToken('draft-token')->create(['organization_id' => $this->organization->id]);
    $poster = Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Poster']);
    $channel = Channel::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Deals']);
    $pageAd = $from === 'a channel'
        ? ChannelAd::factory()->create(['channel_id' => $channel->id, 'media_id' => $page->id, 'title' => 'Winter sale'])
        : null;
    ChannelAd::factory()->create(['channel_id' => $channel->id, 'title' => 'Coffee', 'position' => 1]);
    $playlist = array_values(array_filter([
        $from === 'a playlist' ? ['media_id' => $page->id, 'duration_seconds' => 12] : null,
        ['media_id' => $poster->id, 'duration_seconds' => 10],
        ['channel_id' => $channel->id],
    ]));
    foreach ($playlist as $position => $line) {
        PlaylistItem::create(['screen_id' => $screen->id, 'position' => $position, ...$line]);
    }

    $pageOnScreen = fn () => collect(addressesOnScreen($this, 'draft-token'))->filter(fn (string $url) => str_contains($url, "/ads/{$ad->id}/"));
    $pickers = fn () => [
        'library' => idsFrom($this, '/media/data'),
        'playlist' => idsFrom($this, "/screens/{$screen->id}/available-media"),
        'holding picture' => idsFrom($this, "/screens/{$screen->id}/media-options"),
        'channel' => idsFrom($this, "/channels/{$channel->id}/library?type=html"),
    ];
    // The one picker that leaves it out even while it is published: the other side's.
    $otherSide = $from === 'a channel' ? 'playlist' : 'channel';
    $reach = $from === 'a channel' ? '1 channel' : '1 screen';

    expect($pageOnScreen())->toHaveCount(1);

    // -- Unpublished ---------------------------------------------------------------------------------
    $this->postJson("/builder/{$ad->id}/unpublish")
        ->assertOk()
        ->assertJsonPath('message', "Unpublished — taken off {$reach}")
        ->assertJsonPath('ad.status', 'draft');

    expect($ad->fresh()->isPublished())->toBeFalse()
        ->and(ActivityLog::where('action', 'ad.unpublished')->value('description'))
        ->toBe("Unpublished ad Winter sale — taken off {$reach}");
    $this->postJson("/builder/{$ad->id}/unpublish")->assertStatus(422)->assertJsonValidationErrors(['ad' => 'This ad is not on any screen.']);

    // Nobody sees it: no television — as a line or as the channel's ad — no picker, no library.
    expect($pageOnScreen())->toBeEmpty()
        ->and(addressesOnScreen($this, 'draft-token'))->toHaveCount(2);
    foreach ($pickers() as $ids) {
        expect($ids)->not->toContain($page->id);
    }

    // Nothing is deleted: whatever holds it keeps its place, and says why it is quiet.
    $lines = collect($this->getJson("/screens/{$screen->id}/playlist")->assertOk()->json('items'));
    expect($lines->firstWhere('media_id', $poster->id)['is_draft'])->toBeFalse();

    if ($pageAd === null) {
        expect($lines->firstWhere('media_id', $page->id)['is_draft'])->toBeTrue();
    } else {
        $ads = collect($this->getJson("/channels/{$channel->id}/ads")->assertOk()->json('ads'))->keyBy('id');
        expect($ads[$pageAd->id]['status'])->toBe('draft');
    }

    $row = collect($this->getJson('/channels/data')->assertOk()->json('channels'))->firstWhere('id', $channel->id);
    expect($row['ads_count'])->toBe($pageAd === null ? 1 : 2)->and($row['running_ads_count'])->toBe(1);

    // A save keeps a quiet line where it is.
    $version = $this->getJson("/screens/{$screen->id}/playlist")->json('version');
    $this->putJson("/screens/{$screen->id}/playlist", ['version' => $version, 'items' => $playlist])->assertOk();

    // -- Published again: the same row, back everywhere at once ---------------------------------------
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();

    expect(Media::find($page->id))->not->toBeNull()
        ->and($pageOnScreen())->toHaveCount(1);
    foreach ($pickers() as $picker => $ids) {
        $picker === $otherSide
            ? expect($ids)->not->toContain($page->id)
            : expect($ids)->toContain($page->id);
    }
})->with(['from a playlist' => 'a playlist', 'from a channel' => 'a channel']);

test('a page on a line AND in a channel — as a playlist could hold before 2026-09-26 — is counted in both', function () {
    $ad = BuilderAd::factory()->withText('Winter sale')->published()->create([
        'organization_id' => $this->organization->id, 'name' => 'Winter sale',
    ]);
    $screen = Screen::factory()->create(['organization_id' => $this->organization->id]);
    $channel = Channel::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Deals']);
    ChannelAd::factory()->create(['channel_id' => $channel->id, 'media_id' => $ad->media_id]);
    PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $ad->media_id, 'position' => 0, 'duration_seconds' => 12]);

    $this->postJson("/builder/{$ad->id}/unpublish")
        ->assertOk()
        ->assertJsonPath('message', 'Unpublished — taken off 1 screen and 1 channel');
});

test('a save that changes nothing leaves the ad up to date, and the history alone', function () {
    $ad = BuilderAd::factory()->withText('Winter sale')->create(['organization_id' => $this->organization->id, 'name' => 'Winter sale']);
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();

    // The design as stored, its keys in another order and a number written another way: the same design.
    $document = $ad->fresh()->document;
    $document['elements'][0] = array_reverse($document['elements'][0], true);
    $document['elements'][0]['opacity'] = 1.0;

    $this->putJson("/builder/{$ad->id}", [
        'name' => 'Winter sale', 'document' => array_reverse($document, true), 'thumbnail' => posterDataUri(10, 120, 60),
    ])->assertOk()
        ->assertJsonPath('message', 'Ad saved')
        ->assertJsonPath('ad.status', 'published');

    expect(ActivityLog::where('action', 'ad.updated')->exists())->toBeFalse();
});

test('an ad changed after its publish before versions were kept stays on the screens, marked changed', function () {
    // The owner's own "Example · Burger Deal (Urdu)" was edited after publishing on 2026-09-20: what it published
    // is still what its screens play, and its next Publish keeps a version.
    $ad = BuilderAd::factory()->withText()->published()->create([
        'organization_id' => $this->organization->id, 'published_document' => null, 'published_name' => null,
    ]);
    DB::table('builder_ads')->where('id', $ad->id)->update(['updated_at' => now()->addMinute()]);

    expect($ad->fresh()->status())->toBe('changed')
        ->and($ad->fresh()->hasPublishedVersion())->toBeFalse()
        ->and(Media::find($ad->media_id)->isDraft())->toBeFalse()
        ->and(idsFrom($this, '/media/data'))->toContain($ad->media_id);
});

test("an unpublished ad is nobody's holding picture", function () {
    $ad = BuilderAd::factory()->withText()->published()->create(['organization_id' => $this->organization->id]);
    Screen::factory()->withToken('hold-token')->create(['organization_id' => $this->organization->id, 'default_media_id' => $ad->media_id]);

    expect(addressesOnScreen($this, 'hold-token'))->toHaveCount(1);

    // A change alone keeps it there; unpublishing does not.
    $this->putJson("/builder/{$ad->id}", ['name' => $ad->name, 'document' => changedDesign($ad, 'Spring sale')])->assertOk();
    expect(addressesOnScreen($this, 'hold-token'))->toHaveCount(1);

    $this->postJson("/builder/{$ad->id}/unpublish")->assertOk();
    expect(addressesOnScreen($this, 'hold-token'))->toBe([]);
});

test('a photograph of the design is not a change to it', function () {
    // A published ad that never had a poster gets one on a save that changes nothing: still up to date.
    $ad = BuilderAd::factory()->withText()->published()->create(['organization_id' => $this->organization->id, 'thumbnail_path' => null]);

    $this->putJson("/builder/{$ad->id}", ['name' => $ad->name, 'document' => $ad->document, 'thumbnail' => posterDataUri(200, 60, 40)])
        ->assertOk()->assertJsonPath('ad.status', 'published');

    expect($ad->fresh()->thumbnail_path)->not->toBeNull();
});

test('unpublish and discard are Update Ads, inside the organization the ad belongs to', function () {
    $mine = BuilderAd::factory()->withText()->published()->create(['organization_id' => $this->organization->id]);
    $theirs = BuilderAd::factory()->withText()->published()->create(['organization_id' => Organization::factory()->create()->id]);

    foreach (['unpublish', 'discard'] as $action) {
        $this->postJson("/builder/{$theirs->id}/{$action}")->assertNotFound();
    }

    $watcher = createOrganizationUser($this->organization, ['ad-view'], 'Ad Watcher');
    $this->actingAs($watcher)->withSession(['current_organization_id' => $this->organization->id]);

    foreach (['unpublish', 'discard'] as $action) {
        $this->postJson("/builder/{$mine->id}/{$action}")->assertForbidden();
    }

    expect($mine->fresh()->isPublished())->toBeTrue()
        ->and($theirs->fresh()->isPublished())->toBeTrue();
});
