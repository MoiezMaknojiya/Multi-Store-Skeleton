<?php

use App\Models\Daypart;
use App\Models\Media;
use App\Models\ScheduleRule;
use App\Models\Screen;
use App\Models\Store;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Schedules travel with the playlist save
|--------------------------------------------------------------------------
|
| A playlist is written by replacing the WHOLE list in one PUT. That is why an
| item's schedule rules are part of that same request rather than living behind
| an endpoint of their own: stored separately, the cascade would delete every
| rule on the screen the next time somebody merely reordered two items.
|
*/

/** The body the playlist endpoint expects, with a fresh version by default. */
function playlistBody(Screen $screen, array $items): array
{
    return ['items' => $items, 'version' => $screen->playlistFingerprint()];
}

/** One ten-second item, optionally scheduled. */
function itemBody(Media $media, array $rules = []): array
{
    return ['media_id' => $media->id, 'duration_seconds' => 10, 'rules' => $rules];
}

beforeEach(function () {
    $this->store = Store::factory()->create();
    $this->actor = createStoreUser($this->store, ['screen-view', 'screen-playlist']);
    $this->screen = Screen::factory()->create(['store_id' => $this->store->id]);
    $this->poster = Media::factory()->create(['store_id' => $this->store->id, 'title' => 'Poster']);
    $this->other = Media::factory()->create(['store_id' => $this->store->id, 'title' => 'Other']);
    $this->lunch = Daypart::factory()->between('11:00', '15:00')->create(['store_id' => $this->store->id]);

    $this->actingAs($this->actor)->withSession(['current_store_id' => $this->store->id]);
});

test('a schedule is saved with the playlist and read back with it', function () {
    $this->putJson("/screens/{$this->screen->id}/playlist", playlistBody($this->screen, [
        itemBody($this->poster, [[
            'daypart_id' => $this->lunch->id,
            'recurrence_type' => ScheduleRule::WEEKLY,
            'recurrence_weekdays' => [5],
            'recurrence_interval' => 1,
        ]]),
    ]))->assertOk();

    $rules = $this->getJson("/screens/{$this->screen->id}/playlist")->assertOk()->json('items.0.rules');

    expect($rules)->toHaveCount(1);
    expect($rules[0]['daypart_id'])->toBe($this->lunch->id);
    expect($rules[0]['recurrence_type'])->toBe('weekly');
    expect($rules[0]['recurrence_weekdays'])->toBe([5]);
});

test('reordering the playlist does not throw the schedules away', function () {
    // The regression this whole design exists to prevent: the save replaces the
    // whole list, so rules stored anywhere but IN the request would be cascaded
    // away by an ordinary drag of one row.
    $this->putJson("/screens/{$this->screen->id}/playlist", playlistBody($this->screen, [
        itemBody($this->poster, [['daypart_id' => $this->lunch->id]]),
        itemBody($this->other),
    ]))->assertOk();

    $items = $this->getJson("/screens/{$this->screen->id}/playlist")->json('items');

    // Swap them and save exactly what the page is holding.
    $this->putJson("/screens/{$this->screen->id}/playlist", [
        'items' => [
            itemBody($this->other),
            itemBody($this->poster, [['daypart_id' => $this->lunch->id]]),
        ],
        'version' => $this->screen->playlistFingerprint(),
    ])->assertOk();

    $after = $this->getJson("/screens/{$this->screen->id}/playlist")->json('items');

    expect($after[0]['title'])->toBe('Other');
    expect($after[1]['title'])->toBe('Poster');
    expect($after[1]['rules'])->toHaveCount(1);
    expect($items[0]['rules'])->toHaveCount(1);
});

test('changing only a schedule is enough to make a stale save conflict', function () {
    $this->putJson("/screens/{$this->screen->id}/playlist", playlistBody($this->screen, [
        itemBody($this->poster),
    ]))->assertOk();

    $stale = $this->screen->playlistFingerprint();

    // A colleague sets the item's hours.
    $this->putJson("/screens/{$this->screen->id}/playlist", playlistBody($this->screen, [
        itemBody($this->poster, [['daypart_id' => $this->lunch->id]]),
    ]))->assertOk();

    // Without the rules in the fingerprint this would go through and silently erase
    // the hours they just set, with nothing to show that anything was lost.
    $this->putJson("/screens/{$this->screen->id}/playlist", [
        'items' => [itemBody($this->poster)],
        'version' => $stale,
    ])->assertStatus(409);

    expect($this->screen->fresh()->playlistItems->first()->scheduleRules)->toHaveCount(1);
});

