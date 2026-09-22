<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    // Keep the seeder from printing a generated admin password on every test.
    config(['app.seed_admin_password' => 'not-used-by-these-tests']);
});

test('the catalogue lists cover every labelled permission exactly once', function () {
    $listed = collect([...Permission::STORE, ...Permission::PLATFORM, ...Permission::SUPER_ADMIN_ONLY]);

    expect($listed->duplicates())->toBeEmpty();
    expect($listed->sort()->values()->all())->toBe(collect(array_keys(Permission::LABELS))->sort()->values()->all());

    // What a store's role may carry beyond its own permissions is a part of the platform list — all of it but
    // the accounts (a store's people are its Members page) and the yearly maintenance that drops every store's
    // history at once.
    expect(collect(Permission::STORE_SCOPED)->diff(Permission::PLATFORM))->toBeEmpty()
        ->and(collect(Permission::PLATFORM)->diff(Permission::STORE_SCOPED)->values()->all())->toBe(['user-view', 'user-destroy', 'activity-destroy']);
});

test('the seeder adds the Super-Admin role, holding the whole catalogue, and its account — over what the migrations installed', function () {
    // The migrations install the catalogue and the four starter store roles (DatabaseSchemaTest checks
    // them before any seeder); the seeder repairs the labels, adds Super-Admin and the admin account, and
    // touches no starter role.
    $this->seed();

    expect(Permission::count())->toBe(count(Permission::LABELS));
    expect(Permission::where('name', 'member-invite')->value('label'))->toBe('Invite Members');

    $superAdminRole = Role::where('name', 'Super-Admin')->firstOrFail();
    expect($superAdminRole->is_global)->toBeTrue()
        ->and($superAdminRole->permissions()->count())->toBe(count(Permission::LABELS));

    // Owner and Admin still start with every store permission and the Stores tab; the Owner alone also deletes
    // the store (owner's rules, 2026-09-17).
    expect(Role::whereNotNull('key')->count())->toBe(count(Role::STARTERS));
    expect(Role::starter(Role::OWNER)->permissions()->pluck('name')->sort()->values()->all())
        ->toBe(collect([...Permission::STORE, 'store-view', 'store-destroy'])->sort()->values()->all())
        ->and(Role::starter(Role::ADMIN)->permissions()->pluck('name')->sort()->values()->all())
        ->toBe(collect([...Permission::STORE, 'store-view'])->sort()->values()->all());

    $admin = User::where('email', 'admin@gmail.com')->firstOrFail();
    expect($admin->isSuperAdmin())->toBeTrue();
});

test('running the seeder twice in a row does not error or duplicate data', function () {
    $this->seed();
    $this->seed();

    expect(Permission::count())->toBe(count(Permission::LABELS));
    expect(Role::where('name', 'Super-Admin')->count())->toBe(1);
    expect(Role::whereNotNull('key')->count())->toBe(4);
    expect(User::where('email', 'admin@gmail.com')->count())->toBe(1);
});

test('re-seeding keeps what the super admin changed on a starter store role', function () {
    $this->seed();

    $staff = Role::starter(Role::STAFF);
    $staff->permissions()->sync(Permission::whereIn('name', ['screen-view', 'screen-store'])->pluck('id'));

    $this->seed();

    expect($staff->fresh()->permissions()->pluck('name')->sort()->values()->all())->toBe(['screen-store', 'screen-view']);
});
