<?php

use Tests\Support\PageScript;

/*
|--------------------------------------------------------------------------
| The Roles page: a list that did not come, and a save's late answer (the review of 2026-10-15)
|--------------------------------------------------------------------------
|
| A list that could not be loaded said "No roles yet." — over roles that exist — with a toast to refresh the page. It says
| so where the roles would be now, with Try Again, as every hand-written list does (rule 02, the audit of 2026-10-13, item
| 1). And a role saved on a slow line, its form shut and opened again for another role, no longer shuts that one or paints
| its refusal under it.
|
*/

/** The Roles page's component (the platform's), and the steps of $then. */
function rolesPage(string $then): array
{
    return PageScript::run(<<<JS
        import { registerRolesPage } from './resources/js/pages/roles-page.js';

        const made = {};
        registerRolesPage({ data: (name, factory) => { made[name] = factory; } });
        const page = asComponent(made.rolesPage({ isPlatform: true }));

        {$then}
        JS);
}

test('a list of roles that did not come says so, with Try Again, and a refresh after a change keeps its rows', function () {
    $result = rolesPage(<<<'JS'
        axios.get = async () => { throw refusal(500, { message: 'Server Error' }); };
        await page.fetch();
        const first = { loading: page.loading, failed: page.loadFailed, toasts: [...toasts] };

        axios.get = async () => ({ data: { roles: [{ id: 1, kind: 'super_admin', name: 'Super-Admin' }] } });
        await page.fetch();
        const tried = { failed: page.loadFailed, roles: page.roles.length };

        axios.get = async () => { throw refusal(500, {}); };
        await page.fetch({ quiet: true });

        report({ first, tried, quiet: { failed: page.loadFailed, roles: page.roles.length, toasts } });
        JS);

    // Before: nothing told the list apart from an empty one, and it said "No roles yet."
    expect($result['first'])->toBe(['loading' => false, 'failed' => true, 'toasts' => []])
        ->and($result['tried'])->toBe(['failed' => false, 'roles' => 1])
        ->and($result['quiet']['failed'])->toBeFalse()
        ->and($result['quiet']['roles'])->toBe(1)
        ->and($result['quiet']['toasts'])->toHaveCount(1);
});

test('the page draws the failure with Try Again, and "No roles yet." only for a list that came empty', function () {
    $page = $this->actingAs(createSuperAdmin())->get('/roles')->assertOk();

    $page->assertSee('x-if="!loading && loadFailed"', false)
        ->assertSee('dusk="roles-load-failed"', false)
        ->assertSee('@click="fetch()" dusk="roles-load-failed-try-again"', false)
        ->assertSee('x-if="!loading && !loadFailed && mainRoles().length === 0"', false);
});

test('a role saved on a slow line leaves the form opened since for another role as it is', function () {
    $result = rolesPage(<<<'JS'
        axios.get = async () => ({ data: [{ id: 3, name: 'screen-view' }] });
        await page.openForm({ id: 7, kind: 'organization', name: 'Cashier', permissions: [{ id: 3 }] });
        const answer = later();
        axios.put = () => answer.promise;
        const form = formOnPage();
        const saving = page.save({ target: form });

        // Escape, then Edit on another role, and a name typed there.
        form.shown = false;
        await page.openForm({ id: 8, kind: 'organization', name: 'Manager', permissions: [{ id: 3 }] });
        form.shown = true;
        page.form.name = 'Manager, half typed';
        dispatched.length = 0;

        answer.reject(refusal(422, { errors: { name: ['A role of that name is offered already.'] } }));
        await saving;

        report({ dispatched, editing: page.editingRole?.id, typed: page.form.name, errors: page.formErrors, toasts });
        JS);

    // Before: Cashier's refusal was painted under Manager's name.
    expect($result)->toBe([
        'dispatched' => [],
        'editing' => 8,
        'typed' => 'Manager, half typed',
        'errors' => [],
        'toasts' => [['message' => 'Cashier was not saved: A role of that name is offered already.', 'type' => 'error']],
    ]);
});

test('a role saved while its form is still open shuts it, as ever', function () {
    $result = rolesPage(<<<'JS'
        axios.get = async (url) => (url === '/roles/data' ? { data: { roles: [] } } : { data: [{ id: 3, name: 'screen-view' }] });
        await page.openForm({ id: 7, kind: 'organization', name: 'Cashier', permissions: [{ id: 3 }] });
        axios.put = async () => ({ data: { message: 'Role updated.' } });
        dispatched.length = 0;
        await page.save({ target: formOnPage() });

        report({ dispatched, toasts });
        JS);

    expect($result)->toBe([
        'dispatched' => [['name' => 'close-modal', 'detail' => 'role-form']],
        'toasts' => [['message' => 'Role updated.', 'type' => 'success']],
    ]);
});
