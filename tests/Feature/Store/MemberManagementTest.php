<?php

use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\Role;
use App\Models\Screen;
use App\Models\Store;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Changing, removing and leaving (docs/STORE-ORGANIZATION-SPEC.md rules 7–10, 20)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->store = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->owner = createStoreMember($this->store, Role::OWNER);
    $this->admin = createStoreMember($this->store, Role::ADMIN);
    $this->staff = createStoreMember($this->store, Role::STAFF);
});

function inStore(User $user, Store $store)
{
    return test()->actingAs($user)->withSession(['current_store_id' => $store->id]);
}

test('an Owner changes a member’s role, and it is logged', function () {
    inStore($this->owner, $this->store)
        ->putJson("/members/{$this->staff->id}", ['role_id' => Role::starter(Role::ADMIN)->id])
        ->assertOk();

    expect(roleKeyIn($this->staff, $this->store))->toBe(Role::ADMIN);
    expect(ActivityLog::where('action', 'member.role_changed')->latest('id')->value('description'))->toContain('from Staff to Admin');
});

test('the Owner role is given and managed only by whoever holds everything it allows — not an Admin', function () {
    inStore($this->admin, $this->store)
        ->putJson("/members/{$this->staff->id}", ['role_id' => Role::starter(Role::OWNER)->id])
        ->assertStatus(422)->assertJsonValidationErrors('role_id');

    inStore($this->admin, $this->store)
        ->putJson("/members/{$this->owner->id}", ['role_id' => Role::starter(Role::STAFF)->id])
        ->assertForbidden();

    inStore($this->admin, $this->store)->deleteJson("/members/{$this->owner->id}")->assertForbidden();

    expect(roleKeyIn($this->owner, $this->store))->toBe(Role::OWNER);
    expect(roleKeyIn($this->staff, $this->store))->toBe(Role::STAFF);
});

test('a role from another store cannot be given here', function () {
    $foreign = Role::create(['name' => 'Foreign', 'store_id' => Store::factory()->create()->id]);

    inStore($this->owner, $this->store)
        ->putJson("/members/{$this->staff->id}", ['role_id' => $foreign->id])
        ->assertStatus(422)->assertJsonValidationErrors('role_id');
});

test('a custom role gives and manages only what it holds itself', function () {
    // A supervisor who may change roles, and can see — but not upload — content.
    $supervisor = createStoreUser($this->store, ['member-update', 'screen-view', 'media-view', 'daypart-view'], 'Supervisor');
    $viewer = createStoreMember($this->store, Role::VIEWER);
    $helper = Role::create(['name' => 'Helper', 'store_id' => $this->store->id]);
    $helper->permissions()->sync(grantPermissions(['screen-view'])->pluck('id'));

    // Staff can upload — more than the supervisor holds — so it cannot be handed out…
    inStore($supervisor, $this->store)
        ->putJson("/members/{$viewer->id}", ['role_id' => Role::starter(Role::STAFF)->id])
        ->assertStatus(422);

    // …and a Staff member, holding more than the supervisor, cannot be changed at all.
    inStore($supervisor, $this->store)
        ->putJson("/members/{$this->staff->id}", ['role_id' => $helper->id])
        ->assertForbidden();

    // Within its reach, it works.
    inStore($supervisor, $this->store)
        ->putJson("/members/{$viewer->id}", ['role_id' => $helper->id])
        ->assertOk();
});

test('an Owner may demote another Owner, and then — the last one left — cannot leave', function () {
    $partner = createStoreMember($this->store, Role::OWNER);

    inStore($this->owner, $this->store)
        ->putJson("/members/{$partner->id}", ['role_id' => Role::starter(Role::ADMIN)->id])
        ->assertOk();

    // Now the only Owner, they cannot leave the store without one — and the partner, an Admin
    // now, holds less than the Owner role allows, so cannot remove them either.
    inStore($this->owner, $this->store)->postJson('/members/leave')->assertStatus(422)
        ->assertJsonFragment(['message' => 'You are the only Owner of Alpha Mart. Make someone else an Owner before you leave.']);
    inStore($partner, $this->store)->deleteJson("/members/{$this->owner->id}")->assertForbidden();

    expect(roleKeyIn($this->owner, $this->store))->toBe(Role::OWNER);
});

test('holding everything the Owner role allows reaches an Owner — yet the store\'s only Owner always stays', function () {
    // No Owner-only rule (owner's rule, 2026-09-17): a role with every permission the Owner role holds reaches an Owner.
    $deputy = createStoreUser($this->store, Role::owner()->permissions()->pluck('name')->all(), 'Deputy');
    $message = "{$this->owner->name} is the only Owner of Alpha Mart. Make someone else an Owner first.";

    $row = fn () => collect(inStore($deputy, $this->store)->getJson('/members/data')->assertOk()->json('members'))->firstWhere('id', $this->owner->id);
    expect($row()['can_manage'])->toBeFalse();

    inStore($deputy, $this->store)
        ->putJson("/members/{$this->owner->id}", ['role_id' => Role::starter(Role::ADMIN)->id])
        ->assertStatus(422)->assertJsonValidationErrors(['role_id' => $message]);
    inStore($deputy, $this->store)
        ->deleteJson("/members/{$this->owner->id}", ['password' => 'password'])
        ->assertStatus(422)->assertJsonPath('message', $message);
    expect(roleKeyIn($this->owner, $this->store))->toBe(Role::OWNER);

    // With a partner Owner beside them, the deputy may make the first one an Admin.
    $partner = createStoreMember($this->store, Role::OWNER);
    expect($row()['can_manage'])->toBeTrue();

    inStore($deputy, $this->store)
        ->putJson("/members/{$this->owner->id}", ['role_id' => Role::starter(Role::ADMIN)->id])
        ->assertOk();
    expect(roleKeyIn($this->owner, $this->store))->toBe(Role::ADMIN)
        ->and(roleKeyIn($partner, $this->store))->toBe(Role::OWNER);
});

test('removing a member takes only the membership — their account and their work stay', function () {
    $upload = Media::factory()->create(['store_id' => $this->store->id, 'created_by' => $this->staff->id]);
    $screen = Screen::factory()->create(['store_id' => $this->store->id, 'created_by' => $this->staff->id]);

    inStore($this->admin, $this->store)->deleteJson("/members/{$this->staff->id}", ['password' => 'password'])->assertOk();

    expect(roleKeyIn($this->staff, $this->store))->toBeNull();
    expect(User::find($this->staff->id))->not->toBeNull();
    expect(Media::find($upload->id)->created_by)->toBe($this->staff->id);
    expect(Screen::find($screen->id))->not->toBeNull();
    expect(ActivityLog::where('action', 'member.removed')->exists())->toBeTrue();
});

test('you do not remove yourself — you leave, and the store drops out of your session', function () {
    inStore($this->admin, $this->store)->deleteJson("/members/{$this->admin->id}")->assertStatus(422);

    inStore($this->admin, $this->store)->postJson('/members/leave')
        ->assertOk()
        ->assertJsonFragment(['redirect' => route('dashboard')]);

    expect(roleKeyIn($this->admin, $this->store))->toBeNull();
    expect(session('current_store_id'))->toBeNull();
    expect(ActivityLog::where('action', 'member.left')->exists())->toBeTrue();
});

test('the gates hold for people without the permission', function () {
    inStore($this->staff, $this->store)
        ->putJson("/members/{$this->admin->id}", ['role_id' => Role::starter(Role::VIEWER)->id])
        ->assertForbidden();

    inStore($this->staff, $this->store)->deleteJson("/members/{$this->admin->id}")->assertForbidden();
});
