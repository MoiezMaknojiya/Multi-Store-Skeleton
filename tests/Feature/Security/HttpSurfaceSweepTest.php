<?php

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Campaign;
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
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Every route, every kind of person, nothing but rubbish in the body
|--------------------------------------------------------------------------
|
| The blunt sweep: walk the whole route table as a guest, a Staff member, an Owner, platform support and
| the super admin, sending an empty body everywhere. Two rules, and they are the point of the exercise:
|
|   1. nothing answers 500 — a refusal is a 401/403/404/405/422, never a stack trace;
|   2. a guest is offered nothing but the public doors.
|
*/

beforeEach(function () {
    // A sweep publishes ads, uploads and deletes files: all of it lands on a disk of the test's own. And
    // nothing it sends may reach out of the server — installing a font would ask Google.
    Storage::fake('public');
    Http::preventStrayRequests();
});

/** The kinds of person a pass is made as, in the order the passes run. */
function sweepPeople(): array
{
    return ['guest', 'staff', 'owner', 'support', 'super admin'];
}

/** Real rows, so an id in a URL resolves to something — one of each thing a route can point at. */
function sweepFixtures(): array
{
    $store = Store::factory()->create(['name' => 'Alpha Mart']);
    $owner = createStoreMember($store, Role::OWNER);
    $staff = createStoreMember($store, Role::STAFF);
    $support = createPlatformUser(['user-view', 'store-view', 'channel-view', 'activity-view'], 'Support');
    $superAdmin = createSuperAdmin();

    $screen = Screen::factory()->withToken('sweep-token-'.$store->id)->create(['store_id' => $store->id]);
    $media = Media::factory()->create(['store_id' => $store->id]);
    // A line on the playlist, so the playlist routes have something to read, copy and preview.
    PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $media->id, 'position' => 0, 'duration_seconds' => 10]);
    $daypart = Daypart::factory()->create(['store_id' => $store->id]);
    $channel = Channel::factory()->create(['store_id' => $store->id]);
    $channelAd = ChannelAd::factory()->create(['channel_id' => $channel->id]);
    $design = BuilderAd::factory()->withText()->create(['store_id' => $store->id]);
    $asset = BuilderAsset::factory()->create(['store_id' => $store->id]);
    $campaign = Campaign::factory()->create();
    $invitation = Invitation::factory()->create(['store_id' => $store->id]);
    $role = Role::create(['name' => 'Sweep Role', 'store_id' => $store->id]);
    $permission = Permission::firstWhere('name', 'screen-view');

    return [
        'people' => [
            'guest' => null,
            'staff' => $staff,
            'owner' => $owner,
            'support' => $support,
            'super admin' => $superAdmin,
        ],
        'store' => $store,
        // Keyed by route parameter name. A name two sections use for different things is given per
        // section — the URI's first segment.
        'parameters' => [
            'store' => $store->id,
            'user' => $staff->id,
            'role' => $role->id,
            'permission' => $permission->id,
            'invitation' => $invitation->id,
            'screen' => $screen->id,
            'media' => $media->id,
            'daypart' => $daypart->id,
            'channel' => $channel->id,
            // An ad inside a channel, and a design in the Ad Builder.
            'ad' => ['channels' => $channelAd->id, 'builder' => $design->id],
            'asset' => $asset->id,
            'campaign' => $campaign->id,
            'token' => str_repeat('a', 64),
        ],
    ];
}

/**
 * The app's own routes. Laravel Dusk's (`/_dusk/…`, how the browser tests sign in) are left out: Dusk is a
 * dev dependency, so they never exist in production, and they are its surface rather than the app's.
 *
 * @return Collection<int, RouteDefinition>
 */
function sweepRouteTable(): Collection
{
    return collect(Route::getRoutes()->getRoutes())
        ->reject(fn (RouteDefinition $route) => str_starts_with((string) $route->getName(), 'dusk.'))
        ->values();
}

/**
 * Every route the app answers, with its parameters filled in. What takes something away — a DELETE, or
 * leaving the store — comes after everything else, so every route before it still finds the rows and the
 * membership it is about. (PHP's sort is stable: the rest keep the order of the route files.)
 */
