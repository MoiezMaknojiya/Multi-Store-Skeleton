<?php

use Tests\Support\PageScript;

/*
|--------------------------------------------------------------------------
| The Users page's late answers (the review of 2026-10-15)
|--------------------------------------------------------------------------
|
| The platform team's invitation, Manage Organizations and Revoke each acted on whatever was open by the time their answer
| came: an invitation sent on a slow line, its form shut and opened again, shut the new one and lost what was typed, or put
| its refusal under the new fields; an account added to an organization, its dialog shut and opened for another account,
| emptied that one's choices or painted the first account's refusal under them, and reloaded its lists over the roles picked
| there; a revoke's answer shut the question asked since about another invitation. Each answer now acts on its own
| question alone (core/form-asked.js), as the Members page's do.
|
*/

/** The Users page's component, as a super admin has it, and the steps of $then. */
function usersPage(string $then): array
{
    return PageScript::run(<<<JS
        import { registerUsersTable } from './resources/js/tables/users-table.js';

        const made = {};
        registerUsersTable({ data: (name, factory) => { made[name] = factory; } });
        const page = asComponent(made.usersTable({ isSuperAdmin: true }));
        page.refreshInPlace = async () => {};

        /** What Manage Organizations lists for an account: one membership, and one organization it may be added to. */
        const accessOf = (id) => ({
            memberships: [{ organization_id: 10 + id, organization_name: 'Org ' + id, role_id: 2, role_name: 'Staff' }],
            organizations: [{ id: 20 + id, name: 'Other ' + id }],
            organization_roles: [{ id: 2, name: 'Staff' }, { id: 3, name: 'Admin' }],
            custom_roles: {},
        });
        const accessAsked = [];
        axios.get = async (url) => {
            if (url === '/users/invitations') return { data: { invitations: [] } };
            const id = Number(url.match(/^\/users\/(\d+)\/organizations$/)?.[1]);
            accessAsked.push(id);

            return { data: accessOf(id) };
        };

        const ali = { id: 1, name: 'Ali Khan' };
        const sara = { id: 2, name: 'Sara Malik' };

        {$then}
        JS);
}

test('an invitation sent on a slow line never shuts the form opened since, nor loses what is typed there', function () {
    $result = usersPage(<<<'JS'
        page.openInvite();
        page.inviteForm = { email: 'ali@example.com', role_id: 2 };
        const answer = later();
        axios.post = () => answer.promise;
        const inviteForm = formOnPage();
        const sending = page.sendInvite({ target: inviteForm });

        // Escape, then Discard Changes; Invite to Platform Team again, and an address half typed.
        inviteForm.shown = false;
        page.openInvite();
        inviteForm.shown = true;
        page.inviteForm.email = 'sara@exa';
        dispatched.length = 0;

        answer.resolve({ data: { message: 'Invitation sent to ali@example.com.', email_sent: true } });
        await sending;

        report({ dispatched, typed: page.inviteForm.email, toasts });
        JS);

    // Before: the answer shut the form opened since (close-modal invite-platform-member), and the typing went with it.
    expect($result)->toBe([
        'dispatched' => [],
        'typed' => 'sara@exa',
        'toasts' => [['message' => 'Invitation sent to ali@example.com.', 'type' => 'success']],
    ]);
});

test('an invitation refused after its form was shut and opened again is said in a toast, never under the new fields', function () {
    $result = usersPage(<<<'JS'
        page.openInvite();
        page.inviteForm = { email: 'ali@example.com', role_id: 2 };
        const answer = later();
        axios.post = () => answer.promise;
        const inviteForm = formOnPage();
        const sending = page.sendInvite({ target: inviteForm });

        inviteForm.shown = false;
        page.openInvite();
        inviteForm.shown = true;

        answer.reject(refusal(422, { errors: { email: ['ali@example.com is in an organization, so it cannot join the platform team.'] } }));
        await sending;

        report({ errors: page.formErrors, toasts });
        JS);

    // Before: Ali's refusal was painted under the Email of the form opened since.
    expect($result)->toBe([
        'errors' => [],
        'toasts' => [['message' => 'The invitation to ali@example.com was not sent: ali@example.com is in an organization, so it cannot join the platform team.', 'type' => 'error']],
    ]);
});

