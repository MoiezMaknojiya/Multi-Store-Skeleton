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
    expect(session('impersonating_user_id'))->toBe($target->id);
});

/*
 * The way back must belong to the impersonated account alone. A session whose impersonated account
 * was deleted mid-way used to keep the super admin's id through the NEXT person's login on that
 * browser (regenerate() keeps session data), and "stop" then signed that person in as the admin.
 */
test('signing in clears a way back left in the session, so the next person cannot use it', function () {
    $admin = createSuperAdmin();
    $next = User::factory()->create(['email' => 'next@example.com']);

    $this->withSession(['impersonating_original_id' => $admin->id, 'impersonating_user_id' => 999])
        ->post('/login', ['email' => 'next@example.com', 'password' => 'password'])
        ->assertRedirect();

    expect(auth()->id())->toBe($next->id)
        ->and(session('impersonating_original_id'))->toBeNull()
        ->and(session('impersonating_user_id'))->toBeNull();

    $this->post('/impersonate/stop')->assertRedirect(route('dashboard'));
    expect(auth()->id())->toBe($next->id);
});

test('stopping works only for the account being impersonated', function () {
    $admin = createSuperAdmin();
    $someoneElse = User::factory()->create();

    $this->actingAs($someoneElse)
        ->withSession(['impersonating_original_id' => $admin->id, 'impersonating_user_id' => $someoneElse->id + 1])
        ->post('/impersonate/stop')
        ->assertRedirect(route('dashboard'));

    expect(auth()->id())->toBe($someoneElse->id)
        ->and(session('impersonating_original_id'))->toBeNull();
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

test('the users list offers "Log in as" for everyone but super admins and yourself', function () {
    $admin = createSuperAdmin(['user-view']);
    $otherAdmin = User::factory()->create();
    $otherAdmin->stores()->attach(0, ['role_id' => Role::where('name', 'Super-Admin')->value('id')]);
    $regular = User::factory()->create();

    $users = collect($this->actingAs($admin)->getJson('/users/data')->assertOk()->json('users'))->keyBy('id');

    expect($users[$otherAdmin->id]['platform_role'])->toBe('Super-Admin')
        ->and($users[$otherAdmin->id]['can']['impersonate'])->toBeFalse()
        ->and($users[$admin->id]['can']['impersonate'])->toBeFalse()
        ->and($users[$regular->id]['can']['impersonate'])->toBeTrue();
});
