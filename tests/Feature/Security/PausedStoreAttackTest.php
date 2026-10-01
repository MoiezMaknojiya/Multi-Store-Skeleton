<?php

use App\Http\Middleware\EnsureStoreIsActive;
use App\Models\BuilderAd;
use App\Models\BuilderAsset;
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
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Getting into a paused store anyway
|--------------------------------------------------------------------------
|
| A paused store is closed to its own people (EnsureStoreIsActive) — the pages the panel links to, and every
| request a page makes. These go through every door by hand, as the store's own Owner, and try the ways around
| it: a session naming a store the person is not in, "Log in as", and the platform with a paused store left in
| its session.
|
*/

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    $this->store = Store::factory()->create(['name' => 'Alpha Mart', 'is_active' => false]);
    $this->owner = createStoreMember($this->store);
});

/** Every row a door of the store could name, made in the paused store. @return array<string, int|string> */
function pausedStoreRows(Store $store, $owner): array
{
    $channel = Channel::factory()->create(['store_id' => $store->id]);

    return [
        'screen' => Screen::factory()->create(['store_id' => $store->id])->id,
        'media' => Media::factory()->create(['store_id' => $store->id])->id,
        'daypart' => Daypart::factory()->create(['store_id' => $store->id])->id,
        'channel' => $channel->id,
        'channel-ad' => ChannelAd::factory()->create(['channel_id' => $channel->id])->id,
        'builder-ad' => BuilderAd::factory()->create(['store_id' => $store->id])->id,
        'asset' => BuilderAsset::factory()->create(['store_id' => $store->id])->id,
        'role' => Role::create(['name' => 'Cashier', 'store_id' => $store->id])->id,
        'user' => $owner->id,
        'invitation' => Invitation::factory()->create(['store_id' => $store->id])->id,
        'permission' => Permission::firstOrCreate(['name' => 'media-view'])->id,
        'campaign' => Campaign::factory()->create()->id,
        'store' => $store->id,
        'upload' => (string) Str::uuid(),
    ];
}

test('every door of a paused store is shut to its own Owner, whatever is asked and however', function () {
    $ids = pausedStoreRows($this->store, $this->owner);
    $counts = fn () => collect(['screens', 'media', 'dayparts', 'channels', 'channel_ads', 'builder_ads', 'builder_assets', 'roles', 'invitations', 'store_user', 'playlist_items'])
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
    $before = $counts();
    $words = EnsureStoreIsActive::message('Alpha Mart');

    $doors = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn (Route $route) => in_array('store.active', $route->gatherMiddleware(), true))
        ->reject(fn (Route $route) => in_array($route->getName(), ['store.switch', 'members.leave'], true));

    expect($doors->count())->toBeGreaterThan(80);

    foreach ($doors as $route) {
        $uri = preg_replace_callback('/\{(\w+)\??\}/', function (array $match) use ($ids, $route) {
            return match (true) {
                $match[1] === 'ad' => str_starts_with($route->uri(), 'channels') ? $ids['channel-ad'] : $ids['builder-ad'],
                default => $ids[$match[1]] ?? 1,
            };
        }, $route->uri());

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $response = $this->actingAs($this->owner->fresh())->withSession(['current_store_id' => $this->store->id])
                ->json($method, '/'.$uri, ['name' => 'Taken over', 'title' => 'Taken over', 'email' => 'x@example.com']);

            expect($response->status())->toBe(403, "{$method} /{$uri} answered {$response->status()}")
                ->and($response->json('message'))->toBe($words, "{$method} /{$uri} said something else");
        }
    }

    expect($counts())->toBe($before)
        ->and($this->store->fresh()->name)->toBe('Alpha Mart');
});

test('a session naming a paused store the person is not in neither opens it nor says its name', function () {
    $beta = Store::factory()->create(['name' => 'Beta Mart']);
    $outsider = createStoreMember($beta);

    $this->actingAs($outsider)->withSession(['current_store_id' => $this->store->id])
        ->get('/dashboard')->assertOk()->assertDontSee('Alpha Mart')->assertDontSee('dusk="dashboard-paused"', false);

    $response = $this->actingAs($outsider->fresh())->withSession(['current_store_id' => $this->store->id])->getJson('/media/data');
    expect($response->status())->toBeIn([403, 404])
        ->and($response->getContent())->not->toContain('Alpha Mart');
});

test('"Log In As" a paused store\'s person shows the paused page, and the way back still works', function () {
    $admin = createSuperAdmin();

    $this->actingAs($admin)->post("/users/{$this->owner->id}/impersonate")->assertRedirect();
    $this->get('/dashboard')->assertOk()->assertSee('Alpha Mart is paused');
    $this->get('/screens')->assertRedirect(route('dashboard'));

    $this->post('/impersonate/stop')->assertRedirect(route('dashboard'));
    $this->get('/stores')->assertOk();
});

test('the platform is never stopped by a paused store left in its session', function () {
    $admin = createSuperAdmin();

    $this->actingAs($admin)->withSession(['current_store_id' => $this->store->id])->get('/stores')->assertOk();
    $this->actingAs($admin)->withSession(['current_store_id' => $this->store->id])->getJson('/screens/data')->assertOk();
    expect($admin->pausedStore())->toBeNull();
});

test('turning a store off from inside it is not possible: the switch is the platform\'s alone', function () {
    $this->store->update(['is_active' => true]);

    $this->actingAs($this->owner->fresh())->withSession(['current_store_id' => $this->store->id])
        ->put('/settings/store', [
            'name' => 'Alpha Mart', 'street' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'zip_code' => '78701',
            'country' => 'USA', 'is_active' => false,
        ])->assertRedirect(route('store-settings.edit'));

    expect($this->store->fresh()->is_active)->toBeTrue();
});
