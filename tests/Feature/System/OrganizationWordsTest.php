<?php

use App\Models\Channel;
use App\Models\Invitation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Screen;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| People read "organization", never "store" or "shop" (owner, 2026-10-01)
|--------------------------------------------------------------------------
|
| "Store change kar k organization kar du? Baki sub functionality same rakho bs naam change ... hospital aur education
| like school aur bhi dusre institue ko bhi sell kar saku future mein." Every word a person reads says organization;
| the code, the tables, the routes and the permission names (`store-view` …) keep "store", so nothing else changes.
| A page that says "store" or "shop" again fails here.
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
    $store = Store::factory()->create(['name' => 'Alpha Clinic']);
    $owner = createStoreMember($store);
    [, $token] = Invitation::open($store, 'new.person@example.com', Role::owner(), $owner);

    assertEveryPageSaysOrganization($this, ['/login', '/register', '/forgot-password', '/invitations/'.$token]);
});

test('the pages above the organizations say organization', function () {
    Store::factory()->create(['name' => 'Alpha Clinic']);
    $channel = Channel::factory()->create(['name' => 'GAMA Wholesale']);

    $this->actingAs(createSuperAdmin());

    assertEveryPageSaysOrganization($this, [
        '/dashboard', '/users', '/stores', '/permissions', '/roles', '/activity', '/channels', '/channels/'.$channel->id,
        '/campaigns', '/media', '/builder', '/builder/assets', '/builder/create?orientation=portrait', '/profile',
    ]);
});

test('the pages inside an organization say organization', function () {
    $store = Store::factory()->create(['name' => 'Alpha Clinic']);
    // A role holding everything an organization's role may hold, so no page, card or button is hidden for want of one.
    $member = createStoreUser($store, [...Permission::STORE, ...Permission::STORE_SCOPED], 'Everything');
    $screen = Screen::factory()->create(['store_id' => $store->id, 'name' => 'Lobby TV']);
    $channel = Channel::factory()->create(['store_id' => $store->id, 'name' => 'Clinic News']);

    $this->actingAs($member)->withSession(['current_store_id' => $store->id]);

    assertEveryPageSaysOrganization($this, [
        '/dashboard', '/screens', '/screens/'.$screen->id, '/media', '/dayparts', '/channels', '/channels/'.$channel->id,
        '/builder', '/builder/assets', '/builder/create?orientation=landscape', '/members', '/roles', '/activity',
        '/settings/store', '/profile',
    ]);
});

test('the four permissions about organizations are labelled so, and a label the super admin retyped is kept', function () {
    $labels = fn () => DB::table('permissions')->whereIn('name', ['store-update', 'store-view', 'store-store', 'store-destroy'])
        ->pluck('label', 'name')->all();

    // A fresh install: the baseline inserts the words of its day, and this migration brings them to what the code ships.
    expect($labels())->toEqual([
        'store-update' => Permission::LABELS['store-update'],
        'store-view' => Permission::LABELS['store-view'],
        'store-store' => Permission::LABELS['store-store'],
        'store-destroy' => Permission::LABELS['store-destroy'],
    ])->and(Permission::LABELS['store-view'])->toBe('View Organizations');

    $migration = require database_path('migrations/2026_10_01_140000_say_organization_in_the_permission_labels.php');
    $migration->down();

    expect($labels())->toEqual([
        'store-update' => 'Update Store Details', 'store-view' => 'View Stores', 'store-store' => 'Create Stores', 'store-destroy' => 'Delete Stores',
    ]);

    // The super admin retyped one on the Permissions page meanwhile, in other capitals: theirs stays.
    DB::table('permissions')->where('name', 'store-view')->update(['label' => 'view stores']);
    $migration->up();

    expect($labels())->toEqual([
        'store-update' => 'Update Organization Details', 'store-view' => 'view stores', 'store-store' => 'Create Organizations', 'store-destroy' => 'Delete Organizations',
    ]);
});
