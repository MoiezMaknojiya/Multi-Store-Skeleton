<?php

use Tests\Support\PageScript;

/*
|--------------------------------------------------------------------------
| A screen's playlist page, its own script (the review of 2026-10-15)
|--------------------------------------------------------------------------
|
| The schedule window's Select All / Clear All for Copy to Other Lines turned twice at one double click; the
| playlist's total time counted a draft's seconds while "N playing" left the draft out; and the window's
| preview named no line, so a repeat with no Starting date was previewed from today while its save would keep
| the day the line's repeat already counted from.
|
*/

/** The playlist page's component with three lines on it, and the steps of $then. */
function playlistPage(string $then): array
{
    return PageScript::run(<<<JS
        import { registerScreenPlaylist } from './resources/js/pages/screen-playlist.js';

        const made = {};
        registerScreenPlaylist({ data: (name, factory) => { made[name] = factory; } });
        const page = asComponent(made.screenPlaylist({ screenId: 7, canEdit: true }));
        page.items = [
            { key: 1, media_id: 11, title: 'Poster', type: 'image', duration_seconds: 10, rules: [] },
            { key: 2, media_id: 12, title: 'Menu', type: 'html', runs_own_length: true, duration_seconds: 20, is_draft: true, rules: [] },
            { key: 3, media_id: null, channel_id: 5, title: 'GAMA', type: 'channel', pass_seconds: 30, locked: false, rules: [] },
        ];

        {$then}
        JS);
}

test('Select All for Copy to Other Lines selects once at a double click', function () {
    $result = playlistPage(<<<'JS'
        page.scheduleIndex = 0;

        // A mouse that sends two: the second press of the multi-click carries detail 2.
        page.toggleEveryCopyLine({ detail: 1 });
        page.toggleEveryCopyLine({ detail: 2 });
        const selected = [...page.copyToKeys];

        // Two presses apart still turn it and back.
        page.toggleEveryCopyLine({ detail: 1 });

        report({ selected, after: page.copyToKeys });
        JS);

    // Before: the second press cleared what the first had selected.
    expect($result)->toBe(['selected' => [2, 3], 'after' => []]);
});

test('the playlist’s time counts the lines that play, as "N playing" does', function () {
    $result = playlistPage(<<<'JS'
        const withADraft = page.summary();
        page.items[2].locked = true;
        const withALock = page.summary();
        page.items.forEach((item) => { item.locked = false; item.is_draft = false; });

        report({ withADraft, withALock, allPlaying: page.summary() });
        JS);

    // Before: "3 items · 2 playing · 1 min" — the draft's twenty seconds counted with the rest.
    expect($result)->toBe([
        'withADraft' => '3 items · 2 playing · 40 secs',
        'withALock' => '3 items · 1 playing · 10 secs',
        'allPlaying' => '3 items · 1 min',
    ]);
});

test('the schedule window’s preview names the line, so it counts from the day the save keeps', function () {
    $result = playlistPage(<<<'JS'
        const sent = [];
        axios.post = async (url, body) => { sent.push({ url, body }); return { data: { occurrences: [] } }; };

        page.openSchedule(0);
        page.addRule();
        Object.assign(page.scheduleRules[0], { day_mode: 'repeat', recurrence_type: 'weekly', recurrence_interval: 2, recurrence_weekdays: [1] });
        page.refreshPreview();
        await new Promise((done) => setTimeout(done, 400));

        page.closeSchedule();
        page.openSchedule(2);
        page.addRule();
        page.refreshPreview();
        await new Promise((done) => setTimeout(done, 400));

        report(sent.map(({ url, body }) => ({ url, media_id: body.media_id, channel_id: body.channel_id, days: body.days })));
        JS);

    expect($result)->toBe([
        ['url' => '/screens/7/playlist/preview', 'media_id' => 11, 'channel_id' => null, 'days' => 7],
        ['url' => '/screens/7/playlist/preview', 'media_id' => null, 'channel_id' => 5, 'days' => 7],
    ]);
});
