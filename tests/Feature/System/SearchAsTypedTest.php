<?php

use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\PlayerAppRelease;
use App\Models\PlaylistItem;
use App\Models\Screen;
use App\Models\User;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Every search box finds what was typed, as it was typed
|--------------------------------------------------------------------------
|
| Owner, 2026-10-15: "point 1 kardo and jitni bhi search field ha organization aur admin jitni bhi ha check karna". `%` and
| `_` are LIKE's wildcards, so "50%" also found "Sale 500 off", "r_1" found "Bar21", and a lone `%` or `_` found everything.
| Every search goes through whereContains (AppServiceProvider::registerSearchMacros), which escapes them with `!` — itself
| escaped — and a backslash stays a backslash on MySQL too. Each listing of the panel and each picker is asked here, inside
| an organization and above them.
|
*/

/** What is listed: a decoy beside each name a search must find. */
function namesToSearch(): array
{
    return ['Sale 50% off', 'Sale 500 off', 'Bar_1', 'Bar21', 'Wow!', 'C:\\menu', 'C:menu'];
}

/** What a person types, what it must find, and what it must not. */
function typedSearches(): array
{
    return [
        ['50%', 'Sale 50% off', ['Sale 500 off']],
        ['r_1', 'Bar_1', ['Bar21']],
        ['%', 'Sale 50% off', ['Sale 500 off', 'Bar21', 'Wow!', 'C:menu']],
        ['_', 'Bar_1', ['Sale 500 off', 'Bar21', 'Wow!', 'C:menu']],
        ['w!', 'Wow!', ['Sale 500 off', 'Bar21']],
        [':\\m', 'C:\\menu', ['C:menu']],
    ];
}

/** The names a listing answers for a search: `$field` of every row under `$key`. */
function foundBy(TestCase $test, string $url, string $key, string $field): Closure
{
    return fn (string $typed): array => collect($test->getJson($url.(str_contains($url, '?') ? '&' : '?').http_build_query(['search' => $typed]))
        ->assertOk()->json($key))->pluck($field)->all();
}

function expectEverySearchAsTyped(Closure $found): void
{
    foreach (typedSearches() as [$typed, $finds, $misses]) {
        $names = $found($typed);

        expect(in_array($finds, $names, true))->toBeTrue("A search for {$typed} did not find {$finds}.");
        foreach ($misses as $miss) {
            expect(in_array($miss, $names, true))->toBeFalse("A search for {$typed} found {$miss}.");
        }
    }
}

/* ── Inside an organization ──────────────────────────────────────────────── */

