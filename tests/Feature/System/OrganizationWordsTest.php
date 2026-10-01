<?php

use App\Models\Channel;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Screen;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| People read "organization", never "store" or "shop" (owner, 2026-10-01)
|--------------------------------------------------------------------------
|
| "Store change kar k organization kar du? Baki sub functionality same rakho bs naam change ... hospital aur education
| like school aur bhi dusre institue ko bhi sell kar saku future mein." Every word a person reads says organization —
| and, the same day, the code and the database too ("ab haar jagha organization kardo"). A page that says "store" or
| "shop" again fails here.
|
*/

/**
 * What a person reads on a page: its text, the attributes a browser shows or reads aloud, and the words Alpine writes
 * into them (the quoted parts of an x-text or a bound label) — never a script, a style, a dusk name or a URL.
 *
 * @return list<string>
 */
function wordsAPersonReads(string $html): array
{
    $page = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $page->loadHTML('<?xml encoding="utf-8"?>'.$html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $read = [];
    $xpath = new DOMXPath($page);

    foreach ($xpath->query('//text()[not(ancestor::script) and not(ancestor::style)]') as $text) {
        $read[] = trim($text->nodeValue);
    }

    foreach ($xpath->query('//*[@*]') as $element) {
        /** @var DOMElement $element */
        foreach ($element->attributes as $attribute) {
            $name = $attribute->nodeName;

            if (in_array($name, ['placeholder', 'aria-label', 'title', 'alt'], true)) {
                $read[] = $attribute->nodeValue;
            } elseif (in_array($name, ['x-text', ':aria-label', 'x-bind:aria-label', ':title', 'x-bind:title', ':placeholder', 'x-bind:placeholder'], true)) {
                preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $attribute->nodeValue, $quoted);
                array_push($read, ...$quoted[1]);
            }
        }
    }

    return array_values(array_filter($read, fn (string $words) => preg_match('/(?<![\w-])(stores?|shops?)(?![\w-])/i', $words) === 1));
}

/** @param  list<string>  $pages */
function assertEveryPageSaysOrganization(TestCase $test, array $pages): void
{
    foreach ($pages as $page) {
        $response = $test->get($page);
        $response->assertOk();

        expect(wordsAPersonReads((string) $response->getContent()))
            ->toBe([], "{$page} still says store or shop where a person reads it.");
    }
}

test('the check finds the words wherever a person reads them, and never in code', function () {
    $html = '<div dusk="manage-stores-1" x-bind:dusk="\'leave-store-\' + id" data-url="/stores/1">'
        .'<p>Your store</p><input placeholder="Search shops..."><button aria-label="Open the shop">Go</button>'
        .'<span x-text="item.store_name ? item.store_name + \' only\' : \'Every shop\'"></span>'
        .'<script>const shop = "the store";</script><style>.store-card{}</style></div>';

    expect(wordsAPersonReads($html))->toBe(['Your store', 'Search shops...', 'Open the shop', 'Every shop'])
        ->and(wordsAPersonReads('<p>Your organization</p><a href="/stores" dusk="store-card">Alpha Clinic</a>'))->toBe([]);
});

test('the pages a guest sees say organization', function () {
    $organization = Organization::factory()->create(['name' => 'Alpha Clinic']);
    $owner = createOrganizationMember($organization);
    [, $token] = Invitation::open($organization, 'new.person@example.com', Role::owner(), $owner);

    assertEveryPageSaysOrganization($this, ['/login', '/register', '/forgot-password', '/invitations/'.$token]);
});

test('the pages above the organizations say organization', function () {
    Organization::factory()->create(['name' => 'Alpha Clinic']);
    $channel = Channel::factory()->create(['name' => 'GAMA Wholesale']);

    $this->actingAs(createSuperAdmin());

    assertEveryPageSaysOrganization($this, [
        '/dashboard', '/users', '/organizations', '/permissions', '/roles', '/activity', '/channels', '/channels/'.$channel->id,
        '/campaigns', '/media', '/builder', '/builder/assets', '/builder/create?orientation=portrait', '/profile',
    ]);
});

test('the pages inside an organization say organization', function () {
    $organization = Organization::factory()->create(['name' => 'Alpha Clinic']);
    // A role holding everything an organization's role may hold, so no page, card or button is hidden for want of one.
    $member = createOrganizationUser($organization, [...Permission::ORGANIZATION, ...Permission::ORGANIZATION_SCOPED], 'Everything');
    $screen = Screen::factory()->create(['organization_id' => $organization->id, 'name' => 'Lobby TV']);
    $channel = Channel::factory()->create(['organization_id' => $organization->id, 'name' => 'Clinic News']);

    $this->actingAs($member)->withSession(['current_organization_id' => $organization->id]);

    assertEveryPageSaysOrganization($this, [
        '/dashboard', '/screens', '/screens/'.$screen->id, '/media', '/dayparts', '/channels', '/channels/'.$channel->id,
        '/builder', '/builder/assets', '/builder/create?orientation=landscape', '/members', '/roles', '/activity',
        '/settings/organization', '/profile',
    ]);
});
