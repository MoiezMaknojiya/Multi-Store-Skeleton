<?php

use App\Models\Media;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The layer below the pages: middleware, methods, headers and shapes
|--------------------------------------------------------------------------
|
| Attacks that never touch a controller's logic — claiming to be a DELETE, claiming to come from
| another address, sending an array where the code expects a word, or hoping the device API's
| stateless stack was accidentally given to the panel as well.
|
*/

beforeEach(function () {
    $this->store = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->owner = createStoreUser($this->store, [...Permission::STORE], 'Everything');
    $this->staff = createStoreMember($this->store, Role::STAFF);
    $this->actingAs($this->owner)->withSession(['current_store_id' => $this->store->id]);
});

/** Every middleware a route really runs, as one string to search (a closure middleware is named as such). */
function resolvedMiddleware(Illuminate\Routing\Route $route): string
{
    $resolved = app('router')->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware());

    return collect($resolved)
        ->map(fn ($middleware) => is_string($middleware) ? $middleware : 'Closure')
        ->implode(',');
}

test('the panel keeps the session and the CSRF check; the device API deliberately has neither', function () {
    $web = implode(',', app(Kernel::class)->getMiddlewareGroups()['web']);
    expect($web)->toContain('RequestForgery')->toContain('StartSession');

    foreach (Route::getRoutes() as $route) {
        $middleware = resolvedMiddleware($route);
        $uri = $route->uri();

        if (str_starts_with($uri, 'device/')) {
            // A television has no cookie jar: no session, no CSRF check, and a named limiter instead.
            $this->assertStringNotContainsString('RequestForgery', $middleware, $uri);
            $this->assertStringNotContainsString('StartSession', $middleware, $uri);
            $this->assertStringContainsString('ThrottleRequests:device-', $middleware, $uri);

            continue;
        }

        if (in_array('GET', $route->methods(), true) || $uri === 'up') {
            continue;
        }

        // Everything that writes, on the other hand, is behind the session and the token.
        $this->assertStringContainsString('RequestForgery', $middleware, $uri);
        $this->assertStringContainsString('StartSession', $middleware, $uri);
    }
});

test('claiming to be another method is not a way past a rule', function () {
    // A form POST that says it is really a DELETE either lands on the DELETE route — with every rule
    // that route has, the password included — or is refused. What it must never do is succeed.
    $status = $this->post("/members/{$this->staff->id}", ['_method' => 'DELETE'])->status();
    expect($status)->toBeIn([302, 404, 405, 422], "spoofed DELETE answered {$status}")
        ->and(roleKeyIn($this->staff, $this->store))->toBe(Role::STAFF);

    // The override HEADER, which Symfony does honour on a POST, reaches the same route the same way:
    // the DELETE route's own rules run, so the member is still there afterwards. (It is not a CSRF
    // hole either — a method the app treats as a write is token-checked, and a cross-site form
    // cannot set a header in the first place.)
    $status = $this->postJson("/members/{$this->staff->id}", [], ['X-HTTP-Method-Override' => 'DELETE'])->status();
    expect($status)->toBeIn([302, 404, 405, 422], "the override header answered {$status}")
        ->and(roleKeyIn($this->staff, $this->store))->toBe(Role::STAFF);

    // And a spoofed method cannot conjure up a route that does not exist.
    expect($this->post('/members', ['_method' => 'PATCH'])->status())->toBeIn([404, 405]);
});