test('saving the same schedule twice is not a conflict', function () {
    $rule = [['daypart_id' => $this->lunch->id, 'recurrence_type' => ScheduleRule::WEEKLY, 'recurrence_weekdays' => [5]]];

    $this->putJson("/screens/{$this->screen->id}/playlist", playlistBody($this->screen, [
        itemBody($this->poster, $rule),
    ]))->assertOk();

    // Two people now hold this version. The fingerprint is content-based on purpose:
    // re-saving an identical list — its schedule included — has lost nobody anything,
    // so the first save keeps the version as it was, and the second, sent with the
    // version from before the first, is no conflict either.
    $version = $this->getJson("/screens/{$this->screen->id}/playlist")->json('version');
    $sameAgain = ['items' => [itemBody($this->poster, $rule)], 'version' => $version];

    $this->putJson("/screens/{$this->screen->id}/playlist", $sameAgain)
        ->assertOk()
        ->assertJsonPath('version', $version);

    $this->putJson("/screens/{$this->screen->id}/playlist", $sameAgain)->assertOk();

    expect($this->screen->fresh()->playlistItems->first()->scheduleRules)->toHaveCount(1);
});

test('a daypart from another store cannot be pinned to this screen\'s playlist', function () {
    $theirs = Daypart::factory()->create(['store_id' => Store::factory()->create()->id]);

    $this->putJson("/screens/{$this->screen->id}/playlist", playlistBody($this->screen, [
        itemBody($this->poster, [['daypart_id' => $theirs->id]]),
    ]))->assertStatus(422)->assertJsonValidationErrors('items');

    expect($this->screen->fresh()->playlistItems)->toHaveCount(0);
});

test('each repeat type is made to bring the fields it needs', function () {
    $post = fn (array $rule) => $this->putJson("/screens/{$this->screen->id}/playlist",
        playlistBody($this->screen, [itemBody($this->poster, [$rule])]));

    $post(['recurrence_type' => ScheduleRule::WEEKLY])
        ->assertStatus(422)->assertJsonValidationErrors('items.0.rules.0.recurrence_weekdays');

    $post(['recurrence_type' => ScheduleRule::MONTHLY_DAY])
        ->assertStatus(422)->assertJsonValidationErrors('items.0.rules.0.recurrence_monthday');

    $post(['recurrence_type' => ScheduleRule::MONTHLY_WEEKDAY])
        ->assertStatus(422)->assertJsonValidationErrors('items.0.rules.0.recurrence_ordinal');

    $post(['starts_on' => '2026-03-22', 'ends_on' => '2026-03-20'])
        ->assertStatus(422)->assertJsonValidationErrors('items.0.rules.0.ends_on');
});

test('the fields of the other repeat types are dropped, not carried around', function () {
    // A rule that used to be "monthly on the 21st" and is now "every Friday" must not
    // keep a 21 in its pocket — the next person to read the row would be misled.
    $this->putJson("/screens/{$this->screen->id}/playlist", playlistBody($this->screen, [
        itemBody($this->poster, [[
            'recurrence_type' => ScheduleRule::WEEKLY,
            'recurrence_weekdays' => [5],
            'recurrence_monthday' => 21,
            'recurrence_ordinal' => 3,
        ]]),
    ]))->assertOk();

    $rule = ScheduleRule::firstOrFail();

    expect($rule->recurrence_weekdays)->toBe([5]);
    expect($rule->recurrence_monthday)->toBeNull();
    expect($rule->recurrence_ordinal)->toBeNull();
});

test('the preview says when the item would actually play', function () {
    $response = $this->postJson("/screens/{$this->screen->id}/playlist/preview", [
        'rules' => [[
            'daypart_id' => $this->lunch->id,
            'recurrence_type' => ScheduleRule::WEEKLY,
            'recurrence_weekdays' => [5],
        ]],
        'days' => 14,
    ])->assertOk();

    $dates = collect($response->json('occurrences'))->pluck('date');

    expect($dates)->not->toBeEmpty();
    // Every one of them is a Friday, and each carries the window it opens.
    foreach ($dates as $date) {
        expect(CarbonImmutable::parse($date)->dayOfWeekIso)->toBe(5);
    }
    expect($response->json('occurrences.0.start'))->toBe('11:00');
});

test('the preview merges several rules rather than listing each separately', function () {
    $evening = Daypart::factory()->between('16:00', '20:00')->create(['store_id' => $this->store->id]);

    $occurrences = $this->postJson("/screens/{$this->screen->id}/playlist/preview", [
        'rules' => [
            ['daypart_id' => $this->lunch->id, 'recurrence_type' => ScheduleRule::WEEKLY, 'recurrence_weekdays' => [5]],
            ['daypart_id' => $evening->id, 'recurrence_type' => ScheduleRule::WEEKLY, 'recurrence_weekdays' => [5]],
        ],
        'days' => 7,
    ])->assertOk()->json('occurrences');

    // The item plays if ANY rule says yes, so one Friday shows both of its windows —
    // and they come back in order, not interleaved at random.
    $starts = collect($occurrences)->pluck('start')->all();

    expect($starts)->toContain('11:00', '16:00');
    expect($starts)->toBe(collect($starts)->sort()->values()->all());
});

