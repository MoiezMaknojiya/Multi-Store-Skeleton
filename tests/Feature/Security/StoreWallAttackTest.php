<?php

use App\Models\ActivityLog;
use App\Models\BuilderAd;
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

/*
|--------------------------------------------------------------------------
| The store wall, attacked on purpose
|--------------------------------------------------------------------------
|
| A member of Alpha Mart holding EVERY store permission goes after Beta Deli's things by their ids —
| the attack the "store is the wall" rule exists for. Nothing may answer with anything but 403/404/422,
| and Beta must be untouched afterwards.
|
*/

beforeEach(function () {
    $this->alpha = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->beta = Store::factory()->create(['name' => 'Beta Deli']);

    // Every store permission, plus the platform ones a store's role may carry: as much reach as a store
    // member can ever have.
    $this->attacker = createStoreUser($this->alpha, [
        ...Permission::STORE,
        'store-view', 'store-store', 'store-destroy', 'channel-view', 'channel-store', 'channel-update',
        'channel-destroy', 'activity-view',
    ], 'Everything');

    $this->betaOwner = createStoreMember($this->beta, Role::OWNER);
    $this->betaScreen = Screen::factory()->create(['store_id' => $this->beta->id, 'name' => 'Beta TV']);
    $this->betaMedia = Media::factory()->create(['store_id' => $this->beta->id, 'title' => 'Beta poster']);
    $this->betaDaypart = Daypart::factory()->create(['store_id' => $this->beta->id, 'name' => 'Beta hours']);
    $this->betaChannel = Channel::factory()->create(['store_id' => $this->beta->id, 'name' => 'Beta Specials']);
    $this->betaAd = ChannelAd::factory()->create(['channel_id' => $this->betaChannel->id, 'title' => 'Beta ad']);
    $this->betaRole = Role::create(['name' => 'Beta Cashier', 'store_id' => $this->beta->id]);
    $this->betaInvitation = Invitation::factory()->create(['store_id' => $this->beta->id]);

    $this->alphaScreen = Screen::factory()->create(['store_id' => $this->alpha->id, 'name' => 'Alpha TV']);

    $this->actingAs($this->attacker)->withSession(['current_store_id' => $this->alpha->id]);
});

test('every one of another store’s rows answers 403 or 404, whatever the attacker holds', function () {
    $refusals = [
        // Screens and their playlists
        fn () => $this->getJson("/screens/{$this->betaScreen->id}"),
        fn () => $this->putJson("/screens/{$this->betaScreen->id}", ['name' => 'Taken', 'orientation' => 'landscape']),
        fn () => $this->deleteJson("/screens/{$this->betaScreen->id}"),
        fn () => $this->getJson("/screens/{$this->betaScreen->id}/playlist"),
        fn () => $this->putJson("/screens/{$this->betaScreen->id}/playlist", ['items' => [], 'version' => 'x']),
        // Media
        fn () => $this->putJson("/media/{$this->betaMedia->id}", ['title' => 'Taken']),
        fn () => $this->deleteJson("/media/{$this->betaMedia->id}"),
        // Dayparts
        fn () => $this->putJson("/dayparts/{$this->betaDaypart->id}", ['name' => 'Taken', 'start_time' => '07:00', 'end_time' => '11:00']),
        fn () => $this->deleteJson("/dayparts/{$this->betaDaypart->id}"),
        // Channels and their ads
        fn () => $this->getJson("/channels/{$this->betaChannel->id}"),
        fn () => $this->putJson("/channels/{$this->betaChannel->id}", ['name' => 'Taken']),
        fn () => $this->deleteJson("/channels/{$this->betaChannel->id}", ['password' => 'password']),
        fn () => $this->getJson("/channels/{$this->betaChannel->id}/ads"),
        fn () => $this->deleteJson("/channels/{$this->betaChannel->id}/ads/{$this->betaAd->id}"),
        // The store's people, roles and invitations
        fn () => $this->putJson("/members/{$this->betaOwner->id}", ['role_id' => Role::starter(Role::STAFF)->id]),
        fn () => $this->deleteJson("/members/{$this->betaOwner->id}", ['password' => 'password']),
        fn () => $this->putJson("/roles/{$this->betaRole->id}", ['name' => 'Taken', 'permissions' => []]),
        fn () => $this->deleteJson("/roles/{$this->betaRole->id}", ['password' => 'password']),
        fn () => $this->deleteJson("/members/invitations/{$this->betaInvitation->id}"),
        fn () => $this->postJson("/members/invitations/{$this->betaInvitation->id}/resend"),
        // The store itself
        fn () => $this->putJson("/stores/{$this->beta->id}", ['name' => 'Taken']),
        fn () => $this->deleteJson("/stores/{$this->beta->id}", ['confirm_name' => 'Beta Deli', 'password' => 'password']),
        fn () => $this->postJson("/stores/{$this->beta->id}/owner-invitation", ['email' => 'me@example.com']),
        fn () => $this->post('/stores/switch', ['store_id' => $this->beta->id]),
    ];

    foreach ($refusals as $i => $attempt) {
        $status = $attempt()->status();
        expect($status)->toBeIn([403, 404], "attempt #{$i} answered {$status}");
    }

    // Beta is exactly as it was.
    expect($this->beta->fresh()->name)->toBe('Beta Deli')
        ->and(Screen::find($this->betaScreen->id)->name)->toBe('Beta TV')
        ->and(Media::find($this->betaMedia->id)->title)->toBe('Beta poster')
        ->and(Daypart::find($this->betaDaypart->id)->name)->toBe('Beta hours')
        ->and(Channel::find($this->betaChannel->id)->name)->toBe('Beta Specials')
        ->and(ChannelAd::find($this->betaAd->id))->not->toBeNull()
        ->and(Role::find($this->betaRole->id)->name)->toBe('Beta Cashier')
        ->and(Invitation::find($this->betaInvitation->id))->not->toBeNull()
        ->and(roleKeyIn($this->betaOwner, $this->beta))->toBe(Role::OWNER);
});

