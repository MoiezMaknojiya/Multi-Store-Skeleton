<?php

use App\Models\AdCategory;
use App\Models\BuilderAd;
use App\Models\Organization;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Breaking the Ad Builder's categories
|--------------------------------------------------------------------------
|
| One list, the platform's (owner, 2026-10-22). An organization's person may put its own ads in categories, never change the
| list, never reach another organization's ad through it, and never have the permissions put on a role of theirs. Ids and
| payloads nobody sends are refused, never a 500.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->beta = Organization::factory()->create(['name' => 'Beta Deli']);
    $this->menu = AdCategory::factory()->create(['name' => 'Menu']);
});

test('the category permissions cannot be put on an organization’s role, by the platform or from inside', function () {
    $roleMaker = createOrganizationUser($this->alpha, ['role-view', 'role-store', 'ad-view'], 'Role maker');
    $this->actingAs($roleMaker)->withSession(['current_organization_id' => $this->alpha->id]);
    $this->postJson('/roles', ['name' => 'Tagger', 'permissions' => ['ad-category-store']])->assertStatus(422);

    $this->actingAs(createSuperAdmin())->flushSession();
    $this->postJson('/roles', ['name' => 'Organization tagger', 'type' => 'organization', 'permissions' => ['ad-category-view', 'ad-category-destroy']])
        ->assertStatus(422);

    expect(Role::where('name', 'like', '%agger%')->exists())->toBeFalse();
});

test('a permission row put on an organization’s role by hand still opens nothing: the routes keep the platform’s lock', function () {
    $sneaky = createOrganizationUser($this->alpha, ['ad-view', 'ad-category-view', 'ad-category-store', 'ad-category-update', 'ad-category-destroy'], 'Sneaky');
    $this->actingAs($sneaky)->withSession(['current_organization_id' => $this->alpha->id]);

    $this->get('/builder/categories')->assertForbidden();
    $this->getJson('/builder/categories/data')->assertForbidden();
    $this->postJson('/builder/categories', ['name' => 'Taken'])->assertForbidden();
    $this->putJson("/builder/categories/{$this->menu->id}", ['name' => 'Taken'])->assertForbidden();
    $this->deleteJson("/builder/categories/{$this->menu->id}")->assertForbidden();

    expect(AdCategory::pluck('name')->all())->toBe(['Menu']);
});

test('another organization’s ad is never put in a category, nor found through one', function () {
    $theirs = BuilderAd::factory()->create(['organization_id' => $this->beta->id, 'name' => 'Beta lunch']);
    $designer = createOrganizationUser($this->alpha, ['ad-view', 'ad-store', 'ad-update'], 'Designer');
    $this->actingAs($designer)->withSession(['current_organization_id' => $this->alpha->id]);

    $this->putJson("/builder/{$theirs->id}", ['name' => 'Beta lunch', 'document' => $theirs->document, 'categories' => [$this->menu->id]])
        ->assertNotFound();
    $this->postJson("/builder/{$theirs->id}/duplicate")->assertNotFound();
    expect(DB::table('ad_category_builder_ad')->count())->toBe(0);

    $theirs->categories()->attach($this->menu->id);
    expect($this->getJson("/builder/data?category={$this->menu->id}")->assertOk()->json('ads'))->toBe([]);

    // Nor a platform draft through the gallery: only published templates are listed there.
    BuilderAd::factory()->create(['organization_id' => null, 'name' => 'Platform draft'])->categories()->attach($this->menu->id);
    expect($this->getJson("/builder/templates?orientation=landscape&category={$this->menu->id}")->assertOk()->json('templates'))->toBe([]);
});

test('ids and payloads nobody sends are refused, never a 500, and change nothing', function () {
    $this->actingAs(createSuperAdmin());

    foreach (['abc', '0', '-1', '999999', str_repeat('9', 30)] as $id) {
        $put = $this->putJson("/builder/categories/{$id}", ['name' => 'Taken']);
        $delete = $this->deleteJson("/builder/categories/{$id}");

        expect($put->status())->toBe(404, "PUT {$id}")->and($delete->status())->toBe(404, "DELETE {$id}");
    }

    foreach ([['name' => ['a' => 'b']], ['name' => 42], ['name' => null], ['name' => "Menu\u{200B}"], []] as $payload) {
        expect($this->postJson('/builder/categories', $payload)->status())->toBe(422, json_encode($payload));
    }

    foreach (['search[]=x', 'search[a]=b', 'page[]=1', 'per_page=999999'] as $query) {
        $this->getJson('/builder/categories/data?'.$query)->assertOk();
    }

    expect(AdCategory::pluck('name')->all())->toBe(['Menu']);
});

test('the editor’s address asking for categories by hand gets only those on the list', function () {
    $designer = createOrganizationUser($this->alpha, ['ad-view', 'ad-store'], 'Designer');
    $this->actingAs($designer)->withSession(['current_organization_id' => $this->alpha->id]);

    foreach (['categories[]=1', 'categories[a]=1', 'categories='.str_repeat('9', 40), "categories={$this->menu->id}.0", "categories={$this->menu->id}x", 'categories=1;DROP'] as $query) {
        $page = $this->get("/builder/create?orientation=landscape&{$query}");

        expect($page->status())->toBe(200, $query)->and($page->viewData('categoryIds'))->toBe([], $query);
    }
});
