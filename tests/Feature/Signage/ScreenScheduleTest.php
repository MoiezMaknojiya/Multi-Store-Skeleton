<?php

use App\Models\Daypart;
use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\ScheduleRule;
use App\Models\Screen;
use App\Models\Store;
use App\Services\DevicePairing;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| A screen's clock and its holding picture, and what the television is handed
|--------------------------------------------------------------------------
|
| A screen keeps no hours of its own — WHEN something plays is said once, on the
| file, inside the screen. What the screen carries is what the schedule has to be
| READ against: the timezone those wall-clock times are kept on, and the picture
| that fills an hour nothing was scheduled for.
|
| Chicago is UTC-5 in late March (daylight saving began on the 8th), so 18:00 UTC
| is one in the afternoon there, and 10:00 UTC is five in the morning.
|
*/

beforeEach(function () {
    $this->store = Store::factory()->create();
    $this->actor = createStoreUser($this->store, ['screen-view', 'screen-update']);
    $this->screen = Screen::factory()->create(['store_id' => $this->store->id, 'name' => 'Deli TV']);
    $this->hours = Daypart::factory()->between('07:00', '20:00')->create([
        'store_id' => $this->store->id, 'name' => 'Deli hours',
    ]);
    $this->welcome = Media::factory()->create(['store_id' => $this->store->id, 'title' => 'Welcome']);

    $this->actingAs($this->actor)->withSession(['current_store_id' => $this->store->id]);
});

test('a screen keeps its clock and its holding picture', function () {
    $this->putJson("/screens/{$this->screen->id}", [
        'name' => 'Deli TV',
        'orientation' => 'landscape',
        'timezone' => 'America/New_York',
        'default_media_id' => $this->welcome->id,
    ])->assertOk();

    $fresh = $this->screen->fresh();

    expect($fresh->timezone)->toBe('America/New_York');
    expect($fresh->default_media_id)->toBe($this->welcome->id);
});

test('a newly paired screen carries a sensible clock and nothing else', function () {
    // Paired the way a shop really adds one — the code on the television, typed into the panel — so
    // what is checked is what pairing leaves behind. The factory sets a timezone of its own.
    $installer = createStoreUser($this->store, ['screen-store'], 'Installer Role');
    $code = app(DevicePairing::class)->register()['code'];

    $id = $this->actingAs($installer)->withSession(['current_store_id' => $this->store->id])
        ->postJson('/screens/pair', ['code' => $code, 'mode' => 'new', 'name' => 'Till TV', 'orientation' => 'landscape'])
        ->assertOk()
        ->json('screen.id');

    $paired = Screen::findOrFail($id);

    expect($paired->timezone)->toBe(Screen::DEFAULT_TIMEZONE)
        ->and($paired->default_media_id)->toBeNull();
});

test('clearing the holding picture is a real instruction', function () {
    $this->screen->update(['default_media_id' => $this->welcome->id]);

    $this->putJson("/screens/{$this->screen->id}", [
        'name' => 'Deli TV', 'orientation' => 'landscape', 'default_media_id' => null,
    ])->assertOk();

    expect($this->screen->fresh()->default_media_id)->toBeNull();
});

test('renaming a screen leaves everything the schedule added alone', function () {
    // A caller that only renames must not silently reset the timezone — a key that
    // was never sent is not an instruction to clear it.
    $this->screen->update(['timezone' => 'Europe/London', 'default_media_id' => $this->welcome->id]);

    $this->putJson("/screens/{$this->screen->id}", [
        'name' => 'Front Window', 'orientation' => 'portrait',
    ])->assertOk();

    $fresh = $this->screen->fresh();

    expect($fresh->name)->toBe('Front Window');
    expect($fresh->timezone)->toBe('Europe/London');
    expect($fresh->default_media_id)->toBe($this->welcome->id);
});

test('a screen cannot hold another store\'s file as its default', function () {
    $theirFile = Media::factory()->create(['store_id' => Store::factory()->create()->id]);

    $this->putJson("/screens/{$this->screen->id}", [
        'name' => 'Deli TV', 'orientation' => 'landscape', 'default_media_id' => $theirFile->id,
    ])->assertStatus(422)->assertJsonValidationErrors('default_media_id');

    expect($this->screen->fresh()->default_media_id)->toBeNull();
});

test('a timezone the server does not know is refused', function () {
    $this->putJson("/screens/{$this->screen->id}", [
        'name' => 'Deli TV', 'orientation' => 'landscape', 'timezone' => 'Mars/Olympus_Mons',
    ])->assertStatus(422)->assertJsonValidationErrors('timezone');
});

