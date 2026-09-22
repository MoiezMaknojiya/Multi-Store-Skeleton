<?php

use App\Models\BuilderAd;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Daypart;
use App\Models\Media;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Screen;
use App\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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

test('an upload joins the library of the store it is made in, whatever library the payload names', function () {
    // Above the stores `store_id` picks the library an upload joins; inside a store it is never read.
    Storage::fake('public');
    $channel = Channel::factory()->create(['store_id' => $this->store->id, 'name' => 'Our Deals']);

    $this->postJson('/media', ['file' => UploadedFile::fake()->image('menu.jpg'), 'store_id' => $this->other->id])->assertOk();
    $this->postJson("/channels/{$channel->id}/ads", [
        'file' => UploadedFile::fake()->image('deal.jpg'), 'seconds' => 10, 'store_id' => $this->other->id,
    ])->assertOk();

    expect(Media::count())->toBe(2)
        ->and(Media::pluck('store_id')->unique()->all())->toBe([$this->store->id]);
});

test('the library pickers and a channel ad\'s file take one shape only — never a 500', function () {
    $channel = Channel::factory()->create(['store_id' => $this->store->id, 'name' => 'Our Deals']);

    // A listing forgives what it can (a page that is not a number is page 1) and refuses the rest.
    foreach (['library[]=1', 'library=0', 'library=-1', 'library=1%20OR%201=1', 'type[]=image', 'type=pdf', 'search[]=x', 'page[]=1', 'per_page=999999'] as $query) {
        $status = $this->getJson("/channels/{$channel->id}/library?{$query}")->status();
        expect($status)->toBeIn([200, 422], "the channel picker's ?{$query} answered {$status}");
    }

    foreach (['library[]=1', 'library=0', 'library=platform%27--'] as $query) {
        expect($this->getJson("/media/data?{$query}")->status())->toBe(422, "/media/data?{$query}");
    }

    foreach ([0, -1, '1 OR 1=1', [1], 1.5, 'abc', '99999999999'] as $id) {
        $status = $this->postJson("/channels/{$channel->id}/ads", ['media_id' => $id, 'seconds' => 10])->status();
        expect($status)->toBe(422, 'media_id '.json_encode($id).' answered '.$status);
    }

    expect(ChannelAd::count())->toBe(0);
});

test('an unpublished Ad Builder page never reaches a television, however its line is posted', function () {
    // The pickers never offer an unpublished ad. A line posted by hand is taken — it keeps its place like any line
    // waiting for its page — but nothing of it goes to a screen until the ad is published again (docs/AD-BUILDER-SPEC.md §9).
    $design = BuilderAd::factory()->withText()->published()->create(['store_id' => $this->store->id]);
    $this->postJson("/builder/{$design->id}/unpublish")->assertOk();
    $screen = Screen::factory()->withToken('smuggled-token')->create(['store_id' => $this->store->id]);
    $channel = Channel::factory()->create(['store_id' => $this->store->id, 'name' => 'Our Deals']);
    $version = $this->getJson("/screens/{$screen->id}/playlist")->json('version');

    $this->putJson("/screens/{$screen->id}/playlist", ['version' => $version, 'items' => [
        ['media_id' => $design->media_id, 'duration_seconds' => 10],
        ['channel_id' => $channel->id],
    ]])->assertOk();
    $this->postJson("/channels/{$channel->id}/ads", ['media_id' => $design->media_id, 'seconds' => 10])->assertOk();
    $screen->forceFill(['default_media_id' => $design->media_id])->save();

    // As a line, as a channel's ad, as the holding picture: none of it.
    $manifest = $this->getJson('/device/playlist', ['Authorization' => 'Bearer smuggled-token'])->assertOk()->json();
    expect($manifest['items'])->toBe([]);
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

    // Words no title here contains. Read as SQL, the first would match every row; read as text, nothing.
    foreach (["' OR '1'='1", "'; DROP TABLE media; --", '"', '\\', '100%%', 'ünïcödé', str_repeat('a', 500)] as $search) {
        $titles = collect($this->getJson('/media/data?search='.urlencode($search))->assertOk()->json('media'))->pluck('title');

        expect($titles->all())->toBe([], "search [{$search}] matched a title that does not contain it");
    }

    // A LIKE wildcard still matches everything it can — but only ever inside this store.
    foreach (['%', '_'] as $search) {
        $titles = collect($this->getJson('/media/data?search='.urlencode($search))->assertOk()->json('media'))->pluck('title');

        expect($titles->contains('Beta poster'))->toBeFalse("search [{$search}] leaked another store's row")
            ->and($titles->all())->toBe(['Burger deal']);
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
    $daypartName = '<script>alert("daypart-xss")</script>';     // its own words, to be found on a page by

    // Kept exactly as typed — never stripped, never refused for looking like code.
    $this->postJson('/dayparts', ['name' => $daypartName, 'start_time' => '07:00', 'end_time' => '08:00'])->assertOk();
    $this->put('/settings/store', [
        'name' => $payload, 'street' => '1 Main St', 'city' => 'Dallas', 'state' => 'TX',
        'zip_code' => '75001', 'country' => 'USA',
    ])->assertRedirect(route('store-settings.edit'));

    expect(Daypart::sole()->name)->toBe($daypartName)
        ->and($this->store->fresh()->name)->toBe($payload);

    $page = $this->get('/settings/store')->assertOk();
    $page->assertDontSee($payload, false);
    $page->assertSee('&lt;script&gt;', false);

    // The dashboard and the members page print the store's name too.
    $this->get('/dashboard')->assertOk()->assertDontSee($payload, false);
    $this->get('/members')->assertOk()->assertDontSee($payload, false);

    // A screen's page hands the store's dayparts to its schedule editor inside an attribute, where one raw
    // quote or tag would break out of it: the daypart's name is on the page, but only ever encoded.
    $screen = Screen::factory()->create(['store_id' => $this->store->id]);

    $this->get("/screens/{$screen->id}")->assertOk()
        ->assertSee('daypart-xss', false)
        ->assertDontSee($daypartName, false)
        ->assertDontSee('"daypart-xss', false);
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

test('a search for "0" is a search, not "no search"', function () {
    // PHP reads "0" as false; the listings once did too, and showed everything.
    Media::factory()->create(['store_id' => $this->store->id, 'title' => 'Menu 2020']);
    Media::factory()->create(['store_id' => $this->store->id, 'title' => 'Burger deal']);

    $titles = collect($this->getJson('/media/data?search=0')->assertOk()->json('media'))->pluck('title')->all();

    expect($titles)->toBe(['Menu 2020']);
});
