<?php

use App\Models\ActivityLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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
    Notification::fake();
    $admin = createSuperAdmin(['store-store']);

    $this->actingAs($admin)->postJson('/stores', [
        'name' => 'Logged Mart', 'street' => '1 Main St', 'city' => 'Austin', 'state' => 'TX',
        'zip_code' => '73301', 'country' => 'USA', 'owner_email' => 'owner@example.com',
    ])->assertCreated();

    $this->assertDatabaseHas('activity_logs', [
        'actor_id' => $admin->id,
        'actor_name' => $admin->name,
        'action' => 'store.created',
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

test('login, permission changes, and self-account-deletion are all logged', function () {
    // Login through the real endpoint writes auth.login.
    $store = Store::factory()->create();
    $owner = createStoreUser($store, []);
    $this->post('/login', ['email' => $owner->email, 'password' => 'password'])->assertRedirect();
    $this->assertDatabaseHas('activity_logs', ['action' => 'auth.login', 'actor_id' => $owner->id]);

    // Permission creation is logged.
    $admin = createSuperAdmin(['permission-store']);
    $this->actingAs($admin)->postJson('/permissions', ['name' => 'reports-view'])->assertOk();
    $this->assertDatabaseHas('activity_logs', ['action' => 'permission.created', 'actor_id' => $admin->id]);

    // Self-deletion is logged, and takes the account alone.
    $colleague = createStoreUser($store, []);
    $this->actingAs($owner)->delete('/profile', ['password' => 'password'])->assertRedirect('/');
    $this->assertDatabaseHas('activity_logs', ['action' => 'account.deleted']);
    $this->assertDatabaseMissing('users', ['id' => $owner->id]);
    $this->assertDatabaseHas('users', ['id' => $colleague->id]);
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
    // A fixed clock: "today" must not turn into tomorrow between writing an entry and asking for it.
    $this->travelTo('2026-06-16 12:00:00');
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
    // range from the viewer's LOCAL day. A viewer in the US (UTC-5) at 19:30 on
    // 15 June is already on 16 June by UTC, so a log made at 00:19 UTC sits on the
    // next UTC date — yet it happened on the viewer's 15 June. Because the UI sends
    // the local day's ends AS UTC instants, the log stays in range. A fixed clock,
    // so the scenario is always this one.
    $this->travelTo('2026-06-16 00:30:00');
    $admin = createSuperAdmin(['activity-view']);
    insertLogAt('2026-06-16 00:19:00', 'user.created');
    // 05:30 UTC is already the viewer's 16 June: outside the day they asked for.
    insertLogAt('2026-06-16 05:30:00', 'user.updated');

    // The viewer's 15 June, 00:00 to 23:59:59 local, as the UTC instants the browser sends.
    $localDayStartUtc = '2026-06-15T05:00:00+00:00';
    $localDayEndUtc = '2026-06-16T04:59:59+00:00';

    $actions = collect($this->actingAs($admin)
        ->getJson('/activity/data?from='.urlencode($localDayStartUtc).'&to='.urlencode($localDayEndUtc))
        ->assertOk()->json('logs'))->pluck('action');
    expect($actions)->toContain('user.created')->not->toContain('user.updated');
});

test('yearly maintenance deletes logs older than 2 years and keeps the recent ones', function () {
    $admin = createSuperAdmin(['activity-view', 'activity-destroy']);
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

test('partition status comes with activity-view, but maintenance needs activity-destroy', function () {
    // Reading the log is not deleting it: maintenance drops whole years for good, so it
    // is a permission of its own (who may hold it: PlatformPermissionTiersTest). A super admin
    // holds every permission, so the reader here is a platform team member.
    $reader = createPlatformUser(['activity-view']);

    $this->actingAs($reader)->getJson('/activity/partitions')->assertOk();
    $this->actingAs($reader)->postJson('/activity/partitions/maintain')->assertForbidden();
});

test('the activity listing returns entries newest first and survives actor deletion', function () {
    $admin = createSuperAdmin(['activity-view', 'user-destroy']);
    $store = Store::factory()->create();
    $member = createStoreUser($store, []);
    $name = $member->name;

    // Something the soon-deleted person did…
    $this->actingAs($member)->post('/stores/switch', ['store_id' => $store->id])->assertRedirect();
    $this->actingAs($admin)->deleteJson("/users/{$member->id}", ['password' => 'password'])->assertOk();

    $logs = collect($this->actingAs($admin)->getJson('/activity/data')->assertOk()->json('logs'));

    expect($logs->pluck('action')->first())->toBe('user.deleted');
    // …still names them after they are gone — and no longer points at their id, which a later account
    // could otherwise be mistaken for (on MySQL the partitioned log has no foreign key to empty it).
    expect($logs->firstWhere('action', 'store.switched')['actor_name'])->toBe($name)
        ->and(ActivityLog::where('action', 'store.switched')->value('actor_id'))->toBeNull();
});

test('a long name or description is cut to its column instead of failing the action', function () {
    // Two 200-character names make a 401-character full name; a column of 255 in MySQL's strict mode would
    // refuse it, and the log line that fails first is the sign-out's.
    $person = User::factory()->create(['first_name' => str_repeat('a', 200), 'last_name' => str_repeat('b', 200)]);

    ActivityLog::record('test.long', null, str_repeat('x', 1500), $person);

    $entry = ActivityLog::latest('id')->first();

    expect(mb_strlen($entry->actor_name))->toBe(255)
        ->and(mb_strlen($entry->description))->toBe(1000);
});
