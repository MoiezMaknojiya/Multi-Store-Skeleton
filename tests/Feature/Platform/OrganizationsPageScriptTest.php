<?php

use Tests\Support\PageScript;

/*
|--------------------------------------------------------------------------
| The Organizations page's late answers (the review of 2026-10-15)
|--------------------------------------------------------------------------
|
| Billing and Invite Owner each acted on whatever dialog was open by the time their answer came: a billing saved on a slow
| line, its dialog shut and opened for another organization, shut that one, or put the first organization's extra-storage
| refusal under the second one's field; an owner invitation shut the dialog opened since for another organization and lost
| the email typed there, or painted its refusal under it. Each answer now acts on its own dialog alone (core/form-asked.js).
|
*/

/** The Organizations page's component, the billing dialog's form on the page, and the steps of $then. */
function organizationsPage(string $then): array
{
    return PageScript::run(<<<JS
        import { registerOrganizationsTable } from './resources/js/tables/organizations-table.js';

        const made = {};
        registerOrganizationsTable({ data: (name, factory) => { made[name] = factory; } });
        const page = asComponent(made.organizationsTable());
        page.refreshInPlace = async () => {};

        /** One organization's billing summary, as GET /organizations/{organization}/billing gives it. */
        const billingOf = (extra = 0, templates = true) => ({
            features: { premium_templates: { unlocked: templates, price: 10 }, platform_channels: { unlocked: true, price: 10 } },
            storage: { used: 0, included: 512 * 1024 * 1024, extra_gb: extra, max_extra_gb: 50 },
            screen_count: 0, screen_price: 5, screens_monthly: 0,
        });
        axios.get = async () => ({ data: { billing: billingOf() } });

        const billingForm = formOnPage();
        boxes['[dusk="organization-billing"]'] = billingForm;

        const alpha = { id: 1, name: 'Alpha Mart' };
        const beta = { id: 2, name: 'Beta Foods' };

        {$then}
        JS);
}

test('a billing saved on a slow line never shuts the dialog opened since for another organization, nor turns its switches back', function () {
    $result = organizationsPage(<<<'JS'
        page.openBilling(alpha);
        await settle();
        page.billingForm.extra_storage_gb = 2;
        const answer = later();
        axios.put = () => answer.promise;
        const saving = page.saveBilling();

        // Escape, then Discard Changes; Billing for Beta Foods, and a switch turned there.
        billingForm.shown = false;
        page.openBilling(beta);
        await settle();
        billingForm.shown = true;
        page.billingForm.premium_templates_unlocked = false;
        dispatched.length = 0;

        answer.resolve({ data: { message: 'Billing saved for Alpha Mart.', billing: billingOf(2) } });
        await saving;

        report({ dispatched, asked: page.billingOrganization.id, form: page.billingForm, toasts });
        JS);

    // Before: Alpha's answer shut Beta's dialog (close-modal organization-billing).
    expect($result)->toBe([
        'dispatched' => [],
        'asked' => 2,
        'form' => ['premium_templates_unlocked' => false, 'platform_channels_unlocked' => true, 'extra_storage_gb' => 0],
        'toasts' => [['message' => 'Billing saved for Alpha Mart.', 'type' => 'success']],
    ]);
});

