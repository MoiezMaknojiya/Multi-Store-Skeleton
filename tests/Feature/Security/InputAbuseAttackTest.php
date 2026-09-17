<?php

use App\Models\Channel;
use App\Models\Daypart;
use App\Models\Media;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Screen;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Payloads nobody should send
|--------------------------------------------------------------------------
|
| Fields the form never shows, ids that are not ids, strings the size of a book, quotes meant for a
| database and tags meant for a browser. None of it may be trusted, stored, executed — or answered
| with a 500.
|
*/

beforeEach(function () {
    $this->store = Store::factory()->create(['name' => 'Alpha Mart', 'is_active' => true]);
    $this->other = Store::factory()->create(['name' => 'Beta Deli']);
    $this->owner = createStoreUser($this->store, [
        ...Permission::STORE, 'store-view', 'store-store', 'channel-view', 'channel-store', 'channel-update',
    ], 'Everything');

    $this->actingAs($this->owner)->withSession(['current_store_id' => $this->store->id]);
});

test('the store details form changes only what it shows', function () {
    $this->put('/settings/store', [
        'name' => 'Alpha Mart Downtown', 'street' => '1 Main St', 'suite' => '', 'city' => 'Dallas',
        'state' => 'TX', 'zip_code' => '75001', 'country' => 'USA',
        // None of these belong to the form.
        'is_active' => false, 'accepts_network_ads' => true, 'created_by' => $this->owner->id,
        'slug' => 999, 'id' => $this->other->id, 'deleted_at' => now()->toDateTimeString(),
    ])->assertRedirect(route('store-settings.edit'));

    $store = $this->store->fresh();
    expect($store->name)->toBe('Alpha Mart Downtown')
        ->and($store->is_active)->toBeTrue()
        ->and($store->accepts_network_ads)->toBeFalse()
        ->and($store->created_by)->toBeNull()
        ->and((int) $store->slug)->not->toBe(999)
        ->and($this->other->fresh()->name)->toBe('Beta Deli');
});

test('a screen keeps its store, its device token and its advertising flag whatever the payload says', function () {
    $screen = Screen::factory()->withToken('real-token')->create(['store_id' => $this->store->id, 'name' => 'Front TV']);
    $hash = $screen->token_hash;

    $this->putJson("/screens/{$screen->id}", [
        'name' => 'Front TV', 'orientation' => 'portrait',
        'store_id' => $this->other->id, 'token_hash' => 'hijacked', 'device_uuid' => 'mine',
        'accepts_network_ads' => true, 'paired_by' => $this->owner->id, 'id' => 999,
    ])->assertOk();

    $screen = $screen->fresh();
    expect($screen->store_id)->toBe($this->store->id)
        ->and($screen->token_hash)->toBe($hash)
        ->and($screen->accepts_network_ads)->toBeFalse()
        ->and($screen->orientation)->toBe('portrait');
});

test('a media row keeps its store and its file whatever the payload says', function () {
    $media = Media::factory()->create(['store_id' => $this->store->id, 'title' => 'Poster', 'path' => 'media/1/real.jpg']);

    $this->putJson("/media/{$media->id}", [
        'title' => 'Poster two', 'store_id' => $this->other->id, 'path' => '../../.env',
        'disk' => 'local', 'size' => 1, 'created_by' => null, 'mime_type' => 'text/html',
    ])->assertOk();

    $media = $media->fresh();
    expect($media->store_id)->toBe($this->store->id)
        ->and($media->path)->toBe('media/1/real.jpg')
        ->and($media->disk)->toBe('public')
        ->and($media->title)->toBe('Poster two');
});

test('a daypart and a channel made inside a store belong to that store, never to the one posted', function () {
    $this->postJson('/dayparts', [
        'name' => 'Deli hours', 'start_time' => '07:00', 'end_time' => '20:00',
        'store_id' => $this->other->id, 'created_by' => null,
    ])->assertOk();

    $this->postJson('/channels', [
        'name' => 'Our Deals', 'store_id' => $this->other->id, 'is_active' => true,
    ])->assertOk();

    expect(Daypart::firstWhere('name', 'Deli hours')->store_id)->toBe($this->store->id)
        ->and(Channel::firstWhere('name', 'Our Deals')->store_id)->toBe($this->store->id);
});

