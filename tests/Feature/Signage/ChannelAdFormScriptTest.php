<?php

use App\Models\Channel;
use Tests\Support\PageScript;

/*
|--------------------------------------------------------------------------
| A channel's Add Ad: its upload box and its late answers (the review of 2026-10-15)
|--------------------------------------------------------------------------
|
| The Upload way's box was reached through $refs — a ref inside the dialog, never found from Add Ad or an ad's Edit,
| outside it. Shut with Escape and Discard Changes, the dialog opened again saying "Uploaded. It is added when you save."
| while the form had forgotten the file ("Choose the file to upload." under a box showing one), and the upload stayed open
| on the server, counted to the organization's room. The box is found on the page now and emptied at every opening and
| shutting, an upload a save on its way carries is never given up under it, and a late answer — a save's or a removal's —
| acts on its own question alone.
|
*/

/** A channel's ads page (channel 5), a box `u1` comes into, and the steps of $then. */
function channelAdForm(string $then, bool $insideTheDialog = false): array
{
    $refs = $insideTheDialog ? '{ adUpload: { querySelector: () => box } }' : '{}';

    return PageScript::run(<<<JS
        import { registerChannelAds } from './resources/js/pages/channel-ads.js';

        const made = {};
        registerChannelAds({ data: (name, factory) => { made[name] = factory; } });
        const box = uploadBox('[dusk="channel-ad-dropzone"]');
        const page = asComponent(made.channelAds({ channelId: 5, maxImageSeconds: 300 }), { refs: {$refs} });
        axios.get = async () => ({ data: { media: [], currentPage: 1, lastPage: 1, on_playlists: 0, ads: [] } });

        /** Upload chosen, a picture sent up and every byte in. */
        const uploadOne = () => {
            page.setSource('upload');
            page.onPicked({ name: 'menu.png', size: 1000, type: 'image/png', meta: {} });
            box.data.uploads.push({ uploadId: 'u1', status: 'ready' });
            page.onUploadReady({ upload: 'u1', meta: {} });
        };

        {$then}
        JS);
}

test('Add Ad opened again after Escape starts with an empty box, and the file nobody saved is given up', function () {
    $result = channelAdForm(<<<'JS'
        page.openAdModal();
        uploadOne();
        // Escape, then Discard Changes; opened again with Add Ad, outside the dialog.
        page.openAdModal();

        report({ given: box.given, rows: box.data.uploads.length, picked: page.picked, uploaded: page.uploaded, source: page.source });
        JS);

    // Before: the box kept its "Uploaded" row, unreachable from Add Ad, and its upload stayed open on the server.
    expect($result)->toBe(['given' => ['u1'], 'rows' => 0, 'picked' => null, 'uploaded' => null, 'source' => 'library']);
});

test('a saved ad lets go of its upload without giving it up: the server has taken it', function () {
    $result = channelAdForm(<<<'JS'
        page.openAdModal();
        uploadOne();
        axios.post = async () => ({ data: { message: 'Ad added.', ads: [{ id: 1 }] } });
        await page.saveAd({ target: formOnPage() });
        await settle();

        report({ given: box.given, rows: box.data.uploads.length, ads: page.ads.length });
        JS, insideTheDialog: true);

    expect($result)->toBe(['given' => [], 'rows' => 0, 'ads' => 1]);
});

test('a slow save leaves the form opened since for another ad as it is, and keeps its upload meanwhile', function () {
    $result = channelAdForm(<<<'JS'
        page.openAdModal();
        uploadOne();
        const answer = later();
        axios.post = () => answer.promise;
        const form = formOnPage();
        const saving = page.saveAd({ target: form });

        form.shown = false;
        page.openAdModal({ id: 9, title: 'Lunch', type: 'image', duration_seconds: 10, starts_on: '', ends_on: '' });
        form.shown = true;
        page.form.title = 'Lunch, half typed';
        const givenWhileSaving = [...box.given];
        dispatched.length = 0;

        answer.reject(refusal(422, { errors: { title: ['That title is taken.'] } }));
        await saving;
        await settle();

        report({ givenWhileSaving, given: box.given, dispatched, editing: page.editingAd?.id, typed: page.form.title, errors: page.formErrors, toasts });
        JS);

    // Before: the refusal of the first ad was painted under Lunch's title.
    expect($result)->toBe([
        'givenWhileSaving' => [],
        'given' => ['u1'],
        'dispatched' => [],
        'editing' => 9,
        'typed' => 'Lunch, half typed',
        'errors' => [],
        'toasts' => [['message' => 'Not saved: That title is taken.', 'type' => 'error']],
    ]);
});