test('arrays in a write body are refused, never crashed into', function () {
    $admin = createSuperAdmin();
    $this->actingAs($admin);
    $this->flushSession();

    foreach ([
        ['/roles', ['name' => 'Odd', 'type' => ['platform'], 'permissions' => []]],
        ['/roles', ['name' => ['a' => 'b'], 'type' => 'store', 'permissions' => []]],
        ['/roles', ['name' => 'Odd', 'type' => 'store', 'permissions' => ['x' => ['y']]]],
        ['/permissions', ['name' => ['mine-view'], 'label' => ['Mine']]],
        ['/stores', ['name' => ['Mine'], 'owner_email' => ['a@b.c']]],
        ['/users/invitations', ['email' => ['a@b.c'], 'role_id' => ['1']]],
        ['/dayparts', ['name' => ['D'], 'start_time' => ['07:00'], 'end_time' => '08:00']],
        ['/channels', ['name' => ['Ours'], 'is_active' => ['yes']]],
        ['/screens/pair', ['code' => ['ABCDEF'], 'mode' => ['new']]],
    ] as $i => [$url, $payload]) {
        $status = $this->postJson($url, $payload)->status();
        expect($status)->toBeIn([403, 404, 422], "write #{$i} to {$url} answered {$status}");
    }

    expect(Role::where('name', 'Odd')->exists())->toBeFalse()
        ->and(Store::where('name', 'Mine')->exists())->toBeFalse();
});

test('a forged X-Forwarded-For does not hand out a fresh rate limit', function () {
    // The app trusts no proxy (bootstrap/app.php never calls trustProxies), so a header a visitor
    // writes themselves cannot move their counter to a new address.
    $statuses = [];
    for ($i = 0; $i < 25; $i++) {
        $statuses[] = $this->withHeaders(['X-Forwarded-For' => "203.0.113.{$i}", 'X-Real-IP' => "198.51.100.{$i}"])
            ->get('/invitations/'.str_pad((string) $i, 64, 'a', STR_PAD_LEFT))->status();
    }

    expect($statuses)->toContain(429);
});

test('an array where the code expects a word is answered, not exploded', function () {
    Media::factory()->count(2)->create(['store_id' => $this->store->id]);

    // A listing reads anything that is not one plain value as "not sent" (HandlesCrudData::plainValue),
    // so each of these is an ordinary page of results.
    $answer = function (string $url): void {
        $status = $this->getJson($url)->status();
        expect($status)->toBe(200, "{$url} answered {$status}");
    };

    foreach ([
        '/media/data?search[]=x',
        '/media/data?search[a][b]=x',
        '/media/data?per_page[]=5',
        '/media/data?page[]=2',
        '/media/data?search[]=x&search[]=y',
        '/screens/data?search[]=x',
        '/dayparts/data?search[]=x',
        '/members/data?search[]=x',
    ] as $url) {
        $answer($url);
    }

    // The owner here holds no channel or log permission, and the accounts and stores listings are the
    // platform's: a 403 at the door would never let the array reach the code, so these go as a super admin.
    $this->actingAs(createSuperAdmin());

    foreach (['/channels/data?search[]=x', '/activity/data?search[]=x', '/users/data?search[]=x', '/stores/data?search[]=x'] as $url) {
        $answer($url);
    }
});

test('the device endpoints refuse arrays, empties and query-string tokens without breaking', function () {
    foreach ([
        ['device_uuid' => ['a']],
        ['device_uuid' => ['a' => 'b']],
        ['device_uuid' => str_repeat('u', 500)],
        ['device_uuid' => ''],
        ['device_uuid' => null],
        [],
    ] as $i => $payload) {
        $status = $this->postJson('/device/register', $payload)->status();
        expect($status)->toBeIn([200, 422], "register payload #{$i} answered {$status}");
    }

    expect($this->getJson('/device/pair-status?device_uuid[]=x&poll_secret[]=y')->status())->toBeIn([200, 422]);

    // A token belongs in the Authorization header. In a query string it is not a token at all.
    expect($this->getJson('/device/playlist?token='.str_repeat('a', 64))->status())->toBe(401)
        ->and($this->postJson('/device/heartbeat?token='.str_repeat('a', 64))->status())->toBe(401);
});

test('nothing in the app serves a file by a path it was handed', function () {
    // There is no file-serving endpoint, so there is nothing for ../../ to climb. This keeps it that
    // way: a route parameter that names a path is how traversal gets in.
    foreach (Route::getRoutes() as $route) {
        foreach ($route->parameterNames() as $name) {
            expect($name)->not->toBeIn(['path', 'file', 'filename', 'disk'], "{$route->uri()} takes a {$name}");
        }
    }
});
