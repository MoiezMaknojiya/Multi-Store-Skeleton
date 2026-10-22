<?php

use App\Models\BuilderAd;
use App\Models\Organization;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| The Ads page's Status list (owner, 2026-10-22)
|--------------------------------------------------------------------------
|
| "Platform aur organization ka selectbox ha wasia hi draft publish ka bhi bana do": beside the Owner list, a list of
| every ad, the published ones (on the screens, with changes waiting or not) and the drafts. It only ever narrows what
| a person may already see, and a value it does not offer is every ad, as the Screens page's Status is.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->beta = Organization::factory()->create(['name' => 'Beta Deli']);

    BuilderAd::factory()->create(['organization_id' => null, 'name' => 'Platform draft']);
    BuilderAd::factory()->published()->create(['organization_id' => null, 'name' => 'Platform template']);
    BuilderAd::factory()->create(['organization_id' => $this->alpha->id, 'name' => 'Alpha draft']);
    BuilderAd::factory()->published()->create(['organization_id' => $this->alpha->id, 'name' => 'Alpha live']);
    BuilderAd::factory()->published()->create(['organization_id' => $this->beta->id, 'name' => 'Beta live']);

    // Published, then changed since: still on the screens, so still published ("Changes not published").
    $changed = BuilderAd::factory()->published()->create(['organization_id' => $this->alpha->id, 'name' => 'Alpha changed']);
    $changed->update(['name' => 'Alpha changed again']);

    // Unpublished: its page stays a row of the library, but no screen shows it — a draft again.
    BuilderAd::factory()->published()->create(['organization_id' => $this->alpha->id, 'name' => 'Alpha unpublished'])
        ->update(['published_at' => null]);
});

function adNames(object $test, string $query = ''): array
{
    return collect($test->getJson('/builder/data'.($query === '' ? '' : '?'.$query))->assertOk()->json('ads'))
        ->pluck('name')->sort()->values()->all();
}

test('above the organizations the Status list narrows every owner to the published ads or the drafts', function () {
    $this->actingAs(createSuperAdmin());

    expect(adNames($this, 'status=published'))->toBe(['Alpha changed again', 'Alpha live', 'Beta live', 'Platform template'])
        ->and(adNames($this, 'status=draft'))->toBe(['Alpha draft', 'Alpha unpublished', 'Platform draft'])
        ->and(adNames($this))->toHaveCount(7);

    // With the Owner list: the platform's drafts, one organization's published ads.
    expect(adNames($this, 'organization_id=platform&status=draft'))->toBe(['Platform draft'])
        ->and(adNames($this, "organization_id={$this->alpha->id}&status=published"))->toBe(['Alpha changed again', 'Alpha live'])
        ->and(adNames($this, "organization_id={$this->beta->id}&status=draft"))->toBe([]);

    // Each row still says which it is, so the card's badge and the list agree.
    $statuses = collect($this->getJson('/builder/data?status=published')->json('ads'))->pluck('status', 'name');
    expect($statuses['Alpha changed again'])->toBe('changed')
        ->and($statuses['Alpha live'])->toBe('published');
});

test('inside an organization the Status list narrows its own ads alone', function () {
    $designer = createOrganizationUser($this->alpha, ['ad-view', 'ad-store', 'ad-update'], 'Designer');
    $this->actingAs($designer)->withSession(['current_organization_id' => $this->alpha->id]);

    expect(adNames($this, 'status=published'))->toBe(['Alpha changed again', 'Alpha live'])
        ->and(adNames($this, 'status=draft'))->toBe(['Alpha draft', 'Alpha unpublished']);

    // The page offers it to an organization too, with the words it shows.
    $this->get('/builder')->assertOk()
        ->assertSee('dusk="ads-filter-status"', false)
        ->assertSee('All Statuses')->assertSee('Published')->assertSee('Drafts');
});

test('a status the list does not offer is every ad, in any shape, never a 500', function () {
    $this->actingAs(createSuperAdmin());

    foreach (['status=archived', 'status=', 'status[]=draft', 'status[draft]=1', 'status=DRAFT'] as $query) {
        expect(adNames($this, $query))->toHaveCount(7, $query);
    }
});
