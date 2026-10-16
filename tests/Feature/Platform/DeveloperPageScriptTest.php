<?php

use Tests\Support\PageScript;

/*
|--------------------------------------------------------------------------
| Developer → TV App after Publish Version (the review of 2026-10-15)
|--------------------------------------------------------------------------
|
| Publish Version empties the form for the next version, upload box and all. The box was emptied with its own clear(),
| which gives a finished upload up on the server — the very upload the version had just been made from, which the server
| had used and forgotten, so every publish ended with a DELETE answered 404. The box now lets that row go without giving
| it up (emptyTheBox in core/form-asked.js), as the campaign form and a channel's Add Ad do after their saves.
|
*/

test('publishing a version empties the box without giving up the upload the version was made from', function () {
    $result = PageScript::run(<<<'JS'
        import { registerDeveloperPage } from './resources/js/pages/developer-page.js';

        const made = {};
        registerDeveloperPage({ data: (name, factory) => { made[name] = factory; } });
        // The box, inside the page's own element (the ref the old code looked it up through).
        const box = uploadBox('[dusk="tv-app-dropzone"]', [{ uploadId: 'u1', status: 'ready' }]);
        const page = asComponent(made.developerPage(), {
            refs: { appUpload: { querySelector: (selector) => document.querySelector(selector) } },
        });
        page.refreshInPlace = async () => {};

        // Every byte in, and the server read the version from the file.
        const sent = [];
        axios.post = async (url, payload) => {
            sent.push({ url, payload });

            return url === '/developer/app/inspect'
                ? { data: { version_read: true, version_name: '1.1', version_code: 2, current: null } }
                : { data: { message: 'Version 1.1 is published.' } };
        };
        page.onReady({ upload: 'u1' });
        await settle();

        await page.publish({ target: formOnPage() });

        report({ published: sent.at(-1).payload.upload, cleared: box.cleared, given: box.given, uploaded: page.uploaded, toasts });
        JS);

    // Before: the box's clear() gave u1 up (a DELETE the server answered 404).
    expect($result)->toBe([
        'published' => 'u1',
        'cleared' => 1,
        'given' => [],
        'uploaded' => null,
        'toasts' => [['message' => 'Version 1.1 is published.', 'type' => 'success']],
    ]);
});

test('the TV App page finds its box on the page, not through a ref', function () {
    $this->actingAs(createSuperAdmin())->get('/developer')->assertOk()
        ->assertSee('dusk="tv-app-dropzone"', false)
        ->assertDontSee('x-ref="appUpload"', false);
});
