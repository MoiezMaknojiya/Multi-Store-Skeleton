<?php

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

function insertLogAt(string $createdAt, string $action = 'user.created'): void
{
    DB::table('activity_logs')->insert([
        'actor_id' => null,
        'actor_name' => 'Old Actor',
        'action' => $action,
        'description' => 'historic entry',
        'created_at' => $createdAt,
    ]);
}

test('mutations write activity log entries with the actor snapshot', function () {
    $admin = createSuperAdmin(['user-store']);

    $this->actingAs($admin)->postJson('/users', [
        'first_name' => 'Logged',
        'last_name' => 'User',
        'phone' => '1231231234',
        'email' => 'logged@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertOk();

    $this->assertDatabaseHas('activity_logs', [
        'actor_id' => $admin->id,
        'actor_name' => $admin->name,
        'action' => 'user.created',
    ]);
});

test('the activity page and data need the activity-view permission', function () {
    $admin = createSuperAdmin(['activity-view']);

    $this->actingAs($admin)->get('/activity')->assertOk();
    $this->actingAs($admin)->getJson('/activity/data')->assertOk();

    $store = Store::factory()->create();
    $unauthorized = createStoreUser($store, ['user-view']);

    $this->actingAs($unauthorized)
        ->withSession(['current_store_id' => $store->id])
        ->getJson('/activity/data')->assertForbidden();
});

test('login, permission changes, and self-account-deletion are all logged (and self-delete cascades)', function () {
    // Login through the real endpoint writes auth.login.
    $store = Store::factory()->create();
    $owner = createStoreUser($store, []);
    $this->post('/login', ['email' => $owner->email, 'password' => 'password'])->assertRedirect();
    $this->assertDatabaseHas('activity_logs', ['action' => 'auth.login', 'actor_id' => $owner->id]);

    // Permission creation is logged.
    $admin = createSuperAdmin(['permission-store']);
    $this->actingAs($admin)->postJson('/permissions', ['name' => 'reports-view'])->assertOk();
    $this->assertDatabaseHas('activity_logs', ['action' => 'permission.created', 'actor_id' => $admin->id]);

    // Self-deletion is logged AND cascades the user's creations.
    $child = User::factory()->create(['created_by' => $owner->id]);
    $this->actingAs($owner)->delete('/profile', ['password' => 'password'])->assertRedirect('/');
    $this->assertDatabaseHas('activity_logs', ['action' => 'account.deleted']);
    $this->assertDatabaseMissing('users', ['id' => $owner->id]);
    $this->assertDatabaseMissing('users', ['id' => $child->id]);
});

test('store switching and password reset via email link are logged', function () {
    $store = Store::factory()->create();
    $member = createStoreUser($store, []);

    // Resetting a password through the email-link flow writes password.reset,
    // attributed to the user even though they are not authenticated yet.
    $token = Password::createToken($member);
    $this->post('/reset-password', [
        'token' => $token,
        'email' => $member->email,
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertRedirect(route('login'));
    $this->assertDatabaseHas('activity_logs', [
        'action' => 'password.reset',
        'actor_id' => $member->id,
        'actor_name' => $member->name,
    ]);

    // Switching into a store writes store.switched.
    $this->actingAs($member)->post('/stores/switch', ['store_id' => $store->id])->assertRedirect();
    $this->assertDatabaseHas('activity_logs', [
        'action' => 'store.switched',
        'actor_id' => $member->id,
        'subject_id' => $store->id,
    ]);
});

test('the activity listing can be bounded by a date range (partition-friendly)', function () {
    $admin = createSuperAdmin(['activity-view']);
    $today = now()->format('Y-m-d');

    insertLogAt($today.' 10:00:00', 'user.created');
    insertLogAt(now()->subYear()->format('Y-m-d').' 10:00:00', 'user.updated');

    // Bounded to today: only today's entry.
    $actions = collect($this->actingAs($admin)
        ->getJson("/activity/data?from={$today}&to={$today}")
        ->assertOk()->json('logs'))->pluck('action');
    expect($actions)->toContain('user.created')->not->toContain('user.updated');

    // No range (All time): both entries.
    $actions = collect($this->actingAs($admin)
        ->getJson('/activity/data')
        ->assertOk()->json('logs'))->pluck('action');
    expect($actions)->toContain('user.created')->toContain('user.updated');

    // The UI sends full ISO-8601 UTC instants (local day boundaries → UTC); those
    // are honored too. A window spanning only today keeps just today's entry.
    $fromIso = now()->startOfDay()->toIso8601String();
    $toIso = now()->endOfDay()->toIso8601String();
    $actions = collect($this->actingAs($admin)
        ->getJson('/activity/data?from='.urlencode($fromIso).'&to='.urlencode($toIso))
        ->assertOk()->json('logs'))->pluck('action');
    expect($actions)->toContain('user.created')->not->toContain('user.updated');

    // Malformed dates are rejected, not silently ignored.
    $this->actingAs($admin)->getJson('/activity/data?from=not-a-date')->assertStatus(422);
});

test('a log made just after UTC midnight is still visible to a viewer whose local day is behind UTC', function () {
    // Regression for the real timezone bug: server stores UTC, the UI computes the
    // range from the viewer's LOCAL day. A viewer in the US (UTC-5) at 19:30 local
    // sees "today" as the previous UTC date — the log sits just past UTC midnight.
    // Because the UI sends local-end-of-day AS a UTC instant, the log stays in range.
    $admin = createSuperAdmin(['activity-view']);
    insertLogAt(now()->startOfDay()->addMinutes(19)->format('Y-m-d H:i:s'), 'user.created');

    // Local-day-end for a UTC-5 viewer whose local "today" is yesterday's UTC date,
    // expressed as the UTC instant the browser would send (local 23:59:59 → +5h UTC).
    $localDayEndUtc = now()->startOfDay()->subMinutes(1)->addDay()->addHours(5)->toIso8601String();
    $localDayStartUtc = now()->startOfDay()->subDay()->addHours(5)->toIso8601String();

    $actions = collect($this->actingAs($admin)
        ->getJson('/activity/data?from='.urlencode($localDayStartUtc).'&to='.urlencode($localDayEndUtc))
        ->assertOk()->json('logs'))->pluck('action');
    expect($actions)->toContain('user.created');
});

test('yearly maintenance deletes logs older than 2 years and keeps the recent ones', function () {
    $admin = createSuperAdmin(['activity-view']);
    $currentYear = now()->year;

    insertLogAt(($currentYear - 3).'-06-01 10:00:00'); // 3 years old — must go
    insertLogAt(($currentYear - 2).'-06-01 10:00:00'); // 2 years old — must go
    insertLogAt(($currentYear - 1).'-06-01 10:00:00'); // previous year — stays
    insertLogAt($currentYear.'-01-15 10:00:00');       // current year — stays

    $response = $this->actingAs($admin)->postJson('/activity/partitions/maintain');

    $response->assertOk();
    expect(DB::table('activity_logs')->where('created_at', '<', ($currentYear - 1).'-01-01')->count())->toBe(0);
    expect(DB::table('activity_logs')->where('description', 'historic entry')->count())->toBe(2);

    // The maintenance run is itself audited.
    $this->assertDatabaseHas('activity_logs', ['action' => 'activity.maintenance', 'actor_id' => $admin->id]);
});

test('partition status is visible with activity-view but maintenance is super-admin-only', function () {
    $admin = createSuperAdmin(['activity-view']);
    $this->actingAs($admin)->getJson('/activity/partitions')->assertOk();

    $store = Store::factory()->create();
    $viewer = createStoreUser($store, ['activity-view']);

    $this->actingAs($viewer)
        ->withSession(['current_store_id' => $store->id])
        ->getJson('/activity/partitions')->assertOk();

    $this->actingAs($viewer)
        ->withSession(['current_store_id' => $store->id])
        ->postJson('/activity/partitions/maintain')->assertForbidden();
});

test('the activity listing returns entries newest first and survives actor deletion', function () {
    $admin = createSuperAdmin(['activity-view', 'user-store', 'user-destroy']);

    $this->actingAs($admin)->postJson('/users', [
        'first_name' => 'Short',
        'last_name' => 'Lived',
        'phone' => '9998887771',
        'email' => 'shortlived@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertOk();

    $target = User::where('email', 'shortlived@example.com')->firstOrFail();
    $this->actingAs($admin)->deleteJson("/users/{$target->id}")->assertOk();

    $response = $this->actingAs($admin)->getJson('/activity/data');
    $response->assertOk();
    $actions = collect($response->json('logs'))->pluck('action');
    expect($actions->first())->toBe('user.deleted');
    expect($actions)->toContain('user.created');
});
