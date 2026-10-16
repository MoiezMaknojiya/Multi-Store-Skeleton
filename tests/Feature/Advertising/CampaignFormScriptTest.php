<?php

use Tests\Support\PageScript;

/*
|--------------------------------------------------------------------------
| The campaign form's advert box, its late answers and Select All (the review of 2026-10-15)
|--------------------------------------------------------------------------
|
| The advert's upload box was reached through $refs — a ref inside the dialog, which is the dialog's own and is never found
| from Add Campaign or a row's Edit, outside it. Shut with Escape and Discard Changes, the form opened again saying
| "Uploaded. It is added when you save." over a form that had forgotten the file: Add said "Choose the advert to upload."
| under a box showing one, Edit silently kept the old advert, and the upload stayed open on the server. The box is found on
| the page now, emptied at every opening and every shutting, and an upload a save on its way carries is never given up
| under it. A save's late answer acts on its own form alone; and Select All is one press for a double click.
|
*/

/** The campaign form, a box holding the advert `u1` that came, and the steps of $then. */
function campaignForm(string $then, bool $insideTheDialog = false): array
{
    $refs = $insideTheDialog ? '{ advertUpload: { querySelector: () => box } }' : '{}';

    return PageScript::run(<<<JS
        import { registerCampaignsTable } from './resources/js/tables/campaigns-table.js';

        const made = {};
        registerCampaignsTable({ data: (name, factory) => { made[name] = factory; } });
        const box = uploadBox('[dusk="campaign-dropzone"]');
        const table = asComponent(made.campaignsTable({ maxBreakSeconds: 60 }), { refs: {$refs} });
        table.allScreens = [{ id: 1 }];
        table.refreshInPlace = async () => {};
        axios.get = async () => ({ data: { screens: [{ id: 1 }], max_break_seconds: 60 } });

        /** The form filled in as a person would: a name, and the advert that came into the box. */
        const fillIn = () => {
            table.form.name = 'Ramadan';
            table.form.duration_seconds = 10;
            table.onPicked({ name: 'ad.png', size: 1000, type: 'image/png', meta: {} });
            box.data.uploads.push({ uploadId: 'u1', status: 'ready' });
            table.onUploadReady({ upload: 'u1', meta: {} });
        };

        {$then}
        JS);
}

test('the form opened again after Escape starts with an empty box, and the advert nobody saved is given up', function () {
    $result = campaignForm(<<<'JS'
        table.openCampaignModal();
        fillIn();
        // Escape, then Discard Changes: the dialog shuts by itself. Opened again with Add Campaign, outside the dialog:
        table.openCampaignModal();

        report({ cleared: box.cleared, given: box.given, rows: box.data.uploads.length, picked: table.picked, uploaded: table.uploaded });
        JS);

    // Before: the box was never reached from Add Campaign — it kept its "Uploaded" row and the upload stayed open.
    expect($result)->toBe(['cleared' => 2, 'given' => ['u1'], 'rows' => 0, 'picked' => null, 'uploaded' => null]);
});

test('a successful save lets go of its own upload without giving it up: the server has taken it', function () {
    $result = campaignForm(<<<'JS'
        table.openCampaignModal();
        fillIn();
        axios.post = async () => ({ data: { message: 'Campaign saved.' } });
        await table.saveCampaign({ target: formOnPage() });
        await settle();

        report({ given: box.given, rows: box.data.uploads.length, toasts, closed: dispatched.some((each) => each.name === 'close-modal') });
        JS, insideTheDialog: true);

    // Before: closing after the save gave the saved upload up, a request answered 404 once the server had forgotten it.
    expect($result)->toBe(['given' => [], 'rows' => 0, 'toasts' => [['message' => 'Campaign saved.', 'type' => 'success']], 'closed' => true]);
});

test('a slow save keeps its upload while the form is shut and opened again, and its late answer leaves the new form alone', function () {
    $result = campaignForm(<<<'JS'
        table.openCampaignModal();
        fillIn();
        const answer = later();
        axios.post = () => answer.promise;
        const form = formOnPage();
        const saving = table.saveCampaign({ target: form });

        // Escape, Discard Changes, then Edit on another campaign — and a name typed there.
        form.shown = false;
        table.openCampaignModal({ id: 7, name: 'Eid', type: 'image', is_active: true, duration_seconds: 10, play_seconds: 10, screens: [] });
        form.shown = true;
        table.form.name = 'Eid, half typed';
        const givenWhileSaving = [...box.given];
        dispatched.length = 0;

        answer.resolve({ data: { message: 'Campaign saved.' } });
        await saving;
        await settle();

        report({ givenWhileSaving, given: box.given, dispatched, editing: table.editingItem?.id, typed: table.form.name });
        JS);

    // Before: the late answer shut Eid's form, the typing lost.
    expect($result)->toBe(['givenWhileSaving' => [], 'given' => [], 'dispatched' => [], 'editing' => 7, 'typed' => 'Eid, half typed']);
});