test('a repeat with no start date previews the same way saving it would behave', function () {
    // "Every 2 weeks" has to be every-2-weeks FROM something. A saved rule anchors on
    // its creation date; the preview anchors on today, so the two agree. Without that
    // the preview would quietly show every week.
    //
    // Wednesday 4 March 2026, noon on the screen's clock (Chicago). A count alone cannot
    // tell the anchor apart — any fortnight holds seven of fourteen days — so the dates are.
    $this->travelTo('2026-03-04 18:00:00');
    $fortnightly = [
        'recurrence_type' => ScheduleRule::WEEKLY,
        'recurrence_weekdays' => [1, 2, 3, 4, 5, 6, 7],
        'recurrence_interval' => 2,
    ];

    $dates = collect($this->postJson("/screens/{$this->screen->id}/playlist/preview", [
        'rules' => [$fortnightly],
        'days' => 14,
    ])->assertOk()->json('occurrences'))->pluck('date')->all();

    // This week from today to Sunday, then not the week after, then the Monday and
    // Tuesday of the week after that — the last two days of the fortnight.
    expect($dates)->toBe([
        '2026-03-04', '2026-03-05', '2026-03-06', '2026-03-07', '2026-03-08',
        '2026-03-16', '2026-03-17',
    ]);

    // Saved today, the rule anchors on today, and plays on exactly those days.
    $this->putJson("/screens/{$this->screen->id}/playlist", playlistBody($this->screen, [
        itemBody($this->poster, [$fortnightly]),
    ]))->assertOk();

    $saved = ScheduleRule::firstOrFail()->occurrences($this->screen->localTime(), 14);

    expect(collect($saved)->pluck('date')->all())->toBe($dates);
});

test('a preview cannot be built against another store\'s hours', function () {
    $theirs = Daypart::factory()->create(['store_id' => Store::factory()->create()->id]);

    $this->postJson("/screens/{$this->screen->id}/playlist/preview", [
        'rules' => [['daypart_id' => $theirs->id]],
    ])->assertStatus(422);
});

test('the whole playlist, schedules included, copies onto other screens', function () {
    $target = Screen::factory()->create(['store_id' => $this->store->id, 'name' => 'Window TV']);

    // The target already has something, which the copy is going to replace.
    $this->putJson("/screens/{$target->id}/playlist", playlistBody($target, [itemBody($this->other)]))->assertOk();

    $this->putJson("/screens/{$this->screen->id}/playlist", playlistBody($this->screen, [
        itemBody($this->poster, [['daypart_id' => $this->lunch->id, 'recurrence_type' => ScheduleRule::WEEKLY, 'recurrence_weekdays' => [5]]]),
    ]))->assertOk();

    $this->postJson("/screens/{$this->screen->id}/playlist/copy", [
        'target_screen_ids' => [$target->id],
    ])->assertOk();

    $copied = $this->getJson("/screens/{$target->id}/playlist")->json('items');

    expect($copied)->toHaveCount(1);
    expect($copied[0]['title'])->toBe('Poster');            // the old item is gone
    expect($copied[0]['rules'])->toHaveCount(1);
    expect($copied[0]['rules'][0]['recurrence_weekdays'])->toBe([5]);
});

test('the copy targets say how many items each screen would lose', function () {
    $target = Screen::factory()->create(['store_id' => $this->store->id, 'name' => 'Window TV']);
    $this->putJson("/screens/{$target->id}/playlist", playlistBody($target, [
        itemBody($this->other), itemBody($this->poster),
    ]))->assertOk();

    $screens = $this->getJson("/screens/{$this->screen->id}/playlist/copy-targets")->assertOk()->json('screens');

    // The screen being copied FROM is never a target for itself.
    expect(collect($screens)->pluck('id')->all())->toBe([$target->id]);
    expect($screens[0]['playlist_items_count'])->toBe(2);
});

test('a playlist cannot be copied onto another store\'s screen', function () {
    $theirs = Screen::factory()->create(['store_id' => Store::factory()->create()->id]);

    $this->putJson("/screens/{$this->screen->id}/playlist", playlistBody($this->screen, [
        itemBody($this->poster),
    ]))->assertOk();

    $this->postJson("/screens/{$this->screen->id}/playlist/copy", [
        'target_screen_ids' => [$theirs->id],
    ])->assertStatus(422)->assertJsonValidationErrors('target_screen_ids');

    expect($theirs->fresh()->playlistItems)->toHaveCount(0);
});

test('changing playlists is what gates the schedule, the preview and the copy', function () {
    $viewer = createStoreUser($this->store, ['screen-view'], 'Viewer Role');

    $this->actingAs($viewer)->withSession(['current_store_id' => $this->store->id]);

    $this->postJson("/screens/{$this->screen->id}/playlist/preview", ['rules' => []])->assertForbidden();
    $this->getJson("/screens/{$this->screen->id}/playlist/copy-targets")->assertForbidden();
    $this->postJson("/screens/{$this->screen->id}/playlist/copy", ['target_screen_ids' => [1]])->assertForbidden();
});
