<?php

use App\Models\BuilderAd;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Role;
use App\Models\Store;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| The Ad Builder — saving, listing and deleting a design
|--------------------------------------------------------------------------
|
| docs/AD-BUILDER-SPEC.md. An ad belongs to a store like everything else a shop makes, the platform
| works above them all, and the design itself is checked on the way in — it becomes HTML on a
| television later, so a document of the wrong shape never reaches the database.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->store = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->other = Store::factory()->create(['name' => 'Beta Deli']);
    $this->designer = createStoreUser($this->store, ['ad-view', 'ad-store', 'ad-update', 'ad-destroy'], 'Designer');
    $this->actingAs($this->designer)->withSession(['current_store_id' => $this->store->id]);
});

/** A minimal, valid design: one line of text on the stage. */
function adDocument(string $text = 'Winter sale'): array
{
    return [
        ...BuilderAd::blankDocument(),
        'elements' => [[
            'id' => 'el_1', 'type' => 'text', 'name' => 'Headline',
            'x' => 160, 'y' => 240, 'w' => 1200, 'h' => 200,
            'rotation' => 0, 'opacity' => 1, 'z' => 0, 'locked' => false, 'visible' => true,
            'text' => $text,
            'style' => ['fontSize' => 96, 'color' => '#ffffff'],
            'animations' => [],
        ]],
    ];
}

test('the three tabs open for somebody who may see ads, and are shut to everybody else', function () {
    $this->get('/builder')->assertOk()->assertSee('Ad Builder');
    $this->get('/builder/create')->assertOk();
    $this->get('/builder/assets')->assertOk();

    // A member of the same store without the permission gets nothing.
    $outsider = createStoreUser($this->store, ['screen-view'], 'Screens only');
    $this->actingAs($outsider)->withSession(['current_store_id' => $this->store->id]);

    $this->get('/builder')->assertForbidden();
    $this->get('/builder/create')->assertForbidden();
    $this->getJson('/builder/data')->assertForbidden();
});

test('an ad is saved, listed and opened again with its design intact', function () {
    $response = $this->postJson('/builder', [
        'name' => 'Winter sale',
        'document' => adDocument(),
    ])->assertOk();

    $id = $response->json('ad.id');
    $ad = BuilderAd::findOrFail($id);

    expect($ad->store_id)->toBe($this->store->id)
        ->and($ad->created_by)->toBe($this->designer->id)
        ->and($ad->document['elements'][0]['text'])->toBe('Winter sale')
        ->and($ad->isPublished())->toBeFalse();      // nothing is on a television until it is published

    // It appears in the listing, without dragging the whole design along.
    $listed = $this->getJson('/builder/data')->assertOk()->json('ads');
    expect($listed)->toHaveCount(1)
        ->and($listed[0]['name'])->toBe('Winter sale')
        ->and($listed[0]['is_published'])->toBeFalse()
        ->and($listed[0])->not->toHaveKey('document');

    $this->get("/builder/{$id}")->assertOk()->assertSee('Winter sale', false);

    // And saving again replaces the whole document, the way a playlist is written.
    $this->putJson("/builder/{$id}", [
        'name' => 'Winter sale 2',
        'document' => adDocument('Spring sale'),
    ])->assertOk();

    expect($ad->fresh()->name)->toBe('Winter sale 2')
        ->and($ad->fresh()->document['elements'][0]['text'])->toBe('Spring sale')
        ->and($ad->fresh()->updated_by)->toBe($this->designer->id);
});

