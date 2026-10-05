<?php

use App\Models\Organization;
use App\Models\Screen;
use App\Services\DevicePairing;

/** Register a waiting device and return its pairing code. */
function waitingDeviceCode(): string
{
    return app(DevicePairing::class)->register()['code'];
}

test('guests cannot access any screen endpoint', function () {
    // Every route under /screens — the playlist, its picker and copy included — read from the route table,
    // so one added later is asked too.
    $routes = routesUnder('screens');

    expect($routes)->not->toBeEmpty();

    foreach ($routes as [$method, $uri]) {
        expect($this->json($method, $uri)->status())->toBe(401, "{$method} {$uri}");
    }
});

test('an organization user only sees the screens of the organization they are working in', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    $actor = createOrganizationUser($organizationA, ['screen-view']);

    $mine = Screen::factory()->create(['organization_id' => $organizationA->id, 'name' => 'Alpha Counter']);
    $theirs = Screen::factory()->create(['organization_id' => $organizationB->id, 'name' => 'Beta Counter']);

    $ids = collect($this->actingAs($actor)->withSession(['current_organization_id' => $organizationA->id])
        ->getJson('/screens/data')->assertOk()->json('screens'))->pluck('id');

    expect($ids)->toContain($mine->id);
    expect($ids)->not->toContain($theirs->id);
});

test('another organization\'s screen is unreachable, not just hidden', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    $actor = createOrganizationUser($organizationA, ['screen-view', 'screen-update', 'screen-destroy']);
    $theirs = Screen::factory()->create(['organization_id' => $organizationB->id, 'name' => 'Beta Counter']);

    $this->actingAs($actor)->withSession(['current_organization_id' => $organizationA->id])
        ->putJson("/screens/{$theirs->id}", ['name' => 'Hacked', 'orientation' => 'landscape'])
        ->assertNotFound();

    $this->actingAs($actor)->withSession(['current_organization_id' => $organizationA->id])
        ->deleteJson("/screens/{$theirs->id}")->assertNotFound();

    $this->assertDatabaseHas('screens', ['id' => $theirs->id, 'name' => 'Beta Counter']);
});

test('the token hash never leaves the server', function () {
    $organization = Organization::factory()->create();
    $actor = createOrganizationUser($organization, ['screen-view']);
    Screen::factory()->create(['organization_id' => $organization->id]);

    $payload = $this->actingAs($actor)->withSession(['current_organization_id' => $organization->id])
        ->getJson('/screens/data')->assertOk()->json('screens.0');

    expect($payload)->not->toHaveKey('token_hash');
    expect($payload)->toHaveKey('is_online');
});

/* ── Pairing from the owner's own panel ────────────────────────────────── */

test('an organization owner pairs their own TV without any admin help', function () {
    $organization = Organization::factory()->create();
    $owner = createOrganizationUser($organization, ['screen-store'], 'Organization Owner');
    $code = waitingDeviceCode();

    $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
        ->postJson('/screens/pair', [
            'code' => $code,
            'mode' => 'new',
            'name' => 'Counter TV',
            'orientation' => 'portrait',
        ])->assertOk();

    $screen = Screen::firstOrFail();
    expect($screen->organization_id)->toBe($organization->id);
    expect($screen->name)->toBe('Counter TV');
    expect($screen->orientation)->toBe('portrait');
    expect($screen->isPaired())->toBeTrue();
    expect($screen->paired_by)->toBe($owner->id);
});

test('the pairing code is accepted in lower case, the way it is typed', function () {
    $organization = Organization::factory()->create();
    $owner = createOrganizationUser($organization, ['screen-store']);
    $code = waitingDeviceCode();

    $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
        ->postJson('/screens/pair', [
            'code' => strtolower($code),
            'mode' => 'new',
            'name' => 'Counter TV',
            'orientation' => 'landscape',
        ])->assertOk();

    expect(Screen::firstOrFail()->isPaired())->toBeTrue();
});