test('an account added to an organization on a slow line leaves the dialog opened since for another account as it is', function () {
    $result = usersPage(<<<'JS'
        await page.openManageOrganizations(ali);
        page.assignForm = { organization_id: 21, role_id: 2 };
        const answer = later();
        axios.post = () => answer.promise;
        const assignForm = formOnPage();
        const adding = page.assignToOrganization({ target: assignForm });

        // Escape; Manage Organizations for Sara, a role picked on her membership and an organization chosen to add her to.
        assignForm.shown = false;
        await page.openManageOrganizations(sara);
        assignForm.shown = true;
        page.access.memberships[0].selected_role_id = 3;
        page.assignForm = { organization_id: 22, role_id: 3 };
        accessAsked.length = 0;

        answer.resolve({ data: { message: 'Ali Khan was added to Other 1 as Staff.' } });
        await adding;

        report({ choices: page.assignForm, picked: page.access.memberships[0].selected_role_id, accessAsked, toasts });
        JS);

    // Before: Sara's choices were emptied, and her lists loaded again over the role picked there.
    expect($result)->toBe([
        'choices' => ['organization_id' => 22, 'role_id' => 3],
        'picked' => 3,
        'accessAsked' => [],
        'toasts' => [['message' => 'Ali Khan was added to Other 1 as Staff.', 'type' => 'success']],
    ]);
});

test('an account refused an organization after its dialog was opened for another is said in a toast, not under that one', function () {
    $result = usersPage(<<<'JS'
        await page.openManageOrganizations(ali);
        page.assignForm = { organization_id: 21, role_id: 2 };
        const answer = later();
        axios.post = () => answer.promise;
        const assignForm = formOnPage();
        const adding = page.assignToOrganization({ target: assignForm });

        assignForm.shown = false;
        await page.openManageOrganizations(sara);
        assignForm.shown = true;

        answer.reject(refusal(422, { errors: { role_id: ['That role is not offered in Other 1.'] } }));
        await adding;

        report({ errors: page.assignErrors, toasts });
        JS);

    expect($result)->toBe([
        'errors' => [],
        'toasts' => [['message' => 'Ali Khan was not added: That role is not offered in Other 1.', 'type' => 'error']],
    ]);
});

test('a role saved on a membership, its dialog shut and opened for another account, never reloads that one over its picks', function () {
    $result = usersPage(<<<'JS'
        await page.openManageOrganizations(ali);
        const membership = page.access.memberships[0];
        membership.selected_role_id = 3;
        const answer = later();
        axios.put = () => answer.promise;
        const save = formOnPage();
        const saving = page.saveMembershipRole(membership, { currentTarget: save });

        save.shown = false;
        await page.openManageOrganizations(sara);
        save.shown = true;
        page.access.memberships[0].selected_role_id = 3;
        accessAsked.length = 0;

        answer.resolve({ data: { message: "Ali Khan's role in Org 1 is now Admin." } });
        await saving;

        report({ shows: page.access.memberships[0].organization_id, picked: page.access.memberships[0].selected_role_id, accessAsked });
        JS);

    expect($result)->toBe(['shows' => 12, 'picked' => 3, 'accessAsked' => []]);
});

test('a change made from a dialog opened again since for the same account brings its lists up to date, unless a role is picked there', function () {
    $result = usersPage(<<<'JS'
        const changeOnASlowLine = async (pick) => {
            await page.openManageOrganizations(ali);
            const membership = page.access.memberships[0];
            membership.selected_role_id = 3;
            const answer = later();
            axios.put = () => answer.promise;
            const save = formOnPage();
            const saving = page.saveMembershipRole(membership, { currentTarget: save });

            // Escape; Manage Organizations for Ali again — its lists read before the change landed.
            save.shown = false;
            await page.openManageOrganizations(ali);
            save.shown = true;
            if (pick) page.access.memberships[0].selected_role_id = 3;
            accessAsked.length = 0;

            answer.resolve({ data: { message: "Ali Khan's role in Org 1 is now Admin." } });
            await saving;

            return { accessAsked: [...accessAsked], picked: page.access.memberships[0].selected_role_id };
        };

        report({ untouched: await changeOnASlowLine(false), picked: await changeOnASlowLine(true) });
        JS);

    // Untouched, it is read again; with a role picked there, that pick is kept.
    expect($result)->toBe([
        'untouched' => ['accessAsked' => [1], 'picked' => 2],
        'picked' => ['accessAsked' => [], 'picked' => 3],
    ]);
});