test('a slow save refused after its form was shut gives its upload up and says why in a toast', function () {
    $result = campaignForm(<<<'JS'
        table.openCampaignModal();
        fillIn();
        const answer = later();
        axios.post = () => answer.promise;
        const form = formOnPage();
        const saving = table.saveCampaign({ target: form });
        form.shown = false;
        table.clearFile();  // what the dialog's own shutting does (campaigns/index.blade.php)

        answer.reject(refusal(422, { errors: { name: ['A campaign of that name already runs.'] } }));
        await saving;
        await settle();

        report({ given: box.given, errors: table.formErrors, toasts });
        JS);

    expect($result)->toBe([
        'given' => ['u1'],
        'errors' => [],
        'toasts' => [['message' => 'Ramadan was not saved: A campaign of that name already runs.', 'type' => 'error']],
    ]);
});

test('the box is held while a save carries its advert, and free again once the answer is in or the form is shut', function () {
    $result = campaignForm(<<<'JS'
        table.openCampaignModal();
        fillIn();
        const before = table.holdsTheSavedUpload();
        const form = formOnPage();

        // A save on its way, refused while its form is still open: the box is held, then free to change the file.
        const first = later();
        axios.post = () => first.promise;
        const saving = table.saveCampaign({ target: form });
        const whileSaving = table.holdsTheSavedUpload();
        first.reject(refusal(422, { errors: { name: ['A campaign of that name already runs.'] } }));
        await saving;
        await settle();
        const afterTheRefusal = table.holdsTheSavedUpload();

        // Saved again, and shut with Escape while it is on its way: the box lets go and takes the next file at once.
        const second = later();
        axios.post = () => second.promise;
        const savingAgain = table.saveCampaign({ target: form });
        const whileSavingAgain = table.holdsTheSavedUpload();
        form.shown = false;
        table.clearFile();  // what the dialog's own shutting does (campaigns/index.blade.php)
        const afterShutting = table.holdsTheSavedUpload();
        second.resolve({ data: { message: 'Campaign saved.' } });
        await savingAgain;
        await settle();

        // An edit that keeps its advert carries no upload: nothing to hold.
        table.openCampaignModal({ id: 7, name: 'Eid', type: 'image', is_active: true, duration_seconds: 10, play_seconds: 10, screens: [] });
        const third = later();
        axios.post = () => third.promise;
        const keeping = table.saveCampaign({ target: formOnPage() });
        const keepingItsAdvert = table.holdsTheSavedUpload();
        third.resolve({ data: { message: 'Campaign saved.' } });
        await keeping;

        report({ before, whileSaving, afterTheRefusal, whileSavingAgain, afterShutting, keepingItsAdvert, given: box.given });
        JS);

    // Before: nothing held the box — its × or a new file gave the advert up while the save was reading it.
    expect($result)->toBe([
        'before' => false,
        'whileSaving' => true,
        'afterTheRefusal' => false,
        'whileSavingAgain' => true,
        'afterShutting' => false,
        'keepingItsAdvert' => false,
        'given' => [],
    ]);
});

test('Select All chooses every screen of an organization once for a double click, and a second click clears them', function () {
    $result = PageScript::run(<<<'JS'
        import { registerCampaignsTable } from './resources/js/tables/campaigns-table.js';

        const made = {};
        registerCampaignsTable({ data: (name, factory) => { made[name] = factory; } });
        const table = asComponent(made.campaignsTable({ maxBreakSeconds: 60 }));
        const group = { id: 3, screens: [{ id: 1, carries_ads: true }, { id: 2, carries_ads: true }, { id: 4, carries_ads: false }] };

        // A person's double click: two presses, the second carrying its click count.
        table.toggleOrganization(group, { detail: 1 });
        table.toggleOrganization(group, { detail: 2 });
        const afterADoubleClick = [...table.form.screen_ids];

        table.toggleOrganization(group, { detail: 1 });

        report({ afterADoubleClick, afterAnotherClick: table.form.screen_ids });
        JS);

    // Before: the double click's second press cleared what its first had chosen.
    expect($result)->toBe(['afterADoubleClick' => [1, 2], 'afterAnotherClick' => []]);
});

test('the page wires it: the box lets go however the dialog shuts, is held under a save, and Select All hears the press it came with', function () {
    $this->actingAs(createSuperAdmin())->get('/campaigns')->assertOk()
        ->assertSee("\$watch('show', (open) => open || clearFile())", false)
        ->assertSee('x-bind:inert="holdsTheSavedUpload()"', false)
        ->assertSee('toggleOrganization(group, $event)', false)
        ->assertDontSee('x-ref="advertUpload"', false);
});

test('Select All and Clear All are named by the words they show (WCAG 2.5.3), with the organization beside them', function () {
    $page = $this->actingAs(createSuperAdmin())->get('/campaigns')->assertOk()->getContent();

    // The whole button: its x-show holds a "=>", so not up to the first ">".
    expect(preg_match('/<button[^<]*dusk="\'campaign-organization-all-\' \+ group\.id"[^<]*<\/button>/', $page, $button))->toBe(1);

    preg_match('/x-bind:aria-label="\(organizationAllChosen\(group\) \? \'([^\']*)\' : \'([^\']*)\'\) \+ group\.name"/', $button[0], $named);
    preg_match('/x-text="organizationAllChosen\(group\) \? \'([^\']*)\' : \'([^\']*)\'"/', $button[0], $shown);

    // Before: "Clear every screen of …" and "Choose every screen of …", holding neither "Clear All" nor "Select All".
    expect($shown)->toHaveCount(3)
        ->and($named[1] ?? '')->toStartWith($shown[1])
        ->and($named[2] ?? '')->toStartWith($shown[2]);
});
