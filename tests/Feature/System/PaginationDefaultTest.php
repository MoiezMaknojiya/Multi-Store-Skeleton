<?php

use App\Models\Store;

/*
|--------------------------------------------------------------------------
| One page size, everywhere
|--------------------------------------------------------------------------
|
| Every listing in the panel used to pick its own number — ten here, twenty-five
| on the activity log, a hundred on users — so somebody moving between them had no
| way of knowing whether a short list meant "that is all there is" or "there is
| more, below". One number now, in two places that must agree: ROWS_PER_PAGE in
| resources/js/core/crud-table-base.js, and HandlesCrudData's own default for a caller
| that sends none.
|
*/

beforeEach(function () {
    $this->actor = createSuperAdmin(['store-view']);
});

test('a listing returns fifty rows when the caller asks for no particular number', function () {
    Store::factory()->count(55)->create();

    $response = $this->actingAs($this->actor)->getJson('/stores/data')->assertOk();

    expect($response->json('perPage'))->toBe(50);
    expect($response->json('stores'))->toHaveCount(50);
    expect($response->json('lastPage'))->toBe(2);
});

test('the last page holds what is left over', function () {
    Store::factory()->count(55)->create();

    $response = $this->actingAs($this->actor)->getJson('/stores/data?page=2')->assertOk();

    expect($response->json('stores'))->toHaveCount(5);
    expect($response->json('currentPage'))->toBe(2);
});

test('a caller cannot dump the whole table, or ask for nothing', function () {
    Store::factory()->count(120)->create();

    // The ceiling stands whatever the default is — a listing is a page, not an export.
    expect($this->actingAs($this->actor)->getJson('/stores/data?per_page=999999')
        ->assertOk()->json('perPage'))->toBe(100);

    // And a zero or a negative would divide the total by nothing.
    expect($this->actingAs($this->actor)->getJson('/stores/data?per_page=0')
        ->assertOk()->json('perPage'))->toBe(1);
});