test('a daypart in use by a playlist rule cannot be deleted — it has to be retired', function () {
    $owner = createStoreUser($this->store, ['daypart-destroy', 'daypart-update'], 'Hours Role');
    $item = PlaylistItem::create([
        'screen_id' => $this->screen->id, 'media_id' => $this->welcome->id,
        'position' => 0, 'duration_seconds' => 10,
    ]);
    $item->scheduleRules()->create(['daypart_id' => $this->hours->id]);

    // The foreign key is nullOnDelete, so this would not error — it would quietly set
    // a lunchtime poster playing all day, with nothing to show why.
    $this->actingAs($owner)->withSession(['current_store_id' => $this->store->id])
        ->deleteJson("/dayparts/{$this->hours->id}")
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    $this->assertDatabaseHas('dayparts', ['id' => $this->hours->id]);
    expect($item->fresh()->scheduleRules->first()->daypart_id)->toBe($this->hours->id);
});

test('a daypart nothing points at can still be deleted', function () {
    $owner = createStoreUser($this->store, ['daypart-destroy'], 'Hours Role');

    $this->actingAs($owner)->withSession(['current_store_id' => $this->store->id])
        ->deleteJson("/dayparts/{$this->hours->id}")
        ->assertOk();

    $this->assertDatabaseMissing('dayparts', ['id' => $this->hours->id]);
});

/*
|--------------------------------------------------------------------------
| What the television is handed
|--------------------------------------------------------------------------
*/

test('an hour nothing is scheduled for tells the television to go black', function () {
    $screen = Screen::factory()->withToken('tok')->create([
        'store_id' => $this->store->id, 'timezone' => 'America/Chicago',
    ]);
    $item = PlaylistItem::create([
        'screen_id' => $screen->id, 'media_id' => $this->welcome->id,
        'position' => 0, 'duration_seconds' => 10,
    ]);
    $item->scheduleRules()->create(['daypart_id' => $this->hours->id]);   // 07:00–20:00

    Carbon::setTestNow('2026-03-20 10:00:00');   // five in the morning in Chicago

    $quiet = $this->withHeader('Authorization', 'Bearer tok')
        ->getJson('/device/playlist')->assertOk();

    expect($quiet->json('blank'))->toBeTrue();
    expect($quiet->json('items'))->toBeEmpty();

    Carbon::setTestNow('2026-03-20 18:00:00');   // one in the afternoon

    $busy = $this->withHeader('Authorization', 'Bearer tok')
        ->getJson('/device/playlist')->assertOk();

    expect($busy->json('blank'))->toBeFalse();
    expect($busy->json('items'))->toHaveCount(1);

    // Going dark changes nothing about the (empty) item list, so blankness has to be
    // part of the version or the player would never notice it.
    expect($busy->json('version'))->not->toBe($quiet->json('version'));
});

test('a television switched on at two in the afternoon is handed the two-o\'clock playlist', function () {
    // The owner switches the set off at nine and back on at two. It asks the server
    // what to show, and the answer is for the moment it asked — the morning menu is
    // simply not in it.
    $screen = Screen::factory()->withToken('tok')->create([
        'store_id' => $this->store->id, 'timezone' => 'America/Chicago',
    ]);
    $breakfast = Media::factory()->create(['store_id' => $this->store->id, 'title' => 'Breakfast menu']);
    $lunch = Media::factory()->create(['store_id' => $this->store->id, 'title' => 'Lunch menu']);

    $morning = PlaylistItem::create([
        'screen_id' => $screen->id, 'media_id' => $breakfast->id, 'position' => 0, 'duration_seconds' => 10,
    ]);
    $morning->scheduleRules()->create([
        'daypart_id' => Daypart::factory()->between('07:00', '11:00')->create(['store_id' => $this->store->id])->id,
    ]);

    $afternoon = PlaylistItem::create([
        'screen_id' => $screen->id, 'media_id' => $lunch->id, 'position' => 1, 'duration_seconds' => 10,
    ]);
    $afternoon->scheduleRules()->create([
        'daypart_id' => Daypart::factory()->between('11:00', '16:00')->create(['store_id' => $this->store->id])->id,
    ]);

    // Nine in the morning, Chicago. The set is showing breakfast, then switched off.
    Carbon::setTestNow('2026-03-20 14:00:00');
    $atNine = $this->withHeader('Authorization', 'Bearer tok')->getJson('/device/playlist')->json('items');

    // Switched on again at two in the afternoon.
    Carbon::setTestNow('2026-03-20 19:00:00');
    $atTwo = $this->withHeader('Authorization', 'Bearer tok')->getJson('/device/playlist')->json('items');

    expect($atNine)->toHaveCount(1);
    expect($atNine[0]['url'])->toBe($breakfast->url);

    expect($atTwo)->toHaveCount(1);
    expect($atTwo[0]['url'])->toBe($lunch->url);
});