test('a dead code creates no screen at all', function () {
    $organization = Organization::factory()->create();
    $owner = createOrganizationUser($organization, ['screen-store']);

    $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
        ->postJson('/screens/pair', [
            'code' => 'ZZZZZZ',
            'mode' => 'new',
            'name' => 'Counter TV',
            'orientation' => 'landscape',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);

    // The half-made screen must be rolled back, not left as a ghost row.
    expect(Screen::count())->toBe(0);
});

test('the same code cannot be used twice', function () {
    $organization = Organization::factory()->create();
    $owner = createOrganizationUser($organization, ['screen-store']);
    $code = waitingDeviceCode();

    $payload = ['code' => $code, 'mode' => 'new', 'name' => 'First', 'orientation' => 'landscape'];

    $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
        ->postJson('/screens/pair', $payload)->assertOk();

    $this->flushSession();
    $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
        ->postJson('/screens/pair', [...$payload, 'name' => 'Second'])
        ->assertStatus(422);

    expect(Screen::count())->toBe(1);
});

/* ── Pairing from above the organizations ──────────────────────────────── */

test('the platform pairs a TV for the organization it chooses in the dialog', function () {
    // Owner, 2026-10-05: the super admin's Screens page offered Add Screen and nowhere to say whose screen it was.
    $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    Organization::factory()->create(['name' => 'Beta Foods']);
    $admin = createSuperAdmin(['screen-store']);

    $this->actingAs($admin)->postJson('/screens/pair', [
        'code' => waitingDeviceCode(),
        'mode' => 'new',
        'name' => 'Counter TV',
        'orientation' => 'landscape',
        'organization_id' => $organization->id,
    ])->assertOk();

    $screen = Screen::sole();
    expect($screen->organization_id)->toBe($organization->id);
    expect($screen->isPaired())->toBeTrue();
    // The log says whose it is, and belongs to that organization.
    $this->assertDatabaseHas('activity_logs', [
        'action' => 'screen.paired',
        'organization_id' => $organization->id,
        'description' => 'Paired screen Counter TV for Alpha Mart',
    ]);
});

test('the platform must say whose screen it is, and a gone organization is said so', function () {
    $admin = createSuperAdmin(['screen-store']);

    // Nothing chosen: said under the dialog's Organization list, and the code is not spent.
    $code = waitingDeviceCode();
    $this->actingAs($admin)->postJson('/screens/pair', [
        'code' => $code, 'mode' => 'new', 'name' => 'Counter TV', 'orientation' => 'landscape',
    ])->assertStatus(422)->assertJsonValidationErrors(['organization_id' => 'Choose the organization this screen belongs to.']);

    // Deleted while the dialog was open.
    $this->actingAs($admin)->postJson('/screens/pair', [
        'code' => $code, 'mode' => 'new', 'name' => 'Counter TV', 'orientation' => 'landscape', 'organization_id' => 999_999,
    ])->assertStatus(422)->assertJsonValidationErrors(['organization_id' => 'That organization no longer exists. Reload the page and choose again.']);

    // Not a number at all.
    $this->actingAs($admin)->postJson('/screens/pair', [
        'code' => $code, 'mode' => 'new', 'name' => 'Counter TV', 'orientation' => 'landscape', 'organization_id' => ['x'],
    ])->assertStatus(422)->assertJsonValidationErrors(['organization_id' => 'Choose an organization from the list.']);

    expect(Screen::count())->toBe(0);
    // The television's code still works for the next try.
    $organization = Organization::factory()->create();
    $this->actingAs($admin)->postJson('/screens/pair', [
        'code' => $code, 'mode' => 'new', 'name' => 'Counter TV', 'orientation' => 'landscape', 'organization_id' => $organization->id,
    ])->assertOk();
});

test('above the organizations the list says whose each screen is, and finds them by it', function () {
    $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
    $beta = Organization::factory()->create(['name' => 'Beta Foods']);
    $admin = createSuperAdmin(['screen-view']);
    Screen::factory()->create(['organization_id' => $alpha->id, 'name' => 'Counter TV']);
    Screen::factory()->create(['organization_id' => $beta->id, 'name' => 'Deli TV']);

    $rows = collect($this->actingAs($admin)->getJson('/screens/data')->assertOk()->json('screens'));
    expect($rows->pluck('organization.name', 'name')->sortKeys()->all())->toBe(['Counter TV' => 'Alpha Mart', 'Deli TV' => 'Beta Foods']);
    // Only the name of the organization travels with a row.
    expect(array_keys($rows->first()['organization']))->toBe(['id', 'name']);

    $found = collect($this->actingAs($admin)->getJson('/screens/data?search=Beta')->assertOk()->json('screens'))->pluck('name');
    expect($found->all())->toBe(['Deli TV']);

    // The page offers the list of organizations to choose from in the Add Screen dialog.
    $this->actingAs($admin)->get('/screens')->assertOk()
        ->assertSee('dusk="screen-pair-organization"', false)
        ->assertSee('Beta Foods');
});

test('inside an organization nothing asks whose screen it is, and nothing says it', function () {
    $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $owner = createOrganizationUser($organization, ['screen-view', 'screen-store']);
    Screen::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
        ->get('/screens')->assertOk()->assertDontSee('screen-pair-organization', false);

    $row = $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
        ->getJson('/screens/data')->assertOk()->json('screens.0');
    expect($row)->not->toHaveKey('organization');
});

test('a user without screen-store cannot pair anything', function () {
    $organization = Organization::factory()->create();
    $actor = createOrganizationUser($organization, ['screen-view']);

    $this->actingAs($actor)->withSession(['current_organization_id' => $organization->id])
        ->postJson('/screens/pair', [
            'code' => waitingDeviceCode(),
            'mode' => 'new',
            'name' => 'Counter TV',
            'orientation' => 'landscape',
        ])->assertForbidden();
});

test('an unknown orientation is rejected', function () {
    $organization = Organization::factory()->create();
    $owner = createOrganizationUser($organization, ['screen-store']);

    $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
        ->postJson('/screens/pair', [
            'code' => waitingDeviceCode(),
            'mode' => 'new',
            'name' => 'Counter TV',
            'orientation' => 'sideways',
        ])->assertStatus(422)->assertJsonValidationErrors(['orientation']);
});

/* ── Replacing the device behind a screen ──────────────────────────────── */

test('replacing a device keeps the screen and its settings', function () {
    $organization = Organization::factory()->create();
    $owner = createOrganizationUser($organization, ['screen-store']);
    $screen = Screen::factory()->withToken('old-token')->create([
        'organization_id' => $organization->id,
        'name' => 'Counter TV',
        'orientation' => 'portrait',
    ]);

    $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
        ->postJson('/screens/pair', [
            'code' => waitingDeviceCode(),
            'mode' => 'replace',
            'screen_id' => $screen->id,
        ])->assertOk();

    $screen->refresh();
    expect(Screen::count())->toBe(1);          // no second screen appeared
    expect($screen->name)->toBe('Counter TV'); // settings untouched
    expect($screen->orientation)->toBe('portrait');
    expect($screen->token_hash)->not->toBe(hash('sha256', 'old-token'));  // token rotated
});

test('a screen from another organization cannot be re-paired', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    $owner = createOrganizationUser($organizationA, ['screen-store']);
    $theirs = Screen::factory()->create(['organization_id' => $organizationB->id]);

    $this->actingAs($owner)->withSession(['current_organization_id' => $organizationA->id])
        ->postJson('/screens/pair', [
            'code' => waitingDeviceCode(),
            'mode' => 'replace',
            'screen_id' => $theirs->id,
        ])->assertNotFound();
});

