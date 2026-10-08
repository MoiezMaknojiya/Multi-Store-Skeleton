<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Billing's two permissions (owner, 2026-10-08 — "billing ki permission bana rae ho theek kaam kar rae"; docs/BILLING-SPEC.md §2):
 * View Billing, an organization's own (its Settings → Billing tab; on a platform role every organization's billing, read-only),
 * held by Super-Admin and by the Owner and Admin roles while the installation has them; and Change Billing, the platform's alone
 * (the Premium Templates and Platform Channels switches), held by Super-Admin. A role is never touched otherwise.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'billing-view' => 'View Billing',
        'billing-update' => 'Change Billing',
    ];

    public function up(): void
    {
        DB::transaction(function () {
            $now = now();

            foreach (self::PERMISSIONS as $name => $label) {
                if (! DB::table('permissions')->where('name', $name)->exists()) {
                    DB::table('permissions')->insert(['name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now]);
                }
            }

            $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id', 'name');

            // The Super-Admin anchor is its exact name, compared here and not in SQL (a collation folds look-alikes together).
            $superAdmin = DB::table('roles')->where('is_global', true)->orderBy('id')->get(['id', 'name'])
                ->first(fn (object $role) => $role->name === 'Super-Admin');

            if ($superAdmin !== null) {
                foreach ($ids as $permissionId) {
                    $this->grant((int) $superAdmin->id, (int) $permissionId);
                }
            }

            foreach (DB::table('roles')->whereIn('key', ['owner', 'admin'])->pluck('id') as $roleId) {
                $this->grant((int) $roleId, (int) $ids['billing-view']);
            }
        });
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }

    private function grant(int $roleId, int $permissionId): void
    {
        if (! DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
            DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
};
