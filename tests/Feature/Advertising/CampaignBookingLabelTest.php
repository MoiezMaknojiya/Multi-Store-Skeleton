<?php

use App\Models\Campaign;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Screen;
use Tests\Support\PageScript;

/*
|--------------------------------------------------------------------------
| What ticking a screen does to its break, edit or add (the review of 2026-10-15)
|--------------------------------------------------------------------------
|
| Beside every screen of the campaign form stands "35s of 60s booked", amber once the break is oversold. The server's
| booked_seconds holds every switched-on campaign — the one being edited too, as it was saved, on the screens it was saved
| on. Editing, the label used to add nothing at all: a screen newly ticked never showed the campaign's seconds (an oversold
| break missed), and one unticked still counted them. The form's own seconds now count exactly once on each screen ticked
| (campaigns-table.js, bookedWith).
|
*/

/** The campaign form, with screen 1 (20 s sold to others) and screen 2 (50 s sold to others) and $setUp run on it. */
function bookingLabels(string $setUp): array
{
    return PageScript::run(<<<JS
        import { registerCampaignsTable } from './resources/js/tables/campaigns-table.js';

        const made = {};
        registerCampaignsTable({ data: (name, factory) => { made[name] = factory; } });
        const table = asComponent(made.campaignsTable({ maxBreakSeconds: 60 }));
        axios.get = async () => ({ data: { screens: [], max_break_seconds: 60 } });

        const one = { id: 1, carries_ads: true, booked_seconds: 20 };
        const two = { id: 2, carries_ads: true, booked_seconds: 50 };

        {$setUp}

        report({
            one: table.bookedLabel(one), two: table.bookedLabel(two),
            oneOversold: table.isOversold(one), twoOversold: table.isOversold(two),
        });
        JS);
}

/** Campaign 9 as the list sends it: on screen 1, switched on or not, a picture of 15 seconds unless said otherwise. */
function savedCampaign(bool $active = true, string $type = 'image', int $seconds = 15): string
{
    $active = $active ? 'true' : 'false';

    return "{ id: 9, name: 'Ramadan', type: '{$type}', is_active: {$active}, duration_seconds: {$seconds}, play_seconds: {$seconds}, screens: [{ id: 1 }] }";
}

test('adding, a screen ticked counts the advert\'s seconds and one left unticked does not', function () {
    $labels = bookingLabels(<<<'JS'
        table.openCampaignModal();
        table.form.duration_seconds = 15;
        table.toggleScreen(1);
        JS);

    expect($labels)->toBe(['one' => '35s of 60s booked', 'two' => '50s of 60s booked', 'oneOversold' => false, 'twoOversold' => false]);
});

test('editing, a screen newly ticked counts the campaign once: an oversold break is said', function () {
    // Screen 1's 35 already holds the campaign's own 15 (as saved); screen 2's 50 does not.
    $campaign = savedCampaign();
    $labels = bookingLabels(<<<JS
        one.booked_seconds = 35;
        table.openCampaignModal({$campaign});
        table.toggleScreen(2);
        JS);

    // Before: 35 and 50, and screen 2 not oversold — the 15 the campaign adds there was never counted.
    expect($labels)->toBe(['one' => '35s of 60s booked', 'two' => '65s of 60s booked', 'oneOversold' => false, 'twoOversold' => true]);
});

test('editing, a screen unticked no longer counts the campaign, and new seconds replace the saved ones', function () {
    $campaign = savedCampaign();

    $unticked = bookingLabels(<<<JS
        one.booked_seconds = 35;
        table.openCampaignModal({$campaign});
        table.toggleScreen(1);
        JS);

    $longer = bookingLabels(<<<JS
        one.booked_seconds = 35;
        table.openCampaignModal({$campaign});
        table.form.duration_seconds = 30;
        JS);

    // Before: 35 both times — the saved 15 stayed on a screen it was taken off, and 30 typed changed nothing.
    expect($unticked['one'])->toBe('20s of 60s booked')
        ->and($longer['one'])->toBe('50s of 60s booked');
});

test('editing a paused campaign, which the server counts nowhere, counts it on the screens ticked', function () {
    $campaign = savedCampaign(active: false);
    $labels = bookingLabels(<<<JS
        table.openCampaignModal({$campaign});
        JS);

    // Before: 20 — a paused campaign switched back on would join a break the label said it was not in.
    expect($labels['one'])->toBe('35s of 60s booked');
});

test('editing a video, its own length counts, and a new file\'s length replaces it', function () {
    $campaign = savedCampaign(type: 'video', seconds: 42);

    $kept = bookingLabels(<<<JS
        one.booked_seconds = 62;
        table.openCampaignModal({$campaign});
        JS);

    $replaced = bookingLabels(<<<JS
        one.booked_seconds = 62;
        table.openCampaignModal({$campaign});
        table.onPicked({ name: 'short.mp4', size: 1000, type: 'video/mp4', meta: { duration_seconds: 12 } });
        JS);

    expect($kept)->toMatchArray(['one' => '62s of 60s booked', 'oneOversold' => true])
        ->and($replaced)->toMatchArray(['one' => '32s of 60s booked', 'oneOversold' => false]);
});

test('the form reads the same seconds the server counts: a campaign\'s play_seconds is its share of booked_seconds', function () {
    // The label takes the saved campaign's play_seconds back off booked_seconds, so the two must be one number.
    $organization = Organization::factory()->create(['accepts_network_ads' => true]);
    $screen = Screen::factory()->create(['organization_id' => $organization->id, 'accepts_network_ads' => true]);
    // A picture saved under the least (it plays for ten), a video with its own length, and a paused one counted nowhere.
    $picture = Campaign::factory()->create(['type' => Media::TYPE_IMAGE, 'duration_seconds' => 4]);
    $video = Campaign::factory()->video(33)->create();
    $paused = Campaign::factory()->create(['is_active' => false]);
    collect([$picture, $video, $paused])->each(fn (Campaign $campaign) => $campaign->screens()->sync([$screen->id]));

    $this->actingAs(createSuperAdmin());
    $booked = collect($this->getJson('/campaigns/screens')->assertOk()->json('screens'))->firstWhere('id', $screen->id)['booked_seconds'];
    $listed = collect($this->getJson('/campaigns/data')->assertOk()->json('campaigns'))->keyBy('id');

    expect($booked)->toBe(43)
        ->and($listed[$picture->id]['play_seconds'] + $listed[$video->id]['play_seconds'])->toBe($booked);
});
