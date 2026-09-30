<?php

use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\Channel;
use App\Models\Media;
use App\Models\Screen;
use App\Models\Store;
use App\Models\User;
use App\Services\DiskGuard;
use App\Services\StoreStorage;
use Illuminate\Testing\TestResponse;

/*
 * The dashboard (owner, 2026-09-30: "user friendly banao puri site ko"): a shop's numbers, what needs a look and its
 * first steps, or the platform's — every part by its permission, every link to a page the person may open, and a
 * shop's counts from that shop alone.
 */

/** The dashboard as the person sees it, working in $store when one is given. */
function dashboardFor(User $user, ?Store $store = null): TestResponse
{
    $request = test()->actingAs($user);

    if ($store !== null) {
        $request = $request->withSession(['current_store_id' => $store->id]);
    }

    return $request->get('/dashboard')->assertOk();
}

/** @return array<string, array<string, mixed>> the cards by key */
function cardsOf(TestResponse $response): array
{
    return collect($response->viewData('summary')['cards'])->keyBy('key')->all();
}

/** Every link the summary offers: the cards', the actions', the steps' and the attention list's. */
function linksOf(array $summary): array
{
    return collect([
        ...$summary['cards'],
        ...($summary['actions'] ?? []),
        ...($summary['steps'] ?? []),
        ...($summary['attention'] ?? []),
    ])->pluck('href')->filter()->values()->all();
}

test('a shop\'s dashboard counts that shop alone', function () {
    $alpha = Store::factory()->create(['name' => 'Alpha Mart']);
    $beta = Store::factory()->create();
    $viewer = createStoreUser($alpha, ['screen-view', 'media-view', 'ad-view', 'channel-view'], 'Looks At Everything');

    Screen::factory()->create(['store_id' => $alpha->id]);                                     // online
    Screen::factory()->create(['store_id' => $alpha->id, 'last_seen_at' => now()->subHour()]); // offline
    Screen::factory()->count(3)->create(['store_id' => $beta->id]);

    Media::factory()->count(2)->create(['store_id' => $alpha->id]);
    Media::factory()->count(5)->create(['store_id' => $beta->id]);

    BuilderAd::factory()->create(['store_id' => $alpha->id, 'published_at' => now()]);
    BuilderAd::factory()->create(['store_id' => $alpha->id]);
    BuilderAd::factory()->count(2)->create(['store_id' => $beta->id, 'published_at' => now()]);

    Channel::factory()->create(['store_id' => $alpha->id]);
    Channel::factory()->create(['store_id' => $beta->id]);
    Channel::factory()->create(['store_id' => null]);   // the platform's: offered to every shop, made by none

    $response = dashboardFor($viewer, $alpha);
    $cards = cardsOf($response);

    expect($response->viewData('summary')['store'])->toBe('Alpha Mart')
        ->and($cards['screens']['value'])->toBe(2)
        ->and($cards['screens']['detail'])->toBe('1 online now')
        ->and($cards['media']['value'])->toBe(2)
        ->and($cards['ads']['value'])->toBe(1)
        ->and($cards['ads']['detail'])->toBe('published · 1 draft')
        ->and($cards['channels']['value'])->toBe(1);

    $response->assertSee('dusk="dashboard-store"', false)->assertSee('Alpha Mart');
});

test('each part of a shop\'s dashboard needs its own permission, and every link opens for the person given it', function () {
    $alpha = Store::factory()->create();
    $screen = Screen::factory()->create(['store_id' => $alpha->id, 'last_seen_at' => null]);

    // Screens alone: their card and what needs a look, nothing else — no actions, no steps, no log.
    $watcher = createStoreUser($alpha, ['screen-view'], 'Screen Watcher');
    $summary = dashboardFor($watcher, $alpha)->viewData('summary');

    expect(array_column($summary['cards'], 'key'))->toBe(['screens'])
        ->and($summary['actions'])->toBe([])
        ->and($summary['steps'])->toBe([])
        ->and($summary['attention'])->not->toBeNull()
        ->and($summary['activity'])->toBeNull();

    // Adding without the page it happens on offers nothing: Add Screen is on the Screens page.
    $adder = createStoreUser($alpha, ['screen-store', 'media-store', 'ad-store'], 'Adder Without Pages');
    $summary = dashboardFor($adder, $alpha)->viewData('summary');

    expect($summary['cards'])->toBe([])
        ->and($summary['actions'])->toBe([])
        ->and($summary['steps'])->toBe([])
        ->and($summary['attention'])->toBeNull();
    dashboardFor($adder, $alpha)->assertSee('dusk="dashboard-store-nothing"', false);

    // The Owner gets every part its role holds (a shop's own channels are the super admin's to give), and every link
    // it is offered opens.
    $owner = createStoreMember($alpha);
    $summary = dashboardFor($owner, $alpha)->viewData('summary');

    expect(array_column($summary['cards'], 'key'))->toBe(['screens', 'media', 'ads'])
        ->and(array_column($summary['actions'], 'key'))->toBe(['pair', 'upload', 'ad']);

    foreach ([$watcher, $owner] as $person) {
        $links = linksOf(dashboardFor($person, $alpha)->viewData('summary'));
        expect($links)->not->toBeEmpty();

        foreach ($links as $link) {
            $this->actingAs($person)->withSession(['current_store_id' => $alpha->id])->get($link)->assertOk();
        }
    }

    expect(linksOf(dashboardFor($owner, $alpha)->viewData('summary')))->toContain(route('screens.show', $screen));
});