function sweepRoutes(array $parameters): array
{
    $routes = [];

    foreach (sweepRouteTable() as $route) {
        $uri = $route->uri();

        foreach ($route->parameterNames() as $name) {
            $value = $parameters[$name] ?? null;

            if (is_array($value)) {
                $value = $value[explode('/', $uri, 2)[0]] ?? null;
            }

            // A parameter the fixtures have no row for leaves the route out of the sweep — which the
            // coverage test below does not allow.
            if ($value === null) {
                continue 2;
            }

            $uri = preg_replace('/\{'.$name.'\??\}/', (string) $value, $uri);
        }

        foreach ($route->methods() as $method) {
            if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                continue;
            }
            $routes[] = [
                'method' => $method, 'uri' => '/'.ltrim($uri, '/'), 'name' => $route->getName() ?? $uri,
                'template' => $method.' '.$route->uri(),
            ];
        }
    }

    $takesAway = fn (array $route): bool => $route['method'] === 'DELETE' || $route['name'] === 'members.leave';

    usort($routes, fn (array $a, array $b) => $takesAway($a) <=> $takesAway($b));

    return $routes;
}

/**
 * Stand as this person, afresh, for the next request of a pass — whatever the request before did: signed
 * out, "logged in as" somebody else, or spent a rate limit.
 */
function sweepAs(object $test, ?User $person, Store $store): void
{
    // The limiters still run on every request (a key built from a misshapen value is one of the things
    // that can 500), but their counters are forgotten: otherwise `throttle:admin` — 240 a minute per
    // person — and the wrong-password counter answer 429 for the tail of a pass, and hide what it does.
    Cache::store(config('cache.limiter'))->flush();

    $test->flushSession();

    if ($person === null) {
        $test->actingAsGuest();

        return;
    }

    // Read afresh: a person memoises their permissions, and a request before may have changed their role.
    $test->actingAs($person->fresh())->withSession(['current_store_id' => $store->id]);
}

/** How many rows each table a write could touch holds. */
function sweepCounts(): array
{
    return collect([
        'stores', 'users', 'store_user', 'roles', 'role_has_permissions', 'permissions', 'invitations',
        'screens', 'playlist_items', 'media', 'dayparts', 'channels', 'channel_ads', 'campaigns',
        'builder_ads', 'builder_assets', 'builder_fonts',
    ])->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
}

test('the sweep reaches every route, and every row it makes is one a route points at', function () {
    $parameters = sweepFixtures()['parameters'];
    $every = sweepRouteTable();

    // A route the fixtures cannot fill in would be skipped by every sweep below without a word.
    $swept = collect(sweepRoutes($parameters))->pluck('template');
    $missed = $every
        ->flatMap(fn (RouteDefinition $route) => collect($route->methods())
            ->reject(fn (string $method) => in_array($method, ['HEAD', 'OPTIONS'], true))
            ->map(fn (string $method) => $method.' '.$route->uri()))
        ->diff($swept);

    expect($missed->values()->all())->toBe([]);

    // And a fixture no route names any more is a row the sweep only pretends to use.
    $named = $every->flatMap(fn (RouteDefinition $route) => $route->parameterNames())->unique();

    expect(collect(array_keys($parameters))->diff($named)->values()->all())->toBe([]);
});

test('no route anywhere answers with a server error, whoever asks and whatever the body', function () {
    $failures = [];

    foreach (sweepPeople() as $label) {
        // Rows of its own for every pass: the pass before may have deleted, left or changed what it found.
        $fixtures = sweepFixtures();
        $routes = sweepRoutes($fixtures['parameters']);
        expect(count($routes))->toBeGreaterThan(50);

        foreach ($routes as $route) {
            sweepAs($this, $fixtures['people'][$label], $fixtures['store']);

            try {
                $response = $route['method'] === 'GET'
                    ? $this->get($route['uri'])
                    : $this->json($route['method'], $route['uri'], []);
                $status = $response->status();
            } catch (Throwable $e) {
                $failures[] = "{$label} {$route['method']} {$route['uri']} threw ".$e::class.': '.$e->getMessage();

                continue;
            }

            if ($status >= 500) {
                $failures[] = "{$label} {$route['method']} {$route['uri']} answered {$status}";
            }
        }
    }

    expect($failures)->toBe([]);
});

