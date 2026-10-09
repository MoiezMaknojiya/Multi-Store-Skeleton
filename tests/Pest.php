<?php

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\DuskTestCase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case
|--------------------------------------------------------------------------
|
| Every Feature test runs on the in-memory SQLite database of phpunit.xml, inside a transaction that is
| rolled back afterwards (RefreshDatabase). The browser tests are PHPUnit classes of their own, extending
| DuskTestCase; the binding below only covers a Pest-style test added under tests/Browser later.
|
*/

pest()->extend(DuskTestCase::class)
    ->in('Browser');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Permission / Role Test Helpers
|--------------------------------------------------------------------------
|
| AppServiceProvider registers a Gate::define for every Permission row, but
| that registration runs once at application boot, before RefreshDatabase
| has migrated the test database. These helpers create the Permission rows
| and re-register their Gates so the "can:" middleware behaves the same
| way it does in a real request.
|
*/

/**
 * Create (or reuse) permission rows and register their Gate abilities.
 *
 * @return Collection<int, Permission>
 */
function grantPermissions(array $names): Collection
{
    return collect($names)->map(function (string $name) {
        $permission = Permission::firstOrCreate(['name' => $name]);

        Gate::define($permission->name, fn (User $user) => $user->hasPermissionInCurrentOrganization($permission->name));

        return $permission;
    });
}

/**
 * Create a super admin: the Super-Admin role (made here when the test has none yet — in a real install the
 * seeder makes it, not the migrations) held on the organization_id = 0 platform row, the way the seeder holds it.
 * The first one made in a test is the primary super admin.
 *
 * A super admin holds every permission whatever the role's rows say (the Gate::before in AppServiceProvider),
 * so the names given change nothing about what they may do: they only write rows onto the role, for a test
 * that reads those rows.
 */
function createSuperAdmin(array $permissionNames = []): User
{
    $permissions = grantPermissions($permissionNames);

    // The Super-Admin role is shared (firstOrCreate) across every call in a test, so
    // accumulate permissions rather than sync() — sync() would wipe out permissions
    // granted by an earlier createSuperAdmin() call in the same test.
    $role = Role::firstOrCreate(['name' => 'Super-Admin']);
    $role->permissions()->syncWithoutDetaching($permissions->pluck('id'));

    $user = User::factory()->create();
    $user->organizations()->attach(0, ['role_id' => $role->id]);

    return $user;
}

/**
 * Create a member of the given organization holding a CUSTOM role of that organization with exactly the given
 * permissions. Callers still need ->withSession(['current_organization_id' => $organization->id]) on the
 * request for the organization-scoped permission check to take effect.
 */
function createOrganizationUser(Organization $organization, array $permissionNames, string $roleName = 'Test Role'): User
{
    $permissions = grantPermissions($permissionNames);

    $role = Role::create(['name' => $roleName, 'organization_id' => $organization->id]);
    $role->permissions()->sync($permissions->pluck('id'));

    $user = User::factory()->create();
    $user->organizations()->attach($organization->id, ['role_id' => $role->id]);

    return $user;
}

/**
 * Register a gate for every permission row, the way AppServiceProvider does at boot — needed
 * whenever a test relies on rows it did not create through grantPermissions(), such as the
 * starter organization roles the migrations install.
 */
function registerPermissionGates(): void
{
    Permission::all()->each(fn (Permission $permission) => Gate::define(
        $permission->name,
        fn (User $user) => $user->hasPermissionInCurrentOrganization($permission->name)
    ));
}

/**
 * Create a member of the organization holding one of the starter organization roles (Owner by default). The
 * migrations install those roles with their permissions; this registers the gates for them.
 */
function createOrganizationMember(Organization $organization, string $roleKey = Role::OWNER, array $attributes = []): User
{
    registerPermissionGates();

    $user = User::factory()->create($attributes);
    $user->organizations()->attach($organization->id, ['role_id' => Role::starter($roleKey)->id]);

    return $user;
}

/**
 * A second organization for $user, so a session with none chosen stays that way. A person of one organization is put in it
 * from their first request (ChooseTheOnlyOrganization), which would hide what a test of "no organization chosen" means to show.
 */
function inASecondOrganization(User $user): User
{
    $elsewhere = Organization::factory()->create(['name' => 'Elsewhere '.$user->id]);
    $user->organizations()->attach($elsewhere->id, ['role_id' => Role::starter(Role::VIEWER)->id]);

    return $user;
}

/**
 * Create a member of the platform team: a global role with the given permissions, held on the
 * organization_id = 0 row. Not a super admin.
 */
function createPlatformUser(array $permissionNames, string $roleName = 'Platform Staff'): User
{
    $permissions = grantPermissions($permissionNames);

    $role = Role::create(['name' => $roleName, 'is_global' => true]);
    $role->permissions()->sync($permissions->pluck('id'));

    $user = User::factory()->create();
    $user->organizations()->attach(0, ['role_id' => $role->id]);

    return $user;
}

/** The starter-role key a person holds in an organization (null for any other role, or when not a member). */
function roleKeyIn(User $user, Organization $organization): ?string
{
    $roleId = DB::table('organization_user')->where('organization_id', $organization->id)->where('user_id', $user->id)->value('role_id');

    return $roleId ? Role::find($roleId)?->key : null;
}

/*
|--------------------------------------------------------------------------
| Route Helpers
|--------------------------------------------------------------------------
*/

/**
 * Every route under a URI prefix — "media" gives /media, /media/data, /media/{media} and the rest — as
 * [method, uri] pairs with each {parameter} filled in with 1. Read from the route table, so a test that
 * promises "any endpoint" asks every one of them, a route added later included. HEAD is left out: it is
 * GET's twin.
 *
 * @return list<array{0: string, 1: string}>
 */
function routesUnder(string $prefix): array
{
    return collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn (Route $route) => $route->uri() === $prefix || str_starts_with($route->uri(), $prefix.'/'))
        ->flatMap(fn (Route $route) => collect($route->methods())
            ->reject(fn (string $method) => $method === 'HEAD')
            ->map(fn (string $method) => [$method, '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri())]))
        ->values()
        ->all();
}
