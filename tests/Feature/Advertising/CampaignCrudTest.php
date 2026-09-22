<?php

use App\Models\Campaign;
use App\Models\Screen;
use App\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Managing network advertising
|--------------------------------------------------------------------------
|
| A campaign belongs to the PLATFORM, not to any shop — so it has no store, no
| visibleTo, and its gate is not a grantable permission row. A store user holding
| one would see every brand's contract across the whole network, which is the one
| wall this app does not break.
|
*/

/** The body the campaign endpoints expect. */
function campaignPayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'Coca-Cola Ramadan',
        'advertiser_name' => 'Coca-Cola',
        'duration_seconds' => 15,
        'is_active' => true,
        'screen_ids' => [],
    ], $overrides);
}

beforeEach(function () {
    Storage::fake('public');

    $this->admin = createSuperAdmin();
    $this->store = Store::factory()->create(['accepts_network_ads' => true]);
    $this->screen = Screen::factory()->create([
        'store_id' => $this->store->id, 'accepts_network_ads' => true,
    ]);
});

/*
|--------------------------------------------------------------------------
| Who may reach any of this
|--------------------------------------------------------------------------
*/

test('guests cannot reach any campaign endpoint', function () {
    $this->getJson('/campaigns/data')->assertUnauthorized();
});

test('a store user cannot see or touch campaigns, whatever they hold', function () {
    // Deliberately given a broad hand-picked set — every screen and media permission,
    // dayparts, the Stores tab, even the accounts and roles views — and none of it
    // opens this door, because campaign-manage is not a permission row at all.
    $actor = createStoreUser($this->store, [
        'screen-view', 'screen-store', 'screen-update', 'screen-destroy', 'screen-playlist',
        'media-view', 'media-store', 'media-update', 'media-destroy',
        'daypart-view', 'daypart-store', 'store-view', 'user-view', 'role-view',
    ]);

    $campaign = Campaign::factory()->create();

    $this->actingAs($actor)->withSession(['current_store_id' => $this->store->id]);

    $this->get('/campaigns')->assertForbidden();
    $this->getJson('/campaigns/data')->assertForbidden();
    $this->getJson('/campaigns/screens')->assertForbidden();
    $this->postJson('/campaigns', campaignPayload())->assertForbidden();
    $this->postJson("/campaigns/{$campaign->id}", campaignPayload())->assertForbidden();
    $this->deleteJson("/campaigns/{$campaign->id}")->assertForbidden();
});

test('a super admin can', function () {
    $this->actingAs($this->admin)->get('/campaigns')->assertOk()->assertSee('Add Campaign');
    $this->actingAs($this->admin)->getJson('/campaigns/data')->assertOk();
});

/*
|--------------------------------------------------------------------------
| Creating, editing, deleting
|--------------------------------------------------------------------------
*/

test('a campaign is created with its file and its targets in one call', function () {
    $this->actingAs($this->admin)->postJson('/campaigns', campaignPayload([
        'file' => UploadedFile::fake()->image('coke.jpg', 1920, 1080),
        'screen_ids' => [$this->screen->id],
        'starts_on' => '2026-03-20',
        'ends_on' => '2026-03-22',
        'start_time' => '11:00',
        'end_time' => '15:00',
    ]))->assertOk();

    $campaign = Campaign::firstOrFail();

    expect($campaign->name)->toBe('Coca-Cola Ramadan');
    expect($campaign->advertiser_name)->toBe('Coca-Cola');
    expect($campaign->type)->toBe('image');
    expect($campaign->start_time)->toBe('11:00');
    expect($campaign->created_by)->toBe($this->admin->id);
    expect($campaign->screens->pluck('id')->all())->toBe([$this->screen->id]);

    Storage::disk('public')->assertExists($campaign->path);
});

test('an edit that only renames does not ask for the file again', function () {
    $campaign = Campaign::factory()->create(['name' => 'Old name']);
    $originalPath = $campaign->path;

    $this->actingAs($this->admin)->postJson("/campaigns/{$campaign->id}", campaignPayload([
        'name' => 'New name',
        'screen_ids' => [$this->screen->id],
    ]))->assertOk();

    expect($campaign->fresh()->name)->toBe('New name');
    expect($campaign->fresh()->path)->toBe($originalPath);
});

test('replacing the advert swaps the file and removes the old one', function () {
    $this->actingAs($this->admin)->postJson('/campaigns', campaignPayload([
        'file' => UploadedFile::fake()->image('first.jpg'),
    ]))->assertOk();

    $campaign = Campaign::firstOrFail();
    $firstPath = $campaign->path;

    $this->actingAs($this->admin)->postJson("/campaigns/{$campaign->id}", campaignPayload([
        'file' => UploadedFile::fake()->image('second.jpg'),
    ]))->assertOk();

    $campaign->refresh();

    expect($campaign->path)->not->toBe($firstPath);
    Storage::disk('public')->assertExists($campaign->path);
    // The old file goes only once the row safely points at the new one.
    Storage::disk('public')->assertMissing($firstPath);
});

