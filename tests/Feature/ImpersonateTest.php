<?php

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('guests cannot access any impersonation endpoint', function () {
    $user = User::factory()->create();

    $this->postJson("/users/{$user->id}/impersonate")->assertUnauthorized();
    $this->postJson('/impersonate/stop')->assertUnauthorized();
});

test('a super admin can log in as another user and the session remembers who to return to', function () {
    $admin = createSuperAdmin();
    $target = User::factory()->create();

    $response = $this->actingAs($admin)->post("/users/{$target->id}/impersonate");

    $response->assertRedirect(route('dashboard'));
    expect(auth()->id())->toBe($target->id);
    expect(session('impersonating_original_id'))->toBe($admin->id);
});

test('impersonating clears any store context the admin had selected', function () {
    $admin = createSuperAdmin();
    $target = User::factory()->create();

    $this->actingAs($admin)
        ->withSession(['current_store_id' => 42])
        ->post("/users/{$target->id}/impersonate");

    expect(session('current_store_id'))->toBeNull();
});

test('stopping impersonation attributes the audit row to the returning super admin, not the impersonated user', function () {
    $admin = createSuperAdmin();
    $target = User::factory()->create();

    $this->actingAs($admin)->post("/users/{$target->id}/impersonate");
    $this->post('/impersonate/stop')->assertRedirect(route('dashboard'));

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'impersonate.stopped',
        'actor_id' => $admin->id,        // the admin who acted — NOT $target
        'actor_name' => $admin->name,
    ]);
    $this->assertDatabaseMissing('activity_logs', [
        'action' => 'impersonate.stopped',
        'actor_id' => $target->id,
    ]);
});

test('a non-super-admin cannot impersonate anyone', function () {
    $store = Store::factory()->create();
    $viewer = createStoreUser($store, []);
    $target = User::factory()->create();

    $response = $this->actingAs($viewer)->post("/users/{$target->id}/impersonate");

    $response->assertForbidden();
    expect(auth()->id())->toBe($viewer->id);
});

test('a super admin cannot impersonate another super admin', function () {
    $admin = createSuperAdmin();
    $otherAdmin = createSuperAdmin();

    $response = $this->actingAs($admin)->post("/users/{$otherAdmin->id}/impersonate");

    $response->assertForbidden();
    expect(auth()->id())->toBe($admin->id);
});

test('an impersonated user can return to the original super admin with one request', function () {
    $admin = createSuperAdmin();
    $target = User::factory()->create();

    $this->actingAs($admin)->post("/users/{$target->id}/impersonate");
    expect(auth()->id())->toBe($target->id);

    $response = $this->post('/impersonate/stop');

    $response->assertRedirect(route('dashboard'));
    expect(auth()->id())->toBe($admin->id);
    expect(session('impersonating_original_id'))->toBeNull();
});

test('stopping impersonation logs out and redirects to login if the original admin no longer exists', function () {
    $admin = createSuperAdmin();
    $target = User::factory()->create();

    $this->actingAs($admin)->post("/users/{$target->id}/impersonate");
    $admin->delete();

    $response = $this->post('/impersonate/stop');

    $response->assertRedirect(route('login'));
    expect(auth()->check())->toBeFalse();
    expect(session('impersonating_original_id'))->toBeNull();
});

test('stopping impersonation logs out if the original admin was demoted from Super-Admin', function () {
    $admin = createSuperAdmin();
    $target = User::factory()->create();

    $this->actingAs($admin)->post("/users/{$target->id}/impersonate");
    DB::table('store_user')->where('user_id', $admin->id)->delete();

    $response = $this->post('/impersonate/stop');

    $response->assertRedirect(route('login'));
    expect(auth()->check())->toBeFalse();
});

test('stopping impersonation clears the store context the impersonated user had selected', function () {
    $admin = createSuperAdmin();
    $target = User::factory()->create();

    $this->actingAs($admin)->post("/users/{$target->id}/impersonate");

    $this->withSession(['current_store_id' => 7])->post('/impersonate/stop');

    expect(session('current_store_id'))->toBeNull();
});

test('stopping impersonation when nothing is being impersonated is a harmless no-op', function () {
    $admin = createSuperAdmin();

    $response = $this->actingAs($admin)->post('/impersonate/stop');

    $response->assertRedirect(route('dashboard'));
    expect(auth()->id())->toBe($admin->id);
});

test('the users list marks super admins so the frontend can hide the impersonate button for them', function () {
    $admin = createSuperAdmin(['user-view']);
    // Created by the viewer so it stays visible — unrelated super admins are hidden from the list.
    $otherAdmin = User::factory()->create(['created_by' => $admin->id]);
    $otherAdmin->stores()->attach(0, ['role_id' => Role::where('name', 'Super-Admin')->value('id')]);
    $regular = User::factory()->create();

    $response = $this->actingAs($admin)->getJson('/users/data');

    $response->assertOk();
    $users = collect($response->json('users'))->keyBy('id');
    expect($users[$otherAdmin->id]['is_super_admin'])->toBeTrue();
    expect($users[$regular->id]['is_super_admin'])->toBeFalse();
});
