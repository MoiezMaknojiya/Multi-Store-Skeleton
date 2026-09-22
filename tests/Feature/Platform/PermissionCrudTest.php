<?php

use App\Models\Permission;
use App\Models\Role;

test('guests cannot access any permission endpoint', function () {
    // Every route under /permissions, read from the route table — so one added later is asked too.
    $routes = routesUnder('permissions');

    expect($routes)->not->toBeEmpty();

    foreach ($routes as [$method, $uri]) {
        expect($this->json($method, $uri)->status())->toBe(401, "{$method} {$uri}");
    }
});

test('nobody but a super admin reaches the catalogue — not even holding its permissions', function () {
    $user = createPlatformUser(['permission-view', 'permission-store', 'permission-destroy']);
    $permission = Permission::create(['name' => 'keep-me']);

    $this->actingAs($user)->getJson('/permissions/data')->assertForbidden();
    $this->actingAs($user)->postJson('/permissions', ['name' => 'report-view'])->assertForbidden();
    $this->actingAs($user)->deleteJson("/permissions/{$permission->id}", ['password' => 'password'])->assertForbidden();
    $this->assertDatabaseHas('permissions', ['id' => $permission->id]);
});

test('a super admin holds every permission, even with none on the role (owner\'s rule)', function () {
    $user = createSuperAdmin([]); // Super-Admin role with zero granted permissions

    $this->actingAs($user)->getJson('/permissions/data')->assertOk();
    $this->actingAs($user)->postJson('/permissions', ['name' => 'report-view'])->assertSuccessful();
    $this->assertDatabaseHas('permissions', ['name' => 'report-view']);
});

test('a super admin lists the permissions', function () {
    Permission::create(['name' => 'sample-permission', 'label' => 'Sample Permission']);

    $user = createSuperAdmin();

    // Searched by name, so the row is found whatever page the rest of the catalogue fills.
    $response = $this->actingAs($user)->getJson('/permissions/data?search=sample-permission');

    $response->assertOk();
    $response->assertJsonStructure(['permissions', 'total', 'currentPage', 'lastPage', 'perPage']);
    $response->assertJsonPath('total', 1);
    $response->assertJsonPath('permissions.0.name', 'sample-permission');
    $response->assertJsonPath('permissions.0.display_name', 'Sample Permission');
});

test('a super admin creates a permission', function () {
    $user = createSuperAdmin();

    $response = $this->actingAs($user)->postJson('/permissions', ['name' => 'report-view']);

    $response->assertOk();
    $response->assertJsonPath('permission.name', 'report-view');
    $this->assertDatabaseHas('permissions', ['name' => 'report-view']);
});

test('a permission can be created with a human-readable label', function () {
    $user = createSuperAdmin();

    $response = $this->actingAs($user)->postJson('/permissions', [
        'name' => 'report-view',
        'label' => 'View Reports',
    ]);

    $response->assertOk();
    $response->assertJsonPath('permission.label', 'View Reports');
    $this->assertDatabaseHas('permissions', ['name' => 'report-view', 'label' => 'View Reports']);
});

test('a permission label rejects dashes, symbols, and other special characters', function () {
    $user = createSuperAdmin();

    $response = $this->actingAs($user)->postJson('/permissions', [
        'name' => 'report-view',
        'label' => 'View-Reports $$$',
    ]);

    $response->assertJsonValidationErrors('label');
    $response->assertJsonFragment(['label' => ['Label can only contain letters, numbers, and spaces.']]);
});

