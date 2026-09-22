<?php

use App\Models\ActivityLog;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Every account, from the platform's side (docs/STORE-ORGANIZATION-SPEC.md rules 13, 19, 21, 24)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Notification::fake();

    // The first super admin ever made is the primary one.
    $this->primary = createSuperAdmin(['user-view', 'user-destroy']);
    $this->store = Store::factory()->create(['name' => 'Alpha Mart']);
});

function usersFor(User $viewer)
{
    return collect(test()->actingAs($viewer)->getJson('/users/data')->assertOk()->json('users'))->keyBy('id');
}

test('a super admin sees every account, with what each one can reach', function () {
    $owner = createStoreMember($this->store, Role::OWNER);
    $ops = createPlatformUser(['user-view'], 'Support');

    $rows = usersFor($this->primary);

    expect($rows[$owner->id]['memberships'])->toBe([['store_id' => $this->store->id, 'store_name' => 'Alpha Mart', 'role_name' => 'Owner', 'role_key' => Role::OWNER]])
        ->and($rows[$owner->id]['sole_owner_of'])->toBe(['Alpha Mart'])
        ->and($rows[$ops->id]['platform_role'])->toBe('Support')
        ->and($rows[$this->primary->id]['is_you'])->toBeTrue()
        ->and($rows[$this->primary->id]['is_primary'])->toBeTrue()
        ->and($rows[$owner->id]['can'])->toBe(['impersonate' => true, 'manage_stores' => true, 'remove_platform_role' => false, 'delete' => true])
        // The platform team works above the stores: nobody there is put in one.
        ->and($rows[$ops->id]['can']['manage_stores'])->toBeFalse();
});

test('support sees customers only — never the platform team', function () {
    $owner = createStoreMember($this->store, Role::OWNER);
    $support = createPlatformUser(['user-view']);

    $ids = usersFor($support)->keys();

    expect($ids)->toContain($owner->id)->not->toContain($this->primary->id)->not->toContain($support->id);
});

test('deleting an account removes the person from their stores and reports stores left without an owner', function () {
    $owner = createStoreMember($this->store, Role::OWNER);
    $staff = createStoreMember($this->store, Role::STAFF);

    $this->actingAs($this->primary)->deleteJson("/users/{$owner->id}", ['password' => 'password'])
        ->assertOk()
        ->assertJsonFragment(['ownerless_stores' => ['Alpha Mart']]);

    expect(User::find($owner->id))->toBeNull()
        ->and(Store::find($this->store->id))->not->toBeNull()
        ->and(roleKeyIn($staff, $this->store))->toBe(Role::STAFF)
        ->and(ActivityLog::where('action', 'user.deleted')->latest('id')->value('description'))->toContain('left without an Owner: Alpha Mart');
});

test('nobody deletes themselves, the primary super admin, or — unless primary — another super admin', function () {
    $second = createSuperAdmin();
    $third = createSuperAdmin();

    $this->actingAs($this->primary)->deleteJson("/users/{$this->primary->id}")->assertStatus(422);
    $this->actingAs($second)->deleteJson("/users/{$this->primary->id}")->assertForbidden();
    $this->actingAs($second)->deleteJson("/users/{$third->id}")->assertForbidden();

    $this->actingAs($this->primary)->deleteJson("/users/{$third->id}", ['password' => 'password'])->assertOk();
    expect(User::find($third->id))->toBeNull();
});

test('support cannot reach platform accounts even with user-destroy', function () {
    $support = createPlatformUser(['user-view', 'user-destroy']);
    $ops = createPlatformUser(['user-view'], 'Ops');

    $this->actingAs($support)->deleteJson("/users/{$ops->id}")->assertNotFound();
    $this->actingAs($support)->deleteJson("/users/{$this->primary->id}")->assertNotFound();
    expect(User::find($ops->id))->not->toBeNull();
});

test('removing a platform role: super admins only, the primary protected, super admins by the primary alone', function () {
    $second = createSuperAdmin();
    $third = createSuperAdmin();
    $ops = createPlatformUser(['user-view'], 'Ops');

    $this->actingAs($second)->deleteJson("/users/{$ops->id}/platform-role", ['password' => 'password'])->assertOk();
    expect(DB::table('store_user')->where('user_id', $ops->id)->exists())->toBeFalse();

    $this->actingAs($second)->deleteJson("/users/{$third->id}/platform-role")->assertForbidden();
    $this->actingAs($second)->deleteJson("/users/{$this->primary->id}/platform-role")->assertForbidden();
    $this->actingAs($this->primary)->deleteJson("/users/{$this->primary->id}/platform-role")->assertStatus(422);

    $this->actingAs($this->primary)->deleteJson("/users/{$third->id}/platform-role", ['password' => 'password'])->assertOk();
    expect($third->fresh()->isSuperAdmin())->toBeFalse();

    $support = createPlatformUser(['user-view', 'user-destroy'], 'Support');
    $this->actingAs($support)->deleteJson("/users/{$second->id}/platform-role")->assertForbidden();
});