test('a billing saved from a dialog opened again since for the same organization shows what was saved there, unless something was changed', function () {
    $result = organizationsPage(<<<'JS'
        // `staleFirst`: the summary the dialog opened again read before the save landed comes before the save's answer, or after.
        const saveOnASlowLine = async (change, staleFirst = true) => {
            page.openBilling(alpha);
            await settle();
            page.billingForm.extra_storage_gb = 2;
            const answer = later();
            axios.put = () => answer.promise;
            const saving = page.saveBilling();

            // Escape; Billing for Alpha again.
            billingForm.shown = false;
            const stale = later();
            axios.get = () => stale.promise;
            page.openBilling(alpha);
            billingForm.shown = true;
            if (staleFirst) {
                stale.resolve({ data: { billing: billingOf(0) } });
                await settle();
            }
            if (change) page.billingForm.premium_templates_unlocked = false;
            dispatched.length = 0;

            answer.resolve({ data: { message: 'Billing saved for Alpha Mart.', billing: billingOf(2) } });
            await saving;
            stale.resolve({ data: { billing: billingOf(0) } });
            await settle();

            return {
                dispatched: [...dispatched],
                extra: page.billingForm.extra_storage_gb,
                templates: page.billingForm.premium_templates_unlocked,
                loading: page.billingLoading,
            };
        };

        report({
            untouched: await saveOnASlowLine(false),
            stillLoading: await saveOnASlowLine(false, false),
            changed: await saveOnASlowLine(true),
        });
        JS);

    // Before: the dialog opened again kept the summary read before the save (0 GB extra) — saved, it would undo it.
    expect($result)->toBe([
        'untouched' => ['dispatched' => [], 'extra' => 2, 'templates' => true, 'loading' => false],
        'stillLoading' => ['dispatched' => [], 'extra' => 2, 'templates' => true, 'loading' => false],
        'changed' => ['dispatched' => [], 'extra' => 0, 'templates' => false, 'loading' => false],
    ]);
});

test('a billing refused after its dialog was opened for another organization is said in a toast, not under that one\'s field', function () {
    $result = organizationsPage(<<<'JS'
        page.openBilling(alpha);
        await settle();
        page.billingForm.extra_storage_gb = 2;
        const answer = later();
        axios.put = () => answer.promise;
        const saving = page.saveBilling();

        billingForm.shown = false;
        page.openBilling(beta);
        await settle();
        billingForm.shown = true;

        answer.reject(refusal(422, { errors: { extra_storage_gb: ['Alpha Mart holds more than that: delete files first.'] } }));
        await saving;

        report({ errors: page.billingErrors, toasts });
        JS);

    // Before: Alpha's refusal was painted under Beta's Extra storage.
    expect($result)->toBe([
        'errors' => [],
        'toasts' => [['message' => "Alpha Mart's billing was not saved: Alpha Mart holds more than that: delete files first.", 'type' => 'error']],
    ]);
});

test('an owner invitation sent on a slow line never shuts the dialog opened since for another organization, nor loses its email', function () {
    $result = organizationsPage(<<<'JS'
        page.openInviteOwner(alpha);
        page.ownerEmail = 'ali@example.com';
        const answer = later();
        axios.post = () => answer.promise;
        const ownerForm = formOnPage();
        const sending = page.sendOwnerInvite({ target: ownerForm });

        ownerForm.shown = false;
        page.openInviteOwner(beta);
        ownerForm.shown = true;
        page.ownerEmail = 'sara@exa';
        dispatched.length = 0;

        answer.resolve({ data: { message: 'Invitation sent to ali@example.com.', email_sent: true } });
        await sending;

        report({ dispatched, asked: page.ownerOrganization.id, typed: page.ownerEmail, toasts });
        JS);

    // Before: the answer shut Beta's dialog (close-modal invite-organization-owner), and the email went with it.
    expect($result)->toBe([
        'dispatched' => [],
        'asked' => 2,
        'typed' => 'sara@exa',
        'toasts' => [['message' => 'Invitation sent to ali@example.com.', 'type' => 'success']],
    ]);
});

test('an owner invitation refused after its dialog was opened for another organization is said in a toast, not under its Email', function () {
    $result = organizationsPage(<<<'JS'
        page.openInviteOwner(alpha);
        page.ownerEmail = 'ali@example.com';
        const answer = later();
        axios.post = () => answer.promise;
        const ownerForm = formOnPage();
        const sending = page.sendOwnerInvite({ target: ownerForm });

        ownerForm.shown = false;
        page.openInviteOwner(beta);
        ownerForm.shown = true;

        answer.reject(refusal(422, { errors: { email: ['ali@example.com is on the platform team, so it cannot own an organization.'] } }));
        await sending;

        report({ errors: page.ownerErrors, toasts });
        JS);

    expect($result)->toBe([
        'errors' => [],
        'toasts' => [['message' => 'The invitation to ali@example.com was not sent: ali@example.com is on the platform team, so it cannot own an organization.', 'type' => 'error']],
    ]);
});

