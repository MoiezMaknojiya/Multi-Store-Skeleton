<?php

use App\Models\Invitation;
use App\Models\Role;
use App\Models\Store;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Creating and editing stores from the platform (validation and reach)
|--------------------------------------------------------------------------
|
| A store's own people edit it from Settings → Stores (StoreSettingsTest); giving a store an owner and
| deleting one are covered by PlatformStoresTest.
|
*/

beforeEach(function () {
    Notification::fake();
});

function newStorePayload(array $overrides = []): array
{
    return [
        'name' => 'New Store', 'street' => '123 Main St', 'city' => 'Austin', 'state' => 'TX',
        'zip_code' => '78701', 'country' => 'USA', 'is_active' => true, 'owner_email' => 'owner@example.com',
        ...$overrides,
    ];
}

test('guests cannot reach any store endpoint', function () {
    $store = Store::factory()->create();

    $this->getJson('/stores/data')->assertUnauthorized();
    $this->postJson('/stores', newStorePayload())->assertUnauthorized();
    $this->putJson("/stores/{$store->id}", newStorePayload())->assertUnauthorized();
    $this->deleteJson("/stores/{$store->id}")->assertUnauthorized();
    $this->postJson('/stores/switch', ['store_id' => $store->id])->assertUnauthorized();
});

test('a super admin sees every store', function () {
    Store::factory()->count(2)->create();

    $this->actingAs(createSuperAdmin(['store-view']))->getJson('/stores/data')->assertOk()->assertJsonCount(2, 'stores');
});

test('a platform role holding store-view sees every store too', function () {
    Store::factory()->create(['name' => 'Z Grocery']);
    Store::factory()->create(['name' => 'Other Store']);

    $names = collect($this->actingAs(createPlatformUser(['store-view']))->getJson('/stores/data')->assertOk()->json('stores'))->pluck('name');

    expect($names)->toContain('Z Grocery')->toContain('Other Store');
});

test('a store member holding store-update changes the store they work in from Settings → Stores — never through the Stores page', function () {
    $store = Store::factory()->create(['name' => 'Corner Shop']);
    $member = createStoreUser($store, ['store-view', 'store-update']);

    $this->actingAs($member)->withSession(['current_store_id' => $store->id]);

    $this->getJson('/stores/data')->assertForbidden();
    $this->putJson("/stores/{$store->id}", newStorePayload(['name' => 'Taken']))->assertForbidden();

    $this->put('/settings/store', newStorePayload(['name' => 'Corner Shop Renamed', 'is_active' => false]))
        ->assertRedirect(route('store-settings.edit'));

    expect($store->fresh())->name->toBe('Corner Shop Renamed')->is_active->toBeTrue();
});

test('creating a store gives it a slug and invites its owner', function () {
    $response = $this->actingAs(createSuperAdmin(['store-store']))->postJson('/stores', newStorePayload())->assertCreated();

    expect($response->json('store.slug'))->not->toBeNull();
    expect(Invitation::sole()->role_id)->toBe(Role::starter(Role::OWNER)->id);
});

test('creating a store validates required fields, the owner email included', function () {
    $this->actingAs(createSuperAdmin(['store-store']))->postJson('/stores', ['name' => 'Incomplete Store'])
        ->assertJsonValidationErrors(['street', 'city', 'state', 'zip_code', 'country', 'owner_email']);
});

test('the state must be one of the 50 US state codes', function () {
    $admin = createSuperAdmin(['store-store']);

    $this->actingAs($admin)->postJson('/stores', newStorePayload(['state' => 'Texas']))->assertJsonValidationErrors('state');
    $this->actingAs($admin)->postJson('/stores', newStorePayload(['state' => 'DC']))->assertJsonValidationErrors('state');
    $this->actingAs($admin)->postJson('/stores', newStorePayload(['state' => 'TX']))->assertCreated();
});

test('the zip code must be numbers only', function () {
    $admin = createSuperAdmin(['store-store']);

    $this->actingAs($admin)->postJson('/stores', newStorePayload(['zip_code' => '787A1']))->assertJsonValidationErrors('zip_code');
    $this->actingAs($admin)->postJson('/stores', newStorePayload(['zip_code' => '78701', 'owner_email' => 'second@example.com']))->assertCreated();
});

test('the platform edits a store’s details', function () {
    $store = Store::factory()->create(['name' => 'Old Name']);

    $this->actingAs(createSuperAdmin(['store-update']))
        ->putJson("/stores/{$store->id}", newStorePayload(['name' => 'Renamed', 'is_active' => false]))
        ->assertOk();

    expect($store->fresh()->name)->toBe('Renamed')->and($store->fresh()->is_active)->toBeFalse();
});