test('what needs a look: a screen that is offline, one with nothing to play, and a shop nearly full', function () {
    $alpha = Store::factory()->create();
    $owner = createStoreMember($alpha);
    $never = Screen::factory()->create(['store_id' => $alpha->id, 'name' => 'Back Office TV', 'last_seen_at' => null]);
    $gone = Screen::factory()->create(['store_id' => $alpha->id, 'name' => 'Window TV', 'last_seen_at' => now()->subHours(2)]);
    $playing = Screen::factory()->create(['store_id' => $alpha->id, 'name' => 'Counter TV']);
    $playing->playlistItems()->create(['media_id' => Media::factory()->create(['store_id' => $alpha->id])->id, 'position' => 1, 'duration_seconds' => 10]);

    $this->mock(StoreStorage::class, fn ($mock) => $mock->shouldReceive('summary')
        ->andReturn(['used' => 490 * 1024 * 1024, 'limit' => StoreStorage::LIMIT_BYTES]));   // 95.7%, said as 95

    $response = dashboardFor($owner, $alpha);
    $attention = collect($response->viewData('summary')['attention'])->keyBy('key');

    expect($attention->keys()->all())->toContain("screen-offline-{$never->id}", "screen-offline-{$gone->id}", "screen-empty-{$never->id}", 'storage')
        ->and($attention->keys()->all())->not->toContain("screen-offline-{$playing->id}", "screen-empty-{$playing->id}")
        ->and($attention["screen-offline-{$never->id}"]['detail'])->toBe('It has never connected')
        ->and($attention["screen-offline-{$gone->id}"]['detail'])->toBe('Last seen 2 hours ago')
        ->and($attention['storage']['text'])->toBe('Storage is 95% full');

    $response->assertSee('Window TV is offline')->assertDontSee('dusk="dashboard-attention-none"', false);
});

test('a long list names five and counts the rest, leading to where they all are', function () {
    $alpha = Store::factory()->create();
    $watcher = createStoreUser($alpha, ['screen-view'], 'Screen Watcher');
    Screen::factory()->count(7)->create(['store_id' => $alpha->id, 'last_seen_at' => null]);

    $attention = collect(dashboardFor($watcher, $alpha)->viewData('summary')['attention']);
    $offline = $attention->filter(fn (array $item) => str_starts_with($item['key'], 'screen-offline-'));

    expect($offline)->toHaveCount(6)
        ->and($offline->last())->toMatchArray([
            'key' => 'screen-offline-more',
            'text' => '2 more screens are offline',
            'href' => route('screens.view'),
            'count' => 2,
        ]);

    // The number beside "Needs attention" counts every screen, not the lines: 7 offline and 7 with nothing to play.
    expect(dashboardFor($watcher, $alpha)->getContent())
        ->toMatch('/dusk="dashboard-attention-count">14<span class="sr-only"> to look at<\/span>/');

    // Five or fewer: no counting line.
    $beta = Store::factory()->create();
    $small = createStoreUser($beta, ['screen-view'], 'Small Shop Watcher');
    Screen::factory()->count(5)->create(['store_id' => $beta->id, 'last_seen_at' => null]);

    expect(array_column(dashboardFor($small, $beta)->viewData('summary')['attention'], 'key'))->not->toContain('screen-offline-more');
});

test('a shop with nothing wrong says so, rather than showing an empty box', function () {
    $alpha = Store::factory()->create();
    $owner = createStoreMember($alpha);
    $screen = Screen::factory()->create(['store_id' => $alpha->id]);
    $screen->playlistItems()->create(['media_id' => Media::factory()->create(['store_id' => $alpha->id])->id, 'position' => 1, 'duration_seconds' => 10]);

    $response = dashboardFor($owner, $alpha);

    expect($response->viewData('summary')['attention'])->toBe([]);
    $response->assertSee('dusk="dashboard-attention-none"', false)->assertSee('Everything looks good.');
});

test('the first steps are ticked as they are done, and go once all are', function () {
    $alpha = Store::factory()->create();
    $owner = createStoreMember($alpha);
    $steps = fn () => collect(dashboardFor($owner, $alpha)->viewData('summary')['steps'])->pluck('done', 'key')->all();

    // A new shop: pair and upload (nothing to put a file on yet).
    expect($steps())->toBe(['pair' => false, 'upload' => false]);
    dashboardFor($owner, $alpha)->assertSee('Get your shop on screen')->assertSee('0 of 2 done');

    $screen = Screen::factory()->create(['store_id' => $alpha->id]);
    expect($steps())->toBe(['pair' => true, 'upload' => false, 'play' => false]);

    $file = Media::factory()->create(['store_id' => $alpha->id]);
    expect($steps())->toBe(['pair' => true, 'upload' => true, 'play' => false]);

    $screen->playlistItems()->create(['media_id' => $file->id, 'position' => 1, 'duration_seconds' => 10]);
    expect($steps())->toBe([]);
    dashboardFor($owner, $alpha)->assertDontSee('dusk="dashboard-steps"', false);
});

