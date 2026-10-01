<?php

use App\Models\Media;
use App\Models\Organization;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| The database says organization, and would go back unharmed (owner, 2026-10-01)
|--------------------------------------------------------------------------
|
| "Ab haar jagha organization kardo, Database mein aur jaha jaha bhi store use huwa ha usko organization kardo."
| `stores` is `organizations`, `store_user` is `organization_user`, every `store_id` is `organization_id`, and the
| indexes, the keys, the four permissions, the logged actions and the subject a log line names follow. Going back
| must give every name back and lose no row; coming forward again must leave the keys working as before.
|
*/

/** Every name the database gives a table, a column or an index, lower-cased. */
function namesInTheDatabase(): array
{
    $names = [];
    foreach (Schema::getTables() as $table) {
        $names[] = $table['name'];
        array_push($names, ...Schema::getColumnListing($table['name']));
        array_push($names, ...array_column(Schema::getIndexes($table['name']), 'name'));
    }

    return array_map('strtolower', $names);
}

test('the database names no store anywhere: tables, columns, indexes and keys all say organization', function () {
    $named = array_filter(namesInTheDatabase(), fn (string $name) => preg_match('/store|shop/', $name) === 1
        && ! str_starts_with($name, 'sqlite_'));

    expect($named)->toBe([])
        ->and(Schema::hasTable('organizations'))->toBeTrue()
        ->and(Schema::hasTable('organization_user'))->toBeTrue();

    foreach (['activity_logs', 'builder_ads', 'builder_assets', 'channels', 'dayparts', 'invitations', 'media', 'roles',
        'screens', 'organization_user', 'uploads'] as $table) {
        expect(Schema::hasColumn($table, 'organization_id'))->toBeTrue("{$table} has no organization_id");
    }

    $mediaKey = collect(Schema::getForeignKeys('media'))->firstWhere('columns', ['organization_id']);
    expect($mediaKey['foreign_table'])->toBe('organizations')->and($mediaKey['on_delete'])->toBe('cascade');

    expect(DB::table('permissions')->whereIn('name', ['organization-view', 'organization-store', 'organization-update', 'organization-destroy'])->count())
        ->toBe(4)
        ->and(DB::table('permissions')->where('name', 'like', 'store-%')->count())->toBe(0);
});

test('going back gives every name back and keeps every row; coming forward again keeps the keys working', function () {
    $organization = Organization::factory()->create(['name' => 'Alpha Clinic']);
    $owner = createOrganizationMember($organization);
    $file = Media::factory()->create(['organization_id' => $organization->id, 'title' => 'Lobby poster']);
    $role = Role::create(['name' => 'Clinic Desk', 'organization_id' => $organization->id]);
    DB::table('activity_logs')->insert([
        'actor_id' => $owner->id, 'actor_name' => $owner->name, 'organization_id' => $organization->id,
        'action' => 'organization.created', 'subject_type' => 'Organization', 'subject_id' => $organization->id,
        'description' => 'Created organization Alpha Clinic', 'created_at' => now(),
    ]);

    $migration = require database_path('migrations/2026_10_01_150000_call_stores_organizations_in_the_database.php');
    $migration->down();

    expect(Schema::hasTable('stores'))->toBeTrue()
        ->and(Schema::hasTable('store_user'))->toBeTrue()
        ->and(Schema::hasTable('organizations'))->toBeFalse()
        ->and(DB::table('stores')->where('id', $organization->id)->value('name'))->toBe('Alpha Clinic')
        ->and(DB::table('store_user')->where('user_id', $owner->id)->value('store_id'))->toBe($organization->id)
        ->and(DB::table('media')->where('id', $file->id)->value('store_id'))->toBe($organization->id)
        ->and(DB::table('roles')->where('id', $role->id)->value('store_id'))->toBe($organization->id)
        ->and(collect(Schema::getIndexes('media'))->pluck('name'))->toContain('media_store_id_created_at_index')
        ->and(DB::table('permissions')->where('name', 'store-view')->exists())->toBeTrue()
        ->and(DB::table('permissions')->where('name', 'organization-view')->exists())->toBeFalse()
        ->and(DB::table('activity_logs')->where('subject_id', $organization->id)->first(['action', 'subject_type', 'store_id']))
        ->toEqual((object) ['action' => 'store.created', 'subject_type' => 'Store', 'store_id' => $organization->id]);

    $migration->up();

    expect(DB::table('organization_user')->where('user_id', $owner->id)->value('organization_id'))->toBe($organization->id)
        ->and(DB::table('media')->where('id', $file->id)->value('organization_id'))->toBe($organization->id)
        ->and(DB::table('activity_logs')->where('subject_id', $organization->id)->value('action'))->toBe('organization.created')
        ->and(array_filter(namesInTheDatabase(), fn (string $name) => str_contains($name, 'store') && ! str_starts_with($name, 'sqlite_')))->toBe([]);

    // The keys still hold: the organization's row going takes its files and its custom roles with it.
    DB::table('organizations')->where('id', $organization->id)->delete();

    expect(DB::table('media')->where('id', $file->id)->exists())->toBeFalse()
        ->and(DB::table('roles')->where('id', $role->id)->exists())->toBeFalse();
});
