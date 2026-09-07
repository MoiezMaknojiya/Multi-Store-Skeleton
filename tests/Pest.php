<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Tests\DuskTestCase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(DuskTestCase::class)
    ->in('Browser');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

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

        Gate::define($permission->name, fn (User $user) => $user->hasPermissionInCurrentStore($permission->name));

        return $permission;
    });
}

/**
 * Create a Super-Admin user (assigned via the store_id = 0 sentinel, matching
 * the real seeder) with the given permissions granted to the Super-Admin role.
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
    $user->stores()->attach(0, ['role_id' => $role->id]);

    return $user;
}

/**
 * Create a user assigned to the given store with a role granting the given permissions.
 * Callers still need ->withSession(['current_store_id' => $store->id]) on the request
 * for the store-scoped permission check to take effect.
 */
function createStoreUser(Store $store, array $permissionNames, string $roleName = 'Test Role'): User
{
    $permissions = grantPermissions($permissionNames);

    $role = Role::create(['name' => $roleName]);
    $role->permissions()->sync($permissions->pluck('id'));

    $user = User::factory()->create();
    $user->stores()->attach($store->id, ['role_id' => $role->id]);

    return $user;
}