test('a permission label allows letters, numbers, and spaces', function () {
    $user = createSuperAdmin();

    $response = $this->actingAs($user)->postJson('/permissions', [
        'name' => 'report-view',
        'label' => 'Level 2 Access',
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('permissions', ['name' => 'report-view', 'label' => 'Level 2 Access']);
});

test('display_name falls back to the raw name when no label is set', function () {
    $permission = Permission::create(['name' => 'report-view']);

    expect($permission->display_name)->toBe('report-view');

    $permission->update(['label' => 'View Reports']);

    expect($permission->fresh()->display_name)->toBe('View Reports');
});

test('a super admin sets or changes a permission label', function () {
    $permission = Permission::create(['name' => 'report-view', 'label' => 'Old Label']);
    $user = createSuperAdmin();

    $response = $this->actingAs($user)->putJson("/permissions/{$permission->id}", [
        'name' => 'report-view',
        'label' => 'New Label',
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('permissions', ['id' => $permission->id, 'label' => 'New Label']);
});

test('creating a permission requires a unique name', function () {
    Permission::create(['name' => 'report-view']);
    $user = createSuperAdmin();

    $response = $this->actingAs($user)->postJson('/permissions', ['name' => 'report-view']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('name');
});

test('creating a permission requires a name', function () {
    $user = createSuperAdmin();

    $this->actingAs($user)->postJson('/permissions', [])->assertJsonValidationErrors('name');
});

test('a super admin renames a permission', function () {
    $permission = Permission::create(['name' => 'old-name']);
    $user = createSuperAdmin();

    $response = $this->actingAs($user)->putJson("/permissions/{$permission->id}", ['name' => 'new-name']);

    $response->assertOk();
    $this->assertDatabaseHas('permissions', ['id' => $permission->id, 'name' => 'new-name']);
});

test('updating a permission ignores its own name for the uniqueness check', function () {
    $permission = Permission::create(['name' => 'unchanged-name']);
    $user = createSuperAdmin();

    $response = $this->actingAs($user)->putJson("/permissions/{$permission->id}", ['name' => 'unchanged-name']);

    $response->assertOk();
});

test('a super admin deletes a permission by confirming their password', function () {
    $permission = Permission::create(['name' => 'to-delete']);
    $user = createSuperAdmin();

    $response = $this->actingAs($user)->deleteJson("/permissions/{$permission->id}", ['password' => 'password']);

    $response->assertOk();
    $this->assertDatabaseMissing('permissions', ['id' => $permission->id]);
});

test('a permission currently assigned to a role cannot be deleted', function () {
    $permission = Permission::create(['name' => 'gift-card-view']);
    $role = Role::create(['name' => 'Manager']);
    $role->permissions()->sync([$permission->id]);

    $user = createSuperAdmin();

    $response = $this->actingAs($user)->deleteJson("/permissions/{$permission->id}", ['password' => 'password']);

    $response->assertStatus(422);
    $response->assertJsonPath('message', 'This permission is assigned to one or more roles and cannot be deleted. Remove it from those roles first.');
    $this->assertDatabaseHas('permissions', ['id' => $permission->id]);
});

test('a permission can be deleted once it has been removed from every role', function () {
    $permission = Permission::create(['name' => 'gift-card-view']);
    $role = Role::create(['name' => 'Manager']);
    $role->permissions()->sync([$permission->id]);
    $role->permissions()->sync([]); // detach before deleting

    $user = createSuperAdmin();

    $response = $this->actingAs($user)->deleteJson("/permissions/{$permission->id}", ['password' => 'password']);

    $response->assertOk();
    $this->assertDatabaseMissing('permissions', ['id' => $permission->id]);
});

test('deleting a permission requires a password', function () {
    $permission = Permission::create(['name' => 'keep-me']);
    $user = createSuperAdmin();

    $response = $this->actingAs($user)->deleteJson("/permissions/{$permission->id}", []);

    $response->assertJsonValidationErrors('password');
    $this->assertDatabaseHas('permissions', ['id' => $permission->id]);
});

test('deleting a permission rejects the wrong password', function () {
    $permission = Permission::create(['name' => 'keep-me']);
    $user = createSuperAdmin();

    $response = $this->actingAs($user)->deleteJson("/permissions/{$permission->id}", ['password' => 'wrong-password']);

    $response->assertStatus(422);
    $response->assertJsonFragment(['password' => ['The password is incorrect.']]);
    $this->assertDatabaseHas('permissions', ['id' => $permission->id]);
});
