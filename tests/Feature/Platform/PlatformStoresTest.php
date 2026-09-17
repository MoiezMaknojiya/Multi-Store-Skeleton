<?php

use App\Models\ActivityLog;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\Screen;
use App\Models\Store;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Every store, from the platform's side (docs/STORE-ORGANIZATION-SPEC.md rule 18)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Notification::fake();

    $this->admin = createSuperAdmin(['store-view', 'store-store', 'store-update', 'store-destroy']);
    registerPermissionGates();
});

function storeFormPayload(array $overrides = []): array
{
    return [
        'name' => 'Gamma Grocers', 'street' => '9 Elm St', 'suite' => null, 'city' => 'Houston',
        'state' => 'TX', 'zip_code' => '77001', 'country' => 'USA', 'is_active' => true, ...$overrides,
    ];
}

test('the platform sees every store with its members — and is offered Invite owner only where there is no Owner', function () {
    $owned = Store::factory()->create(['name' => 'Alpha Mart']);
    createStoreMember($owned, Role::OWNER, ['first_name' => 'Olive', 'last_name' => 'Owner']);
    createStoreMember($owned, Role::STAFF);
    $orphan = Store::factory()->create(['name' => 'Beta Deli']);
    createStoreMember($orphan, Role::STAFF);
    Store::factory()->create(['name' => 'Gamma Grill']);

    $rows = collect($this->actingAs($this->admin)->getJson('/stores/data')->assertOk()->json('stores'))->keyBy('name');

    // No owners on the list: a store may have several Owners — partners. Invite owner is for a store with none
    // (owner's rule, 2026-09-17); more Owners come from the store's own Members page, or Users → Stores.
    expect($rows['Alpha Mart']['members_count'])->toBe(2)
        ->and($rows['Alpha Mart'])->not->toHaveKey('owners')->not->toHaveKey('owner_invitation')
        ->and($rows['Alpha Mart']['can'])->toBe(['update' => true, 'destroy' => true, 'invite_owner' => false])
        ->and($rows['Beta Deli']['can']['invite_owner'])->toBeTrue()
        ->and($rows['Gamma Grill']['can']['invite_owner'])->toBeTrue();

    // Without store-store nobody is offered it at all.
    $support = createPlatformUser(['store-view']);
    expect(collect($this->actingAs($support)->getJson('/stores/data')->json('stores'))->pluck('can.invite_owner')->unique()->all())->toBe([false]);
});

test('the Stores page is the platform\'s — a store member, even an Owner holding every store permission, works in Settings → Stores', function () {
    $store = Store::factory()->create(['name' => 'Own Shop']);
    $owner = createStoreMember($store, Role::OWNER);
    Role::owner()->permissions()->syncWithoutDetaching(grantPermissions(['store-view', 'store-store', 'store-update', 'store-destroy'])->pluck('id'));

    $this->actingAs($owner)->withSession(['current_store_id' => $store->id]);

    $this->get('/stores')->assertForbidden();
    $this->getJson('/stores/data')->assertForbidden();
    $this->postJson('/stores', storeFormPayload())->assertForbidden();
    $this->putJson("/stores/{$store->id}", storeFormPayload(['name' => 'Renamed']))->assertForbidden();
    $this->deleteJson("/stores/{$store->id}", ['confirm_name' => 'Own Shop', 'password' => 'password'])->assertForbidden();
    $this->postJson("/stores/{$store->id}/owner-invitation", ['email' => 'x@example.com'])->assertForbidden();

    expect($store->fresh()->name)->toBe('Own Shop')
        ->and(Store::count())->toBe(1);
});

test('creating a store sends its owner an invitation and gives nobody access yet', function () {
    $this->actingAs($this->admin)->postJson('/stores', [...storeFormPayload(), 'owner_email' => 'Gina@Example.com'])
        ->assertCreated();

    $store = Store::where('name', 'Gamma Grocers')->firstOrFail();
    expect(DB::table('store_user')->where('store_id', $store->id)->count())->toBe(0);

    $invitation = Invitation::sole();
    expect($invitation->store_id)->toBe($store->id)
        ->and($invitation->email)->toBe('gina@example.com')
        ->and($invitation->role_id)->toBe(Role::starter(Role::OWNER)->id);

    Notification::assertSentOnDemand(InvitationNotification::class);
    expect(ActivityLog::where('action', 'store.created')->exists())->toBeTrue();
});

