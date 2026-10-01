<?php

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('guests are redirected to login', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('super admin dashboard shows the real organization count', function () {
    Organization::factory()->count(3)->create();

    $admin = createSuperAdmin();

    $response = $this->actingAs($admin)->get('/dashboard');

    $response->assertOk();
    $response->assertViewHas('summary', fn (array $summary) => collect($summary['cards'])->firstWhere('key', 'organizations')['value'] === 3);
    // The number on the Organizations card itself — a bare "3" turns up all over a page.
    expect($response->getContent())->toMatch('/dusk="dashboard-card-organizations"[^>]*>[\s\S]*?>\s*3\s*<\/div>\s*<div[^>]*>\s*Organizations\s*</');
});

test('the organization selection page runs the same number of queries for ten organizations as for two', function () {
    $user = User::factory()->create();
    $join = fn (int $count) => Organization::factory()->count($count)->create()->each(
        fn (Organization $organization) => $user->organizations()->attach($organization->id, ['role_id' => Role::owner()->id])
    );

    // A fresh instance each time, so both requests start with nothing remembered about the person.
    $queriesFor = function () use ($user): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user->fresh())->get('/select-organization')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $join(2);
    $two = $queriesFor();

    $join(8);
    $ten = $queriesFor();

    // Role names for every card come from one batched query, not one per organization — so eight more
    // organizations cost nothing. A ceiling could not catch one query per organization; only equality can.
    expect($user->organizations()->count())->toBe(10)
        ->and($two)->toBeGreaterThan(0)
        ->and($ten)->toBe($two);
});

test('a user with a custom global role sees the global stats dashboard, not the empty organization list', function () {
    Organization::factory()->count(2)->create();
    $globalRole = Role::create(['name' => 'Global Admin', 'is_global' => true]);
    $user = User::factory()->create();
    $user->organizations()->attach(0, ['role_id' => $globalRole->id]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertSee('dusk="dashboard-card-organizations"', false);
    $response->assertDontSee("You're not a member of any organization yet", false);
});

test('a global user always gets the global stats dashboard — the global tier wins', function () {
    // Tiers are meant to be exclusive; even if a stray organization row exists, holding a
    // global role keeps the user on the global stats view (never the organization context
    // or the selector).
    $organization = Organization::factory()->create(['name' => 'Attached Organization']);
    $globalRole = Role::create(['name' => 'Global Admin', 'is_global' => true]);
    $organizationRole = Role::create(['name' => 'Manager']);
    $user = User::factory()->create();
    $user->organizations()->attach(0, ['role_id' => $globalRole->id]);
    $user->organizations()->attach($organization->id, ['role_id' => $organizationRole->id]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertViewHas('view', 'global');
    $response->assertSee('dusk="dashboard-card-organizations"', false);
});

test('super admin dashboard reflects zero organizations when none exist', function () {
    $admin = createSuperAdmin();

    $response = $this->actingAs($admin)->get('/dashboard');

    $response->assertOk();
    $response->assertViewHas('summary', fn (array $summary) => collect($summary['cards'])->firstWhere('key', 'organizations')['value'] === 0);
});

test('a single-organization user is auto-selected into that organization (smart default)', function () {
    $organization = Organization::factory()->create(['name' => 'My Organization']);
    $user = createOrganizationUser($organization, []);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertViewHas('view', 'organization');
    // The single organization was written into session context without any manual pick.
    expect(session('current_organization_id'))->toBe($organization->id);
});

test('a multi-organization user with no organization chosen is sent to the selection page', function () {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'Owner']);
    $organizationA = Organization::factory()->create(['name' => 'First Organization']);
    $organizationB = Organization::factory()->create(['name' => 'Second Organization']);
    $user->organizations()->attach($organizationA->id, ['role_id' => $role->id]);
    $user->organizations()->attach($organizationB->id, ['role_id' => $role->id]);

    $this->actingAs($user)->get('/dashboard')->assertRedirect(route('organizations.select'));

    $response = $this->actingAs($user)->get('/select-organization');
    $response->assertOk();
    $response->assertSee('First Organization');
    $response->assertSee('Second Organization');
});

test('the selection page redirects away when there is nothing to pick', function () {
    // A single-organization user does not need the picker.
    $organization = Organization::factory()->create();
    $single = createOrganizationUser($organization, []);
    $this->actingAs($single)->get('/select-organization')->assertRedirect(route('dashboard'));

    // A global user has no organization to pick either.
    $globalRole = Role::create(['name' => 'Global Admin', 'is_global' => true]);
    $global = User::factory()->create();
    $global->organizations()->attach(0, ['role_id' => $globalRole->id]);
    $this->actingAs($global)->get('/select-organization')->assertRedirect(route('dashboard'));
});

test('a name that starts with a letter of more than one byte gives its avatar that whole letter', function () {
    // substr() cut the first BYTE of "علی", which is half a character: every page then carried an invalid
    // UTF-8 byte in the header, the sidebar and the organization picker (a "�" on screen).
    $organization = Organization::factory()->create();
    $person = createOrganizationUser($organization, ['organization-view'], 'Staff');
    $person->update(['first_name' => 'علی', 'last_name' => 'Khan']);

    foreach (['/dashboard', '/profile'] as $page) {
        $html = $this->actingAs($person->fresh())->withSession(['current_organization_id' => $organization->id])->get($page)->assertOk()->getContent();

        expect(mb_check_encoding($html, 'UTF-8'))->toBeTrue("{$page} is not valid UTF-8")
            ->and($html)->toContain('ع');
    }
});

test('above the organizations the sidebar lists Users first and Organizations after it (owner, 2026-09-30)', function () {
    $html = $this->actingAs(createSuperAdmin())->get('/dashboard')->assertOk()->getContent();

    $users = strpos($html, 'href="'.route('users.view').'"');
    $organizations = strpos($html, 'href="'.route('organizations.view').'"');

    expect($users)->not->toBeFalse()
        ->and($organizations)->not->toBeFalse()
        ->and($users)->toBeLessThan($organizations);
});
