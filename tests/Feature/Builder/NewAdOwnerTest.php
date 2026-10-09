<?php

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Organization;

/*
|--------------------------------------------------------------------------
| Above the organizations, whose new ad it is is asked with its shape
|--------------------------------------------------------------------------
|
| Owner, 2026-10-09: "jab super admin mein ad builder k ander orientation select karte han wahi per organization select karne ka
| do ander mat do woo hard ha." Create Ad's dialog asks For — Platform first, then each organization — beside Landscape and
| Portrait; the editor opens for that organization (`organization_id` in its address), shows only a badge saying whose it is, and
| has no list to change it. An organization's own people are never asked, and an organization they name is not listened to.
|
*/

beforeEach(function () {
    $this->alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->beta = Organization::factory()->create(['name' => 'Beta Deli']);
});

test('above the organizations Create Ad asks For with the shape: Platform first, then every organization', function () {
    $html = $this->actingAs(createSuperAdmin())->get('/builder')->assertOk()->getContent();

    expect($html)->toContain('dusk="new-ad-owner"')
        ->and($html)->toMatch('/<select id="new-ad-owner"[^>]*>\s*<option value="platform">Platform<\/option>\s*<optgroup label="Organizations">/')
        ->and($html)->toContain('<option value="'.$this->alpha->id.'">Alpha Mart</option>')
        ->and($html)->toContain('<option value="'.$this->beta->id.'">Beta Deli</option>')
        // The two shapes carry the choice into the editor's address.
        ->and($html)->toContain("x-bind:href=\"'".route('builder.create', ['orientation' => 'landscape'])."' + newAdOwnerQuery()\"")
        ->and($html)->toContain("x-bind:href=\"'".route('builder.create', ['orientation' => 'portrait'])."' + newAdOwnerQuery()\"");
});

test('inside an organization Create Ad never asks whose: the ad is the organization\'s own', function () {
    $designer = createOrganizationUser($this->alpha, ['ad-view', 'ad-store', 'ad-update'], 'Designer');

    $this->actingAs($designer)->withSession(['current_organization_id' => $this->alpha->id])
        ->get('/builder')->assertOk()
        ->assertDontSee('dusk="new-ad-owner"', false)
        ->assertDontSee('Beta Deli');
});

test('the editor opens for the organization chosen: its badge, its own shelf, and the save names it', function () {
    $alphaFile = BuilderAsset::factory()->create(['organization_id' => $this->alpha->id]);
    $betaFile = BuilderAsset::factory()->create(['organization_id' => $this->beta->id]);
    $platformFile = BuilderAsset::factory()->create(['organization_id' => null]);

    foreach ([createSuperAdmin(), createPlatformUser(['ad-view', 'ad-store', 'ad-update'], 'Platform designer')] as $person) {
        $response = $this->actingAs($person)->get('/builder/create?orientation=portrait&organization_id='.$this->alpha->id)->assertOk()
            ->assertViewHas('newAdOrganizationId', $this->alpha->id)
            ->assertViewHas('orientation', 'portrait')
            ->assertViewHas('assets', fn (array $assets) => collect($assets)->pluck('id')->all() === [$alphaFile->id]);

        expect($response->getContent())
            ->toMatch('/<span class="badge-neutral shrink-0"[^>]*dusk="ad-owner">Alpha Mart<\/span>/')
            ->not->toContain('dusk="ad-organization"')
            ->not->toContain('Beta Deli');

        expect([$betaFile->id, $platformFile->id])->each->not->toBeIn(collect($response->viewData('assets'))->pluck('id')->all());
    }

    // What the editor then sends: the organization the address named.
    $this->postJson('/builder', [
        'name' => 'Alpha poster',
        'orientation' => 'portrait',
        'organization_id' => $this->alpha->id,
        'document' => BuilderAd::blankDocument('portrait'),
    ])->assertOk();

    expect(BuilderAd::firstWhere('name', 'Alpha poster')->organization_id)->toBe($this->alpha->id);
});

test('with no organization named the new ad is the platform\'s, and says so', function () {
    $platformFile = BuilderAsset::factory()->create(['organization_id' => null]);
    BuilderAsset::factory()->create(['organization_id' => $this->alpha->id]);

    $response = $this->actingAs(createSuperAdmin())->get('/builder/create?orientation=landscape')->assertOk()
        ->assertViewHas('newAdOrganizationId', null)
        ->assertViewHas('assets', fn (array $assets) => collect($assets)->pluck('id')->all() === [$platformFile->id]);

    expect($response->getContent())->toMatch('/<span class="badge-info shrink-0"[^>]*dusk="ad-owner">Platform<\/span>/');
});

test('an organization that is not there, or no plain id, sends the question back', function (string $query) {
    $this->actingAs(createSuperAdmin())->get('/builder/create?orientation=landscape&'.$query)
        ->assertRedirect(route('builder.index', ['new' => 1]));
})->with([
    'no such organization' => ['organization_id=999999'],
    'nought' => ['organization_id=0'],
    'a word' => ['organization_id=platform'],
    'a negative number' => ['organization_id=-1'],
    'a list' => ['organization_id[]=1'],
    'too long a number' => ['organization_id=99999999999999999999999'],
]);

test('an organization\'s person who names another organization is not listened to', function () {
    $designer = createOrganizationUser($this->alpha, ['ad-view', 'ad-store', 'ad-update'], 'Designer');
    $own = BuilderAsset::factory()->create(['organization_id' => $this->alpha->id]);
    BuilderAsset::factory()->create(['organization_id' => $this->beta->id]);

    $response = $this->actingAs($designer)->withSession(['current_organization_id' => $this->alpha->id])
        ->get('/builder/create?orientation=landscape&organization_id='.$this->beta->id)->assertOk()
        ->assertViewHas('newAdOrganizationId', null)
        ->assertViewHas('ownerLabel', null)
        ->assertViewHas('assets', fn (array $assets) => collect($assets)->pluck('id')->all() === [$own->id]);

    expect($response->getContent())->not->toContain('dusk="ad-owner"')->not->toContain('Beta Deli');

    // Nor does a save that names it: the ad is the organization worked in.
    $this->postJson('/builder', [
        'name' => 'Own poster',
        'orientation' => 'landscape',
        'organization_id' => $this->beta->id,
        'document' => BuilderAd::blankDocument('landscape'),
    ])->assertOk();

    expect(BuilderAd::firstWhere('name', 'Own poster')->organization_id)->toBe($this->alpha->id);
});
