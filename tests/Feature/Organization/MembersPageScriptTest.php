<?php

use App\Models\Organization;
use App\Models\Role;
use Tests\Support\PageScript;

/*
|--------------------------------------------------------------------------
| The Members page's late answers (the review of 2026-10-15)
|--------------------------------------------------------------------------
|
| Invite and Change Role share the page's formErrors, and each answer used to act on whatever dialog was open by the time
| it came: a role change saved on a slow line, its dialog shut and another member's opened, shut that one or painted its
| refusal under it — or under the Invite form's fields. A revoke's answer shut the question asked since about another
| invitation. Each answer now acts on its own question alone (core/form-asked.js).
|
*/

/** The Members page's component, and the steps of $then. */
function membersPage(string $then): array
{
    return PageScript::run(<<<JS
        import { registerMembersPage } from './resources/js/pages/members-page.js';

        const made = {};
        registerMembersPage({ data: (name, factory) => { made[name] = factory; } });
        const page = asComponent(made.membersPage({ organizationName: 'Smart Stop' }));
        axios.get = async () => ({ data: { members: [], invitations: [], assignable_roles: [], owner_count: 1 } });

        {$then}
        JS);
}

test('a role change refused after its dialog was shut and the invite form opened is said in a toast, not under the invite', function () {
    $result = membersPage(<<<'JS'
        page.openChangeRole({ id: 4, name: 'Sara', role: { id: 2 } });
        page.roleForm.role_id = 3;
        const answer = later();
        axios.put = () => answer.promise;
        const roleForm = formOnPage();
        const saving = page.saveRole({ target: roleForm });

        // Escape, then Invite Member, and an address typed there.
        roleForm.shown = false;
        page.openInvite();
        page.inviteForm.email = 'ali@example.com';
        dispatched.length = 0;

        answer.reject(refusal(422, { errors: { role_id: ['Sara is the only Owner: give the Owner role to somebody else first.'] } }));
        await saving;

        report({ dispatched, errors: page.formErrors, typed: page.inviteForm.email, toasts });
        JS);

    // Before: Sara's refusal was put in formErrors, under the invite form's Role.
    expect($result)->toBe([
        'dispatched' => [],
        'errors' => [],
        'typed' => 'ali@example.com',
        'toasts' => [['message' => "Sara's role was not saved: Sara is the only Owner: give the Owner role to somebody else first.", 'type' => 'error']],
    ]);
});

test('an invitation refused after its form was shut says which one was not sent, in a toast', function () {
    $result = membersPage(<<<'JS'
        page.openInvite();
        page.inviteForm = { email: 'ali@example.com', role_id: 2 };
        const answer = later();
        axios.post = () => answer.promise;
        const inviteForm = formOnPage();
        const sending = page.sendInvite({ target: inviteForm });

        inviteForm.shown = false;
        answer.reject(refusal(422, { errors: { email: ['ali@example.com is already a member of Smart Stop.'] } }));
        await sending;

        report({ errors: page.formErrors, toasts });
        JS);

    expect($result)->toBe([
        'errors' => [],
        'toasts' => [['message' => 'The invitation to ali@example.com was not sent: ali@example.com is already a member of Smart Stop.', 'type' => 'error']],
    ]);
});

test('an invitation sent on a slow line never shuts the form opened since, nor turns the page to its tab', function () {
    $result = membersPage(<<<'JS'
        page.openInvite();
        page.inviteForm = { email: 'ali@example.com', role_id: 2 };
        const answer = later();
        axios.post = () => answer.promise;
        const inviteForm = formOnPage();
        const sending = page.sendInvite({ target: inviteForm });

        inviteForm.shown = false;
        page.openChangeRole({ id: 4, name: 'Sara', role: { id: 2 } });
        dispatched.length = 0;

        answer.resolve({ data: { message: 'Invitation sent to ali@example.com.', email_sent: true } });
        await sending;

        report({ dispatched, tab: page.tab, asked: page.selectedMember?.id, toasts });
        JS);

    // Before: the answer shut Sara's dialog and turned the page to Pending Invitations under it.
    expect($result)->toBe([
        'dispatched' => [],
        'tab' => 'members',
        'asked' => 4,
        'toasts' => [['message' => 'Invitation sent to ali@example.com.', 'type' => 'success']],
    ]);
});

test('a late revoke never shuts the question asked since about another invitation', function () {
    $result = membersPage(<<<'JS'
        const answer = later();
        axios.delete = () => answer.promise;
        page.confirmRevoke({ id: 1, email: 'ali@example.com' });
        const revoking = page.revoke();

        page.confirmRevoke({ id: 2, email: 'sara@example.com' });
        dispatched.length = 0;

        answer.resolve({ data: { message: 'Invitation revoked.' } });
        await revoking;

        report({ dispatched, asked: page.selectedInvitation?.id });
        JS);

    expect($result)->toBe(['dispatched' => [], 'asked' => 2]);
});

test('the Members page sends each form with the press that sent it, so its answer knows its form', function () {
    $organization = Organization::factory()->create();
    $owner = createOrganizationMember($organization, Role::OWNER);

    $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
        ->get('/members')->assertOk()
        ->assertSee('@submit.prevent="sendInvite($event)"', false)
        ->assertSee('@submit.prevent="saveRole($event)"', false);
});