test('another store’s rows cannot be smuggled onto this store’s screen', function () {
    $version = $this->getJson("/screens/{$this->alphaScreen->id}/playlist")->assertOk()->json('version');

    // Beta's file as a line
    $this->putJson("/screens/{$this->alphaScreen->id}/playlist", [
        'version' => $version,
        'items' => [['media_id' => $this->betaMedia->id, 'duration_seconds' => 10]],
    ])->assertStatus(422);

    // Beta's channel as a line
    $this->putJson("/screens/{$this->alphaScreen->id}/playlist", [
        'version' => $version,
        'items' => [['channel_id' => $this->betaChannel->id]],
    ])->assertStatus(422);

    // Beta's daypart on a schedule rule of this store's own file
    $mine = Media::factory()->create(['store_id' => $this->alpha->id]);
    $this->putJson("/screens/{$this->alphaScreen->id}/playlist", [
        'version' => $version,
        'items' => [[
            'media_id' => $mine->id, 'duration_seconds' => 10,
            'rules' => [['daypart_id' => $this->betaDaypart->id]],
        ]],
    ])->assertStatus(422);

    // Beta's file as the screen's holding picture
    $this->putJson("/screens/{$this->alphaScreen->id}", [
        'name' => 'Alpha TV', 'orientation' => 'landscape', 'default_media_id' => $this->betaMedia->id,
    ])->assertStatus(422);

    expect(PlaylistItem::where('screen_id', $this->alphaScreen->id)->count())->toBe(0)
        ->and(Screen::find($this->alphaScreen->id)->default_media_id)->toBeNull();
});

test('the platform’s library and another store’s files stay out of this store’s channels, listings and screens', function () {
    // docs/CHANNEL-CONTENT-SPEC.md: a channel holds library files by id, so an id is the thing to attack.
    $platformFile = Media::factory()->platformOwned()->create(['title' => 'Platform promo']);
    $platformChannel = Channel::factory()->create(['name' => 'GAMA']);
    $alphaChannel = Channel::factory()->create(['store_id' => $this->alpha->id, 'name' => 'Alpha Specials']);
    $mine = Media::factory()->create(['store_id' => $this->alpha->id, 'title' => 'Alpha poster']);

    // Neither foreign file goes into this store's own channel — and the refusal does not say which one exists.
    foreach ([$this->betaMedia, $platformFile] as $foreign) {
        $this->postJson("/channels/{$alphaChannel->id}/ads", ['media_id' => $foreign->id, 'seconds' => 10])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['media_id' => "Choose a file from this channel's library."]);
    }

    // The channel's picker lists this store's library, whatever library is asked for.
    foreach (['', '?library=platform', "?library={$this->beta->id}", '?type=html'] as $query) {
        $expected = $query === '?type=html' ? [] : [$mine->id];
        expect($this->getJson("/channels/{$alphaChannel->id}/library{$query}")->assertOk()->json('media.*.id'))->toBe($expected);
    }

    // The platform's channel is read from here, never added to — not even with this store's own file.
    $this->postJson("/channels/{$platformChannel->id}/ads", ['media_id' => $mine->id, 'seconds' => 10])->assertNotFound();
    $this->getJson("/channels/{$platformChannel->id}/library")->assertNotFound();

    // The platform's file is in no listing here, and cannot be changed, deleted or played on this store's screen.
    expect($this->getJson('/media/data?library=platform')->assertOk()->json('media.*.id'))->toBe([$mine->id]);
    $this->putJson("/media/{$platformFile->id}", ['title' => 'Taken'])->assertNotFound();
    $this->deleteJson("/media/{$platformFile->id}")->assertNotFound();
    $version = $this->getJson("/screens/{$this->alphaScreen->id}/playlist")->assertOk()->json('version');
    $this->putJson("/screens/{$this->alphaScreen->id}/playlist", [
        'version' => $version, 'items' => [['media_id' => $platformFile->id, 'duration_seconds' => 10]],
    ])->assertStatus(422);

    expect(ChannelAd::whereIn('channel_id', [$alphaChannel->id, $platformChannel->id])->exists())->toBeFalse()
        ->and($platformFile->fresh()->title)->toBe('Platform promo')
        ->and(PlaylistItem::where('screen_id', $this->alphaScreen->id)->exists())->toBeFalse();
});