test('an account\'s lists asked for before the dialog was opened for another never overwrite that one\'s', function () {
    $result = usersPage(<<<'JS'
        await page.openManageOrganizations(ali);

        // A refresh of Ali's lists on a slow line (after a change), then the dialog opened for Sara.
        const slow = later();
        const fast = axios.get;
        axios.get = (url) => (url === '/users/1/organizations' ? slow.promise : fast(url));
        const refreshing = page.loadAccess();
        await page.openManageOrganizations(sara);

        slow.resolve({ data: accessOf(1) });
        await refreshing;

        report({ shows: page.access.memberships[0].organization_name });
        JS);

    // Before: Ali's lists landed in Sara's dialog.
    expect($result)->toBe(['shows' => 'Org 2']);
});

test('a late revoke never shuts the question asked since about another invitation', function () {
    $result = usersPage(<<<'JS'
        const answer = later();
        axios.delete = () => answer.promise;
        page.confirmRevoke({ id: 1, email: 'ali@example.com' });
        const revoking = page.revokeInvitation();

        page.confirmRevoke({ id: 2, email: 'sara@example.com' });
        dispatched.length = 0;

        answer.resolve({ data: { message: 'Invitation revoked.' } });
        await revoking;

        report({ dispatched, asked: page.selectedInvitation?.id });
        JS);

    // Before: the answer shut the question about Sara's invitation.
    expect($result)->toBe(['dispatched' => [], 'asked' => 2]);
});

test('while their form is still open the answers act on it as ever: shut on success, the refusal under its field', function () {
    $result = usersPage(<<<'JS'
        const inviteForm = formOnPage();
        page.openInvite();
        page.inviteForm = { email: 'ali@example.com', role_id: 2 };
        axios.post = async () => { throw refusal(422, { errors: { email: ['That email already has an open invitation.'] } }); };
        await page.sendInvite({ target: inviteForm });
        const inviteRefused = page.formErrors;

        axios.post = async () => ({ data: { message: 'Invitation sent to ali@example.com.', email_sent: true } });
        dispatched.length = 0;
        await page.sendInvite({ target: inviteForm });
        const inviteShut = [...dispatched];

        await page.openManageOrganizations(ali);
        const assignForm = formOnPage();
        page.assignForm = { organization_id: 21, role_id: 2 };
        axios.post = async () => { throw refusal(422, { errors: { role_id: ['That role is not offered in Other 1.'] } }); };
        await page.assignToOrganization({ target: assignForm });
        const assignRefused = page.assignErrors;

        axios.post = async () => ({ data: { message: 'Ali Khan was added to Other 1 as Staff.' } });
        accessAsked.length = 0;
        await page.assignToOrganization({ target: assignForm });

        report({ inviteRefused, inviteShut, assignRefused, choices: page.assignForm, accessAsked });
        JS);

    expect($result)->toBe([
        'inviteRefused' => ['email' => ['That email already has an open invitation.']],
        'inviteShut' => [['name' => 'close-modal', 'detail' => 'invite-platform-member']],
        'assignRefused' => ['role_id' => ['That role is not offered in Other 1.']],
        'choices' => ['organization_id' => '', 'role_id' => ''],
        'accessAsked' => [1],
    ]);
});

test('the Users page sends each form with the press that sent it, so its answer knows its form', function () {
    $this->actingAs(createSuperAdmin())->get('/users')->assertOk()
        ->assertSee('@submit.prevent="sendInvite($event)"', false)
        ->assertSee('@submit.prevent="assignToOrganization($event)"', false)
        ->assertSee('@click="saveMembershipRole(membership, $event)"', false);
});
