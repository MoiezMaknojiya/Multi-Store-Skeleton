<?php

use Tests\Support\PageScript;

/*
|--------------------------------------------------------------------------
| The Screens page's own script (the review of 2026-10-15)
|--------------------------------------------------------------------------
|
| Two things only the browser's side decides. A pairing answer that comes back slowly acted on whatever dialog
| was open by then: shut and another opened (Replace Device on another row, a fresh Add Screen), it closed that
| one or painted its own refusal on it. And "Adverts On / Off — every screen in this organization at once" sent
| the rows of the page in sight: fifty at most, narrowed by the search and the Online/Offline filter.
|
*/

/** The Screens page's component (one row on the page), the pairing form on the page, and the steps of $then. */
function screensPage(string $then): array
{
    return PageScript::run(<<<JS
        import { registerScreensTable } from './resources/js/tables/screens-table.js';

        const made = {};
        registerScreensTable({ data: (name, factory) => { made[name] = factory; } });
        const page = asComponent(made.screensTable({ organizations: null, orientations: {}, organizationAcceptsAds: true }));
        const form = formOnPage();
        boxes['[dusk="screen-pair-form"]'] = form;
        page.items = [{ id: 3, name: 'Counter TV', orientation: 'landscape', timezone: 'America/Chicago' }];

        const sent = [];
        axios.get = async () => ({ data: { screens: page.items, total: 1, currentPage: 1, lastPage: 1 } });

        {$then}
        JS);
}

test('a refused pairing that answers after its dialog was shut and another opened leaves the new one alone', function () {
    $result = screensPage(<<<'JS'
        const answer = later();
        axios.post = (url, body) => { sent.push(body); return answer.promise; };

        page.openPairModal();
        Object.assign(page.pairForm, { code: 'K7M4XQ', name: 'Window TV', orientation: 'landscape' });
        const pairing = page.pairScreen();

        // Shut on a slow line, and Replace Device opened on the row: the person is typing another code.
        form.shown = false;
        page.closePairModal();
        page.openReplaceModal(page.items[0]);
        form.shown = true;
        page.pairForm.code = 'ABCDEF';
        const before = dispatched.length;

        answer.reject(refusal(422, { message: 'That code is not valid.', errors: { code: ['That code is not valid, or it has expired.'] } }));
        await pairing;

        report({
            errors: page.formErrors,
            closed: dispatched.slice(before).filter((event) => event.name === 'close-modal').length,
            code: page.pairForm.code,
            mode: page.pairForm.mode,
            toasts,
            saving: page.saving,
        });
        JS);

    // Before: the refusal painted "That code is not valid…" under the Replace Device dialog's own code.
    expect($result)->toBe([
        'errors' => [],
        'closed' => 0,
        'code' => 'ABCDEF',
        'mode' => 'replace',
        'toasts' => [['message' => 'That code is not valid, or it has expired.', 'type' => 'error']],
        'saving' => false,
    ]);
});

test('a pairing that succeeds after its dialog was shut and another opened never shuts the new one', function () {
    $result = screensPage(<<<'JS'
        const answer = later();
        axios.post = () => answer.promise;

        page.openPairModal();
        Object.assign(page.pairForm, { code: 'K7M4XQ', name: 'Window TV', orientation: 'landscape' });
        const pairing = page.pairScreen();

        form.shown = false;
        page.openReplaceModal(page.items[0]);
        form.shown = true;
        const before = dispatched.length;

        answer.resolve({ data: {} });
        await pairing;
        await settle();

        report({ closed: dispatched.slice(before).filter((event) => event.name === 'close-modal').length, mode: page.pairForm.mode, toasts: toasts.map((toast) => toast.type) });
        JS);

    // Before: the Replace Device dialog was shut by the Add Screen's answer.
    expect($result)->toBe(['closed' => 0, 'mode' => 'replace', 'toasts' => ['success']]);
});

test('a pairing answered while its own dialog is open still acts on it', function () {
    $result = screensPage(<<<'JS'
        axios.post = async () => { throw refusal(422, { errors: { code: ['That code is not valid, or it has expired.'] } }); };

        page.openPairModal();
        Object.assign(page.pairForm, { code: 'K7M4XQ', name: 'Window TV', orientation: 'landscape' });
        await page.pairScreen();
        const refused = page.formErrors;

        axios.post = async () => ({ data: {} });
        const before = dispatched.length;
        await page.pairScreen();
        await settle();

        report({ refused, closed: dispatched.slice(before).filter((event) => event.name === 'close-modal').length, toasts: toasts.length });
        JS);

    expect($result)->toBe(['refused' => ['code' => ['That code is not valid, or it has expired.']], 'closed' => 1, 'toasts' => 1]);
});

test('adverts on for every screen asks the server for every screen, not the rows in sight', function () {
    $result = screensPage(<<<'JS'
        axios.put = async (url, body) => { sent.push({ url, body }); return { data: { message: 'Updated 61 screens' } }; };

        page.allScreenAds(true);
        await settle();
        page.setScreenAds([3], false);
        await settle();

        report({ sent, toasts });
        JS);

    // Before: { screen_ids: [3], accepts: true } — the one row the page showed.
    expect($result)->toBe([
        'sent' => [
            ['url' => '/network-ads/screens', 'body' => ['all' => true, 'accepts' => true]],
            ['url' => '/network-ads/screens', 'body' => ['screen_ids' => [3], 'accepts' => false]],
        ],
        'toasts' => [['message' => 'Updated 61 screens', 'type' => 'success']],
    ]);
});