test('a design of the wrong shape is refused before it can ever become a page', function () {
    $tooWide = adDocument();
    $tooWide['stage']['width'] = 1280;

    // The frame is a television's, and nothing may say otherwise.
    $this->postJson('/builder', ['name' => 'Odd size', 'document' => $tooWide])
        ->assertStatus(422)->assertJsonValidationErrors('document.stage.width');

    // An element has to be an element.
    $nonsense = adDocument();
    $nonsense['elements'][0]['type'] = 'iframe';
    $this->postJson('/builder', ['name' => 'Nonsense', 'document' => $nonsense])->assertStatus(422);

    $arrays = adDocument();
    $arrays['elements'][0]['text'] = ['not', 'a', 'string'];
    $this->postJson('/builder', ['name' => 'Arrays', 'document' => $arrays])->assertStatus(422);

    // And a runaway client cannot fill the table.
    $tooMany = adDocument();
    $tooMany['elements'] = array_fill(0, 250, $tooMany['elements'][0]);
    $this->postJson('/builder', ['name' => 'Too many', 'document' => $tooMany])
        ->assertStatus(422)->assertJsonValidationErrors('document.elements');

    expect(BuilderAd::count())->toBe(0);
});

test('a copy is a draft of its own, named so nobody loses track', function () {
    $ad = BuilderAd::factory()->withText()->create(['store_id' => $this->store->id, 'name' => 'Winter sale']);

    $this->postJson("/builder/{$ad->id}/duplicate")->assertOk();
    $this->postJson("/builder/{$ad->id}/duplicate")->assertOk();

    $names = BuilderAd::where('store_id', $this->store->id)->pluck('name')->all();

    expect($names)->toHaveCount(3)
        ->and($names)->toContain('Winter sale', 'Winter sale (copy)', 'Winter sale (copy 2)')
        ->and(BuilderAd::where('name', 'Winter sale (copy)')->first()->document)->toBe($ad->document);
});

test('deleting an ad asks for the password, and takes its poster with it', function () {
    $ad = BuilderAd::factory()->create(['store_id' => $this->store->id, 'thumbnail_path' => 'builder/1/ads/1/poster.jpg']);
    Storage::disk('public')->put($ad->thumbnail_path, 'bytes');

    // No password, wrong password: nothing happens.
    $this->deleteJson("/builder/{$ad->id}")->assertStatus(422);
    $this->deleteJson("/builder/{$ad->id}", ['password' => 'not-it'])->assertStatus(422);
    expect(BuilderAd::find($ad->id))->not->toBeNull();

    $this->deleteJson("/builder/{$ad->id}", ['password' => 'password'])->assertOk();

    expect(BuilderAd::find($ad->id))->toBeNull();
    Storage::disk('public')->assertMissing('builder/1/ads/1/poster.jpg');
});

test('an ad a channel shows is not deleted until it is taken out of the channel — refused before any password', function () {
    // docs/CHANNEL-CONTENT-SPEC.md: a published ad is a library row, and a channel holds it by id; deleting
    // the design would take the channel's ad with it without anybody deciding so.
    $page = Media::factory()->adPage()->create(['store_id' => $this->store->id, 'title' => 'Winter sale']);
    $ad = BuilderAd::factory()->create(['store_id' => $this->store->id, 'name' => 'Winter sale', 'media_id' => $page->id]);
    foreach (['Weekly Deals', 'Lunch Deals'] as $name) {
        $channel = Channel::factory()->create(['store_id' => $this->store->id, 'name' => $name]);
        ChannelAd::factory()->create(['channel_id' => $channel->id, 'media_id' => $page->id]);
    }

    $this->deleteJson("/builder/{$ad->id}")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name' => 'Still used by the channels Lunch Deals, Weekly Deals. Take it out of those channels first.'])
        ->assertJsonMissingValidationErrors('password');

    expect(BuilderAd::find($ad->id))->not->toBeNull()
        ->and(ChannelAd::where('media_id', $page->id)->count())->toBe(2);

    // Out of both channels, it deletes like any other design.
    ChannelAd::where('media_id', $page->id)->delete();
    $this->deleteJson("/builder/{$ad->id}", ['password' => 'password'])->assertOk();

    expect(BuilderAd::find($ad->id))->toBeNull();
});

