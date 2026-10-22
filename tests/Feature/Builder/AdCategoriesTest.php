<?php

use App\Models\ActivityLog;
use App\Models\AdCategory;
use App\Models\BuilderAd;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| The Ad Builder's categories (owner, 2026-10-22)
|--------------------------------------------------------------------------
|
| "Assets ha ushi k uper ek category ya tag ka tab ho aur us mein mai tags ya category banao jese menu, education, beer ...
| create ad karte ha toh For ki field k nechai aye aur portrait k uper aye aur multiple select ho sakte ho. aur uski filter
| bhi uper bana dena jaha search ha." One list, the platform's: made, renamed and deleted above the organizations alone. Every
| ad may be put in several — in Create Ad and in the editor — and the Ads page and the Premium Templates are filtered by them.
| They describe an ad and are no part of its design: the screens and Publish never hear of them.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->menu = AdCategory::factory()->create(['name' => 'Menu']);
    $this->beer = AdCategory::factory()->create(['name' => 'Beer']);
    $this->school = AdCategory::factory()->create(['name' => 'Education']);
});

function asAlphaDesigner(object $test): object
{
    $designer = createOrganizationUser($test->alpha, ['ad-view', 'ad-store', 'ad-update', 'ad-destroy'], 'Designer');

    return $test->actingAs($designer)->withSession(['current_organization_id' => $test->alpha->id]);
}

/** The ids of the categories an ad is in, A to Z by name. */
function categoryIdsOf(BuilderAd $ad): array
{
    return $ad->categories()->pluck('ad_categories.id')->all();
}

/* ── The Categories page ─────────────────────────────────────────────────── */

test('the super admin opens Categories under the Ad Builder and lists them A to Z, with how many ads each holds', function () {
    $this->actingAs(createSuperAdmin());
    BuilderAd::factory()->create(['organization_id' => null])->categories()->attach([$this->menu->id, $this->beer->id]);
    BuilderAd::factory()->create(['organization_id' => $this->alpha->id])->categories()->attach($this->menu->id);

    $this->get('/builder/categories')->assertOk()
        ->assertSee('<title>Categories', false)
        ->assertSee('dusk="add-category"', false)
        ->assertSee('href="'.route('builder.categories').'"', false);

    $rows = $this->getJson('/builder/categories/data')->assertOk()->json('categories');
    expect(collect($rows)->pluck('ads_count', 'name')->all())->toBe(['Beer' => 1, 'Education' => 0, 'Menu' => 2])
        ->and($rows[0])->not->toHaveKey('created_by');

    expect(collect($this->getJson('/builder/categories/data?search=edu')->json('categories'))->pluck('name')->all())->toBe(['Education']);
});

test('a category is made, renamed and deleted, each logged, and a delete takes it off its ads and nothing more', function () {
    $this->actingAs(createSuperAdmin());
    $ad = BuilderAd::factory()->create(['organization_id' => $this->alpha->id]);
    $ad->categories()->attach([$this->menu->id, $this->beer->id]);

    $made = $this->postJson('/builder/categories', ['name' => '  Fast   food '])->assertOk()->json('category');
    expect($made['name'])->toBe('Fast food')
        ->and(ActivityLog::where('action', 'ad_category.created')->value('description'))->toBe('Created the ad category Fast food');

    $this->putJson("/builder/categories/{$this->beer->id}", ['name' => 'Beer & Wine'])->assertOk();
    expect($this->beer->fresh()->name)->toBe('Beer & Wine')
        ->and(ActivityLog::where('action', 'ad_category.updated')->value('description'))->toBe('Renamed the ad category Beer to Beer & Wine');

    // The same name again is no change, and nothing is logged.
    $this->putJson("/builder/categories/{$this->beer->id}", ['name' => 'Beer & Wine'])->assertOk();
    expect(ActivityLog::where('action', 'ad_category.updated')->count())->toBe(1);

    $this->deleteJson("/builder/categories/{$this->menu->id}")->assertOk();
    expect(AdCategory::find($this->menu->id))->toBeNull()
        ->and(BuilderAd::find($ad->id))->not->toBeNull()
        ->and(categoryIdsOf($ad))->toBe([$this->beer->id])
        ->and(ActivityLog::where('action', 'ad_category.deleted')->value('description'))->toBe('Deleted the ad category Menu (taken off 1 ad)');
});

