<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

test('the default database seeder bootstraps permissions, the Super-Admin role, and the Super-Admin user on a fresh database', function () {
    $this->seed();

    expect(Permission::count())->toBe(20);
    expect(Permission::where('name', 'user-view')->value('label'))->toBe('View Users');
    expect(Permission::whereIn('name', ['permission-data', 'permission-all', 'user-store-updateRole'])->exists())->toBeFalse();

    $superAdminRole = Role::where('name', 'Super-Admin')->first();
    expect($superAdminRole)->not->toBeNull();
    expect($superAdminRole->permissions()->count())->toBe(20);

    $admin = User::where('email', 'admin@gmail.com')->first();
    expect($admin)->not->toBeNull();
    expect($admin->isSuperAdmin())->toBeTrue();
});

test('running the seeder twice in a row does not error or duplicate data', function () {
    $this->seed();
    $this->seed();

    expect(Permission::count())->toBe(20);
    expect(Role::where('name', 'Super-Admin')->count())->toBe(1);
    expect(User::where('email', 'admin@gmail.com')->count())->toBe(1);
});