test('every search inside an organization finds what was typed', function (Closure $listing) {
    $organization = Organization::factory()->create();
    $person = createOrganizationUser($organization, [
        'screen-view', 'screen-update', 'screen-playlist', 'media-view', 'channel-view', 'channel-update', 'ad-view', 'ad-store', 'activity-view',
    ]);
    $this->actingAs($person)->withSession(['current_organization_id' => $organization->id]);

    expectEverySearchAsTyped($listing($this, $organization));
})->with([
    'Screens' => [function (TestCase $test, Organization $organization): Closure {
        foreach (namesToSearch() as $name) {
            Screen::factory()->create(['organization_id' => $organization->id, 'name' => $name]);
        }

        return foundBy($test, '/screens/data', 'screens', 'name');
    }],
    'Media' => [function (TestCase $test, Organization $organization): Closure {
        foreach (namesToSearch() as $name) {
            Media::factory()->create(['organization_id' => $organization->id, 'title' => $name]);
        }

        return foundBy($test, '/media/data', 'media', 'title');
    }],
    'Channels' => [function (TestCase $test, Organization $organization): Closure {
        foreach (namesToSearch() as $name) {
            Channel::factory()->create(['organization_id' => $organization->id, 'name' => $name]);
        }

        return foundBy($test, '/channels/data', 'channels', 'name');
    }],
    'Ad Builder ads' => [function (TestCase $test, Organization $organization): Closure {
        foreach (namesToSearch() as $name) {
            BuilderAd::factory()->create(['organization_id' => $organization->id, 'name' => $name]);
        }

        return foundBy($test, '/builder/data', 'ads', 'name');
    }],
    'Ad Builder assets' => [function (TestCase $test, Organization $organization): Closure {
        foreach (namesToSearch() as $name) {
            BuilderAsset::factory()->create(['organization_id' => $organization->id, 'title' => $name]);
        }

        return foundBy($test, '/builder/assets/data', 'assets', 'title');
    }],
    'Premium Templates' => [function (TestCase $test): Closure {
        foreach (namesToSearch() as $name) {
            BuilderAd::factory()->published()->create(['organization_id' => null, 'name' => $name]);
        }

        return foundBy($test, '/builder/templates?orientation='.BuilderAd::LANDSCAPE, 'templates', 'name');
    }],
    'Activity Log' => [function (TestCase $test, Organization $organization): Closure {
        foreach (namesToSearch() as $name) {
            ActivityLog::create(['organization_id' => $organization->id, 'action' => 'test.searched', 'description' => $name, 'actor_name' => 'Tester']);
        }

        return foundBy($test, '/activity/data', 'logs', 'description');
    }],
    'a playlist’s Content Library' => [function (TestCase $test, Organization $organization): Closure {
        $screen = Screen::factory()->create(['organization_id' => $organization->id]);
        foreach (namesToSearch() as $name) {
            Media::factory()->create(['organization_id' => $organization->id, 'title' => $name]);
        }

        return foundBy($test, "/screens/{$screen->id}/available-media", 'media', 'title');
    }],
    'a screen’s holding picture' => [function (TestCase $test, Organization $organization): Closure {
        $screen = Screen::factory()->create(['organization_id' => $organization->id]);
        foreach (namesToSearch() as $name) {
            Media::factory()->create(['organization_id' => $organization->id, 'title' => $name]);
        }

        return foundBy($test, "/screens/{$screen->id}/media-options", 'media', 'title');
    }],
    'a channel’s Add Ad library' => [function (TestCase $test, Organization $organization): Closure {
        $channel = Channel::factory()->create(['organization_id' => $organization->id]);
        foreach (namesToSearch() as $name) {
            Media::factory()->create(['organization_id' => $organization->id, 'title' => $name]);
        }

        return foundBy($test, "/channels/{$channel->id}/library", 'media', 'title');
    }],
]);

test('a channel’s Add Ad counts the files a playlist keeps out by what was typed, too', function () {
    $organization = Organization::factory()->create();
    $person = createOrganizationUser($organization, ['channel-view', 'channel-update']);
    $channel = Channel::factory()->create(['organization_id' => $organization->id]);
    $screen = Screen::factory()->create(['organization_id' => $organization->id]);

    foreach (['Sale 50% off', 'Sale 500 off'] as $position => $title) {
        $media = Media::factory()->create(['organization_id' => $organization->id, 'title' => $title]);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $media->id, 'position' => $position, 'duration_seconds' => 10]);
    }

    $this->actingAs($person)->withSession(['current_organization_id' => $organization->id])
        ->getJson("/channels/{$channel->id}/library?search=".rawurlencode('50%'))
        ->assertOk()->assertJsonPath('on_playlists', 1);
});

/* ── Above the organizations ─────────────────────────────────────────────── */