test('a name is new to the list however it is typed, and says what is wrong in the form\'s words', function () {
    $this->actingAs(createSuperAdmin());

    foreach (['menu', ' MENU ', 'Mênu', 'Me-nu', 'me nu'] as $typed) {
        $this->postJson('/builder/categories', ['name' => $typed])->assertStatus(422)
            ->assertJsonValidationErrors(['name' => 'There is a category called Menu already.']);
    }

    $this->postJson('/builder/categories', ['name' => ''])->assertStatus(422)->assertJsonValidationErrors(['name' => 'Give the category a name.']);
    $this->postJson('/builder/categories', ['name' => '!!! ---'])->assertStatus(422)
        ->assertJsonValidationErrors(['name' => 'Give the category a name with letters or numbers in it.']);
    $this->postJson('/builder/categories', ['name' => str_repeat('a', 41)])->assertStatus(422)
        ->assertJsonValidationErrors(['name' => 'A category name is 40 characters at most.']);
    $this->postJson('/builder/categories', ['name' => ['Menu']])->assertStatus(422)->assertJsonValidationErrors(['name' => 'Give the category a name.']);

    // Its own name, in other letters, is a rename of itself.
    $this->putJson("/builder/categories/{$this->menu->id}", ['name' => 'MENU'])->assertOk();
    expect($this->menu->fresh()->name)->toBe('MENU')->and(AdCategory::count())->toBe(3);
});

test('only the platform team holding the permissions reaches Categories, never an organization\'s person', function () {
    // An organization's designer, with every ad permission: the list is the platform's.
    asAlphaDesigner($this);
    $this->get('/builder/categories')->assertForbidden();
    $this->getJson('/builder/categories/data')->assertForbidden();
    $this->postJson('/builder/categories', ['name' => 'Sneaky'])->assertForbidden();
    $this->putJson("/builder/categories/{$this->menu->id}", ['name' => 'Sneaky'])->assertForbidden();
    $this->deleteJson("/builder/categories/{$this->menu->id}")->assertForbidden();
    $this->get('/builder')->assertOk()->assertDontSee('href="'.route('builder.categories').'"', false);

    // A platform user with View alone sees the list and changes nothing.
    $this->actingAs(createPlatformUser(['ad-view', 'ad-category-view'], 'Catalogue Reader'))->withSession([]);
    $this->get('/builder/categories')->assertOk()->assertDontSee('dusk="add-category"', false);
    $this->postJson('/builder/categories', ['name' => 'Sneaky'])->assertForbidden();
    $this->putJson("/builder/categories/{$this->menu->id}", ['name' => 'Sneaky'])->assertForbidden();
    $this->deleteJson("/builder/categories/{$this->menu->id}")->assertForbidden();

    expect(AdCategory::pluck('name')->sort()->values()->all())->toBe(['Beer', 'Education', 'Menu']);
});

/* ── Putting an ad in categories ─────────────────────────────────────────── */

test('a new ad is saved in the categories Create Ad ticked, and a save changes them without touching the design', function () {
    asAlphaDesigner($this);

    $id = $this->postJson('/builder', [
        'name' => 'Lunch', 'document' => BuilderAd::blankDocument(), 'categories' => [$this->menu->id, $this->beer->id],
    ])->assertOk()->assertJsonPath('ad.category_ids', [$this->beer->id, $this->menu->id])->json('ad.id');
    $ad = BuilderAd::findOrFail($id);

    expect(categoryIdsOf($ad))->toBe([$this->beer->id, $this->menu->id]);

    // Published, then put in another category: still published as it was, nothing waiting for Publish.
    $ad = BuilderAd::factory()->published()->create(['organization_id' => $this->alpha->id, 'name' => 'Dinner']);
    $stamp = $ad->updated_at->toIso8601String();
    $this->travel(5)->minutes();

    $this->putJson("/builder/{$ad->id}", ['name' => 'Dinner', 'document' => $ad->document, 'categories' => [$this->school->id]])
        ->assertOk()->assertJsonPath('ad.status', 'published')->assertJsonPath('message', 'Ad saved');
    expect(categoryIdsOf($ad))->toBe([$this->school->id])
        ->and($ad->fresh()->updated_at->toIso8601String())->toBe($stamp)
        ->and(ActivityLog::where('action', 'ad.categorized')->value('description'))->toBe('Put ad Dinner in the category Education')
        ->and(ActivityLog::where('action', 'ad.updated')->count())->toBe(0);

    // A save without the key keeps them; an empty list takes the ad out of every one.
    $this->putJson("/builder/{$ad->id}", ['name' => 'Dinner', 'document' => $ad->document])->assertOk();
    expect(categoryIdsOf($ad))->toBe([$this->school->id]);

    $this->putJson("/builder/{$ad->id}", ['name' => 'Dinner', 'document' => $ad->document, 'categories' => []])->assertOk();
    expect(categoryIdsOf($ad))->toBe([])
        ->and(ActivityLog::where('action', 'ad.categorized')->latest('id')->value('description'))->toBe('Took ad Dinner out of every category');

    // The same ones again are no change, and nothing more is logged.
    $logged = ActivityLog::count();
    $this->putJson("/builder/{$ad->id}", ['name' => 'Dinner', 'document' => $ad->document, 'categories' => []])->assertOk();
    expect(ActivityLog::count())->toBe($logged);
});

