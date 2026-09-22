<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| A super admin holds every permission (owner's rule, 2026-09-16: "sab matlab sab")
|--------------------------------------------------------------------------
|
| Whatever rows the Super-Admin role has, every permission check answers yes — including a
| permission created later. Rules that are not permissions still stand.
|
*/

test('a super admin passes every permission check with none on the role — and one made later', function () {
    $superAdmin = createSuperAdmin([]);
    registerPermissionGates();

    $this->actingAs($superAdmin)->getJson('/channels/data')->assertOk();
    $this->actingAs($superAdmin)->getJson('/activity/data')->assertOk();
    $this->actingAs($superAdmin)->getJson('/stores/data')->assertOk();

    Permission::create(['name' => 'report-export']);
    expect($superAdmin->fresh()->can('report-export'))->toBeTrue();
});

test('the rules that are not permissions still stand for a super admin', function () {
    $superAdmin = createSuperAdmin();
    registerPermissionGates();
    $store = Store::factory()->create();

    // The in-shop advertising switch is a place ("logged in as one of the shop's people"), not a permission.
    $this->actingAs($superAdmin)->withSession(['current_store_id' => $store->id])
        ->putJson('/network-ads/store', ['accepts' => true])->assertForbidden();

    // Super-Admin and the Owner role are never deleted; a super admin never deletes themselves.
    $this->actingAs($superAdmin)->deleteJson('/roles/'.Role::superAdminId(), ['password' => 'password'])->assertForbidden();
    $this->actingAs($superAdmin)->deleteJson('/roles/'.Role::starter(Role::OWNER)->id, ['password' => 'password'])->assertForbidden();
    $this->actingAs($superAdmin)->delete('/profile', ['password' => 'password'])->assertForbidden();
    expect(User::find($superAdmin->id))->not->toBeNull();

    // Somebody else holding every permission row is still not a super admin.
    $support = createPlatformUser(Permission::pluck('name')->all());
    $this->actingAs($support)->get('/permissions')->assertForbidden();
});