test('while their dialog is still open the answers act on it as ever: shut on success, the refusal under its field', function () {
    $result = organizationsPage(<<<'JS'
        page.openBilling(alpha);
        await settle();
        page.billingForm.extra_storage_gb = 2;
        axios.put = async () => { throw refusal(422, { errors: { extra_storage_gb: ['Alpha Mart holds more than that: delete files first.'] } }); };
        await page.saveBilling();
        const billingRefused = page.billingErrors;

        axios.put = async () => ({ data: { message: 'Billing saved for Alpha Mart.', billing: billingOf(2) } });
        dispatched.length = 0;
        await page.saveBilling();
        const billingShut = [...dispatched];
        const shown = page.billing.storage.extra_gb;

        const ownerForm = formOnPage();
        page.openInviteOwner(alpha);
        page.ownerEmail = 'ali@example.com';
        axios.post = async () => { throw refusal(422, { errors: { email: ['That email already has an open invitation.'] } }); };
        await page.sendOwnerInvite({ target: ownerForm });
        const ownerRefused = page.ownerErrors;

        axios.post = async () => ({ data: { message: 'Invitation sent to ali@example.com.', email_sent: true } });
        dispatched.length = 0;
        await page.sendOwnerInvite({ target: ownerForm });

        report({ billingRefused, billingShut, shown, ownerRefused, ownerShut: dispatched });
        JS);

    expect($result)->toBe([
        'billingRefused' => ['extra_storage_gb' => ['Alpha Mart holds more than that: delete files first.']],
        'billingShut' => [['name' => 'close-modal', 'detail' => 'organization-billing']],
        'shown' => 2,
        'ownerRefused' => ['email' => ['That email already has an open invitation.']],
        'ownerShut' => [['name' => 'close-modal', 'detail' => 'invite-organization-owner']],
    ]);
});

test('the Organizations page sends the owner invitation with the press that sent it, so its answer knows its form', function () {
    $this->actingAs(createSuperAdmin())->get('/organizations')->assertOk()
        ->assertSee('@submit.prevent="sendOwnerInvite($event)"', false)
        ->assertSee('@click="copyOwnerInviteLink($event)"', false)
        ->assertSee('dusk="organization-billing"', false);
});

/** The page with a clipboard that keeps what it is given (or refuses, with `refuses`), and Invite Owner open for Alpha Mart. */
function copyLinkPage(string $then, bool $refuses = false): array
{
    $clipboard = $refuses
        ? 'writeText: async () => { throw new Error("Write permission denied."); }'
        : 'writeText: async (text) => { clipboard.push(text); }';

    return organizationsPage(<<<JS
        const clipboard = [];
        Object.defineProperty(globalThis, 'navigator', { value: { clipboard: { {$clipboard} } }, configurable: true });
        const posts = [];
        const ownerForm = formOnPage();
        const press = (detail = 1) => ({ detail, target: { closest: () => ownerForm } });
        const link = 'https://sign.example.com/invitations/' + 'a'.repeat(64);
        page.openInviteOwner(alpha);
        page.ownerEmail = 'driver@example.com';

        {$then}
        JS);
}

