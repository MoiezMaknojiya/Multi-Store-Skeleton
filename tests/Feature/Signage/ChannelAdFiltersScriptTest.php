<?php

use Tests\Support\PageScript;

/*
|--------------------------------------------------------------------------
| A channel's ads, filtered (owner, 2026-10-23)
|--------------------------------------------------------------------------
|
| "sub se phele channel k ander 'Starts later' aur 'Running' ka filter ya sorting dalo. ya month ka filter dalo … bhohot
| mushkil ho rae ha": 36 GAMA ads of two months in one list. The list now filters by status, by month and by the words of
| a title — which ads are shown, never the order they play in: each row keeps its place number, an arrow moves an ad past
| the next one shown and the ads the filter hides keep their places, and the address keeps the filters.
|
*/

/** A channel's ads page holding $ads, its address $address, and the steps of $then. */
function channelAdList(string $then, string $address = ''): array
{
    return PageScript::run(<<<JS
        import { registerChannelAds } from './resources/js/pages/channel-ads.js';

        const written = [];
        window.location = { pathname: '/channels/1', search: '{$address}', hash: '' };
        window.history = {
            state: null,
            replaceState: (state, title, url) => {
                written.push(url);
                window.location.search = url.includes('?') ? url.slice(url.indexOf('?')) : '';
            },
        };

        const made = {};
        registerChannelAds({ data: (name, factory) => { made[name] = factory; } });
        const page = asComponent(made.channelAds({ channelId: 1, maxImageSeconds: 300 }));

        const ad = (id, title, status, starts_on = null, ends_on = null) => ({ id, title, status, starts_on, ends_on, type: 'html', play_seconds: 15 });
        const ads = [
            ad(1, 'GAMA Ghost Energy', 'running', '2026-10-01', '2026-10-31'),
            ad(2, 'GAMA Reign & Bang', 'scheduled', '2026-11-01', '2026-11-30'),
            ad(3, 'GAMA Red Bull 12oz', 'running', '2026-10-01', '2026-10-31'),
            ad(4, 'GAMA Red Bull Winter', 'scheduled', '2026-11-01', '2026-11-30'),
            ad(5, 'Store Hours', 'running'),
            ad(6, 'September Deal', 'ended', '2026-09-01', '2026-09-30'),
        ];
        const sent = [];
        axios.get = async () => ({ data: { ads: structuredClone(ads), storage: null } });
        axios.put = async (url, body) => {
            sent.push(body.ad_ids);
            const byId = Object.fromEntries(page.ads.map((one) => [one.id, one]));

            return { data: { message: 'Order saved', ads: body.ad_ids.map((id) => byId[id]) } };
        };
        const shown = () => page.shownAds().map((one) => one.id);

        {$then}
        JS);
}

test('the Status, Month and search filters choose the ads shown, each count read under the other filters', function () {
    $result = channelAdList(<<<'JS'
        await page.load();
        const all = shown();

        page.filterStatus = 'scheduled';
        const startsLater = shown();

        page.filterStatus = '';
        page.filterMonth = '2026-11';
        // November: its two ads, and the one with no dates, which runs every month.
        const november = shown();
        const novemberCounts = { running: page.statusCount('running'), scheduled: page.statusCount('scheduled'), ended: page.statusCount('ended') };

        page.filterMonth = '';
        page.search = 'red BULL';
        const searched = shown();

        page.filterStatus = 'running';
        const months = page.months().map((month) => [month.key, month.count]);

        report({ all, startsLater, november, novemberCounts, searched, both: shown(), months, offers: page.offersMonths() });
        JS);

    expect($result)->toBe([
        'all' => [1, 2, 3, 4, 5, 6],
        'startsLater' => [2, 4],
        'november' => [2, 4, 5],
        'novemberCounts' => ['running' => 1, 'scheduled' => 2, 'ended' => 0],
        'searched' => [3, 4],
        'both' => [3],
        // From the first month an ad names to the last; each count under Running and "red bull".
        'months' => [['2026-09', 0], ['2026-10', 1], ['2026-11', 0]],
        'offers' => true,
    ]);
});

