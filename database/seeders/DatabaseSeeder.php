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
     * Seed the application's database. Safe to run again: everything is matched, never duplicated.
     */
    public function run(): void
    {
        // 1. The permission catalogue (the migrations already insert the one this release ships with), labels
        //    repaired on every run.
        foreach (Permission::LABELS as $name => $label) {
            Permission::updateOrCreate(['name' => $name], ['label' => $label]);
        }

        // 2. The Super-Admin role — found by its exact name (Role::SUPER_ADMIN) — holds the whole catalogue.
        $superAdminRole = Role::find(Role::superAdminId()) ?? new Role(['name' => Role::SUPER_ADMIN]);
        $superAdminRole->fill(['is_global' => true])->save();
        $superAdminRole->permissions()->sync(Permission::pluck('id'));

        // 3. The Owner role — the store role whose holders own their store; public signup needs it. The
        //    migrations create all four starter roles (2026_09_16_110200_insert_permissions_and_starter_roles); this only puts the Owner role back
        //    should it be missing, because its name and permissions are the super admin's to change (Roles
        //    page) and a re-seed must never undo that. The other starters are ordinary store roles, so a
        //    re-seed never brings back one the super admin deleted.
        $owner = Role::firstOrCreate(
            ['key' => Role::OWNER],
            ['name' => Role::STARTERS[Role::OWNER]['name'], 'is_global' => false, 'store_id' => null, 'created_by' => null]
        );

        if ($owner->wasRecentlyCreated) {
            $owner->permissions()->sync(Permission::whereIn('name', Role::starterPermissions(Role::OWNER))->pluck('id'));
        }

        // 4. The Super Admin account.
        $superAdmin = User::firstOrNew(['email' => 'admin@gmail.com']);
        $superAdmin->fill([
            'first_name' => 'Admin',
            'last_name' => 'Momin',
            'phone' => '0000000000',
            'email_verified_at' => now(),
        ]);

        // Set the password only when creating the admin, or when SEED_ADMIN_PASSWORD is given —
        // a routine re-seed must never silently rotate a working password. Without the env key
        // a random one is generated and printed ONCE.
        $envPassword = env('SEED_ADMIN_PASSWORD');
        if (! $superAdmin->exists || $envPassword) {
            $password = $envPassword ?: Str::random(16);
            if (! $envPassword) {
                $this->command?->warn("SEED_ADMIN_PASSWORD is not set — generated admin password: {$password}");
            }
            $superAdmin->password = Hash::make($password);
        }
        $superAdmin->save();

        // Held on the platform row (store_id = 0), by role object — never a hardcoded id.
        $superAdmin->stores()->syncWithoutDetaching([
            0 => ['role_id' => $superAdminRole->id],
        ]);
    }
}