test('an item outside its schedule never reaches the device at all', function () {
    $screen = Screen::factory()->withToken('tok')->create([
        'store_id' => $this->store->id, 'timezone' => 'America/Chicago',
    ]);
    $lunch = Daypart::factory()->between('11:00', '15:00')->create(['store_id' => $this->store->id]);

    $item = PlaylistItem::create([
        'screen_id' => $screen->id, 'media_id' => $this->welcome->id,
        'position' => 0, 'duration_seconds' => 10,
    ]);
    $item->scheduleRules()->create([
        'daypart_id' => $lunch->id,
        'recurrence_type' => ScheduleRule::WEEKLY,
        'recurrence_weekdays' => [5],
    ]);

    // Friday lunchtime in Chicago.
    Carbon::setTestNow('2026-03-20 18:00:00');
    expect($this->withHeader('Authorization', 'Bearer tok')->getJson('/device/playlist')->json('items'))
        ->toHaveCount(1);

    // Friday evening: the rule says no, so the file is simply not sent. A cheap box
    // with a wrong clock cannot get this wrong, because it is never given the choice.
    Carbon::setTestNow('2026-03-20 23:00:00');
    expect($this->withHeader('Authorization', 'Bearer tok')->getJson('/device/playlist')->json('items'))
        ->toBeEmpty();
});

test('a gap in the schedule shows the holding picture rather than a black rectangle', function () {
    $holding = Media::factory()->create(['store_id' => $this->store->id, 'title' => 'Shop branding']);
    $screen = Screen::factory()->withToken('tok')->create([
        'store_id' => $this->store->id,
        'timezone' => 'America/Chicago',
        'default_media_id' => $holding->id,
    ]);

    $item = PlaylistItem::create([
        'screen_id' => $screen->id, 'media_id' => $this->welcome->id,
        'position' => 0, 'duration_seconds' => 10,
    ]);
    $item->scheduleRules()->create([
        'recurrence_type' => ScheduleRule::WEEKLY, 'recurrence_weekdays' => [5],
    ]);

    Carbon::setTestNow('2026-03-21 18:00:00');   // a Saturday

    $manifest = $this->withHeader('Authorization', 'Bearer tok')->getJson('/device/playlist')->assertOk();

    // It goes out as an ordinary item — the player needs no idea it is a fallback.
    expect($manifest->json('blank'))->toBeFalse();
    expect($manifest->json('items'))->toHaveCount(1);
    expect($manifest->json('items.0.url'))->toBe($holding->url);
    // Id 0, because no playlist row stands behind it.
    expect($manifest->json('items.0.id'))->toBe(0);
});

test('the schedule editor offers the screen’s own store’s dayparts, and names a retired one still in use', function () {
    // The platform sees every store's dayparts on their own page, but a rule on THIS screen can only use
    // this screen's store's (the playlist refuses the rest), so only those are offered.
    Daypart::factory()->create(['store_id' => Store::factory()->create()->id, 'name' => 'Another shop’s hours']);

    // Retired, and still used by a rule here: it keeps working, so the page must say what it is —
    // left out, the rule read "All day".
    $lunch = Daypart::factory()->between('11:00', '15:00')->create([
        'store_id' => $this->store->id, 'name' => 'Old lunch', 'is_retired' => true,
    ]);
    Daypart::factory()->create(['store_id' => $this->store->id, 'name' => 'Old breakfast', 'is_retired' => true]);

    $line = PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $this->welcome->id, 'position' => 0, 'duration_seconds' => 10]);
    $line->scheduleRules()->create(['recurrence_type' => ScheduleRule::DAILY, 'recurrence_interval' => 1, 'daypart_id' => $lunch->id]);

    $check = function () {
        $dayparts = collect($this->get("/screens/{$this->screen->id}")->assertOk()->viewData('dayparts'))->keyBy('name');

        expect($dayparts->keys()->sort()->values()->all())->toBe(['Deli hours', 'Old lunch'])
            ->and($dayparts['Deli hours']['retired'])->toBeFalse()
            ->and($dayparts['Old lunch']['retired'])->toBeTrue();
    };

    $check();

    $this->actingAs(createSuperAdmin());
    $this->flushSession();
    $check();
});