test('a platform account cannot be made a store owner', function () {
    $ops = createPlatformUser(['user-view']);

    $this->actingAs($this->admin)->postJson('/stores', [...storeFormPayload(), 'owner_email' => $ops->email])
        ->assertStatus(422)->assertJsonValidationErrors('owner_email');

    expect(Store::where('name', 'Gamma Grocers')->exists())->toBeFalse();
});

test('giving a store an owner: anybody is invited, a member is made Owner at once — and then the store has one', function () {
    $store = Store::factory()->create(['name' => 'Orphan Mart']);
    $staff = createStoreMember($store, Role::STAFF);

    $this->actingAs($this->admin)->postJson("/stores/{$store->id}/owner-invitation", ['email' => 'partner@example.com'])
        ->assertCreated();
    expect(Invitation::where('email', 'partner@example.com')->value('role_id'))->toBe(Role::starter(Role::OWNER)->id);

    // The member made Owner takes the store's one way in as its Owner: the invitation to another address goes.
    $this->actingAs($this->admin)->postJson("/stores/{$store->id}/owner-invitation", ['email' => strtoupper($staff->email)])
        ->assertOk()
        ->assertJsonPath('message', "{$staff->name} is now an Owner of Orphan Mart. The earlier invitation for partner@example.com no longer works.");
    expect(roleKeyIn($staff, $store))->toBe(Role::OWNER)
        ->and(Invitation::forStore($store)->exists())->toBeFalse()
        ->and(ActivityLog::where('action', 'store.owner_assigned')->value('description'))
        ->toBe("Made {$staff->name} ({$staff->email}) an Owner of Orphan Mart, replacing the invitation for partner@example.com");

    // It has an Owner now: more come from its own Members page, or Users → Stores.
    foreach ([$staff->email, 'another@example.com'] as $email) {
        $this->actingAs($this->admin)->postJson("/stores/{$store->id}/owner-invitation", ['email' => $email])
            ->assertStatus(422)->assertJsonValidationErrors(['email' => 'Orphan Mart already has an Owner.']);
    }
    expect(Invitation::forStore($store)->exists())->toBeFalse();
});

test('the platform deletes a store only with its name typed, and everything it owns goes — the store itself too', function () {
    $store = Store::factory()->create(['name' => 'Doomed Deli']);
    $screen = Screen::factory()->create(['store_id' => $store->id]);
    $member = createStoreMember($store, Role::OWNER);
    $cashier = Role::create(['name' => 'Doomed Cashier', 'store_id' => $store->id]);

    $this->actingAs($this->admin)->deleteJson("/stores/{$store->id}", ['confirm_name' => 'doomed deli'])
        ->assertStatus(422)->assertJsonValidationErrors('confirm_name');

    $this->actingAs($this->admin)->deleteJson("/stores/{$store->id}", ['confirm_name' => 'Doomed Deli', 'password' => 'password'])->assertOk();

    $this->assertDatabaseMissing('stores', ['id' => $store->id]);
    expect(Screen::find($screen->id))->toBeNull()
        ->and(Role::find($cashier->id))->toBeNull()
        ->and(User::find($member->id))->not->toBeNull()
        ->and(roleKeyIn($member, $store))->toBeNull()
        ->and(ActivityLog::where('action', 'store.deleted')->value('store_id'))->toBe($store->id);
});

test('support holding only store-view reads the list and changes nothing', function () {
    $support = createPlatformUser(['store-view']);
    $store = Store::factory()->create();

    $this->actingAs($support)->getJson('/stores/data')->assertOk();
    $this->actingAs($support)->postJson('/stores', [...storeFormPayload(), 'owner_email' => 'x@example.com'])->assertForbidden();
    $this->actingAs($support)->deleteJson("/stores/{$store->id}", ['confirm_name' => $store->name])->assertForbidden();
});

