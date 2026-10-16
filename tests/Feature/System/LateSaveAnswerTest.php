<?php

use Tests\Support\PageScript;

/*
|--------------------------------------------------------------------------
| A save's answer is about its own question (the review of 2026-10-15)
|--------------------------------------------------------------------------
|
| Every listing's Add and Edit dialog saves through crud-table-base's saveItem. On a slow line the person could press Save on
| one row, shut the dialog with Escape and Discard Changes, open Edit on another row and type — and the first row's answer
| then shut the second dialog, the typing lost, or put the first row's refusal under the second row's fields. The answer now
| acts on the form it was sent from alone, while that form is still open and not opened since (core/form-asked.js).
|
*/

/** The shared table, a form of one name, and a save sent from it for row A whose answer the script gives later. */
function lateSaveScript(string $then): string
{
    return <<<JS
        import { createCrudTable } from './resources/js/core/crud-table-base.js';

        const table = asComponent(createCrudTable({
            fetchUrl: '/things/data', dataKey: 'things', entityLabel: 'thing', formModalName: 'thing-form',
            defaultForm: { name: '' }, mapItemToForm: (item) => ({ name: item.name }),
        })());
        table.refreshInPlace = async () => {};

        const answer = later();
        axios.put = () => answer.promise;

        const form = formOnPage();
        table.openFormModal({ id: 1, name: 'Alpha' });
        table.form.name = 'Alpha 2';
        const saving = table.saveItem({ target: form });

        // Escape, then Discard Changes: the dialog shuts by itself — the page is not told.
        form.shown = false;
        {$then}
        JS;
}

test('the answer to a save of a form shut since, and opened again for another row, leaves that one open and as typed', function () {
    $result = PageScript::run(lateSaveScript(<<<'JS'
        dispatched.length = 0;
        table.openFormModal({ id: 2, name: 'Beta' });
        form.shown = true;
        table.form.name = 'Beta, half typed';

        answer.resolve({ data: { message: 'Thing updated.' } });
        await saving;
        await settle();

        report({ dispatched, editing: table.editingItem?.id, typed: table.form.name, toasts });
        JS));

    // Before: the late answer closed Beta's dialog (close-modal) and forgot whom it was editing.
    expect($result['dispatched'])->toBe([['name' => 'open-modal', 'detail' => 'thing-form']])
        ->and($result['editing'])->toBe(2)
        ->and($result['typed'])->toBe('Beta, half typed')
        // The save itself went through, and says so.
        ->and($result['toasts'])->toBe([['message' => 'Thing updated.', 'type' => 'success']]);
});

test('a refusal of a form shut since is said in a toast, never under the fields of the form open now', function () {
    $result = PageScript::run(lateSaveScript(<<<'JS'
        table.openFormModal({ id: 2, name: 'Beta' });
        form.shown = true;

        answer.reject(refusal(422, { message: 'The name has already been taken.', errors: { name: ['The name has already been taken.'] } }));
        await saving;
        await settle();

        report({ errors: table.formErrors, editing: table.editingItem?.id, toasts });
        JS));

    // Before: Alpha's refusal was painted under Beta's name field.
    expect($result['errors'])->toBe([])
        ->and($result['editing'])->toBe(2)
        ->and($result['toasts'])->toBe([['message' => 'Alpha was not saved: The name has already been taken.', 'type' => 'error']]);
});

test('a refusal of a dialog shut and not opened again is said in a toast too, not left under a hidden field', function () {
    $result = PageScript::run(lateSaveScript(<<<'JS'
        answer.reject(refusal(422, { errors: { name: ['The name has already been taken.'] } }));
        await saving;
        await settle();

        report({ errors: table.formErrors, toasts });
        JS));

    expect($result['errors'])->toBe([])
        ->and($result['toasts'])->toBe([['message' => 'Alpha was not saved: The name has already been taken.', 'type' => 'error']]);
});

test('while its form is still open the answer acts on it as ever: shut on success, the refusal under its field', function () {
    $result = PageScript::run(<<<'JS'
        import { createCrudTable } from './resources/js/core/crud-table-base.js';

        const table = asComponent(createCrudTable({
            fetchUrl: '/things/data', dataKey: 'things', entityLabel: 'thing', formModalName: 'thing-form',
            defaultForm: { name: '' }, mapItemToForm: (item) => ({ name: item.name }),
        })());
        table.refreshInPlace = async () => {};
        const form = formOnPage();

        axios.put = async () => { throw refusal(422, { errors: { name: ['The name has already been taken.'] } }); };
        table.openFormModal({ id: 1, name: 'Alpha' });
        await table.saveItem({ target: form });
        const refused = table.formErrors;

        axios.put = async () => ({ data: { message: 'Thing updated.' } });
        dispatched.length = 0;
        await table.saveItem({ target: form });

        report({ refused, dispatched, editing: table.editingItem, toasts });
        JS);

    expect($result['refused'])->toBe(['name' => ['The name has already been taken.']])
        ->and($result['dispatched'])->toBe([['name' => 'close-modal', 'detail' => 'thing-form']])
        ->and($result['editing'])->toBeNull()
        ->and($result['toasts'])->toBe([['message' => 'Thing updated.', 'type' => 'success']]);
});