test('Copy Invitation Link asks for the link once, copies it and shows it in the dialog', function () {
    $result = copyLinkPage(<<<'JS'
        axios.post = async (url, body) => {
            posts.push([url, body]);
            return { data: { message: 'The same link was emailed to driver@example.com.', email_sent: true, link } };
        };
        // A double click: the second press of it asks nothing.
        const first = page.copyOwnerInviteLink(press(1));
        await page.copyOwnerInviteLink(press(2));
        await first;

        report({ posts, clipboard, shown: page.ownerLink, to: page.ownerLinkEmail, copied: page.ownerLinkCopied, toasts, dispatched, busy: page.invitingOwner });
        JS);

    expect($result)->toBe([
        'posts' => [['/organizations/1/owner-invitation/link', ['email' => 'driver@example.com']]],
        'clipboard' => ['https://sign.example.com/invitations/'.str_repeat('a', 64)],
        'shown' => 'https://sign.example.com/invitations/'.str_repeat('a', 64),
        'to' => 'driver@example.com',
        'copied' => true,
        'toasts' => [['message' => 'Invitation link copied. The same link was emailed to driver@example.com.', 'type' => 'success']],
        // Opened, and left open: it shows the link now.
        'dispatched' => [['name' => 'open-modal', 'detail' => 'invite-organization-owner']],
        'busy' => false,
    ]);
});

test('a clipboard that refuses still leaves the link in the dialog to copy, and Copy Link says it did not copy', function () {
    $result = copyLinkPage(<<<'JS'
        axios.post = async () => ({ data: { message: 'The same link was emailed to driver@example.com.', email_sent: true, link } });
        await page.copyOwnerInviteLink(press());
        await page.copyOwnerLinkAgain();

        report({ shown: page.ownerLink, copied: page.ownerLinkCopied, toasts });
        JS, refuses: true);

    expect($result)->toBe([
        'shown' => 'https://sign.example.com/invitations/'.str_repeat('a', 64),
        'copied' => false,
        'toasts' => [
            ['message' => 'Copy the invitation link from the dialog. The same link was emailed to driver@example.com.', 'type' => 'success'],
            ['message' => 'The browser did not copy it: the link is selected, copy it from there.', 'type' => 'error'],
        ],
    ]);
});

test('a link answered after its dialog was shut is neither shown nor copied, and the email is said instead', function () {
    $result = copyLinkPage(<<<'JS'
        const answer = later();
        axios.post = () => answer.promise;
        const copying = page.copyOwnerInviteLink(press());

        ownerForm.shown = false;
        answer.resolve({ data: { message: 'The same link was emailed to driver@example.com.', email_sent: true, link } });
        await copying;

        report({ shown: page.ownerLink, clipboard, toasts });
        JS);

    expect($result)->toBe([
        'shown' => '',
        'clipboard' => [],
        'toasts' => [['message' => 'The invitation was emailed to driver@example.com.', 'type' => 'success']],
    ]);
});

test('a member made Owner at once has no link: the dialog shuts and says so, and a refusal stays under the field', function () {
    $result = copyLinkPage(<<<'JS'
        axios.post = async () => ({ data: { message: 'Sam Staff is now an Owner of Alpha Mart.' } });
        await page.copyOwnerInviteLink(press());
        const member = { shown: page.ownerLink, dispatched: [...dispatched], toasts: [...toasts] };

        page.openInviteOwner(alpha);
        page.ownerEmail = 'driver@example.com';
        axios.post = async () => { throw refusal(422, { errors: { email: ['Alpha Mart already has an Owner.'] } }); };
        await page.copyOwnerInviteLink(press());

        report({ member, errors: page.ownerErrors, shown: page.ownerLink });
        JS);

    expect($result)->toBe([
        'member' => [
            'shown' => '',
            'dispatched' => [['name' => 'open-modal', 'detail' => 'invite-organization-owner'], ['name' => 'close-modal', 'detail' => 'invite-organization-owner']],
            'toasts' => [['message' => 'Sam Staff is now an Owner of Alpha Mart.', 'type' => 'success']],
        ],
        'errors' => ['email' => ['Alpha Mart already has an Owner.']],
        'shown' => '',
    ]);
});
