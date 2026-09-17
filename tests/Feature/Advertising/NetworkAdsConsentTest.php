<?php

use App\Models\Campaign;
use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\Screen;
use App\Models\Store;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Who may say a shop carries advertising — and what the television then gets
|--------------------------------------------------------------------------
|
| The consent switches belong to the PLATFORM owner, agreed in the deal. They are
| reachable only inside an IMPERSONATED session belonging to a live super admin,
| which is not a convenience: a super admin cannot enter a store directly in this
| app, so "Log in as" is the only way in, and that is where the control sits.
|
| A shopkeeper cannot see it, and — far more to the point — cannot reach the route
| either. Hiding a button is not what protects it.
|
*/

/** A store user acting inside an impersonated super-admin session. */
function impersonating(User $storeUser, Store $store, User $admin): void
{
    test()->actingAs($storeUser)->withSession([
        'current_store_id' => $store->id,
        'impersonating_original_id' => $admin->id,
        'impersonating_user_id' => $storeUser->id,
    ]);
}

beforeEach(function () {
    $this->admin = createSuperAdmin();
    $this->store = Store::factory()->create();
    $this->actor = createStoreUser($this->store, ['screen-view', 'screen-update']);
    $this->screen = Screen::factory()->create(['store_id' => $this->store->id]);
});

/*
|--------------------------------------------------------------------------
| The gate
|--------------------------------------------------------------------------
*/

test('a shopkeeper cannot reach the advertising switches at all', function () {
    // Not merely hidden — the route itself is shut. A button removed from a page
    // protects nothing.
    $this->actingAs($this->actor)->withSession(['current_store_id' => $this->store->id]);

    $this->putJson('/network-ads/store', ['accepts' => true])->assertForbidden();
    $this->putJson('/network-ads/screens', ['screen_ids' => [$this->screen->id], 'accepts' => true])
        ->assertForbidden();

    expect($this->store->fresh()->accepts_network_ads)->toBeFalse();
});

test('a super admin reaches them by logging in as somebody in the shop', function () {
    impersonating($this->actor, $this->store, $this->admin);

    $this->putJson('/network-ads/store', ['accepts' => true])->assertOk();

    expect($this->store->fresh()->accepts_network_ads)->toBeTrue();
});

test('an impersonation session pointing at somebody who is no longer a super admin opens nothing', function () {
    // The rank is re-checked on every request, the same way ImpersonateController
    // does when handing the session back.
    $ordinary = User::factory()->create();

    $this->actingAs($this->actor)->withSession([
        'current_store_id' => $this->store->id,
        'impersonating_original_id' => $ordinary->id,
        'impersonating_user_id' => $this->actor->id,
    ]);

    $this->putJson('/network-ads/store', ['accepts' => true])->assertForbidden();
});

test('the panel and the per-screen button are invisible to a shopkeeper', function () {
    $this->actingAs($this->actor)->withSession(['current_store_id' => $this->store->id])
        ->get('/screens')
        ->assertOk()
        ->assertDontSee('Network advertising')
        ->assertDontSee('This shop has agreed');
});

test('and visible while impersonating', function () {
    impersonating($this->actor, $this->store, $this->admin);

    $this->get('/screens')->assertOk()->assertSee('Network advertising');
});

/*
|--------------------------------------------------------------------------
| The switches themselves
|--------------------------------------------------------------------------
*/

test('screens are switched one at a time or all at once through the same call', function () {
    $second = Screen::factory()->create(['store_id' => $this->store->id]);

    impersonating($this->actor, $this->store, $this->admin);

    // All of them.
    $this->putJson('/network-ads/screens', [
        'screen_ids' => [$this->screen->id, $second->id], 'accepts' => true,
    ])->assertOk();

    expect($this->screen->fresh()->accepts_network_ads)->toBeTrue();
    expect($second->fresh()->accepts_network_ads)->toBeTrue();

    // And just the one — the set over the children's tables.
    $this->putJson('/network-ads/screens', ['screen_ids' => [$second->id], 'accepts' => false])->assertOk();

    expect($this->screen->fresh()->accepts_network_ads)->toBeTrue();
    expect($second->fresh()->accepts_network_ads)->toBeFalse();
});

test('a screen in another shop cannot be switched from this one', function () {
    $theirs = Screen::factory()->create(['store_id' => Store::factory()->create()->id]);

    impersonating($this->actor, $this->store, $this->admin);

    $this->putJson('/network-ads/screens', ['screen_ids' => [$theirs->id], 'accepts' => true])
        ->assertStatus(422)->assertJsonValidationErrors('screen_ids');

    expect($theirs->fresh()->accepts_network_ads)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| What the television is handed
|--------------------------------------------------------------------------
*/

test('the manifest carries the break, and the interval the player counts to', function () {
    $store = Store::factory()->create(['accepts_network_ads' => true]);
    $screen = Screen::factory()->withToken('tok')->create([
        'store_id' => $store->id, 'accepts_network_ads' => true,
    ]);
    $poster = Media::factory()->create(['store_id' => $store->id]);
    PlaylistItem::create([
        'screen_id' => $screen->id, 'media_id' => $poster->id, 'position' => 0, 'duration_seconds' => 10,
    ]);

    $campaign = Campaign::factory()->lasting(20)->create(['name' => 'Coca-Cola']);
    $campaign->screens()->attach($screen);

    $manifest = $this->withHeader('Authorization', 'Bearer tok')
        ->getJson('/device/playlist')->assertOk();

    expect($manifest->json('ad_break.every_seconds'))->toBe(Campaign::breakEverySeconds());
    expect($manifest->json('ad_break.items'))->toHaveCount(1);
    expect($manifest->json('ad_break.items.0.duration'))->toBe(20);
    expect($manifest->json('ad_break.items.0.url'))->toBe($campaign->url);
    // Prefixed, so an advert can never be mistaken for a playlist row.
    expect($manifest->json('ad_break.items.0.id'))->toBe('c'.$campaign->id);

    // The shop's own playlist is untouched by any of it.
    expect($manifest->json('items'))->toHaveCount(1);
});

test('a screen that carries no adverts is sent an empty break', function () {
    $screen = Screen::factory()->withToken('tok')->create(['store_id' => $this->store->id]);
    Campaign::factory()->create()->screens()->attach($screen);

    $manifest = $this->withHeader('Authorization', 'Bearer tok')->getJson('/device/playlist')->assertOk();

    expect($manifest->json('ad_break.items'))->toBeEmpty();
});

test('a campaign starting or ending changes the version, so the player notices', function () {
    $store = Store::factory()->create(['accepts_network_ads' => true]);
    $screen = Screen::factory()->withToken('tok')->create([
        'store_id' => $store->id, 'accepts_network_ads' => true,
    ]);
    $poster = Media::factory()->create(['store_id' => $store->id]);
    PlaylistItem::create([
        'screen_id' => $screen->id, 'media_id' => $poster->id, 'position' => 0, 'duration_seconds' => 10,
    ]);

    $before = $this->withHeader('Authorization', 'Bearer tok')->getJson('/device/playlist')->json('version');

    // A campaign begins. The shop's own playlist has not changed by one byte, so
    // without the break in the fingerprint the television would carry on regardless.
    Campaign::factory()->create()->screens()->attach($screen);

    $after = $this->withHeader('Authorization', 'Bearer tok')->getJson('/device/playlist')->json('version');

    expect($after)->not->toBe($before);
});