test('an arrow under a filter moves the ad past the next one shown, and the hidden ads keep their places', function () {
    $result = channelAdList(<<<'JS'
        await page.load();

        page.filterStatus = 'running';
        // Shown: 1, 3, 5. The first shown has no way up, the last none down.
        const ends = { firstUp: page.neighbourOf(page.ads[0], -1), lastDown: page.neighbourOf(page.ads[4], 1) };

        await page.move(page.ads[2], -1);
        const places = page.ads.map((one) => [one.id, page.placeOf(one)]);

        // With no filter, an arrow is the swap it always was.
        page.filterStatus = '';
        await page.move(page.ads.find((one) => one.id === 6), -1);

        report({ ends, sent, places });
        JS);

    expect($result)->toBe([
        'ends' => ['firstUp' => null, 'lastDown' => null],
        // Ad 3 goes above ad 1, the ad shown before it; 2 and 4, hidden, keep their places.
        'sent' => [[3, 1, 2, 4, 5, 6], [3, 1, 2, 4, 6, 5]],
        'places' => [[3, 1], [1, 2], [2, 3], [4, 4], [5, 5], [6, 6]],
    ]);
});

test('the address keeps the filters that are on, and leaves out what it does not allow', function () {
    $result = channelAdList(<<<'JS'
        page.readFilters();
        const read = { status: page.filterStatus, month: page.filterMonth, search: page.search };

        page.filterMonth = '';
        page.search = '  Bull ';
        page.writeFilters();
        page.clearFilters();
        page.search = '';
        page.writeFilters();

        report({ read, written });
        JS, '?status=scheduled&month=2026-11&search=Red%20Bull&page=2');

    expect($result)->toBe([
        'read' => ['status' => 'scheduled', 'month' => '2026-11', 'search' => 'Red Bull'],
        'written' => ['/channels/1?status=scheduled&search=Bull&page=2', '/channels/1?page=2'],
    ]);
});

test('a status or a month the address bends is not taken, and a month no ad runs in is forgotten once the ads are in', function () {
    $bent = channelAdList(<<<'JS'
        page.readFilters();
        report({ status: page.filterStatus, month: page.filterMonth });
        JS, '?status=paused&month=2026-13');

    $gone = channelAdList(<<<'JS'
        page.readFilters();
        await page.load();
        report({ month: page.filterMonth, shown: shown() });
        JS, '?month=2027-05');

    expect($bent)->toBe(['status' => '', 'month' => ''])
        ->and($gone)->toBe(['month' => '', 'shown' => [1, 2, 3, 4, 5, 6]]);
});

test('an ad saved where the filters hide it says so in its toast', function () {
    $result = channelAdList(<<<'JS'
        await page.load();
        page.filterStatus = 'running';

        page.openAdModal();
        page.chosen = { id: 40, type: 'html', duration_seconds: 15, title: 'GAMA Snapple' };
        axios.post = async () => ({ data: { message: 'Ad added', ads: [...page.ads, ad(7, 'GAMA Snapple', 'scheduled', '2026-11-01', '2026-11-30')] } });
        await page.saveAd({ target: formOnPage() });
        await settle();

        page.openAdModal();
        page.chosen = { id: 41, type: 'html', duration_seconds: 15, title: 'GAMA Poppi' };
        axios.post = async () => ({ data: { message: 'Ad added', ads: [...page.ads, ad(8, 'GAMA Poppi', 'running', '2026-10-01', '2026-10-31')] } });
        await page.saveAd({ target: formOnPage() });
        await settle();

        report(toasts);
        JS);

    expect($result)->toBe([
        ['message' => 'Ad added. The filters hide it.', 'type' => 'success'],
        ['message' => 'Ad added', 'type' => 'success'],
    ]);
});