test('a role of another store cannot be handed out here, however it is posted', function () {
    $staff = createStoreMember($this->alpha, Role::STAFF);

    $this->putJson("/members/{$staff->id}", ['role_id' => $this->betaRole->id])->assertStatus(422);
    $this->postJson('/members/invitations', ['email' => 'new@example.com', 'role_id' => $this->betaRole->id])->assertStatus(422);

    expect(roleKeyIn($staff, $this->alpha))->toBe(Role::STAFF)
        ->and(Invitation::where('email', 'new@example.com')->exists())->toBeFalse();
});

test('the listings never leak another store’s rows, whatever is searched for', function () {
    foreach (['screens', 'media', 'dayparts', 'channels'] as $resource) {
        $rows = collect($this->getJson("/{$resource}/data?search=Beta")->assertOk()->json($resource));
        expect($rows)->toBeEmpty("{$resource} leaked a row for a search on Beta");
    }

    $members = collect($this->getJson('/members/data')->assertOk()->json('members'))->pluck('id');
    expect($members)->not->toContain($this->betaOwner->id);

    $roles = collect($this->getJson('/roles/data')->assertOk()->json('roles'))->pluck('id');
    expect($roles)->not->toContain($this->betaRole->id);

    // An entry in each store's history, so the log has something of Beta's to leak.
    ActivityLog::record('screen.updated', $this->betaScreen, 'Renamed Beta TV', $this->betaOwner);
    ActivityLog::record('screen.updated', $this->alphaScreen, 'Renamed Alpha TV', $this->attacker);

    $activity = collect($this->getJson('/activity/data')->assertOk()->json('logs'));
    expect($activity->pluck('description')->all())->toBe(['Renamed Alpha TV'])
        ->and($activity->pluck('store_id')->unique()->all())->toBe([$this->alpha->id]);

    // Searching for it by name finds nothing either.
    expect($this->getJson('/activity/data?search=Beta')->assertOk()->json('logs'))->toBe([]);
});

test('with no store in the session a store member reaches nothing at all', function () {
    $this->actingAs($this->attacker);
    $this->flushSession();

    // A permission is read through the membership of the store in the session (User::contextPermissionNames).
    // With no store there is no membership to read, so every door is shut — not an unfiltered list.
    foreach (['screens', 'media', 'dayparts', 'channels', 'members'] as $resource) {
        expect($this->getJson("/{$resource}/data")->status())->toBe(403, "/{$resource}/data");
    }

    $this->get('/settings/store')->assertForbidden();
    $this->putJson("/screens/{$this->alphaScreen->id}", ['name' => 'x', 'orientation' => 'landscape'])->assertForbidden();
    expect($this->alphaScreen->fresh()->name)->not->toBe('x');
});

test('a stale or invented store id in the session opens nothing and breaks nothing', function () {
    // Beta (a real store they do not belong to), 0 (the platform's sentinel) and ids that are not stores.
    foreach ([$this->beta->id, 0, -1, 999999] as $stale) {
        $this->actingAs($this->attacker)->withSession(['current_store_id' => $stale]);

        foreach (['screens', 'media', 'dayparts', 'channels', 'members'] as $resource) {
            $status = $this->getJson("/{$resource}/data")->status();
            expect($status)->toBe(403, "store id {$stale} on /{$resource}/data answered {$status}");
        }
    }

    // Beta is exactly as it was.
    expect($this->betaScreen->fresh()->name)->toBe('Beta TV')
        ->and($this->betaMedia->fresh()->title)->toBe('Beta poster');
});

test('another store’s daypart, file or ad answers 404 before anything sent is checked', function () {
    // A 422 would say the id exists — and for a daypart, "this store already has a daypart with that name"
    // would read another store's names back one guess at a time.
    $this->putJson("/dayparts/{$this->betaDaypart->id}", ['name' => 'Beta hours', 'start_time' => '07:00', 'end_time' => '11:00'])->assertNotFound();
    $this->putJson("/dayparts/{$this->betaDaypart->id}", [])->assertNotFound();
    $this->putJson("/media/{$this->betaMedia->id}", ['title' => ''])->assertNotFound();

    $betaDesign = BuilderAd::factory()->create(['store_id' => $this->beta->id, 'name' => 'Beta sale']);
    $this->putJson("/builder/{$betaDesign->id}", ['name' => '', 'document' => 'nonsense'])->assertNotFound();

    expect($this->betaDaypart->fresh()->name)->toBe('Beta hours')
        ->and($this->betaMedia->fresh()->title)->toBe('Beta poster')
        ->and($betaDesign->fresh()->name)->toBe('Beta sale');
});