test('a shop\'s recent activity is its own, and only for a role that may read the log', function () {
    $alpha = Store::factory()->create();
    $beta = Store::factory()->create();
    ActivityLog::create(['actor_name' => 'Ali', 'store_id' => $alpha->id, 'action' => 'media.uploaded', 'description' => 'Uploaded Alpha poster']);
    ActivityLog::create(['actor_name' => 'Bano', 'store_id' => $beta->id, 'action' => 'media.uploaded', 'description' => 'Uploaded Beta poster']);
    ActivityLog::create(['actor_name' => 'Root', 'store_id' => null, 'action' => 'auth.login', 'description' => 'Signed in']);

    $reader = createStoreUser($alpha, ['activity-view'], 'Log Reader');
    $response = dashboardFor($reader, $alpha);

    expect(array_column($response->viewData('summary')['activity'], 'what'))->toBe(['Uploaded Alpha poster']);
    $response->assertSee('Uploaded Alpha poster')->assertDontSee('Beta poster')->assertSee(route('activity.view'), false);

    $other = createStoreUser($alpha, ['screen-view'], 'No Log');
    $response = dashboardFor($other, $alpha);

    expect($response->viewData('summary')['activity'])->toBeNull();
    $response->assertDontSee('dusk="dashboard-activity"', false);
});

test('the platform\'s dashboard: every part by its permission, and a store with no Owner said', function () {
    $owned = Store::factory()->create(['name' => 'Owned Store']);
    createStoreMember($owned);
    Store::factory()->create(['name' => 'Orphan Store']);
    Screen::factory()->create(['store_id' => $owned->id]);

    $admin = createSuperAdmin();
    $summary = dashboardFor($admin)->viewData('summary');

    expect(array_column($summary['cards'], 'key'))->toContain('stores', 'users', 'screens', 'campaigns')
        ->and(array_column($summary['attention'], 'text'))->toBe(['Orphan Store has no Owner'])
        ->and($summary['activity'])->toBe([]);

    foreach (linksOf($summary) as $link) {
        $this->actingAs($admin)->get($link)->assertOk();
    }

    // Stores alone: the count with its link and the stores to look after — no accounts, no log.
    $support = createPlatformUser(['store-view'], 'Store Support');
    $summary = dashboardFor($support)->viewData('summary');

    expect(array_column($summary['cards'], 'key'))->toBe(['stores'])
        ->and($summary['cards'][0]['href'])->toBe(route('stores.view'))
        ->and(array_column($summary['attention'], 'text'))->toBe(['Orphan Store has no Owner'])
        ->and($summary['activity'])->toBeNull();

    // Nothing at all: the count every platform account has always seen, and no link it could not open.
    $nobody = createPlatformUser([], 'Nothing Yet');
    $response = dashboardFor($nobody);
    $summary = $response->viewData('summary');

    expect($summary['cards'][0]['href'])->toBeNull()
        ->and($summary['attention'])->toBeNull()
        ->and($summary['activity'])->toBeNull();
    $response->assertDontSee('href="'.route('stores.view').'"', false);
});

test('the platform\'s count of accounts is what its Users page lists to the viewer', function () {
    $store = Store::factory()->create();
    createStoreMember($store);                                    // a customer
    createStoreMember($store, attributes: ['email_verified_at' => null]);
    $admin = createSuperAdmin();                                  // the platform team…
    $support = createPlatformUser(['user-view'], 'Support');      // …which support does not list

    expect(cardsOf(dashboardFor($support))['users']['value'])->toBe(2)
        ->and(cardsOf(dashboardFor($support))['users']['detail'])->toBe('1 not yet confirmed')
        ->and(cardsOf(dashboardFor($admin))['users']['value'])->toBe(4);
});

test('a server running low on space is on the super admin\'s dashboard', function () {
    config(['signage.disk_warning_bytes' => 10 * 1024 ** 3, 'signage.upload_reserve_bytes' => 5 * 1024 ** 3]);
    $this->app->instance(DiskGuard::class, new DiskGuard(fn () => 3 * 1024 ** 3));

    $summary = dashboardFor(createSuperAdmin())->viewData('summary');

    expect(cardsOf(dashboardFor(createSuperAdmin()))['disk']['value'])->toBe('3 GB')
        ->and(collect($summary['attention'])->firstWhere('key', 'disk')['text'])->toBe('The server is running low on space');

    // Support does not see the server's disk.
    expect(cardsOf(dashboardFor(createPlatformUser(['store-view'], 'Support'))))->not->toHaveKey('disk');
});
