<?php

use App\Models\BuilderAd;
use App\Models\Organization;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| The Ads page's Orientation list (owner, 2026-10-23)
|--------------------------------------------------------------------------
|
| "ad builder k ander landscape aur portrait wala bhi filter laga do jese baki lagaya ha": beside the Status list, every
| ad, the landscape ones or the portrait ones. It only ever narrows what a person may already see, works with the other
| lists, and a value it does not offer is every ad, as the Status list's is.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->alpha = Organization::factory()->create(['name' => 'Alpha Mart']);

    BuilderAd::factory()->create(['organization_id' => null, 'name' => 'Platform wide draft', 'orientation' => BuilderAd::LANDSCAPE]);
    BuilderAd::factory()->published()->create(['organization_id' => null, 'name' => 'Platform upright template', 'orientation' => BuilderAd::PORTRAIT]);
    BuilderAd::factory()->create(['organization_id' => $this->alpha->id, 'name' => 'Alpha upright draft', 'orientation' => BuilderAd::PORTRAIT]);
    BuilderAd::factory()->published()->create(['organization_id' => $this->alpha->id, 'name' => 'Alpha wide live', 'orientation' => BuilderAd::LANDSCAPE]);
});

function adsShown(object $test, string $query = ''): array
{
    return collect($test->getJson('/builder/data'.($query === '' ? '' : '?'.$query))->assertOk()->json('ads'))
        ->pluck('name')->sort()->values()->all();
}

test('above the organizations the Orientation list narrows every owner to its landscape or its portrait ads', function () {
    $this->actingAs(createSuperAdmin());

    expect(adsShown($this, 'orientation=landscape'))->toBe(['Alpha wide live', 'Platform wide draft'])
        ->and(adsShown($this, 'orientation=portrait'))->toBe(['Alpha upright draft', 'Platform upright template'])
        // With the Owner and Status lists.
        ->and(adsShown($this, 'organization_id=platform&orientation=portrait'))->toBe(['Platform upright template'])
        ->and(adsShown($this, "organization_id={$this->alpha->id}&orientation=landscape&status=draft"))->toBe([]);
});

test('inside an organization the Orientation list narrows its own ads alone, and the page offers it', function () {
    $designer = createOrganizationUser($this->alpha, ['ad-view', 'ad-store', 'ad-update'], 'Designer');
    $this->actingAs($designer)->withSession(['current_organization_id' => $this->alpha->id]);

    expect(adsShown($this, 'orientation=portrait'))->toBe(['Alpha upright draft'])
        ->and(adsShown($this, 'orientation=landscape'))->toBe(['Alpha wide live']);

    $this->get('/builder')->assertOk()
        ->assertSee('dusk="ads-filter-orientation"', false)
        ->assertSee('Any orientation');
});

test('an orientation the list does not offer is every ad, in any shape, never a 500', function () {
    $this->actingAs(createSuperAdmin());

    foreach (['orientation=square', 'orientation=', 'orientation[]=portrait', 'orientation[portrait]=1', 'orientation=PORTRAIT'] as $query) {
        expect(adsShown($this, $query))->toHaveCount(4, $query);
    }
});
