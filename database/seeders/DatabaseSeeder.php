<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Create permissions
        $permissions = [
            'user-view' => 'View Users',
            'user-store' => 'Create Users',
            'user-update' => 'Update Users',
            'user-destroy' => 'Delete Users',
            'user-store-view' => 'View User Store Assignments',
            'user-store-assign' => 'Assign User to Store',
            'user-store-unassign' => 'Remove User from Store',
            'store-view' => 'View Stores',
            'store-store' => 'Create Stores',
            'store-update' => 'Update Stores',
            'store-destroy' => 'Delete Stores',
            'role-view' => 'View Roles',
            'role-store' => 'Create Roles',
            'role-update' => 'Update Roles',
            'role-destroy' => 'Delete Roles',
            'permission-view' => 'View Permissions',
            'permission-store' => 'Create Permissions',
            'permission-update' => 'Update Permissions',
            'permission-destroy' => 'Delete Permissions',
            'activity-view' => 'View Activity Log',
        ];

        foreach ($permissions as $name => $label) {
            Permission::updateOrCreate(['name' => $name], ['label' => $label]);
        }

        // 2. Create roles
        $superAdminRole = Role::firstOrCreate(['name' => 'Super-Admin', 'created_by' => null], ['is_global' => true]);

        // Public signup needs exactly one default role. Seed a sensible "Store Owner"
        // only when none is marked yet — an existing choice is never overridden.
        if (! Role::where('is_signup_default', true)->exists()) {
            $storeOwnerRole = Role::firstOrCreate(
                // created_by is part of the match: role names are only unique per
                // creator now, so a user-created "Store Owner" must never be picked.
                ['name' => 'Store Owner', 'created_by' => null],
                ['is_signup_default' => true]
            );
            $storeOwnerRole->permissions()->syncWithoutDetaching(
                Permission::whereIn('name', [
                    'user-view', 'user-store', 'user-store-view', 'user-store-assign',
                    'role-view', 'role-store', 'store-view',
                ])->pluck('id')
            );
        }

        // 3. Assign permissions to roles
        $superAdminRole->permissions()->sync(Permission::all());

        // 4. Create the Super Admin user.
        $superAdmin = User::firstOrNew(['email' => 'admin@gmail.com']);
        $superAdmin->fill([
            'first_name' => 'Admin',
            'last_name' => 'Momin',
            'phone' => '0000000000',
            'created_by' => null,
            'email_verified_at' => now(),
        ]);

        // Set/rotate the password only when creating the admin, or when an explicit
        // SEED_ADMIN_PASSWORD is provided — a routine re-seed must NOT silently
        // rotate an existing admin's working password. When creating without the
        // env key a random one is generated and printed ONCE.
        $envPassword = env('SEED_ADMIN_PASSWORD');
        if (! $superAdmin->exists || $envPassword) {
            $password = $envPassword ?: Str::random(16);
            if (! $envPassword) {
                $this->command?->warn("SEED_ADMIN_PASSWORD is not set — generated admin password: {$password}");
            }
            $superAdmin->password = Hash::make($password);
        }
        $superAdmin->save();

        // Attach on the global sentinel (store_id = 0) with the Super-Admin role —
        // resolved by object, never a hardcoded id, so a recreated role still works.
        $superAdmin->stores()->syncWithoutDetaching([
            0 => ['role_id' => $superAdminRole->id],
        ]);
    }
}
