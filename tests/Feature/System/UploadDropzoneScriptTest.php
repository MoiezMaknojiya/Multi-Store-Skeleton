<?php

use Tests\Support\PageScript;

/*
|--------------------------------------------------------------------------
| The uploader's own script, after the review of 2026-10-15 (docs/UPLOADS-SPEC.md)
|--------------------------------------------------------------------------
|
| What only the browser's side decides, run under Node (Tests\Support\PageScript): the place a file was dropped for — and the
| organization the page works in — is part of its id in the engine, so a file cut short on one library, or in one
| organization, and dropped again on another is an upload of its own rather
| than the first one carried on; and a form's upload the server gave up for good is forgotten by the form too, so a Save
| pressed again asks for a file instead of sending the upload that is gone.
|
*/

/** Ends the script with $value reported, past the engine's own requests still trying (Node has no server to send to). */
const DROPZONE_REPORT_AND_EXIT = 'const done = (value) => process.stdout.write(JSON.stringify(value) + "\n", () => process.exit(0));';

test('the same file dropped on two libraries is two uploads, and twice on one library is refused', function () {
    $result = PageScript::run(DROPZONE_REPORT_AND_EXIT.<<<'JS'
        import { registerUploadDropzone } from './resources/js/core/upload-dropzone.js';

        const made = {};
        registerUploadDropzone({ data: (name, factory) => { made[name] = factory; } });
        const box = asComponent(made.uploadDropzone({ purpose: 'media', mode: 'add', multiple: true, addUrl: '/media' }));

        const file = new File([new Uint8Array(10)], 'menu.jpg', { type: 'image/jpeg', lastModified: 1 });
        box.context = { library: 'platform', fields: {}, storage: null };
        await box.accept(file, box.placeNow());
        // A reload, another tab, or the Library list turned: the same file, for Alpha Mart's library.
        box.context = { library: '7', fields: { organization_id: '7' }, storage: null };
        await box.accept(file, box.placeNow());
        await box.accept(file, box.placeNow());

        const [platform, alpha, again] = box.uploads;
        done({
            statuses: box.uploads.map((row) => row.status),
            twoIds: platform.fileId !== null && alpha.fileId !== null && platform.fileId !== alpha.fileId,
            again: again.error,
        });
        JS);

    // Before: the place was not part of the file's id, so the second was "on its way already" — and after a reload it carried
    // on the platform's upload, which Alpha Mart's library refused.
    expect($result)->toBe([
        'statuses' => ['waiting', 'waiting', 'refused'],
        'twoIds' => true,
        'again' => 'This file is on its way already.',
    ]);
});

/**
 * The id the engine gives a file dropped on a page drawn for $session ("{user}:{organization}", the layout's session-context)
 * — a page loaded afresh, as after a reload. Uppy's tus fingerprint, what a reload carries an upload on from, is
 * "tus-{that id}-/uploads" (@uppy/tus getFingerprint).
 *
 * @param  array<string, mixed>  $context
 */
function fileIdOnAPageOf(string $session, string $purpose, array $context): string
{
    $page = json_encode(['session' => $session, 'purpose' => $purpose, 'context' => $context], JSON_THROW_ON_ERROR);

    return PageScript::run(DROPZONE_REPORT_AND_EXIT.<<<JS
        import { registerUploadDropzone } from './resources/js/core/upload-dropzone.js';

        const page = {$page};
        boxes['meta[name="session-context"]'] = { content: page.session };
        const made = {};
        registerUploadDropzone({ data: (name, factory) => { made[name] = factory; } });
        const box = asComponent(made.uploadDropzone({ purpose: page.purpose, mode: 'add', multiple: true, addUrl: '/media' }));
        box.context = page.context;

        await box.accept(new File([new Uint8Array(10)], 'menu.jpg', { type: 'image/jpeg', lastModified: 1 }), box.placeNow());
        done(box.uploads[0].status === 'waiting' ? box.uploads[0].fileId : box.uploads[0].error);
        JS);
}

test('the same file dropped in another organization of the same person is an upload of its own, and in the same one carries on', function (string $purpose, array $context) {
    // Person 3 works in organization 7, a reload cuts the upload short, they switch to organization 8 and drop the same file.
    $inAlpha = fileIdOnAPageOf('3:7', $purpose, $context);
    $inBeta = fileIdOnAPageOf('3:8', $purpose, $context);
    // Or they stay in organization 7 and drop it again: the upload carries on where it stopped.
    $inAlphaAgain = fileIdOnAPageOf('3:7', $purpose, $context);

    // Before: an organization's own person sends no organization with the upload (the server reads it from the session), so
    // the id was the same in both organizations and Beta Mart's drop carried on Alpha Mart's upload, which its door refused.
    expect($inAlpha)->toStartWith('uppy-menu')
        ->and($inBeta)->toStartWith('uppy-menu')
        ->and($inBeta)->not->toBe($inAlpha)
        ->and($inAlphaAgain)->toBe($inAlpha);
})->with([
    // An organization's own person: the Media page has no Library list, the Assets page no Owner list.
    'the Media page' => ['media', ['library' => null, 'fields' => [], 'storage' => null]],
    "the Ad Builder's shelf" => ['asset', ['organization' => null, 'fields' => [], 'storage' => null]],
]);

test("a form's upload the server gave up is forgotten by the form, its row saying why", function () {
    $result = PageScript::run(DROPZONE_REPORT_AND_EXIT.<<<'JS'
        import { registerUploadDropzone } from './resources/js/core/upload-dropzone.js';

        const made = {};
        registerUploadDropzone({ data: (name, factory) => { made[name] = factory; } });
        const box = asComponent(made.uploadDropzone({ purpose: 'channel', mode: 'form' }));
        box.uploads.push({ place: box.placeNow(), key: 'k1', uploadId: 'u1', status: 'ready', name: 'deal.jpg', size: 10 });

        // The page's own save of another upload, and a refusal of another field: nothing to forget.
        box.noteGivenUp({ config: { data: JSON.stringify({ upload: 'u2' }) }, response: { data: { errors: {}, upload_given_up: true } } });
        box.noteGivenUp({ config: { data: JSON.stringify({ upload: 'u1' }) }, response: { data: { errors: { seconds: ['Too many.'] } } } });
        const before = dispatched.filter((event) => event.name === 'upload-cleared').length;

        // Its own, refused for what the file is.
        box.noteGivenUp({
            config: { data: JSON.stringify({ upload: 'u1' }) },
            response: { data: { errors: { file: ['Only images (JPG, PNG, GIF, WEBP) and videos (MP4, WEBM) can be uploaded.'] }, upload_given_up: true } },
        });

        done({
            before,
            cleared: dispatched.filter((event) => event.name === 'upload-cleared').map((event) => event.detail),
            row: { status: box.uploads[0].status, uploadId: box.uploads[0].uploadId, error: box.uploads[0].error, retry: box.canRetry(box.uploads[0]) },
        });
        JS);

    // Before: the row said why, but the form kept the upload, and Save sent it again to be told only that it was gone.
    expect($result)->toBe([
        'before' => 0,
        'cleared' => [['givenUp' => true]],
        'row' => [
            'status' => 'refused',
            'uploadId' => null,
            'error' => 'Only images (JPG, PNG, GIF, WEBP) and videos (MP4, WEBM) can be uploaded.',
            'retry' => false,
        ],
    ]);
});