/* ── Edit and delete ───────────────────────────────────────────────────── */

test('a user with screen-update can rename a screen and re-orient it', function () {
    $organization = Organization::factory()->create();
    $actor = createOrganizationUser($organization, ['screen-update']);
    $screen = Screen::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($actor)->withSession(['current_organization_id' => $organization->id])
        ->putJson("/screens/{$screen->id}", [
            'name' => 'Window Board',
            'orientation' => 'portrait_flipped',
        ])->assertOk();

    $screen->refresh();
    expect($screen->name)->toBe('Window Board');
    expect($screen->orientation)->toBe('portrait_flipped');
});

test('a user with screen-destroy can delete a screen', function () {
    $organization = Organization::factory()->create();
    $actor = createOrganizationUser($organization, ['screen-destroy']);
    $screen = Screen::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($actor)->withSession(['current_organization_id' => $organization->id])
        ->deleteJson("/screens/{$screen->id}")->assertOk();

    $this->assertDatabaseMissing('screens', ['id' => $screen->id]);
});

test('a user without screen-destroy cannot delete a screen', function () {
    $organization = Organization::factory()->create();
    $actor = createOrganizationUser($organization, ['screen-view']);
    $screen = Screen::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($actor)->withSession(['current_organization_id' => $organization->id])
        ->deleteJson("/screens/{$screen->id}")->assertForbidden();

    $this->assertDatabaseHas('screens', ['id' => $screen->id]);
});

