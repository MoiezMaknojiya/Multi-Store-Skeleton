<?php

use App\Http\Middleware\EnsureOrganizationIsActive;
use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Screen;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Getting into a paused organization anyway
|--------------------------------------------------------------------------
|
| A paused organization is closed to its own people (EnsureOrganizationIsActive) — the pages the panel links to, and every
| request a page makes. These go through every door by hand, as the organization's own Owner, and try the ways around
| it: a session naming an organization the person is not in, "Log in as", and the platform with a paused organization left in
| its session.
|
*/

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart', 'is_active' => false]);
    $this->owner = createOrganizationMember($this->organization);
});

/** Every row a door of the organization could name, made in the paused organization. @return array<string, int|string> */
function pausedOrganizationRows(Organization $organization, $owner): array
{
    $channel = Channel::factory()->create(['organization_id' => $organization->id]);

    return [
        'screen' => Screen::factory()->create(['organization_id' => $organization->id])->id,
        'media' => Media::factory()->create(['organization_id' => $organization->id])->id,
        'channel' => $channel->id,
        'channel-ad' => ChannelAd::factory()->create(['channel_id' => $channel->id])->id,
        'builder-ad' => BuilderAd::factory()->create(['organization_id' => $organization->id])->id,
        'asset' => BuilderAsset::factory()->create(['organization_id' => $organization->id])->id,
        'role' => Role::create(['name' => 'Cashier', 'organization_id' => $organization->id])->id,
        'user' => $owner->id,
        'invitation' => Invitation::factory()->create(['organization_id' => $organization->id])->id,
        'permission' => Permission::firstOrCreate(['name' => 'media-view'])->id,
        'campaign' => Campaign::factory()->create()->id,
        'organization' => $organization->id,
        'upload' => (string) Str::uuid(),
    ];
}

test('every door of a paused organization is shut to its own Owner, whatever is asked and however', function () {
    $ids = pausedOrganizationRows($this->organization, $this->owner);
    $counts = fn () => collect(['screens', 'media', 'channels', 'channel_ads', 'builder_ads', 'builder_assets', 'roles', 'invitations', 'organization_user', 'playlist_items'])
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
    $before = $counts();
    $words = EnsureOrganizationIsActive::message('Alpha Mart');

    $doors = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn (Route $route) => in_array('organization.active', $route->gatherMiddleware(), true))
        ->reject(fn (Route $route) => in_array($route->getName(), ['organization.switch', 'members.leave', 'dashboard.invitations.accept', 'dashboard.invitations.decline'], true));

    expect($doors->count())->toBeGreaterThan(80);

    foreach ($doors as $route) {
        $uri = preg_replace_callback('/\{(\w+)\??\}/', function (array $match) use ($ids, $route) {
            return match (true) {
                $match[1] === 'ad' => str_starts_with($route->uri(), 'channels') ? $ids['channel-ad'] : $ids['builder-ad'],
                default => $ids[$match[1]] ?? 1,
            };
        }, $route->uri());

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $response = $this->actingAs($this->owner->fresh())->withSession(['current_organization_id' => $this->organization->id])
                ->json($method, '/'.$uri, ['name' => 'Taken over', 'title' => 'Taken over', 'email' => 'x@example.com']);

            expect($response->status())->toBe(403, "{$method} /{$uri} answered {$response->status()}")
                ->and($response->json('message'))->toBe($words, "{$method} /{$uri} said something else");
        }
    }

    expect($counts())->toBe($before)
        ->and($this->organization->fresh()->name)->toBe('Alpha Mart');
});

test('a session naming a paused organization the person is not in neither opens it nor says its name', function () {
    $beta = Organization::factory()->create(['name' => 'Beta Mart']);
    $outsider = createOrganizationMember($beta);

    $this->actingAs($outsider)->withSession(['current_organization_id' => $this->organization->id])
        ->get('/dashboard')->assertOk()->assertDontSee('Alpha Mart')->assertDontSee('dusk="dashboard-paused"', false);

    $response = $this->actingAs($outsider->fresh())->withSession(['current_organization_id' => $this->organization->id])->getJson('/media/data');
    expect($response->status())->toBeIn([403, 404])
        ->and($response->getContent())->not->toContain('Alpha Mart');
});

test('"Log In As" a paused organization\'s person shows the paused page, and the way back still works', function () {
    $admin = createSuperAdmin();

    $this->actingAs($admin)->post("/users/{$this->owner->id}/impersonate")->assertRedirect();
    $this->get('/dashboard')->assertOk()->assertSee('Alpha Mart is paused');
    $this->get('/screens')->assertRedirect(route('dashboard'));

    $this->post('/impersonate/stop')->assertRedirect(route('dashboard'));
    $this->get('/organizations')->assertOk();
});

test('the platform is never stopped by a paused organization left in its session', function () {
    $admin = createSuperAdmin();

    $this->actingAs($admin)->withSession(['current_organization_id' => $this->organization->id])->get('/organizations')->assertOk();
    $this->actingAs($admin)->withSession(['current_organization_id' => $this->organization->id])->getJson('/screens/data')->assertOk();
    expect($admin->pausedOrganization())->toBeNull();
});

test('turning an organization off from inside it is not possible: the switch is the platform\'s alone', function () {
    $this->organization->update(['is_active' => true]);

    $this->actingAs($this->owner->fresh())->withSession(['current_organization_id' => $this->organization->id])
        ->put('/settings/organization', [
            'name' => 'Alpha Mart', 'street' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'zip_code' => '78701',
            'country' => 'USA', 'is_active' => false,
        ])->assertRedirect(route('organization-settings.edit'));

    expect($this->organization->fresh()->is_active)->toBeTrue();
});