test('a category deleted while the editor was open is simply not kept, and never stops the design from saving', function () {
    asAlphaDesigner($this);
    $ad = BuilderAd::factory()->create(['organization_id' => $this->alpha->id, 'name' => 'Lunch']);
    $gone = $this->beer->id;
    $this->beer->delete();

    $this->putJson("/builder/{$ad->id}", ['name' => 'Lunch', 'document' => $ad->document, 'categories' => [$this->menu->id, $gone, 999999]])
        ->assertOk();

    expect(categoryIdsOf($ad))->toBe([$this->menu->id]);
});

test('categories in any other shape are refused in words, never a 500', function () {
    asAlphaDesigner($this);
    $ad = BuilderAd::factory()->create(['organization_id' => $this->alpha->id, 'name' => 'Lunch']);
    $save = fn (mixed $categories) => $this->putJson("/builder/{$ad->id}", ['name' => 'Lunch', 'document' => $ad->document, 'categories' => $categories]);

    $save('1,2')->assertStatus(422)->assertJsonValidationErrors(['categories' => 'Choose the categories from the list.']);
    $save(null)->assertStatus(422)->assertJsonValidationErrors(['categories' => 'Choose the categories from the list.']);
    $save(['a' => $this->menu->id])->assertStatus(422)->assertJsonValidationErrors(['categories' => 'Choose the categories from the list.']);
    $save(['Menu'])->assertStatus(422)->assertJsonValidationErrors(['categories.0' => 'Choose the categories from the list.']);
    $save([0])->assertStatus(422)->assertJsonValidationErrors(['categories.0' => 'Choose the categories from the list.']);
    $save([[$this->menu->id]])->assertStatus(422)->assertJsonValidationErrors(['categories.0' => 'Choose the categories from the list.']);
    $save([$this->menu->id, $this->menu->id])->assertStatus(422)->assertJsonValidationErrors(['categories.0' => 'Choose each category once.']);
    $save(range(1, 11))->assertStatus(422)->assertJsonValidationErrors(['categories' => 'An ad may be in at most 10 categories.']);

    expect(categoryIdsOf($ad))->toBe([]);
});

test('Create Ad carries the categories ticked into the editor, those still on the list alone', function () {
    asAlphaDesigner($this);

    $page = $this->get("/builder/create?orientation=portrait&categories={$this->menu->id},{$this->beer->id},999999,x,{$this->menu->id}")->assertOk();
    $page->assertSee('dusk="ad-categories"', false);
    expect($page->viewData('categoryIds'))->toBe([$this->beer->id, $this->menu->id]);

    foreach (['categories[]=1', 'categories=', 'categories=,,', 'categories=1e3', 'categories=-1'] as $query) {
        expect($this->get("/builder/create?orientation=landscape&{$query}")->assertOk()->viewData('categoryIds'))->toBe([], $query);
    }

    // The editor of a saved ad opens with its own.
    $ad = BuilderAd::factory()->create(['organization_id' => $this->alpha->id]);
    $ad->categories()->attach($this->school->id);
    expect($this->get("/builder/{$ad->id}")->assertOk()->viewData('categoryIds'))->toBe([$this->school->id]);
});

test('with no categories on the list, nothing asks for one', function () {
    AdCategory::query()->delete();
    asAlphaDesigner($this);

    $this->get('/builder')->assertOk()->assertDontSee('dusk="ads-filter-category"', false)->assertDontSee('new-ad-categories', false);
    $this->get('/builder/create?orientation=landscape')->assertOk()->assertDontSee('dusk="ad-categories"', false);
});

/* ── The filters ─────────────────────────────────────────────────────────── */

