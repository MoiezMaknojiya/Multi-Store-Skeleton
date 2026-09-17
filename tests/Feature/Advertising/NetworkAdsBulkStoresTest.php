<?php

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\Screen;
use App\Models\Store;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Signing whole shops up to network advertising, from the stores listing
|--------------------------------------------------------------------------
|
| Deals are struck a dozen shops at a time, not one television at a time, so the
| broad switch lives on the platform owner's own page — the stores listing — where
| every shop is already in front of them.
|
| The one rule worth stating out loud: it sets the shop AND every television inside
| it. The two flags are ANDed when a screen asks for its playlist, so a shop switched
| on alone would change nothing at all — every television starts off. A bulk switch
| that silently does nothing is worse than none, so this one reaches all the way
| down, and the panel says so before it is pressed.
|
*/

/** A global user who is emphatically NOT a super admin. */
function globalNonAdmin(): User
{
    $role = Role::create(['name' => 'Global Admin', 'is_global' => true]);
    $user = User::factory()->create();
    $user->stores()->attach(0, ['role_id' => $role->id]);

    return $user;
}

beforeEach(function () {
    $this->admin = createSuperAdmin(['store-view']);

    $this->shop = Store::factory()->create();
    $this->counter = Screen::factory()->create(['store_id' => $this->shop->id]);
    $this->window = Screen::factory()->create(['store_id' => $this->shop->id]);

    $this->other = Store::factory()->create();
    $this->otherScreen = Screen::factory()->create(['store_id' => $this->other->id]);
});

/*
|--------------------------------------------------------------------------
| Who may press it
|--------------------------------------------------------------------------
*/

test('a super admin switches whole shops on without impersonating anybody', function () {
    // The point of this endpoint: the stores listing is reached directly, so demanding
    // an impersonated session here would shut the door on the one person it is for.
    $this->actingAs($this->admin)
        ->putJson('/network-ads/stores', ['store_ids' => [$this->shop->id], 'accepts' => true])
        ->assertOk();

    expect($this->shop->fresh()->accepts_network_ads)->toBeTrue();
});

test('a global user who is not a super admin cannot', function () {
    $this->actingAs(globalNonAdmin())
        ->putJson('/network-ads/stores', ['store_ids' => [$this->shop->id], 'accepts' => true])
        ->assertForbidden();

    expect($this->shop->fresh()->accepts_network_ads)->toBeFalse();
});

