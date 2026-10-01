<?php

use App\Models\Store;

/*
|--------------------------------------------------------------------------
| The sidebar for every platform role (owner, 2026-09-30: Users first, Stores second)
|--------------------------------------------------------------------------
|
| Every combination of the permissions that decide the top of the platform's menu: Users shows with View Users,
| Stores with View Stores, Users always above Stores when both show — and every link the menu offers opens.
|
*/

/** The hrefs of the main menu, in order. @return list<string> */
function mainMenuLinks(string $html): array
{
    preg_match('/<nav[^>]*aria-label="Main"[^>]*>(.*?)<\/nav>/s', $html, $nav);
    preg_match_all('/href="([^"]+)"/', $nav[1] ?? '', $links);

    return array_values(array_unique($links[1]));
}

test('every platform role sees Users above Stores, each only with its permission, and every link opens', function () {
    Store::factory()->create();
    $permissions = ['user-view', 'store-view', 'role-view', 'activity-view', 'channel-view', 'media-view'];

    foreach (range(0, 2 ** count($permissions) - 1) as $mask) {
        $held = array_values(array_filter($permissions, fn (string $p, int $i) => ($mask >> $i) & 1, ARRAY_FILTER_USE_BOTH));
        $person = createPlatformUser($held, "Platform {$mask}");
        $role = json_encode($held);

        $links = mainMenuLinks($this->actingAs($person)->get('/dashboard')->assertOk()->getContent());
        $users = array_search(route('users.view'), $links, true);
        $stores = array_search(route('stores.view'), $links, true);

        expect($users !== false)->toBe(in_array('user-view', $held, true), "Users for {$role}")
            ->and($stores !== false)->toBe(in_array('store-view', $held, true), "Stores for {$role}");

        if ($users !== false && $stores !== false) {
            expect($users)->toBeLessThan($stores, "Users is not above Stores for {$role}");
        }

        foreach ($links as $link) {
            $this->actingAs($person)->get($link)->assertOk();
        }
    }
});

test('the super admin\'s menu: Dashboard, Users with its Roles and Permissions, Stores, then the rest', function () {
    $links = mainMenuLinks($this->actingAs(createSuperAdmin())->get('/dashboard')->assertOk()->getContent());

    expect(array_slice($links, 0, 5))->toBe([
        route('dashboard'), route('users.view'), route('roles.view'), route('permissions.view'), route('stores.view'),
    ]);
});
