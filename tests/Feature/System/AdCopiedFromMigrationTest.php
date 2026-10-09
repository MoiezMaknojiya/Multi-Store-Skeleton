<?php

use App\Models\BuilderAd;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Which Premium Template an organization's ad was made from (owner, 2026-10-08)
|--------------------------------------------------------------------------
|
| Above the organizations a template and an organization's copy of it look alike, so the copy says "Copied from a Premium
| Template". The copies `2026_10_08_100200` made before the column existed are found through their published page, which that
| migration stamped with the template's page (`media.copied_from_id`).
|
*/

function adCopiedFromMigration(): object
{
    return require database_path('migrations/2026_10_08_100400_add_copied_from_id_to_builder_ads_table.php');
}

beforeEach(function () {
    Storage::fake('public');
});

test('a copy made before the column names its template, and nothing else is named', function () {
    $organization = Organization::factory()->create(['name' => 'Smart Stop']);
    $template = BuilderAd::factory()->state(['organization_id' => null, 'name' => 'Burger menu'])->published()->create();
    $copy = BuilderAd::factory()->state(['organization_id' => $organization->id, 'name' => 'Burger menu'])->published()->create();
    $own = BuilderAd::factory()->state(['organization_id' => $organization->id, 'name' => 'Our deals'])->published()->create();
    $draft = BuilderAd::factory()->create(['organization_id' => $organization->id, 'name' => 'Not yet']);
    $copy->media->forceFill(['copied_from_id' => $template->media_id])->save();

    adCopiedFromMigration()->down();
    expect(Schema::hasColumn('builder_ads', 'copied_from_id'))->toBeFalse();

    adCopiedFromMigration()->up();

    expect(DB::table('builder_ads')->pluck('copied_from_id', 'id')->all())
        ->toBe([$template->id => null, $copy->id => $template->id, $own->id => null, $draft->id => null]);

    // Harmless a second time, and the template's going leaves the copy unnamed rather than pointing at nothing.
    adCopiedFromMigration()->up();
    $template->delete();

    expect($copy->fresh()->copied_from_id)->toBeNull();
});

test('down takes the column away, and up brings it back', function () {
    adCopiedFromMigration()->down();
    adCopiedFromMigration()->down();
    expect(Schema::hasColumn('builder_ads', 'copied_from_id'))->toBeFalse();

    adCopiedFromMigration()->up();
    expect(Schema::hasColumn('builder_ads', 'copied_from_id'))->toBeTrue();
});