test('switching stores is for members, into their own stores only', function () {
    [$mine, $theirs] = Store::factory()->count(2)->create();
    $member = createStoreMember($mine, Role::STAFF);
    $member->stores()->attach($theirs->id, ['role_id' => Role::starter(Role::VIEWER)->id]);
    $stranger = Store::factory()->create();

    $this->actingAs($member)->post('/stores/switch', ['store_id' => $theirs->id])->assertRedirect(route('dashboard'));
    expect(session('current_store_id'))->toBe($theirs->id);

    $this->actingAs($member)->post('/stores/switch', ['store_id' => $stranger->id])->assertForbidden();
    $this->actingAs($this->admin)->post('/stores/switch', ['store_id' => $mine->id])->assertForbidden();
});

test('inviting the same owner again sends a fresh link — even once the first one expired', function () {
    $store = Store::factory()->create(['name' => 'Late Larder']);

    $this->actingAs($this->admin)->postJson("/stores/{$store->id}/owner-invitation", ['email' => 'gina@example.com'])->assertCreated();
    $invitation = Invitation::forStore($store)->sole();
    $invitation->update(['expires_at' => now()->subDay()]);
    $oldHash = $invitation->token_hash;

    $this->actingAs($this->admin)->postJson("/stores/{$store->id}/owner-invitation", ['email' => 'Gina@Example.com'])
        ->assertOk()
        ->assertJson(['email_sent' => true]);

    $renewed = Invitation::forStore($store)->sole();
    expect($renewed->id)->toBe($invitation->id)
        ->and($renewed->token_hash)->not->toBe($oldHash)
        ->and($renewed->isExpired())->toBeFalse();
    Notification::assertSentOnDemandTimes(InvitationNotification::class, 2);
});

test('a different address replaces the earlier owner invitation, so a mistyped email stops working', function () {
    $store = Store::factory()->create(['name' => 'Typo Treats']);
    [$typo, $typoToken] = Invitation::open($store, 'gina@exmaple.com', Role::starter(Role::OWNER), $this->admin);
    [$staffInvite] = Invitation::open($store, 'sam@example.com', Role::starter(Role::STAFF), $this->admin);

    $this->actingAs($this->admin)->postJson("/stores/{$store->id}/owner-invitation", ['email' => 'gina@example.com'])
        ->assertCreated()
        ->assertJsonPath('message', 'An invitation to own Typo Treats was sent to gina@example.com. The earlier invitation for gina@exmaple.com no longer works.');

    expect(Invitation::find($typo->id))->toBeNull()
        ->and(Invitation::find($staffInvite->id))->not->toBeNull()   // only owner invitations are replaced
        ->and(Invitation::forStore($store)->where('email', 'gina@example.com')->value('role_id'))->toBe(Role::starter(Role::OWNER)->id);

    auth()->logout();
    $this->get("/invitations/{$typoToken}")->assertViewIs('invitations.invalid');
});

test('an address the store already invited to another role is made the owner invitation', function () {
    $store = Store::factory()->create();
    [$staffInvite] = Invitation::open($store, 'sam@example.com', Role::starter(Role::STAFF), $this->admin);

    $this->actingAs($this->admin)->postJson("/stores/{$store->id}/owner-invitation", ['email' => 'sam@example.com'])->assertOk();

    expect(Invitation::forStore($store)->sole()->id)->toBe($staffInvite->id)
        ->and($staffInvite->fresh()->role_id)->toBe(Role::starter(Role::OWNER)->id);
});

test('a store that has an Owner is given none from here, and keeps the owner invitations its own people sent', function () {
    $store = Store::factory()->create(['name' => 'Busy Bakery']);
    $owner = createStoreMember($store, Role::OWNER);
    [$coOwnerInvite] = Invitation::open($store, 'partner@example.com', Role::starter(Role::OWNER), $owner);

    $this->actingAs($this->admin)->postJson("/stores/{$store->id}/owner-invitation", ['email' => 'second@example.com'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email' => 'Busy Bakery already has an Owner.']);

    expect(Invitation::find($coOwnerInvite->id))->not->toBeNull()
        ->and(Invitation::forStore($store)->count())->toBe(1);
    Notification::assertNothingSent();
});