test('deleting a campaign takes its file and its targets with it', function () {
    $this->actingAs($this->admin)->postJson('/campaigns', campaignPayload([
        'file' => UploadedFile::fake()->image('coke.jpg'),
        'screen_ids' => [$this->screen->id],
    ]))->assertOk();

    $campaign = Campaign::firstOrFail();

    $this->actingAs($this->admin)->deleteJson("/campaigns/{$campaign->id}", ['password' => 'password'])->assertOk();

    $this->assertDatabaseMissing('campaigns', ['id' => $campaign->id]);
    $this->assertDatabaseMissing('campaign_screen', ['campaign_id' => $campaign->id]);
    Storage::disk('public')->assertMissing($campaign->path);
});

test('targets are replaced, not added to', function () {
    $second = Screen::factory()->create(['store_id' => $this->store->id]);
    $campaign = Campaign::factory()->create();
    $campaign->screens()->attach($this->screen);

    $this->actingAs($this->admin)->postJson("/campaigns/{$campaign->id}", campaignPayload([
        'screen_ids' => [$second->id],
    ]))->assertOk();

    expect($campaign->fresh()->screens->pluck('id')->all())->toBe([$second->id]);
});

/*
|--------------------------------------------------------------------------
| What is refused
|--------------------------------------------------------------------------
*/

test('a new campaign must bring an advert', function () {
    $this->actingAs($this->admin)->postJson('/campaigns', campaignPayload())
        ->assertStatus(422)->assertJsonValidationErrors('file');
});

test('an advert cannot be a sound file, or anything else the player cannot show', function () {
    $this->actingAs($this->admin)->postJson('/campaigns', campaignPayload([
        'file' => UploadedFile::fake()->create('jingle.mp3', 500, 'audio/mpeg'),
    ]))->assertStatus(422)->assertJsonValidationErrors('file');
});

test('a window needs both ends, and they cannot be the same', function () {
    $post = fn (array $extra) => $this->actingAs($this->admin)->postJson('/campaigns', campaignPayload([
        'file' => UploadedFile::fake()->image('coke.jpg'),
        ...$extra,
    ]));

    $post(['start_time' => '11:00'])->assertStatus(422)->assertJsonValidationErrors('end_time');
    $post(['end_time' => '15:00'])->assertStatus(422)->assertJsonValidationErrors('start_time');
    $post(['start_time' => '11:00', 'end_time' => '11:00'])
        ->assertStatus(422)->assertJsonValidationErrors('end_time');

    // An end EARLIER than the start is fine — that is how it crosses midnight.
    $post(['start_time' => '22:00', 'end_time' => '02:00'])->assertOk();
});

test('a contract cannot end before it starts', function () {
    $this->actingAs($this->admin)->postJson('/campaigns', campaignPayload([
        'file' => UploadedFile::fake()->image('coke.jpg'),
        'starts_on' => '2026-03-22',
        'ends_on' => '2026-03-20',
    ]))->assertStatus(422)->assertJsonValidationErrors('ends_on');
});

/*
|--------------------------------------------------------------------------
| The targeting list
|--------------------------------------------------------------------------
*/

test('the screen picker says WHY a screen cannot carry adverts', function () {
    // A greyed row that explains itself beats a screen that is silently missing.
    $shyStore = Store::factory()->create(['name' => 'Beta', 'accepts_network_ads' => false]);
    $shyScreen = Screen::factory()->create([
        'store_id' => $shyStore->id, 'name' => 'Beta TV', 'accepts_network_ads' => true,
    ]);
    $quiet = Screen::factory()->create([
        'store_id' => $this->store->id, 'name' => 'Kids corner', 'accepts_network_ads' => false,
    ]);

    $rows = collect($this->actingAs($this->admin)->getJson('/campaigns/screens')->assertOk()->json('screens'))
        ->keyBy('id');

    expect($rows[$this->screen->id]['carries_ads'])->toBeTrue();

    expect($rows[$shyScreen->id]['carries_ads'])->toBeFalse();
    expect($rows[$shyScreen->id]['store_accepts'])->toBeFalse();   // the shop said no
    expect($rows[$shyScreen->id]['screen_accepts'])->toBeTrue();

    expect($rows[$quiet->id]['carries_ads'])->toBeFalse();
    expect($rows[$quiet->id]['store_accepts'])->toBeTrue();
    expect($rows[$quiet->id]['screen_accepts'])->toBeFalse();      // this set said no
});

test('a screen that does not exist is refused before anything is stored', function () {
    // Refused by the rules, not by the foreign key after the upload: the file must never be left behind.
    $this->actingAs($this->admin)->postJson('/campaigns', campaignPayload([
        'file' => UploadedFile::fake()->image('coke.jpg', 1920, 1080),
        'screen_ids' => [$this->screen->id, 999999],
    ]))->assertStatus(422)->assertJsonValidationErrors('screen_ids.1');

    expect(Campaign::count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('a screen id that is not one plain number is refused, never read as screen 1', function () {
    // intval() once read a nested array as 1 — the first screen in the database.
    $this->actingAs($this->admin)->postJson('/campaigns', campaignPayload([
        'file' => UploadedFile::fake()->image('coke.jpg', 1920, 1080),
        'screen_ids' => [[$this->screen->id]],
    ]))->assertStatus(422)->assertJsonValidationErrors('screen_ids.0');

    expect(Campaign::count())->toBe(0);
});
