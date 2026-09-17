<?php

use App\Models\ActivityLog;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Daypart;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\Permission;
use App\Models\PlaylistItem;
use App\Models\Role;
use App\Models\Screen;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Settings → Stores: the store being worked in (docs/STORE-ORGANIZATION-SPEC.md rules 10, 11, 22 and 33)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Storage::fake('public');

    $this->store = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->owner = createStoreMember($this->store, Role::OWNER);
    $this->admin = createStoreMember($this->store, Role::ADMIN);
    $this->staff = createStoreMember($this->store, Role::STAFF);
});

function settingsAs(User $user, Store $store)
{
    return test()->actingAs($user)->withSession(['current_store_id' => $store->id]);
}

function storeDetails(array $overrides = []): array
{
    return [
        'name' => 'Alpha Mart Downtown', 'street' => '1 Main St', 'suite' => '', 'city' => 'Dallas',
        'state' => 'TX', 'zip_code' => '75001', 'country' => 'USA', ...$overrides,
    ];
}

/*
| Details
*/

test('a member with store-update edits the details; Staff cannot even open the page', function () {
    settingsAs($this->admin, $this->store)->get('/settings/store')->assertOk()->assertSee('Store Details')
        // A tab of Settings, beside the profile.
        ->assertSee('dusk="settings-tab-profile"', false)->assertSee('dusk="settings-tab-store"', false);

    settingsAs($this->admin, $this->store)->put('/settings/store', storeDetails())
        ->assertRedirect(route('store-settings.edit'));
    expect($this->store->fresh()->name)->toBe('Alpha Mart Downtown');

    settingsAs($this->staff, $this->store)->get('/settings/store')->assertForbidden();
    settingsAs($this->staff, $this->store)->put('/settings/store', storeDetails(['name' => 'Hijacked']))->assertForbidden();
    expect($this->store->fresh()->name)->toBe('Alpha Mart Downtown');
});

test('the details form cannot switch advertising on or change anything it does not show', function () {
    settingsAs($this->owner, $this->store)->put('/settings/store', storeDetails([
        'accepts_network_ads' => true, 'is_active' => false, 'created_by' => $this->staff->id,
    ]));

    $store = $this->store->fresh();
    expect($store->accepts_network_ads)->toBeFalse()->and($store->is_active)->toBeTrue();
});

test('out of the box only an Owner is shown deleting the store', function () {
    // Delete Stores starts with the Owner role alone.
    settingsAs($this->owner, $this->store)->get('/settings/store')->assertSee('Delete Store');
    settingsAs($this->admin, $this->store)->get('/settings/store')->assertDontSee('Delete Store');
});

test('the store’s settings are the Stores tab of Settings; the sidebar does not list them', function () {
    settingsAs($this->owner, $this->store)->get('/profile')->assertOk()
        ->assertSee('dusk="settings-tab-store"', false)
        ->assertSee('href="'.route('store-settings.edit').'"', false);
    settingsAs($this->owner, $this->store)->get('/dashboard')->assertOk()
        ->assertDontSee('href="'.route('store-settings.edit').'"', false)
        ->assertSee('dusk="sidebar-settings"', false);

    // Staff hold no View Stores, so their Settings has the profile alone.
    settingsAs($this->staff, $this->store)->get('/profile')->assertOk()->assertDontSee('dusk="settings-tab-store"', false);
});

/*
| A store changes hands on the Members page
*/

test('a store changes hands on the Members page — Settings has no handover of its own', function () {
    // The Owner makes a member Owner; the new Owner may then change the first one's role (owner's rule, 2026-09-17).
    settingsAs($this->owner, $this->store)->putJson("/members/{$this->staff->id}", ['role_id' => Role::owner()->id])->assertOk();
    settingsAs($this->staff, $this->store)->putJson("/members/{$this->owner->id}", ['role_id' => Role::starter(Role::ADMIN)->id])->assertOk();

    expect(roleKeyIn($this->staff, $this->store))->toBe(Role::OWNER)
        ->and(roleKeyIn($this->owner, $this->store))->toBe(Role::ADMIN);

    settingsAs($this->staff, $this->store)->get('/settings/store')->assertOk()
        ->assertDontSee('Transfer Ownership')->assertDontSee('dusk="transfer-ownership"', false);
    settingsAs($this->staff, $this->store)->post('/settings/store/transfer-ownership', [
        'user_id' => $this->owner->id, 'role_id' => Role::starter(Role::STAFF)->id, 'password' => 'password',
    ])->assertNotFound();

    expect(Permission::where('name', 'store-transfer')->exists())->toBeFalse()
        ->and(roleKeyIn($this->owner, $this->store))->toBe(Role::ADMIN);
});

/*
| Delete the store
*/

test('deleting needs store-destroy (an Owner\'s out of the box), the exact store name and the password', function () {
    settingsAs($this->admin, $this->store)->delete('/settings/store', ['confirm_name' => 'Alpha Mart', 'password' => 'password'])
        ->assertForbidden();

    settingsAs($this->owner, $this->store)->delete('/settings/store', ['confirm_name' => 'alpha mart', 'password' => 'password'])
        ->assertSessionHasErrorsIn('storeDeletion', 'confirm_name');

    settingsAs($this->owner, $this->store)->delete('/settings/store', ['confirm_name' => 'Alpha Mart', 'password' => 'nope'])
        ->assertSessionHasErrorsIn('storeDeletion', 'password');

    expect(Store::find($this->store->id))->not->toBeNull();
});

