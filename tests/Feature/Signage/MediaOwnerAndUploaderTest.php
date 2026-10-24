<?php

use App\Models\Media;
use App\Models\Organization;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The Media page above the organizations: every library at once, and whose each file is (owner, 2026-10-23)
|--------------------------------------------------------------------------
|
| "media library k ander jo selectbox ha jis mein platform library likha ha … us mein all ka option bhi dalo aur listing
| mein kis organization ya user name upload kiya ha woo bhi dalo": the Library list offers All Libraries, and every row
| says whose library the file is in and who uploaded it, by name alone. Inside an organization nothing changes: every
| file there is its own.
|
*/

test('above the organizations every row says whose library it is in and who uploaded it, by name alone', function () {
    $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
    $admin = createSuperAdmin(['media-view']);
    $admin->update(['first_name' => 'Admin', 'last_name' => 'Momin']);
    $member = createOrganizationUser($alpha, ['media-view']);
    $member->update(['first_name' => 'Zohaib', 'last_name' => 'Momin', 'email' => 'zohaib@example.com']);

    Media::factory()->platformOwned()->create(['title' => 'Platform promo', 'created_by' => $admin->id]);
    Media::factory()->create(['organization_id' => $alpha->id, 'title' => 'Alpha poster', 'created_by' => $member->id]);
    // Uploaded by an account deleted since: the foreign key emptied it.
    Media::factory()->create(['organization_id' => $alpha->id, 'title' => 'Old menu', 'created_by' => null]);

    $answer = $this->actingAs($admin)->getJson('/media/data')->assertOk();
    $rows = collect($answer->json('media'))->keyBy('title');

    expect($rows->map(fn (array $row) => [$row['owner_label'], $row['uploaded_by']])->sortKeys()->all())->toBe([
        'Alpha poster' => ['Alpha Mart', 'Zohaib Momin'],
        'Old menu' => ['Alpha Mart', null],
        'Platform promo' => ['Platform', 'Admin Momin'],
    ])
        // The name, never the account behind it.
        ->and($rows['Alpha poster'])->not->toHaveKey('uploader')
        ->and($answer->getContent())->not->toContain('zohaib@example.com');
});

test('inside an organization the rows say nothing of owners or uploaders: every file there is its own', function () {
    $alpha = Organization::factory()->create();
    $member = createOrganizationUser($alpha, ['media-view']);
    Media::factory()->create(['organization_id' => $alpha->id, 'created_by' => $member->id]);

    $row = $this->actingAs($member)->withSession(['current_organization_id' => $alpha->id])
        ->getJson('/media/data')->assertOk()->json('media.0');

    expect($row)->not->toHaveKey('owner_label')->not->toHaveKey('uploaded_by')->not->toHaveKey('uploader');
});

test('the names are read in one query however many files the page lists', function () {
    $alpha = Organization::factory()->create();
    $admin = createSuperAdmin(['media-view']);
    $queries = function () use ($admin): int {
        $count = 0;
        DB::listen(function (QueryExecuted $query) use (&$count) {
            $count += str_contains($query->sql, '"users"') ? 1 : 0;
        });
        $this->actingAs($admin)->getJson('/media/data')->assertOk();

        return $count;
    };

    Media::factory()->count(2)->create(['organization_id' => $alpha->id, 'created_by' => createOrganizationUser($alpha, ['media-view'])->id]);
    $few = $queries();
    Media::factory()->count(8)->sequence(fn () => ['created_by' => createOrganizationUser($alpha, ['media-view'], 'Role '.fake()->unique()->word())->id])
        ->create(['organization_id' => $alpha->id]);

    expect($queries())->toBe($few);
});