test('a shopkeeper cannot, not even for their own shop', function () {
    $keeper = createStoreUser($this->shop, ['store-view', 'store-update']);

    $this->actingAs($keeper)->withSession(['current_store_id' => $this->shop->id])
        ->putJson('/network-ads/stores', ['store_ids' => [$this->shop->id], 'accepts' => true])
        ->assertForbidden();

    expect($this->shop->fresh()->accepts_network_ads)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| What it actually changes
|--------------------------------------------------------------------------
*/

test('the shop and every television inside it are switched together', function () {
    $this->actingAs($this->admin)
        ->putJson('/network-ads/stores', ['store_ids' => [$this->shop->id], 'accepts' => true])
        ->assertOk();

    expect($this->shop->fresh()->accepts_network_ads)->toBeTrue();
    expect($this->counter->fresh()->accepts_network_ads)->toBeTrue();
    expect($this->window->fresh()->accepts_network_ads)->toBeTrue();
});

test('a shop that was not ticked is left exactly as it was', function () {
    $this->actingAs($this->admin)
        ->putJson('/network-ads/stores', ['store_ids' => [$this->shop->id], 'accepts' => true])
        ->assertOk();

    expect($this->other->fresh()->accepts_network_ads)->toBeFalse();
    expect($this->otherScreen->fresh()->accepts_network_ads)->toBeFalse();
});

test('switching off puts the shop and every television back', function () {
    $this->shop->update(['accepts_network_ads' => true]);
    Screen::where('store_id', $this->shop->id)->update(['accepts_network_ads' => true]);

    $this->actingAs($this->admin)
        ->putJson('/network-ads/stores', ['store_ids' => [$this->shop->id], 'accepts' => false])
        ->assertOk();

    expect($this->shop->fresh()->accepts_network_ads)->toBeFalse();
    expect($this->counter->fresh()->accepts_network_ads)->toBeFalse();
    expect($this->window->fresh()->accepts_network_ads)->toBeFalse();
});

test('a television set apart by hand is swept up too — deliberately, and the panel warns first', function () {
    // The owner's own instruction: the bulk does the broad strokes, and an exception
    // is set again from inside the shop. Recorded as a test so nobody "fixes" it
    // later into a merge that would quietly leave shops half-switched.
    $this->shop->update(['accepts_network_ads' => true]);
    $this->counter->update(['accepts_network_ads' => true]);
    $this->window->update(['accepts_network_ads' => false]);   // the children's corner

    $this->actingAs($this->admin)
        ->putJson('/network-ads/stores', ['store_ids' => [$this->shop->id], 'accepts' => true])
        ->assertOk();

    expect($this->window->fresh()->accepts_network_ads)->toBeTrue();
});

test('several shops in one press, and the message counts both shops and screens', function () {
    $response = $this->actingAs($this->admin)->putJson('/network-ads/stores', [
        'store_ids' => [$this->shop->id, $this->other->id], 'accepts' => true,
    ])->assertOk();

    expect($response->json('message'))->toContain('2 shops')->toContain('3 screens');
    expect(Store::whereIn('id', [$this->shop->id, $this->other->id])
        ->where('accepts_network_ads', false)->count())->toBe(0);
    expect(Screen::where('accepts_network_ads', false)->count())->toBe(0);
});

test('a shop with no televisions yet is still switched, and says so', function () {
    $empty = Store::factory()->create();

    $response = $this->actingAs($this->admin)
        ->putJson('/network-ads/stores', ['store_ids' => [$empty->id], 'accepts' => true])
        ->assertOk();

    expect($empty->fresh()->accepts_network_ads)->toBeTrue();
    expect($response->json('message'))->toContain('0 screens');
});

test('ids that match no shop are refused rather than silently doing nothing', function () {
    $this->actingAs($this->admin)
        ->putJson('/network-ads/stores', ['store_ids' => [999999], 'accepts' => true])
        ->assertStatus(422)->assertJsonValidationErrors('store_ids');
});

test('an empty list is refused', function () {
    $this->actingAs($this->admin)
        ->putJson('/network-ads/stores', ['store_ids' => [], 'accepts' => true])
        ->assertStatus(422)->assertJsonValidationErrors('store_ids');
});

test('the switch is written to the activity log', function () {
    $this->actingAs($this->admin)
        ->putJson('/network-ads/stores', ['store_ids' => [$this->shop->id], 'accepts' => true])
        ->assertOk();

    $entry = ActivityLog::where('action', 'store.network_ads_updated')->latest('id')->first();

    expect($entry)->not->toBeNull();
    expect($entry->description)->toContain('Enabled')->toContain('1 shop')->toContain('2 screens');
});

/*
|--------------------------------------------------------------------------
| What the listing itself shows
|--------------------------------------------------------------------------
*/

test('the bulk switch and the adverts column are on the page for a super admin', function () {
    $this->actingAs($this->admin)->get('/stores')->assertOk()
        ->assertSee('Network advertising')
        ->assertSee('stores-ads-on', false)
        ->assertSee('select-all-stores', false);
});

test('and are absent for everybody else', function () {
    // Platform support can read the stores list; the advertising deal is still not theirs.
    $keeper = createPlatformUser(['store-view']);

    $this->actingAs($keeper)
        ->get('/stores')->assertOk()
        ->assertDontSee('Network advertising')
        ->assertDontSee('stores-ads-on', false)
        ->assertDontSee('select-all-stores', false);
});

test('the listing carries the screen counts a super admin needs, and nobody else pays for them', function () {
    $this->counter->update(['accepts_network_ads' => true]);

    $mine = $this->actingAs($this->admin)->getJson('/stores/data')->assertOk()
        ->json('stores');
    $row = collect($mine)->firstWhere('id', $this->shop->id);

    expect($row['screens_count'])->toBe(2);
    expect($row['ad_screens_count'])->toBe(1);

    $keeper = createPlatformUser(['store-view']);
    $theirs = $this->actingAs($keeper)->getJson('/stores/data')->assertOk()->json('stores');

    expect($theirs[0])->not->toHaveKey('screens_count');
});
