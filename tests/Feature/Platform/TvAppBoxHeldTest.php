<?php

use Tests\Support\PageScript;

/*
|--------------------------------------------------------------------------
| Developer → TV App's box while the file is asked about (the review of 2026-10-15)
|--------------------------------------------------------------------------
|
| While Publish Version is out, the box is held: its × or a new file would give up the upload the publish is making the
| version of. And when the server gives an upload up for good — another app, a file that is not the TV app — the box tells
| the page (upload-cleared with givenUp), the form forgets the file, and the reason the server gave stays under it.
|
*/

test('the box is held while a publish is out, and let go once it is over', function () {
    $result = PageScript::run(<<<'JS'
        import { registerDeveloperPage } from './resources/js/pages/developer-page.js';

        const made = {};
        registerDeveloperPage({ data: (name, factory) => { made[name] = factory; } });
        uploadBox('[dusk="tv-app-dropzone"]', [{ uploadId: 'u1', status: 'ready' }]);
        const page = asComponent(made.developerPage());
        page.refreshInPlace = async () => {};

        const publish = later();
        axios.post = async (url) => url === '/developer/app/inspect'
            ? { data: { version_read: true, version_name: '1.1', version_code: 2, current: null } }
            : publish.promise;

        page.onReady({ upload: 'u1' });
        await settle();
        const beforeHeld = page.holdsTheSavedUpload();

        const out = page.publish({ target: formOnPage() });
        await settle();
        const whileOut = page.holdsTheSavedUpload();

        publish.resolve({ data: { message: 'Version 1.1 is published.' } });
        await out;

        report({ beforeHeld, whileOut, after: page.holdsTheSavedUpload() });
        JS);

    // Before: nothing held the box, so its × gave the upload up under the publish.
    expect($result)->toBe(['beforeHeld' => false, 'whileOut' => true, 'after' => false]);
});

test('an upload the server gave up while the file was being read keeps the reason under the file', function () {
    $result = PageScript::run(<<<'JS'
        import { registerDeveloperPage } from './resources/js/pages/developer-page.js';

        const made = {};
        registerDeveloperPage({ data: (name, factory) => { made[name] = factory; } });
        uploadBox('[dusk="tv-app-dropzone"]', [{ uploadId: 'u1', status: 'ready' }]);
        const page = asComponent(made.developerPage());

        // The box hears the refusal first (its interceptor) and tells the page, then the page's own question hears it.
        axios.post = async () => {
            page.clearFile({ givenUp: true });

            throw refusal(422, {
                message: 'This is another app (com.example.game), not the TV app.',
                errors: { file: ['This is another app (com.example.game), not the TV app.'] },
                upload_given_up: true,
            });
        };

        page.onReady({ upload: 'u1' });
        await settle();

        report({ errors: page.formErrors, uploaded: page.uploaded, checking: page.checking, canPublish: page.canPublish() });
        JS);

    // Before: forgetting the file moved the question on, and its answer — the reason — was dropped.
    expect($result)->toBe([
        'errors' => ['file' => ['This is another app (com.example.game), not the TV app.']],
        'uploaded' => null,
        'checking' => false,
        'canPublish' => false,
    ]);
});

test('the TV App page holds its box by the page, and hears what the box says when it lets the file go', function () {
    $this->actingAs(createSuperAdmin())->get('/developer')->assertOk()
        ->assertSee('x-bind:inert="holdsTheSavedUpload()"', false)
        ->assertSee('x-on:upload-cleared="clearFile($event.detail)"', false);
});