test('another store’s ad is not there at all', function () {
    $theirs = BuilderAd::factory()->create(['store_id' => $this->other->id, 'name' => 'Beta promo']);

    $this->get("/builder/{$theirs->id}")->assertNotFound();
    $this->putJson("/builder/{$theirs->id}", ['name' => 'Mine now', 'document' => adDocument()])->assertNotFound();
    $this->postJson("/builder/{$theirs->id}/duplicate")->assertNotFound();
    $this->deleteJson("/builder/{$theirs->id}", ['password' => 'password'])->assertNotFound();

    $listed = collect($this->getJson('/builder/data')->assertOk()->json('ads'))->pluck('name');
    expect($listed)->not->toContain('Beta promo')
        ->and(BuilderAd::find($theirs->id)->name)->toBe('Beta promo');
});

test('with no store selected, an ad cannot be made at all', function () {
    $this->actingAs($this->designer->fresh());
    $this->flushSession();

    // No store in the session means no permissions either, so the door is shut before the question arises.
    expect($this->postJson('/builder', ['name' => 'Homeless', 'document' => adDocument()])->status())->toBe(403)
        ->and(BuilderAd::count())->toBe(0);
});

test('the shop an ad is for is one plain id — never an array, never read as shop 1', function () {
    $admin = createSuperAdmin();
    $this->actingAs($admin);
    $this->flushSession();

    // (int) of an array is 1: before the rule, any of the first two made the ad the first shop's.
    foreach ([[$this->store->id], ['id' => $this->store->id], 'Alpha Mart', 0, -1] as $storeId) {
        $this->postJson('/builder', ['name' => 'Odd shop', 'document' => adDocument(), 'store_id' => $storeId])
            ->assertStatus(422)->assertJsonValidationErrors('store_id');
    }

    expect(BuilderAd::where('name', 'Odd shop')->exists())->toBeFalse();
});

test('the platform builds for a shop, and must say which one', function () {
    $admin = createSuperAdmin();
    $this->actingAs($admin);
    $this->flushSession();

    // A platform account stands in no store, so the ad would have nowhere to belong.
    $this->postJson('/builder', ['name' => 'For somebody', 'document' => adDocument()])
        ->assertStatus(422)->assertJsonValidationErrors('store_id');

    $this->postJson('/builder', ['name' => 'For Alpha', 'document' => adDocument(), 'store_id' => $this->store->id])
        ->assertOk();

    expect(BuilderAd::firstWhere('name', 'For Alpha')->store_id)->toBe($this->store->id);

    // And from above the stores, every shop's ads are visible — each saying whose it is.
    BuilderAd::factory()->create(['store_id' => $this->other->id, 'name' => 'Beta promo']);

    $listed = collect($this->getJson('/builder/data')->assertOk()->json('ads'));
    expect($listed->pluck('name'))->toContain('Beta promo')
        ->and($listed->firstWhere('name', 'Beta promo')['store_name'])->toBe('Beta Deli');
});

test('deleting a store takes its ads with it', function () {
    $ads = BuilderAd::factory()->count(2)->create(['store_id' => $this->store->id]);
    $keep = BuilderAd::factory()->create(['store_id' => $this->other->id]);

    foreach ([...$ads, $keep] as $ad) {
        $ad->forceFill(['thumbnail_path' => $ad->storageDirectory().'/poster.jpg'])->save();
        Storage::disk('public')->put($ad->thumbnail_path, 'bytes');
    }

    $this->store->delete();

    expect(BuilderAd::where('store_id', $this->store->id)->count())->toBe(0)
        ->and(BuilderAd::find($keep->id))->not->toBeNull();

    // Its posters go; the other store's stays exactly where it was.
    $ads->each(fn (BuilderAd $ad) => Storage::disk('public')->assertMissing($ad->thumbnail_path));
    Storage::disk('public')->assertExists($keep->thumbnail_path);
});

test('the Owner role holds the ad permissions from the start, and a store role may carry them', function () {
    $owner = createStoreMember($this->store, Role::OWNER);

    $this->actingAs($owner)->withSession(['current_store_id' => $this->store->id]);
    $this->get('/builder')->assertOk();
    $this->postJson('/builder', ['name' => 'Owner made this', 'document' => adDocument()])->assertOk();

    expect(BuilderAd::firstWhere('name', 'Owner made this'))->not->toBeNull();
});
