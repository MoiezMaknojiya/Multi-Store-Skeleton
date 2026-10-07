<?php

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| The Users page's tabs and sorting
|--------------------------------------------------------------------------
|
| Owner, 2026-10-07: "haan Platform Team aur Organization Members bana do, sorting bhi". A super admin sees the
| accounts whole, or the platform team, or everybody outside it, each tab saying how many it holds; Account and
| Joined sort the list, newest first as it opens. Support keeps seeing the organizations' people alone.
|
*/

beforeEach(function () {
    $this->primary = createSuperAdmin();
    $this->primary->forceFill(['first_name' => 'Zara', 'last_name' => 'Admin', 'created_at' => now()->subDays(30)])->save();
    $this->support = createPlatformUser(['user-view'], 'Support');
    $this->support->forceFill(['first_name' => 'Musa', 'last_name' => 'Support', 'created_at' => now()->subDays(20)])->save();

    $organization = Organization::factory()->create();
    $this->owner = createOrganizationMember($organization, Role::OWNER, ['first_name' => 'bilal', 'last_name' => 'Owner', 'created_at' => now()->subDays(10)]);
    $this->newcomer = User::factory()->create(['first_name' => 'Ayesha', 'last_name' => 'New', 'created_at' => now()->subDay()]);
});

/** @return list<string> the first names the listing returned, in its order */
function accountsListed(User $viewer, string $query): array
{
    return collect(test()->actingAs($viewer)->getJson('/users/data?'.$query)->assertOk()->json('users'))->pluck('first_name')->all();
}

test('a super admin sees every account, the platform team, or everybody outside it', function () {
    expect(accountsListed($this->primary, 'sort=name&direction=asc'))->toBe(['Ayesha', 'bilal', 'Musa', 'Zara'])
        ->and(accountsListed($this->primary, 'group=platform&sort=name&direction=asc'))->toBe(['Musa', 'Zara'])
        ->and(accountsListed($this->primary, 'group=organization&sort=name&direction=asc'))->toBe(['Ayesha', 'bilal']);
});

test('each tab says how many it holds, whatever is searched or shown', function () {
    $this->actingAs($this->primary)->getJson('/users/data?group=platform&search=Zara')->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('counts', ['all' => 4, 'platform' => 2, 'organization' => 2]);
});

test('anything else asked for is every account', function (string $query) {
    expect(accountsListed($this->primary, $query.'&sort=name&direction=asc'))->toBe(['Ayesha', 'bilal', 'Musa', 'Zara']);
})->with([
    'nothing' => ['group='],
    'a word that is not a tab' => ['group=everyone'],
    'SQL' => ['group='.urlencode("platform' or 1=1 --")],
    'an array' => ['group[]=platform'],
]);

test('Account and Joined sort the list either way round, and the list opens A to Z without a sort', function (string $query, array $names) {
    expect(accountsListed($this->primary, $query))->toBe($names);
})->with([
    'name, A to Z (whatever the case)' => ['sort=name&direction=asc', ['Ayesha', 'bilal', 'Musa', 'Zara']],
    'name, Z to A' => ['sort=name&direction=desc', ['Zara', 'Musa', 'bilal', 'Ayesha']],
    'joined, newest first' => ['sort=joined&direction=desc', ['Ayesha', 'bilal', 'Musa', 'Zara']],
    'joined, oldest first' => ['sort=joined&direction=asc', ['Zara', 'Musa', 'bilal', 'Ayesha']],
    'nothing asked for' => ['', ['Ayesha', 'bilal', 'Musa', 'Zara']],
    'a column that is not a heading' => ['sort=password&direction=asc', ['Ayesha', 'bilal', 'Musa', 'Zara']],
]);

test('support sees the organizations\' people alone: no tab opens the platform team, and no counts are said', function () {
    $answer = $this->actingAs($this->support)->getJson('/users/data?group=platform&sort=name&direction=asc')->assertOk();

    expect(collect($answer->json('users'))->pluck('first_name')->all())->toBe(['Ayesha', 'bilal'])
        ->and($answer->json('counts'))->toBeNull();

    $this->actingAs($this->support)->get('/users')->assertOk()->assertDontSee('dusk="accounts-tab-platform"', false);
    $this->actingAs($this->primary)->get('/users')->assertOk()->assertSee('dusk="accounts-tab-platform"', false);
});