test('every search above the organizations finds what was typed', function (Closure $listing) {
    $this->actingAs(createSuperAdmin());

    expectEverySearchAsTyped($listing($this));
})->with([
    'Organizations' => [function (TestCase $test): Closure {
        foreach (namesToSearch() as $name) {
            Organization::factory()->create(['name' => $name, 'city' => 'Dallas']);
        }

        return foundBy($test, '/organizations/data', 'organizations', 'name');
    }],
    'Users' => [function (TestCase $test): Closure {
        foreach (namesToSearch() as $i => $name) {
            User::factory()->create(['first_name' => $name, 'last_name' => 'Person', 'email' => "person{$i}@example.com"]);
        }

        return foundBy($test, '/users/data', 'users', 'first_name');
    }],
    'Screens, by organization' => [function (TestCase $test): Closure {
        foreach (namesToSearch() as $name) {
            Screen::factory()->create(['name' => 'Counter TV', 'organization_id' => Organization::factory()->create(['name' => $name])->id]);
        }

        return foundBy($test, '/screens/data', 'screens', 'organization.name');
    }],
    'the platform’s Media' => [function (TestCase $test): Closure {
        foreach (namesToSearch() as $name) {
            Media::factory()->platformOwned()->create(['title' => $name]);
        }

        return foundBy($test, '/media/data?library=platform', 'media', 'title');
    }],
    'the platform’s Channels' => [function (TestCase $test): Closure {
        foreach (namesToSearch() as $name) {
            Channel::factory()->create(['organization_id' => null, 'name' => $name]);
        }

        return foundBy($test, '/channels/data', 'channels', 'name');
    }],
    'the platform’s channel Add Ad library' => [function (TestCase $test): Closure {
        $channel = Channel::factory()->create(['organization_id' => null]);
        foreach (namesToSearch() as $name) {
            Media::factory()->platformOwned()->create(['title' => $name]);
        }

        return foundBy($test, "/channels/{$channel->id}/library?library=platform", 'media', 'title');
    }],
    'the platform’s Ad Builder ads' => [function (TestCase $test): Closure {
        foreach (namesToSearch() as $name) {
            BuilderAd::factory()->create(['organization_id' => null, 'name' => $name]);
        }

        return foundBy($test, '/builder/data?organization_id=platform', 'ads', 'name');
    }],
    'the platform’s Ad Builder assets' => [function (TestCase $test): Closure {
        foreach (namesToSearch() as $name) {
            BuilderAsset::factory()->create(['organization_id' => null, 'title' => $name]);
        }

        return foundBy($test, '/builder/assets/data?organization_id=platform', 'assets', 'title');
    }],
    'Campaigns' => [function (TestCase $test): Closure {
        foreach (namesToSearch() as $name) {
            Campaign::factory()->create(['name' => $name, 'advertiser_name' => 'Gama']);
        }

        return foundBy($test, '/campaigns/data', 'campaigns', 'name');
    }],
    'Activity Log' => [function (TestCase $test): Closure {
        foreach (namesToSearch() as $name) {
            ActivityLog::create(['action' => 'test.searched', 'description' => $name, 'actor_name' => 'Tester']);
        }

        return foundBy($test, '/activity/data', 'logs', 'description');
    }],
    'Permissions' => [function (TestCase $test): Closure {
        foreach (namesToSearch() as $name) {
            Permission::create(['name' => $name, 'label' => 'Test']);
        }

        return foundBy($test, '/permissions/data', 'permissions', 'name');
    }],
    'TV App versions' => [function (TestCase $test): Closure {
        foreach (namesToSearch() as $i => $name) {
            PlayerAppRelease::create(['version_code' => $i + 1, 'version_name' => $name, 'path' => "player-app/test-{$i}.apk", 'size' => 1, 'sha256' => str_repeat('a', 64)]);
        }

        return foundBy($test, '/developer/app/releases/data', 'releases', 'version_name');
    }],
]);

test('a full name with a wildcard in it is searched as typed', function () {
    $this->actingAs(createSuperAdmin());
    User::factory()->create(['first_name' => 'Ali', 'last_name' => '50% Khan', 'email' => 'ali.one@example.com']);
    User::factory()->create(['first_name' => 'Ali', 'last_name' => '500 Khan', 'email' => 'ali.two@example.com']);

    $found = collect($this->getJson('/users/data?search='.rawurlencode('Ali 50%'))->assertOk()->json('users'))->pluck('last_name')->all();

    expect($found)->toBe(['50% Khan']);
});

test('TV App versions name whoever uploaded each one', function () {
    $admin = createSuperAdmin();
    PlayerAppRelease::create(['version_code' => 1, 'version_name' => '1.0', 'path' => 'player-app/test.apk', 'size' => 1, 'sha256' => str_repeat('a', 64), 'uploaded_by' => $admin->id]);

    // Its columns were `id,name`, and users have no `name`: SQLite read the unknown "name" as a word, MySQL refused the query.
    $this->actingAs($admin)->getJson('/developer/app/releases/data')->assertOk()
        ->assertJsonPath('releases.0.uploaded_by', $admin->name);
});