test('the online chip follows the last heartbeat', function () {
    $organization = Organization::factory()->create();

    $fresh = Screen::factory()->create(['organization_id' => $organization->id, 'last_seen_at' => now()->subMinute()]);
    $stale = Screen::factory()->offline()->create(['organization_id' => $organization->id]);
    $never = Screen::factory()->unpaired()->create(['organization_id' => $organization->id]);

    expect($fresh->is_online)->toBeTrue();
    expect($stale->is_online)->toBeFalse();
    expect($never->is_online)->toBeFalse();
});

test('deleting an organization takes its screens with it', function () {
    $organization = Organization::factory()->create();
    $screen = Screen::factory()->create(['organization_id' => $organization->id]);

    $organization->delete();

    $this->assertDatabaseMissing('screens', ['id' => $screen->id]);
});

test('the pairing form asks for the screen\'s time zone, and the screen keeps it', function () {
    $organization = Organization::factory()->create();
    $owner = createOrganizationUser($organization, ['screen-store']);

    // Said on the form: the zone chosen is the one the screen keeps.
    $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
        ->postJson('/screens/pair', [
            'code' => waitingDeviceCode(), 'mode' => 'new', 'name' => 'Deli TV', 'orientation' => 'landscape',
            'timezone' => 'America/New_York',
        ])->assertOk();

    expect(Screen::where('name', 'Deli TV')->sole()->timezone)->toBe('America/New_York');

    // Left out (an older page), the usual one.
    $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
        ->postJson('/screens/pair', [
            'code' => waitingDeviceCode(), 'mode' => 'new', 'name' => 'Window TV', 'orientation' => 'landscape',
        ])->assertOk();

    expect(Screen::where('name', 'Window TV')->sole()->timezone)->toBe(Screen::DEFAULT_TIMEZONE);

    // A zone the server does not know is refused under its field, and no screen is made.
    $this->actingAs($owner)->withSession(['current_organization_id' => $organization->id])
        ->postJson('/screens/pair', [
            'code' => waitingDeviceCode(), 'mode' => 'new', 'name' => 'Nowhere TV', 'orientation' => 'landscape',
            'timezone' => 'Mars/Olympus_Mons',
        ])->assertStatus(422)->assertJsonValidationErrors(['timezone' => 'Choose a time zone from the list.']);

    expect(Screen::where('name', 'Nowhere TV')->exists())->toBeFalse();
});
