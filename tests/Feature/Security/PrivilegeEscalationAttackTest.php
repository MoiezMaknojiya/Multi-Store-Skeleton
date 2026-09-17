<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Climbing the ladder, on purpose
|--------------------------------------------------------------------------
|
| Every way a person might try to end up with more than they were given: a role that holds what its
| maker does not, a name that passes for Super-Admin, a flag smuggled through a form, a platform page
| opened from inside a store, "Log in as" pointed at somebody stronger.
|
*/

beforeEach(function () {
    $this->store = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->other = Store::factory()->create(['name' => 'Beta Deli']);

    // A member trusted with the store's roles and its team, and nothing else.
    $this->maker = createStoreUser($this->store, ['role-view', 'role-store', 'role-update', 'role-destroy', 'member-view', 'member-invite', 'member-update', 'screen-view'], 'Role Maker');
    $this->owner = createStoreMember($this->store, Role::OWNER);
    $this->staff = createStoreMember($this->store, Role::STAFF);

    $this->actingAs($this->maker)->withSession(['current_store_id' => $this->store->id]);
});

test('a custom role cannot be given a single permission its maker does not hold', function () {
    foreach (['media-view', 'daypart-view', 'store-destroy', 'channel-view', 'activity-view'] as $beyond) {
        $this->postJson('/roles', [
            'name' => 'Climber '.$beyond,
            'permissions' => Permission::whereIn('name', ['screen-view', $beyond])->pluck('id')->all(),
        ])->assertStatus(422)->assertJsonValidationErrors(['permissions' => 'You can only give a role permissions you hold yourself.']);
    }

    expect(Role::where('name', 'like', 'Climber%')->exists())->toBeFalse();
});

test('a store’s role can never carry the accounts, the catalogue or the yearly log maintenance', function () {
    foreach (['user-view', 'user-destroy', 'activity-destroy', 'permission-view', 'permission-update'] as $never) {
        $this->postJson('/roles', [
            'name' => 'Overreach',
            'permissions' => grantPermissions(['screen-view', $never])->pluck('id')->all(),
        ])->assertStatus(422)->assertJsonValidationErrors('permissions');
    }

    expect(Role::where('name', 'Overreach')->exists())->toBeFalse();
});

test('permission ids that do not exist, or are not ids at all, are refused rather than swallowed', function () {
    $screenView = Permission::firstWhere('name', 'screen-view')->id;

    foreach ([[999999], [0], [-1], [$screenView, 999999], ['abc'], [[1]], [1.5]] as $payload) {
        $status = $this->postJson('/roles', ['name' => 'Nonsense', 'permissions' => $payload])->status();
        expect($status)->toBe(422, 'permissions '.json_encode($payload)." answered {$status}");
    }

    expect(Role::where('name', 'Nonsense')->exists())->toBeFalse();
});

test('flags smuggled through the role form are ignored: no global role, no Owner key, no other store', function () {
    $this->postJson('/roles', [
        'name' => 'Smuggler',
        'permissions' => Permission::whereIn('name', ['screen-view'])->pluck('id')->all(),
        // None of these are part of the form.
        'is_global' => true,
        'key' => Role::OWNER,
        'store_id' => $this->other->id,
        'created_by' => $this->owner->id,
        'id' => 1,
        'type' => 'platform',
    ])->assertCreated();

    $role = Role::firstWhere('name', 'Smuggler');
    expect($role->is_global)->toBeFalse()
        ->and($role->key)->toBeNull()
        ->and($role->store_id)->toBe($this->store->id)
        ->and($role->permissions->pluck('name')->all())->toBe(['screen-view']);
});

test('nothing may be named like Super-Admin, however it is spelled', function () {
    foreach (['Super-Admin', 'super-admin', 'SUPER-ADMIN', ' Super-Admin ', 'Súper-Admin', 'Super Admin', 'super_admin', 'Ѕuper-Admin'] as $name) {
        $status = $this->postJson('/roles', [
            'name' => $name,
            'permissions' => Permission::whereIn('name', ['screen-view'])->pluck('id')->all(),
        ])->status();

        // Refused outright, or created as an ordinary custom role that is NOT the Super-Admin anchor.
        if ($status === 201) {
            $role = Role::firstWhere('name', $name);
            expect($role->isSuperAdmin())->toBeFalse("{$name} passed for the Super-Admin role")
                ->and($role->is_global)->toBeFalse()
                ->and($role->store_id)->toBe($this->store->id);
            $holder = User::factory()->create();
            $holder->stores()->attach($this->store->id, ['role_id' => $role->id]);
            expect($holder->fresh()->isSuperAdmin())->toBeFalse("{$name} made its holder a super admin");
        } else {
            expect($status)->toBe(422, "{$name} answered {$status}");
        }
    }
});

test('the Super-Admin role and the Owner role cannot be edited or deleted from inside a store', function () {
    $superAdminRole = Role::firstOrCreate(['name' => Role::SUPER_ADMIN], ['is_global' => true]);
    $ownerRole = Role::owner();

    expect($this->putJson("/roles/{$superAdminRole->id}", ['name' => 'Mine', 'permissions' => []])->status())->toBeIn([403, 404])
        ->and($this->deleteJson("/roles/{$superAdminRole->id}", ['password' => 'password'])->status())->toBeIn([403, 404])
        ->and($this->putJson("/roles/{$ownerRole->id}", ['name' => 'Mine', 'permissions' => []])->status())->toBeIn([403, 404])
        ->and($this->deleteJson("/roles/{$ownerRole->id}", ['password' => 'password'])->status())->toBeIn([403, 404, 422]);

    expect(Role::find($superAdminRole->id)->name)->toBe(Role::SUPER_ADMIN)
        ->and(Role::owner())->not->toBeNull();
});