test('the Ads page\'s Category list narrows the ads to one category, and every card names its categories', function () {
    asAlphaDesigner($this);
    $lunch = BuilderAd::factory()->create(['organization_id' => $this->alpha->id, 'name' => 'Lunch']);
    $lunch->categories()->attach([$this->menu->id, $this->beer->id]);
    BuilderAd::factory()->create(['organization_id' => $this->alpha->id, 'name' => 'Open day'])->categories()->attach($this->school->id);
    BuilderAd::factory()->create(['organization_id' => $this->alpha->id, 'name' => 'Plain']);
    // Another organization's ad in the same category is still theirs alone.
    BuilderAd::factory()->create(['organization_id' => Organization::factory()->create()->id, 'name' => 'Theirs'])->categories()->attach($this->menu->id);

    $names = fn (string $query) => collect($this->getJson('/builder/data?'.$query)->assertOk()->json('ads'))->pluck('name')->sort()->values()->all();

    expect($names("category={$this->menu->id}"))->toBe(['Lunch'])
        ->and($names("category={$this->school->id}"))->toBe(['Open day'])
        ->and($names('category=999999'))->toBe([])
        ->and($names("category={$this->menu->id}&status=published"))->toBe([]);

    foreach (['category=menu', 'category=', 'category[]=1', 'category=-1', 'category='.str_repeat('9', 30)] as $query) {
        expect($names($query))->toHaveCount(3, $query);
    }

    $row = collect($this->getJson('/builder/data')->json('ads'))->firstWhere('name', 'Lunch');
    expect($row['categories'])->toBe([['id' => $this->beer->id, 'name' => 'Beer'], ['id' => $this->menu->id, 'name' => 'Menu']]);

    $this->get('/builder')->assertOk()->assertSee('dusk="ads-filter-category"', false)->assertSee('All Categories')
        ->assertSee('dusk="new-ad-categories"', false);
});

test('Copy keeps the categories, and deleting an ad leaves no row behind', function () {
    asAlphaDesigner($this);
    $ad = BuilderAd::factory()->create(['organization_id' => $this->alpha->id, 'name' => 'Lunch']);
    $ad->categories()->attach([$this->menu->id, $this->beer->id]);

    $copy = BuilderAd::findOrFail($this->postJson("/builder/{$ad->id}/duplicate")->assertOk()->json('ad.id'));
    expect(categoryIdsOf($copy))->toBe([$this->beer->id, $this->menu->id]);

    $copy->delete();
    expect(DB::table('ad_category_builder_ad')->where('builder_ad_id', $copy->id)->count())->toBe(0);
});

/* ── Premium Templates ───────────────────────────────────────────────────── */

test('the gallery is filtered by category, names each template\'s, and a copy is in the template\'s and the ticked ones', function () {
    $burger = BuilderAd::factory()->published()->create(['organization_id' => null, 'name' => 'Burger menu']);
    $burger->categories()->attach($this->menu->id);
    BuilderAd::factory()->published()->create(['organization_id' => null, 'name' => 'Open day'])->categories()->attach($this->school->id);
    asAlphaDesigner($this);

    $listed = fn (string $query) => collect($this->getJson('/builder/templates?orientation=landscape&'.$query)->assertOk()->json('templates'));

    expect($listed("category={$this->menu->id}")->pluck('name')->all())->toBe(['Burger menu'])
        ->and($listed("category={$this->beer->id}")->all())->toBe([])
        ->and($listed('')->pluck('categories', 'name')->all())->toBe(['Burger menu' => ['Menu'], 'Open day' => ['Education']]);

    $this->getJson('/builder/templates?orientation=landscape&category=menu')->assertStatus(422)
        ->assertJsonValidationErrors(['category' => 'Choose a category from the list.']);
    $this->getJson('/builder/templates?orientation=landscape&category[]=1')->assertStatus(422);

    $copy = BuilderAd::findOrFail($this->postJson("/builder/templates/{$burger->id}", ['categories' => [$this->beer->id, $this->menu->id, 999999]])
        ->assertOk()->json('ad.id'));
    expect(categoryIdsOf($copy))->toBe([$this->beer->id, $this->menu->id])
        ->and(categoryIdsOf($burger))->toBe([$this->menu->id]);

    // Nothing ticked: the template's own.
    $plain = BuilderAd::findOrFail($this->postJson("/builder/templates/{$burger->id}")->assertOk()->json('ad.id'));
    expect(categoryIdsOf($plain))->toBe([$this->menu->id]);

    $this->postJson("/builder/templates/{$burger->id}", ['categories' => 'Menu'])->assertStatus(422)
        ->assertJsonValidationErrors(['categories' => 'Choose the categories from the list.']);
    $this->postJson("/builder/templates/{$burger->id}", ['categories' => ['x']])->assertStatus(422)
        ->assertJsonValidationErrors(['categories.0' => 'Choose the categories from the list.']);
});
