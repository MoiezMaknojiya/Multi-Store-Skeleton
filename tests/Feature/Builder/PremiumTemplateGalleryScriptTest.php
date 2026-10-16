<?php

use Tests\Support\PageScript;

/*
|--------------------------------------------------------------------------
| Use This Template's late answer (the review of 2026-10-15)
|--------------------------------------------------------------------------
|
| Use This Template acted on whatever was open by the time its answer came: a copy pressed on a slow line, the gallery shut
| with Escape and opened again for the other shape, put its refusal ("Not enough storage: this needs …") above the tiles of a
| gallery it was not asked from; and a copy that came after the gallery was shut took the page to the editor from under
| whatever was open by then. Its answer now acts on its own gallery alone (core/form-asked.js): shut or opened since, the copy
| is said in a toast and the list takes it, and a refusal names the template it is about.
|
*/

/** The Ads page's component inside an organization, its gallery on the page, and the steps of $then. */
function adsPage(string $then): array
{
    return PageScript::run(<<<JS
        import { registerAdsTable } from './resources/js/tables/ads-table.js';

        const made = {};
        registerAdsTable({ data: (name, factory) => { made[name] = factory; } });
        const page = asComponent(made.adsTable({}));
        const refreshed = [];
        page.refreshInPlace = async () => { refreshed.push(true); };

        const assigned = [];
        window.location = { assign: (url) => assigned.push(url) };
        axios.get = async () => ({ data: { templates: [], locked: false } });

        // The gallery, and a Use This Template inside it (the press carries the button, whose gallery it finds).
        const gallery = formOnPage();
        const useOf = (detail = 1) => ({ detail, currentTarget: { closest: (selector) => (selector === '[dusk="premium-templates"]' ? gallery : null) } });

        const breakfast = { id: 7, name: 'Breakfast Menu' };
        const noRoom = 'Not enough storage: this needs 5 MB, and Alpha Mart has 1 MB left of its 512 MB.';

        {$then}
        JS);
}

test('a copy answered after its gallery was shut and opened again stays on the page: said in a toast, and the list takes it', function () {
    $result = adsPage(<<<'JS'
        page.chooseOrientation('landscape');
        page.openTemplates({ detail: 1 });
        const answer = later();
        axios.post = () => answer.promise;
        const using = page.useTemplate(breakfast, useOf());

        // Escape; Create Ad again, Portrait, Premium Template.
        gallery.shown = false;
        page.chooseOrientation('portrait');
        page.openTemplates({ detail: 1 });
        gallery.shown = true;

        answer.resolve({ data: { message: 'Template copied to your ads', redirect: '/builder/99/edit' } });
        await using;

        report({ assigned, toasts, refreshed: refreshed.length, using: page.usingTemplateId });
        JS);

    // Before: the page left for the editor from under the gallery opened since.
    expect($result)->toBe([
        'assigned' => [],
        'toasts' => [['message' => 'Template copied to your ads', 'type' => 'success']],
        'refreshed' => 1,
        'using' => null,
    ]);
});

test('a copy refused after its gallery was shut is said in a toast naming the template, never above another gallery\'s tiles', function () {
    $result = adsPage(<<<'JS'
        page.chooseOrientation('landscape');
        page.openTemplates({ detail: 1 });
        const answer = later();
        axios.post = () => answer.promise;
        const using = page.useTemplate(breakfast, useOf());

        gallery.shown = false;
        page.chooseOrientation('portrait');
        page.openTemplates({ detail: 1 });
        await settle();
        gallery.shown = true;

        answer.reject(refusal(422, { errors: { template: [noRoom] } }));
        await using;

        report({ refusal: page.templateRefusal, toasts });
        JS);

    // Before: the refusal about Breakfast Menu stood above the portrait gallery's tiles.
    expect($result)->toBe([
        'refusal' => '',
        'toasts' => [['message' => 'Breakfast Menu was not copied: Not enough storage: this needs 5 MB, and Alpha Mart has 1 MB left of its 512 MB.', 'type' => 'error']],
    ]);
});

test('while its gallery is still the one asked a copy opens the editor, a search typed meanwhile included, and a refusal stays in it', function () {
    $result = adsPage(<<<'JS'
        page.chooseOrientation('landscape');
        page.openTemplates({ detail: 1 });

        // A search typed while the copy is on its way: the gallery is the same one.
        const answer = later();
        axios.post = () => answer.promise;
        const using = page.useTemplate(breakfast, useOf());
        page.templateSearch = 'lunch';
        page.loadTemplates();
        answer.resolve({ data: { message: 'Template copied to your ads', redirect: '/builder/99/edit' } });
        await using;

        axios.post = async () => { throw refusal(422, { errors: { template: [noRoom] } }); };
        page.usingTemplateId = null;
        await page.useTemplate(breakfast, useOf());

        report({ assigned, refusal: page.templateRefusal, toasts });
        JS);

    expect($result)->toBe([
        'assigned' => ['/builder/99/edit'],
        'refusal' => 'Not enough storage: this needs 5 MB, and Alpha Mart has 1 MB left of its 512 MB.',
        'toasts' => [],
    ]);
});