test('nobody promotes themselves, and the Owner role is not handed out by somebody who is not one', function () {
    // Their own role
    $this->putJson("/members/{$this->maker->id}", ['role_id' => Role::starter(Role::OWNER)->id])->assertForbidden();
    // Somebody else's, to a role beyond their own reach: refused outright (403), because handing out
    // a role you do not hold every permission of is not a validation problem — it is not allowed.
    $this->putJson("/members/{$this->staff->id}", ['role_id' => Role::starter(Role::OWNER)->id])->assertForbidden();
    $this->putJson("/members/{$this->staff->id}", ['role_id' => Role::starter(Role::ADMIN)->id])->assertForbidden();
    // An invitation to a role beyond their reach
    $this->postJson('/members/invitations', ['email' => 'boss@example.com', 'role_id' => Role::starter(Role::OWNER)->id])->assertStatus(422);

    expect(roleKeyIn($this->maker, $this->store))->toBeNull()   // still their custom role
        ->and(roleKeyIn($this->staff, $this->store))->toBe(Role::STAFF)
        ->and(DB::table('invitations')->count())->toBe(0);
});

test('the platform’s own pages stay shut to a store member, whatever they hold there', function () {
    foreach ([
        fn () => $this->get('/users'),
        fn () => $this->getJson('/users/data'),
        fn () => $this->getJson("/users/{$this->staff->id}/stores"),
        fn () => $this->postJson("/users/{$this->staff->id}/stores", ['store_id' => $this->other->id, 'role_id' => Role::starter(Role::STAFF)->id]),
        fn () => $this->deleteJson("/users/{$this->staff->id}", ['password' => 'password']),
        fn () => $this->deleteJson("/users/{$this->staff->id}/platform-role", ['password' => 'password']),
        fn () => $this->postJson("/users/{$this->staff->id}/impersonate"),
        fn () => $this->getJson('/users/invitations'),
        fn () => $this->postJson('/users/invitations', ['email' => 'x@example.com', 'role_id' => 1]),
        fn () => $this->get('/permissions'),
        fn () => $this->postJson('/permissions', ['name' => 'mine-view']),
        fn () => $this->get('/stores'),
        fn () => $this->getJson('/stores/data'),
        fn () => $this->postJson('/stores', ['name' => 'Mine']),
        fn () => $this->get('/campaigns'),
        fn () => $this->getJson('/activity/partitions'),
        fn () => $this->postJson('/activity/partitions/maintain'),
        fn () => $this->putJson('/network-ads/stores', ['store_ids' => [$this->other->id], 'accepts' => true]),
    ] as $i => $attempt) {
        $status = $attempt()->status();
        expect($status)->toBeIn([403, 404], "platform attempt #{$i} answered {$status}");
    }

    expect(Permission::where('name', 'mine-view')->exists())->toBeFalse()
        ->and(Store::where('name', 'Mine')->exists())->toBeFalse()
        ->and($this->other->fresh()->accepts_network_ads)->toBeFalse();
});

test('platform support cannot reach what the Super-Admin keeps, and cannot enter a store', function () {
    $support = createPlatformUser(['user-view', 'store-view', 'channel-view', 'activity-view'], 'Support');
    $primary = createSuperAdmin();

    // No store in the session to start with, so the refused "switch" below is the only thing that
    // could ever have put one there.
    $this->actingAs($support);
    $this->flushSession();

    foreach ([
        fn () => $this->get('/permissions'),
        fn () => $this->postJson('/permissions', ['name' => 'support-view']),
        fn () => $this->getJson('/users/invitations'),
        fn () => $this->postJson('/users/invitations', ['email' => 'mate@example.com', 'role_id' => Role::superAdminId()]),
        fn () => $this->getJson("/users/{$primary->id}/stores"),
        fn () => $this->postJson("/users/{$primary->id}/impersonate"),
        fn () => $this->deleteJson("/users/{$primary->id}", ['password' => 'password']),
        fn () => $this->deleteJson("/users/{$primary->id}/platform-role", ['password' => 'password']),
        fn () => $this->post('/stores/switch', ['store_id' => $this->store->id]),
        fn () => $this->postJson('/roles', ['name' => 'Support Made This', 'type' => 'platform', 'permissions' => []]),
    ] as $i => $attempt) {
        $status = $attempt()->status();
        expect($status)->toBeIn([403, 404], "support attempt #{$i} answered {$status}");
    }

    expect(User::find($primary->id))->not->toBeNull()
        ->and($primary->fresh()->isSuperAdmin())->toBeTrue()
        ->and(session('current_store_id'))->toBeNull()
        ->and(Role::where('name', 'Support Made This')->exists())->toBeFalse();
});

test('"Log in as" never reaches a super admin, the primary, or the person themself', function () {
    $primary = createSuperAdmin();          // the first Super-Admin membership: the primary
    $second = createSuperAdmin();
    $member = createStoreMember($this->store, Role::STAFF);

    $this->actingAs($second);
    expect($this->postJson("/users/{$primary->id}/impersonate")->status())->toBe(403)
        ->and($this->postJson("/users/{$second->id}/impersonate")->status())->toBe(403);

    // A real impersonation, then the way back: it belongs to the person being viewed as, nobody else.
    $this->postJson("/users/{$member->id}/impersonate")->assertRedirect();
    expect(auth()->id())->toBe($member->id);

    $this->actingAs($this->staff)->post('/impersonate/stop')->assertRedirect();
    expect(auth()->id())->toBe($this->staff->id);
});