test('a super admin invites platform staff; a store account cannot join the team', function () {
    $supportRole = Role::create(['name' => 'Support', 'is_global' => true]);

    $this->actingAs($this->primary)->postJson('/users/invitations', ['email' => 'helper@example.com', 'role_id' => $supportRole->id])
        ->assertCreated();

    $invitation = Invitation::forPlatform()->sole();
    expect($invitation->role_id)->toBe($supportRole->id);
    Notification::assertSentOnDemand(InvitationNotification::class);

    $owner = createStoreMember($this->store, Role::OWNER);
    $this->actingAs($this->primary)->postJson('/users/invitations', ['email' => $owner->email, 'role_id' => $supportRole->id])
        ->assertStatus(422)->assertJsonValidationErrors('email');

    // A store role is not a platform role.
    $this->actingAs($this->primary)->postJson('/users/invitations', ['email' => 'x@example.com', 'role_id' => Role::starter(Role::ADMIN)->id])
        ->assertStatus(422)->assertJsonValidationErrors('role_id');

    // Accepting puts the new person on the platform row.
    Invitation::factory()->forPlatform()->withToken($token = str_repeat('p', 64))->create([
        'email' => 'joiner@example.com', 'role_id' => $supportRole->id,
    ]);
    auth()->logout();
    $this->post("/invitations/{$token}/register", [
        'first_name' => 'Jo', 'last_name' => 'Iner', 'phone' => '5551234567', 'password' => 'Str0ng-Password!', 'password_confirmation' => 'Str0ng-Password!',
    ])->assertRedirect(route('dashboard'));

    expect(User::where('email', 'joiner@example.com')->firstOrFail()->globalRole()?->id)->toBe($supportRole->id);
});

test('platform invitations are the super admins’ alone', function () {
    $support = createPlatformUser(['user-view', 'user-destroy']);
    $role = Role::create(['name' => 'Support', 'is_global' => true]);

    $this->actingAs($support)->postJson('/users/invitations', ['email' => 'x@example.com', 'role_id' => $role->id])->assertForbidden();
    $this->actingAs($support)->getJson('/users/invitations')->assertForbidden();
});

test('the accounts listing runs a bounded number of queries however many rows there are', function () {
    $countQueries = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->primary)->getJson('/users/data')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    createStoreMember($this->store, Role::OWNER);
    $countQueries();   // warm-up: the first request also loads the viewer's own roles
    $few = $countQueries();

    foreach (range(1, 12) as $i) {
        createStoreMember(Store::factory()->create(), $i % 2 ? Role::OWNER : Role::STAFF);
    }
    $many = $countQueries();

    expect($many)->toBe($few);
});

test('a store left in a platform account’s session never takes its platform powers away', function () {
    // Not a super admin: Gate::before would let one through whatever the session said. A support account's
    // permissions come from its platform role alone, and a store id left in its session — where it is no
    // member, so it holds nothing — must not swap that role for the empty one.
    $support = createPlatformUser(['user-view'], 'Support');
    $owner = createStoreMember($this->store, Role::OWNER);

    $ids = collect($this->actingAs($support)->withSession(['current_store_id' => $this->store->id])
        ->getJson('/users/data')->assertOk()->json('users'))->pluck('id');

    expect($ids)->toContain($owner->id);
});

test('only the primary super admin invites a super admin — the one who can also take it away', function () {
    $second = createSuperAdmin(['user-view']);
    $superAdminRole = Role::find(Role::superAdminId());

    $this->actingAs($second)->postJson('/users/invitations', ['email' => 'boss@example.com', 'role_id' => $superAdminRole->id])
        ->assertStatus(422)->assertJsonValidationErrors(['role_id' => 'Only the primary super admin can invite a super admin.']);

    $this->actingAs($second)->get('/users')->assertOk()
        ->assertViewHas('platformRoles', fn ($roles) => $roles->doesntContain(fn (Role $role) => $role->isSuperAdmin()));

    $this->actingAs($this->primary)->get('/users')->assertOk()
        ->assertViewHas('platformRoles', fn ($roles) => $roles->contains(fn (Role $role) => $role->isSuperAdmin()));

    $this->actingAs($this->primary)->postJson('/users/invitations', ['email' => 'boss@example.com', 'role_id' => $superAdminRole->id])
        ->assertCreated();
});