test('deleting a store takes everything it owns, the store and the roles made in it included — never the people or the history', function () {
    // Everything Alpha Mart owns…
    $file = Media::factory()->create([
        'store_id' => $this->store->id,
        'path' => "media/{$this->store->id}/".Str::ulid().'.jpg',
        'thumbnail_path' => "media/{$this->store->id}/thumbs/".Str::ulid().'.jpg',
    ]);
    Storage::disk('public')->put($file->path, 'image');
    Storage::disk('public')->put($file->thumbnail_path, 'thumb');

    $screen = Screen::factory()->withToken('alpha-tv')->create(['store_id' => $this->store->id]);
    $channel = Channel::factory()->create();
    ChannelAd::factory()->lasting(10)->create(['channel_id' => $channel->id]);
    $ownChannel = Channel::factory()->create(['store_id' => $this->store->id]);
    $ownAd = ChannelAd::factory()->create([
        'channel_id' => $ownChannel->id,
        'path' => "channels/{$ownChannel->id}/".Str::ulid().'.jpg',
        'thumbnail_path' => "channels/{$ownChannel->id}/thumbs/".Str::ulid().'.jpg',
    ]);
    Storage::disk('public')->put($ownAd->path, 'ad');
    Storage::disk('public')->put($ownAd->thumbnail_path, 'ad thumb');
    PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $file->id, 'position' => 0, 'duration_seconds' => 10]);
    PlaylistItem::create(['screen_id' => $screen->id, 'channel_id' => $channel->id, 'position' => 1]);
    $hours = Daypart::factory()->create(['store_id' => $this->store->id]);
    $custom = Role::create(['name' => 'Cashier', 'store_id' => $this->store->id]);
    $invite = Invitation::factory()->create(['store_id' => $this->store->id]);
    ActivityLog::record('screen.paired', $screen, 'Paired screen Alpha TV', $this->owner);

    // The Admin works here under the store's own role.
    DB::table('store_user')->where('store_id', $this->store->id)->where('user_id', $this->admin->id)->update(['role_id' => $custom->id]);

    // …and a neighbour that must not feel a thing.
    $beta = Store::factory()->create();
    $betaOwner = createStoreMember($beta, Role::OWNER);
    $betaScreen = Screen::factory()->create(['store_id' => $beta->id]);
    $betaFile = Media::factory()->create(['store_id' => $beta->id]);

    // The Staff member also works in Beta.
    $this->staff->stores()->attach($beta->id, ['role_id' => Role::starter(Role::VIEWER)->id]);

    settingsAs($this->owner, $this->store)->delete('/settings/store', ['confirm_name' => 'Alpha Mart', 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    $this->assertDatabaseMissing('stores', ['id' => $this->store->id]);
    expect(DB::table('store_user')->where('store_id', $this->store->id)->count())->toBe(0)
        ->and(Invitation::find($invite->id))->toBeNull()
        ->and(Role::find($custom->id))->toBeNull()
        ->and(Screen::find($screen->id))->toBeNull()
        ->and(PlaylistItem::where('screen_id', $screen->id)->count())->toBe(0)
        ->and(Daypart::find($hours->id))->toBeNull()
        ->and(Media::find($file->id))->toBeNull()
        ->and(Channel::find($ownChannel->id))->toBeNull()
        ->and(ChannelAd::find($ownAd->id))->toBeNull();
    foreach ([$file->path, $file->thumbnail_path, $ownAd->path, $ownAd->thumbnail_path] as $path) {
        Storage::disk('public')->assertMissing($path);
    }

    // The deleted store's television is locked out, instead of playing on.
    $this->withHeader('Authorization', 'Bearer alpha-tv')->getJson('/device/playlist')->assertUnauthorized();

    // People keep their accounts — the one who worked under the store's own role too; the platform's channel stays.
    expect(User::find($this->staff->id))->not->toBeNull()
        ->and(User::find($this->admin->id))->not->toBeNull()
        ->and(Channel::find($channel->id))->not->toBeNull()
        ->and(session('current_store_id'))->toBeNull();

    // The neighbour is untouched — including the Staff member's place in it.
    expect(Screen::find($betaScreen->id))->not->toBeNull()
        ->and(Media::find($betaFile->id))->not->toBeNull()
        ->and(roleKeyIn($betaOwner, $beta))->toBe(Role::OWNER)
        ->and(roleKeyIn($this->staff, $beta))->toBe(Role::VIEWER);

    // The history stays: what happened in the store, and its deletion.
    expect(ActivityLog::where('store_id', $this->store->id)->where('action', 'screen.paired')->exists())->toBeTrue()
        ->and(ActivityLog::where('action', 'store.deleted')->latest('id')->value('description'))
        ->toContain('Alpha Mart')->toContain('1 screen')->toContain('1 media file');
});

test('outside a store there are no store settings', function () {
    $support = createPlatformUser(['store-view', 'store-update']);

    $this->actingAs($support)->get('/settings/store')->assertNotFound();
    $this->actingAs($support)->get('/profile')->assertOk()->assertDontSee('dusk="settings-tab-store"', false);
});

test('leaving from the Stores tab comes back to it — unless the store left is the one being worked in', function () {
    $beta = Store::factory()->create(['name' => 'Beta Deli']);
    $this->admin->stores()->attach($beta->id, ['role_id' => Role::starter(Role::STAFF)->id]);

    settingsAs($this->admin, $this->store)->from('/settings/store')->delete("/profile/stores/{$beta->id}")
        ->assertRedirect('/settings/store')->assertSessionHas('status', 'You left Beta Deli.');

    settingsAs($this->admin, $this->store)->from('/settings/store')->delete("/profile/stores/{$this->store->id}")
        ->assertRedirect(route('profile.edit'));
    expect(session('current_store_id'))->toBeNull()
        ->and(roleKeyIn($this->admin, $this->store))->toBeNull();
});