test('ids that are not ids answer 422 or 404 — never a 500', function () {
    $media = Media::factory()->create(['store_id' => $this->store->id]);
    $screen = Screen::factory()->create(['store_id' => $this->store->id]);
    $version = $this->getJson("/screens/{$screen->id}/playlist")->json('version');

    $payloads = [
        ['media_id' => 0, 'duration_seconds' => 10],
        ['media_id' => -1, 'duration_seconds' => 10],
        ['media_id' => '1 OR 1=1', 'duration_seconds' => 10],
        ['media_id' => [$media->id], 'duration_seconds' => 10],
        ['media_id' => 1.5, 'duration_seconds' => 10],
        ['media_id' => $media->id, 'duration_seconds' => -5],
        ['media_id' => $media->id, 'duration_seconds' => 'ten'],
        ['media_id' => $media->id, 'channel_id' => 1, 'duration_seconds' => 10],
        ['media_id' => $media->id, 'duration_seconds' => 10, 'rules' => [['daypart_id' => 0]]],
        ['media_id' => $media->id, 'duration_seconds' => 10, 'rules' => [['daypart_id' => 'x']]],
    ];

    foreach ($payloads as $i => $item) {
        $status = $this->putJson("/screens/{$screen->id}/playlist", ['version' => $version, 'items' => [$item]])->status();
        expect($status)->toBe(422, 'playlist payload #'.$i.' answered '.$status);
    }

    foreach (['abc', '0', '-1', '1.5', '999999999999'] as $id) {
        $status = $this->putJson("/media/{$id}", ['title' => 'x'])->status();
        expect($status)->toBeIn([404, 422], "media id {$id} answered {$status}");
    }
});

test('a search that carries quotes, wildcards or SQL is treated as text', function () {
    Media::factory()->create(['store_id' => $this->store->id, 'title' => 'Burger deal']);
    Media::factory()->create(['store_id' => $this->other->id, 'title' => 'Beta poster']);

    foreach (["' OR '1'='1", "'; DROP TABLE media; --", '%', '_', '\\', '"', '100%%', 'ünïcödé', str_repeat('a', 500)] as $search) {
        $response = $this->getJson('/media/data?search='.urlencode($search))->assertOk();
        $titles = collect($response->json('media'))->pluck('title');
        expect($titles)->not->toContain('Beta poster', "search [{$search}] leaked another store's row");
    }

    // The table is still there, and so are its rows.
    expect(DB::table('media')->count())->toBe(2);
});

test('pagination cannot be used to dump a table or to break the page', function () {
    Media::factory()->count(3)->create(['store_id' => $this->store->id]);

    // The listing answers with perPage, clamped between 1 and 100 in HandlesCrudData::paginatedResponse.
    $huge = $this->getJson('/media/data?per_page=999999')->assertOk()->json();
    expect($huge['perPage'])->toBeLessThanOrEqual(100)
        ->and(count($huge['media']))->toBeLessThanOrEqual(100);

    foreach (['0', '-5', 'abc', '1e9', '', '1.5', '99999999999999999999'] as $perPage) {
        $response = $this->getJson('/media/data?per_page='.$perPage)->assertOk();
        expect($response->json('perPage'))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(100);
    }

    foreach (['-1', '0', 'abc', '999999'] as $page) {
        $this->getJson('/media/data?page='.$page)->assertOk();
    }
});

test('a name the length of a book, or full of control characters, is refused rather than stored', function () {
    $long = str_repeat('a', 5000);

    $this->postJson('/dayparts', ['name' => $long, 'start_time' => '07:00', 'end_time' => '08:00'])
        ->assertStatus(422);
    $this->postJson('/channels', ['name' => $long])->assertStatus(422);
    $this->put('/settings/store', [
        'name' => $long, 'street' => '1 Main St', 'city' => 'Dallas', 'state' => 'TX',
        'zip_code' => '75001', 'country' => 'USA',
    ])->assertSessionHasErrorsIn('storeDetails', 'name');

    expect($this->store->fresh()->name)->toBe('Alpha Mart')
        ->and(Daypart::count())->toBe(0)
        ->and(Channel::count())->toBe(0);
});

test('a script tag in a name is stored as text and printed as text', function () {
    $payload = '<script>alert("xss")</script>';

    $this->postJson('/dayparts', ['name' => $payload, 'start_time' => '07:00', 'end_time' => '08:00']);
    $this->put('/settings/store', [
        'name' => $payload, 'street' => '1 Main St', 'city' => 'Dallas', 'state' => 'TX',
        'zip_code' => '75001', 'country' => 'USA',
    ]);

    $page = $this->get('/settings/store')->assertOk();
    $page->assertDontSee($payload, false);
    $page->assertSee('&lt;script&gt;', false);

    // The dashboard and the members page print the store's name too.
    $this->get('/dashboard')->assertOk()->assertDontSee($payload, false);
    $this->get('/members')->assertOk()->assertDontSee($payload, false);
});

test('an email field takes an address, not a header injection or a list', function () {
    foreach ([
        "friend@example.com\nBcc: victim@example.com",
        'friend@example.com, other@example.com',
        '<script>@example.com',
        'friend@example.com>',
        str_repeat('a', 300).'@example.com',
        'friend@',
        '@example.com',
    ] as $email) {
        $status = $this->postJson('/members/invitations', ['email' => $email, 'role_id' => Role::starter(Role::STAFF)->id])->status();
        expect($status)->toBe(422, "email [{$email}] answered {$status}");
    }

    expect(DB::table('invitations')->count())->toBe(0);
});
