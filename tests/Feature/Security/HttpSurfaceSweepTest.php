<?php

use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Daypart;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Screen;
use App\Models\Store;
use Illuminate\Support\Facades\Route;

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

/** Real rows, so an id in a URL resolves to something. */
function sweepFixtures(): array
{
    $store = Store::factory()->create(['name' => 'Alpha Mart']);
    $owner = createStoreMember($store, Role::OWNER);
    $staff = createStoreMember($store, Role::STAFF);
    $support = createPlatformUser(['user-view', 'store-view', 'channel-view', 'activity-view'], 'Support');
    $superAdmin = createSuperAdmin();
    registerPermissionGates();

    $screen = Screen::factory()->withToken('sweep-token')->create(['store_id' => $store->id]);
    $media = Media::factory()->create(['store_id' => $store->id]);
    $daypart = Daypart::factory()->create(['store_id' => $store->id]);
    $channel = Channel::factory()->create(['store_id' => $store->id]);
    $ad = ChannelAd::factory()->create(['channel_id' => $channel->id]);
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
            'ad' => $ad->id,
            'campaign' => $campaign->id,
            'token' => str_repeat('a', 64),
            'id' => $staff->id,
            'hash' => sha1('nope'),
            'path' => 'nothing.txt',
        ],
    ];
}

/** Every route the app answers, with its parameters filled in. */
function sweepRoutes(array $parameters): array
{
    $routes = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();
        $skip = false;

        foreach ($route->parameterNames() as $name) {
            if (! array_key_exists($name, $parameters)) {
                $skip = true;

                break;
            }
            $uri = preg_replace('/\{'.$name.'\??\}/', (string) $parameters[$name], $uri);
        }

        if ($skip || str_contains($uri, '{')) {
            continue;
        }

        foreach ($route->methods() as $method) {
            if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                continue;
            }
            $routes[] = ['method' => $method, 'uri' => '/'.ltrim($uri, '/'), 'name' => $route->getName() ?? $uri];
        }
    }

    return $routes;
}

test('no route anywhere answers with a server error, whoever asks and whatever the body', function () {
    $fixtures = sweepFixtures();
    $routes = sweepRoutes($fixtures['parameters']);
    expect(count($routes))->toBeGreaterThan(50);

    $failures = [];

    foreach ($fixtures['people'] as $label => $person) {
        foreach ($routes as $route) {
            // Logging out mid-sweep would turn the rest of this person's pass into a guest's.
            if ($route['name'] === 'logout' || $route['name'] === 'impersonate.stop') {
                continue;
            }

            $request = $person === null
                ? $this->withSession([])
                : $this->actingAs($person)->withSession(['current_store_id' => $fixtures['store']->id]);

            try {
                $response = $route['method'] === 'GET'
                    ? $request->get($route['uri'])
                    : $request->json($route['method'], $route['uri'], []);
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
    $fixtures = sweepFixtures();
    $routes = sweepRoutes($fixtures['parameters']);

    // The doors a person with no session is allowed to reach.
    $public = [
        'login', 'register', 'password.request', 'password.reset', 'password.email', 'password.store',
        'invitations.show', 'invitations.accept', 'invitations.register', 'invitations.decline',
        'player', 'device.register', 'device.pair-status', 'device.playlist', 'device.heartbeat',
        'up',   // Laravel's own health check, public on purpose
    ];

    $leaks = [];

    foreach ($routes as $route) {
        if (in_array($route['name'], $public, true) || $route['method'] !== 'GET') {
            continue;
        }

        $status = $this->withSession([])->get($route['uri'])->status();

        // A guest gets sent to the login page (302) or refused; never a page of the panel.
        if (! in_array($status, [302, 401, 403, 404, 405, 419, 429], true)) {
            $leaks[] = "{$route['name']} ({$route['uri']}) answered {$status} for a guest";
        }
    }

    expect($leaks)->toBe([]);
});

test('no write endpoint answers with a server error, however misshapen the body', function () {
    $fixtures = sweepFixtures();
    $routes = sweepRoutes($fixtures['parameters']);

    // Every field name the panel and the device API send anywhere, each holding the wrong SHAPE. This
    // is the sweep that found `name[]=x` 500ing role and channel creation: a rule list without `bail`
    // let a closure that expects a word receive an array.
    $fields = [
        'name', 'title', 'label', 'email', 'search', 'password', 'password_confirmation',
        'current_password', 'confirm_name', 'first_name', 'last_name', 'phone', 'role_id', 'store_id',
        'store_ids', 'media_id', 'channel_id', 'screen_id', 'default_media_id', 'daypart_id',
        'permissions', 'items', 'rules', 'version', 'code', 'mode', 'device_uuid', 'poll_secret',
        'per_page', 'page', 'type', 'is_active', 'accepts', 'accepts_network_ads', 'timezone',
        'orientation', 'start_time', 'end_time', 'duration_seconds', 'expires_at', 'starts_at',
        'street', 'suite', 'city', 'state', 'zip_code', 'country', 'owner_email', 'seconds',
    ];

    $bodies = [
        'arrays' => array_fill_keys($fields, ['x']),
        'nested arrays' => array_fill_keys($fields, ['a' => ['b' => 'c']]),
        'long strings' => array_fill_keys($fields, str_repeat('a', 300)),
        'numbers where words go' => array_fill_keys($fields, -1),
        'booleans everywhere' => array_fill_keys($fields, true),
    ];

    $failures = [];

    foreach (['owner' => $fixtures['people']['owner'], 'super admin' => $fixtures['people']['super admin']] as $label => $person) {
        $this->actingAs($person->fresh())->withSession(['current_store_id' => $fixtures['store']->id]);

        foreach ($bodies as $shape => $body) {
            foreach ($routes as $route) {
                if ($route['method'] === 'GET' || $route['name'] === 'logout' || $route['name'] === 'impersonate.stop') {
                    continue;
                }

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
    $fixtures = sweepFixtures();
    $routes = sweepRoutes($fixtures['parameters']);

    $before = [
        'stores' => Store::count(),
        'screens' => Screen::count(),
        'media' => Media::count(),
        'dayparts' => Daypart::count(),
        'channels' => Channel::count(),
        'roles' => Role::count(),
        'invitations' => Invitation::count(),
        'campaigns' => Campaign::count(),
    ];

    foreach ($routes as $route) {
        if ($route['method'] === 'GET' || str_starts_with($route['uri'], '/device/')) {
            continue;
        }
        // The public invitation and auth endpoints are meant for people with no session.
        if (str_starts_with($route['uri'], '/invitations/') || in_array($route['name'], ['login', 'register', 'password.email', 'password.store'], true)) {
            continue;
        }

        $status = $this->withSession([])->json($route['method'], $route['uri'], [])->status();
        expect($status)->toBeIn([401, 403, 404, 405, 419, 422, 429, 302], "{$route['method']} {$route['uri']} answered {$status} for a guest");
    }

    expect([
        'stores' => Store::count(),
        'screens' => Screen::count(),
        'media' => Media::count(),
        'dayparts' => Daypart::count(),
        'channels' => Channel::count(),
        'roles' => Role::count(),
        'invitations' => Invitation::count(),
        'campaigns' => Campaign::count(),
    ])->toBe($before);
});