test('the box is held while a save carries its file, and free again once the answer is in or the form is shut', function () {
    $result = channelAdForm(<<<'JS'
        page.openAdModal();
        uploadOne();
        const before = page.holdsTheSavedUpload();
        const form = formOnPage();

        // A save on its way, refused while its form is still open: the box is held, then free to change the file.
        const first = later();
        axios.post = () => first.promise;
        const saving = page.saveAd({ target: form });
        const whileSaving = page.holdsTheSavedUpload();
        first.reject(refusal(422, { errors: { title: ['That title is taken.'] } }));
        await saving;
        await settle();
        const afterTheRefusal = page.holdsTheSavedUpload();

        // Saved again, and shut with Escape while it is on its way: the box lets go and takes the next file at once.
        const second = later();
        axios.post = () => second.promise;
        const savingAgain = page.saveAd({ target: form });
        const whileSavingAgain = page.holdsTheSavedUpload();
        form.shown = false;
        page.clearFile();  // what the dialog's own shutting does (channels/show.blade.php)
        const afterShutting = page.holdsTheSavedUpload();
        second.resolve({ data: { message: 'Ad added.', ads: [{ id: 1 }] } });
        await savingAgain;
        await settle();

        // An ad picked from the library carries no upload: nothing to hold.
        page.openAdModal();
        page.pick({ id: 40, title: 'Menu', type: 'image' });
        const third = later();
        axios.post = () => third.promise;
        const picking = page.saveAd({ target: formOnPage() });
        const pickedFromTheLibrary = page.holdsTheSavedUpload();
        third.resolve({ data: { message: 'Ad added.', ads: [{ id: 1 }, { id: 2 }] } });
        await picking;

        report({ before, whileSaving, afterTheRefusal, whileSavingAgain, afterShutting, pickedFromTheLibrary, given: box.given });
        JS);

    // Before: nothing held the box — its × or a new file gave the upload up while the save was reading it.
    expect($result)->toBe([
        'before' => false,
        'whileSaving' => true,
        'afterTheRefusal' => false,
        'whileSavingAgain' => true,
        'afterShutting' => false,
        'pickedFromTheLibrary' => false,
        'given' => [],
    ]);
});

test('a late removal never shuts the question asked since about another ad', function () {
    $result = channelAdForm(<<<'JS'
        const answer = later();
        axios.delete = () => answer.promise;
        page.confirmRemove({ id: 1, title: 'Breakfast' });
        const removing = page.removeAd();

        // Escape on the first question, then × on another ad.
        page.confirmRemove({ id: 2, title: 'Lunch' });
        dispatched.length = 0;

        answer.resolve({ data: { ads: [{ id: 2 }] } });
        await removing;

        report({ dispatched, asked: page.removingAd?.id, ads: page.ads.map((ad) => ad.id) });
        JS);

    // Before: the first answer shut Lunch's question and forgot it.
    expect($result)->toBe(['dispatched' => [], 'asked' => 2, 'ads' => [2]]);
});

test('the page lets the box go however the dialog shuts, and holds it under a save', function () {
    $channel = Channel::factory()->create(['organization_id' => null]);

    $this->actingAs(createSuperAdmin())->get("/channels/{$channel->id}")->assertOk()
        ->assertSee("\$watch('show', (open) => open || clearFile())", false)
        ->assertSee('x-bind:inert="holdsTheSavedUpload()"', false)
        ->assertDontSee('x-ref="adUpload"', false);
});
