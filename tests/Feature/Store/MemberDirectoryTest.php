<?php

use App\Models\Invitation;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Who sees whom inside a store (docs/STORE-ORGANIZATION-SPEC.md rules 12–14)
|--------------------------------------------------------------------------
|
| Membership is the only source of power, so the list is the store's list: every member,
| whoever brought them in — never anybody outside the store, never the platform team.
|
*/

beforeEach(function () {
    $this->store = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->owner = createStoreMember($this->store, Role::OWNER, ['first_name' => 'Olive']);
    $this->admin = createStoreMember($this->store, Role::ADMIN, ['first_name' => 'Adam']);
    $this->staff = createStoreMember($this->store, Role::STAFF, ['first_name' => 'Sam']);

    $this->elsewhere = createStoreMember(Store::factory()->create(), Role::OWNER);
    $this->platform = createPlatformUser(['user-view']);
});

function membersAs(User $user, Store $store)
{
    return test()->actingAs($user)->withSession(['current_store_id' => $store->id])->getJson('/members/data');
}

test('every member of the store is listed, whoever brought them in — and nobody else', function () {
    $ids = collect(membersAs($this->admin, $this->store)->assertOk()->json('members'))->pluck('id');

    expect($ids->sort()->values()->all())->toBe(collect([$this->owner->id, $this->admin->id, $this->staff->id])->sort()->values()->all());
    expect($ids)->not->toContain($this->elsewhere->id)->not->toContain($this->platform->id);
});

test('each row says what the viewer may do to it', function () {
    $rows = collect(membersAs($this->admin, $this->store)->json('members'))->keyBy('id');

    expect($rows[$this->admin->id]['is_you'])->toBeTrue()
        ->and($rows[$this->admin->id]['can_manage'])->toBeFalse()   // never yourself
        ->and($rows[$this->owner->id]['can_manage'])->toBeFalse()   // an Owner only by an Owner
        ->and($rows[$this->staff->id]['can_manage'])->toBeTrue()
        ->and($rows[$this->owner->id]['role']['key'])->toBe(Role::OWNER);

    $asOwner = collect(membersAs($this->owner, $this->store)->json('members'))->keyBy('id');
    expect($asOwner[$this->admin->id]['can_manage'])->toBeTrue();
});

test('the role pickers offer only what the viewer may give', function () {
    expect(collect(membersAs($this->admin, $this->store)->json('assignable_roles'))->pluck('key')->all())
        ->toBe([Role::ADMIN, Role::STAFF, Role::VIEWER]);

    expect(collect(membersAs($this->owner, $this->store)->json('assignable_roles'))->pluck('key')->all())
        ->toBe([Role::OWNER, Role::ADMIN, Role::STAFF, Role::VIEWER]);

    // A custom role of THIS store is offered; another store's never is.
    Role::create(['name' => 'Cashier', 'store_id' => $this->store->id])->permissions()->sync(grantPermissions(['screen-view'])->pluck('id'));
    Role::create(['name' => 'Foreign', 'store_id' => Store::factory()->create()->id]);

    $names = collect(membersAs($this->owner, $this->store)->json('assignable_roles'))->pluck('name');
    expect($names)->toContain('Cashier')->not->toContain('Foreign');
});

test('open invitations are listed with their state and what the viewer may do to them', function () {
    Invitation::factory()->create(['store_id' => $this->store->id, 'email' => 'fresh@example.com', 'role_id' => Role::starter(Role::STAFF)->id]);
    Invitation::factory()->expired()->create(['store_id' => $this->store->id, 'email' => 'stale@example.com']);
    Invitation::factory()->create(['store_id' => $this->store->id, 'email' => 'boss@example.com', 'role_id' => Role::starter(Role::OWNER)->id]);
    Invitation::factory()->create(['email' => 'other-store@example.com']);

    $rows = collect(membersAs($this->admin, $this->store)->json('invitations'))->keyBy('email');

    expect($rows->keys()->sort()->values()->all())->toBe(['boss@example.com', 'fresh@example.com', 'stale@example.com'])
        ->and($rows['stale@example.com']['is_expired'])->toBeTrue()
        ->and($rows['fresh@example.com']['is_expired'])->toBeFalse()
        ->and($rows['fresh@example.com']['can_manage'])->toBeTrue()
        ->and($rows['boss@example.com']['can_manage'])->toBeFalse();
});

test('without member-view the list stays shut', function () {
    membersAs($this->staff, $this->store)->assertForbidden();
});

test('outside a store there is no list, whatever the permission', function () {
    $support = createPlatformUser(['member-view'], 'Support');

    $this->actingAs($support)->getJson('/members/data')->assertNotFound();
});

test('someone removed from the store loses the list on their very next request', function () {
    $this->actingAs($this->owner)->withSession(['current_store_id' => $this->store->id])
        ->deleteJson("/members/{$this->admin->id}", ['password' => 'password'])->assertOk();

    membersAs($this->admin, $this->store)->assertForbidden();
});

test('a member of another store cannot be reached by guessing their id', function () {
    $this->actingAs($this->owner)->withSession(['current_store_id' => $this->store->id])
        ->putJson("/members/{$this->elsewhere->id}", ['role_id' => Role::starter(Role::STAFF)->id])
        ->assertNotFound();

    $this->actingAs($this->owner)->withSession(['current_store_id' => $this->store->id])
        ->deleteJson("/members/{$this->elsewhere->id}")
        ->assertNotFound();
});

test('one person in two stores holds a different role — and different powers — in each', function () {
    $beta = Store::factory()->create(['name' => 'Beta Deli']);
    $this->staff->stores()->attach($beta->id, ['role_id' => Role::starter(Role::ADMIN)->id]);

    // Staff in Alpha: the team page is shut…
    membersAs($this->staff, $this->store)->assertForbidden();

    // …Admin in Beta: it opens, and shows Beta's people only.
    $ids = collect(membersAs($this->staff, $beta)->assertOk()->json('members'))->pluck('id');
    expect($ids->all())->toBe([$this->staff->id]);
});
