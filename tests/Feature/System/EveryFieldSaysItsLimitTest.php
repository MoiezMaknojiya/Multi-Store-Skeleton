<?php

use App\Models\Channel;
use App\Models\Invitation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Screen;
use App\Models\Store;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Every field says how much it takes (owner, 2026-09-29)
|--------------------------------------------------------------------------
|
| "Phone number ki jitni bhi field ha us mein 10 se ziyada likhne hi naah do — aur aesi galti jaha jaha ki ha
| toh woo theek karo": a field must never let a person type more than the server keeps. Every text-like field on
| every page carries `maxlength`, or `data-digits` where only digits go (a phone, a ZIP code —
| resources/js/core/digits-only.js keeps it to its digits, and to that many). A page added later with a field
| that says neither fails here, before anybody finds it by typing.
|
*/

/**
 * The text-like fields of a page that carry no limit, described so the failure says which. A disabled field, a
 * password (no server max) and a field whose type Alpine sets — a password's show/hide toggle — are not text anybody
 * types into a form that keeps it.
 *
 * @return list<string>
 */
function fieldsWithNoLimit(string $html): array
{
    $page = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $page->loadHTML('<?xml encoding="utf-8"?>'.$html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $missing = [];

    foreach (['input', 'textarea'] as $tag) {
        foreach ($page->getElementsByTagName($tag) as $field) {
            /** @var DOMElement $field */
            $type = $tag === 'textarea' ? 'textarea' : strtolower($field->getAttribute('type') ?: 'text');
            $dynamicType = $field->hasAttribute(':type') || $field->hasAttribute('x-bind:type');

            if (! in_array($type, ['text', 'email', 'tel', 'search', 'url', 'textarea'], true) || $dynamicType || $field->hasAttribute('disabled')) {
                continue;
            }

            if ($field->hasAttribute('maxlength') || $field->hasAttribute('data-digits')) {
                continue;
            }

            $missing[] = $tag.' '.collect(['dusk', 'name', 'x-model', 'id', 'placeholder'])
                ->filter(fn (string $attribute) => $field->getAttribute($attribute) !== '')
                ->map(fn (string $attribute) => $attribute.'="'.$field->getAttribute($attribute).'"')
                ->implode(' ');
        }
    }

    return $missing;
}

/** @param  list<string>  $pages */
function assertEveryFieldSaysItsLimit(TestCase $test, array $pages): void
{
    foreach ($pages as $page) {
        $response = $test->get($page);
        $response->assertOk();

        expect(fieldsWithNoLimit((string) $response->getContent()))
            ->toBe([], "A field on {$page} lets a person type more than the server keeps.");
    }
}

test('the pages a guest sees say how much each field takes', function () {
    $store = Store::factory()->create();
    $owner = createStoreMember($store);
    [, $token] = Invitation::open($store, 'new.person@example.com', Role::owner(), $owner);

    assertEveryFieldSaysItsLimit($this, [
        '/login',
        '/register',
        '/forgot-password',
        '/reset-password/a-token?email=someone@example.com',
        '/invitations/'.$token,
    ]);
});

test('the pages above the stores say how much each field takes', function () {
    Store::factory()->create(['name' => 'Alpha Mart']);
    $channel = Channel::factory()->create(['name' => 'GAMA Wholesale']);

    $this->actingAs(createSuperAdmin());

    assertEveryFieldSaysItsLimit($this, [
        '/dashboard', '/users', '/stores', '/permissions', '/roles', '/activity', '/channels', '/channels/'.$channel->id,
        '/campaigns', '/media', '/builder', '/builder/assets', '/builder/create?orientation=portrait', '/profile',
    ]);
});

test('the pages inside a store say how much each field takes', function () {
    $store = Store::factory()->create(['name' => 'Alpha Mart']);
    // A role of this store holding everything a store's role may hold, so no page or form is hidden for want of one.
    $owner = createStoreUser($store, [...Permission::STORE, ...Permission::STORE_SCOPED], 'Everything');
    $screen = Screen::factory()->create(['store_id' => $store->id, 'name' => 'Deli TV']);
    $channel = Channel::factory()->create(['store_id' => $store->id, 'name' => 'Alpha Promos']);

    $this->actingAs($owner)->withSession(['current_store_id' => $store->id]);

    assertEveryFieldSaysItsLimit($this, [
        '/dashboard', '/screens', '/screens/'.$screen->id, '/media', '/dayparts', '/channels', '/channels/'.$channel->id,
        '/builder', '/builder/assets', '/builder/create?orientation=landscape', '/members', '/roles', '/activity',
        '/settings/store', '/profile',
    ]);
});

test('a phone and a ZIP code are digits only, ten at most, wherever they are asked', function () {
    $store = Store::factory()->create();
    $owner = createStoreMember($store);
    [, $token] = Invitation::open($store, 'new.person@example.com', Role::owner(), $owner);

    $digits = function (string $html, string $name): ?string {
        $page = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $page->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach ($page->getElementsByTagName('input') as $input) {
            /** @var DOMElement $input */
            if ($input->getAttribute('name') === $name) {
                return $input->getAttribute('data-digits').'|'.$input->getAttribute('inputmode');
            }
        }

        return null;
    };

    expect($digits((string) $this->get('/register')->getContent(), 'phone'))->toBe('10|numeric')
        ->and($digits((string) $this->get('/register')->getContent(), 'zip_code'))->toBe('10|numeric')
        ->and($digits((string) $this->get('/invitations/'.$token)->getContent(), 'phone'))->toBe('10|numeric');

    // Create store is on the Stores tab for somebody who may open one.
    $this->actingAs(createStoreUser($store, [...Permission::STORE, ...Permission::STORE_SCOPED], 'Everything'))
        ->withSession(['current_store_id' => $store->id]);

    expect($digits((string) $this->get('/profile')->getContent(), 'phone'))->toBe('10|numeric')
        ->and($digits((string) $this->get('/settings/store')->getContent(), 'zip_code'))->toBe('10|numeric')
        ->and($digits((string) $this->get('/settings/store')->getContent(), 'store_zip_code'))->toBe('10|numeric');
});