test('a guest is offered nothing but the public doors', function () {
    $routes = sweepRoutes(sweepFixtures()['parameters']);

    // The pages a person with no session is allowed to open. Pages only: every write is the last test's.
    $public = [
        'login', 'register', 'password.request', 'password.reset', 'invitations.show',
        'player', 'device.pair-status', 'device.playlist',
        'up',   // Laravel's own health check, public on purpose
    ];

    $leaks = [];

    foreach ($routes as $route) {
        if ($route['method'] !== 'GET' || in_array($route['name'], $public, true)) {
            continue;
        }

        $response = $this->get($route['uri']);

        // Every other page sends a guest to sign in — never a page of the panel, and never a refusal that
        // tells them what is behind the door.
        if (! $response->isRedirect(route('login'))) {
            $leaks[] = "{$route['name']} ({$route['uri']}) answered {$response->status()} for a guest";
        }
    }

    expect($leaks)->toBe([]);
});

test('no write endpoint answers with a server error, however misshapen the body', function () {
    // Every field name the panel and the device API send anywhere, each holding the wrong SHAPE. This
    // is the sweep that found `name[]=x` 500ing role and channel creation: a rule list without `bail`
    // let a closure that expects a word receive an array.
    $fields = [
        'name', 'title', 'label', 'description', 'email', 'search', 'password', 'password_confirmation',
        'current_password', 'confirm_name', 'first_name', 'last_name', 'phone', 'role_id', 'store_id',
        'store_ids', 'media_id', 'channel_id', 'screen_id', 'screen_ids', 'target_screen_ids', 'default_media_id',
        'daypart_id', 'ad_ids', 'permissions', 'items', 'rules', 'exceptions', 'version', 'code', 'mode',
        'device_uuid', 'poll_secret', 'per_page', 'page', 'sort', 'from', 'to', 'type', 'is_active', 'is_retired',
        'accepts', 'accepts_network_ads', 'timezone', 'orientation', 'start_time', 'end_time', 'duration_seconds',
        'expires_at', 'starts_at', 'starts_on', 'ends_on', 'days', 'ads_per_pass', 'seconds', 'advertiser_name',
        'street', 'suite', 'city', 'state', 'zip_code', 'country', 'owner_email',
        // Settings → Stores' Create store form, whose fields carry a prefix of their own
        'store_name', 'store_street', 'store_suite', 'store_city', 'store_state', 'store_zip_code', 'store_country',
        // The uploads, and the Ad Builder: a design, its poster, a font
        'file', 'poster', 'width', 'height', 'document', 'thumbnail', 'family',
    ];

    $bodies = [
        'arrays' => array_fill_keys($fields, ['x']),
        'nested arrays' => array_fill_keys($fields, ['a' => ['b' => 'c']]),
        'long strings' => array_fill_keys($fields, str_repeat('a', 300)),
        'numbers where words go' => array_fill_keys($fields, -1),
        'booleans everywhere' => array_fill_keys($fields, true),
    ];

    $failures = [];

    foreach (['owner', 'super admin'] as $label) {
        foreach ($bodies as $shape => $body) {
            // Rows of their own for every body: the body before may have deleted what it found.
            $fixtures = sweepFixtures();

            foreach (sweepRoutes($fixtures['parameters']) as $route) {
                if ($route['method'] === 'GET') {
                    continue;
                }

                sweepAs($this, $fixtures['people'][$label], $fixtures['store']);

                $response = $this->json($route['method'], $route['uri'], $body);

                if ($response->status() >= 500) {
                    $failures[] = "{$label} sending {$shape}: {$route['method']} {$route['uri']} answered ".$response->status();
                }
            }
        }
    }

    expect($failures)->toBe([]);
});

test('every write endpoint refuses a guest without touching anything', function () {
    $routes = sweepRoutes(sweepFixtures()['parameters']);
    $before = sweepCounts();

    foreach ($routes as $route) {
        if ($route['method'] === 'GET' || str_starts_with($route['uri'], '/device/')) {
            continue;
        }
        // The public invitation and auth endpoints are meant for people with no session.
        if (str_starts_with($route['uri'], '/invitations/') || in_array($route['name'], ['login', 'register', 'password.email', 'password.store'], true)) {
            continue;
        }

        // `auth` answers first: 401 to a request that asks for JSON, and to a plain form the way to sign in.
        $status = $this->json($route['method'], $route['uri'], [])->status();
        expect($status)->toBe(401, "{$route['method']} {$route['uri']} answered {$status} to a guest's JSON");

        $response = $this->call($route['method'], $route['uri']);
        expect($response->isRedirect(route('login')))
            ->toBeTrue("{$route['method']} {$route['uri']} answered {$response->status()} to a guest's form");
    }

    expect(sweepCounts())->toBe($before);
});
