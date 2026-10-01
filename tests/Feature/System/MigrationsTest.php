<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| One migration per table (owner, 2026-10-01)
|--------------------------------------------------------------------------
|
| "Itni sari migration ki file ban gae ha, isko kum kar sakte ha? Har table ki ek, jese hum fresh kaam shuru karte
| ha." The forty migrations the app had grown were written anew as one per table, building the very same database.
| A database the old ones built has every table already, so each file leaves a table that is there alone — which
| is also why a file is never edited to change a database that exists: a change is a new migration.
|
*/

/** The tables Laravel's own three migrations make, several to a file. */
const LARAVELS_OWN_TABLES = [
    'users', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
];

/** @return list<string> the migration files, in the order they run */
function migrationFiles(): array
{
    $files = glob(database_path('migrations/*.php'));
    sort($files);

    return $files;
}

/** @return list<string> */
function tablesOfTheApp(): array
{
    return collect(Schema::getTables())->pluck('name')
        ->reject(fn (string $table) => $table === 'migrations' || str_starts_with($table, 'sqlite_'))
        ->sort()->values()->all();
}

/**
 * Everything the schema says about every table, and how many rows each holds, to compare two moments.
 *
 * @return array<string, array<string, mixed>>
 */
function schemaSnapshot(): array
{
    $snapshot = [];

    foreach (tablesOfTheApp() as $table) {
        $snapshot[$table] = [
            'columns' => array_map(fn (array $column) => [$column['name'], $column['type'], $column['nullable'], $column['default']], Schema::getColumns($table)),
            'indexes' => collect(Schema::getIndexes($table))->map(fn (array $index) => [$index['name'], $index['columns'], $index['unique']])->sortBy(0)->values()->all(),
            'keys' => collect(Schema::getForeignKeys($table))->map(fn (array $key) => [$key['columns'], $key['foreign_table'], $key['on_delete']])->sort()->values()->all(),
            'rows' => DB::table($table)->count(),
        ];
    }

    return $snapshot;
}

test('every table has a migration of its own, and nothing in the database is named after a store', function () {
    $files = array_map(fn (string $file) => basename($file, '.php'), migrationFiles());

    foreach (array_diff(tablesOfTheApp(), LARAVELS_OWN_TABLES) as $table) {
        $own = array_filter($files, fn (string $file) => str_ends_with($file, "_create_{$table}_table"));

        expect($own)->toHaveCount(1, "{$table} is not made by one migration of its own.");
    }

    $names = [];
    foreach (tablesOfTheApp() as $table) {
        array_push($names, $table, ...Schema::getColumnListing($table), ...array_column(Schema::getIndexes($table), 'name'));
    }

    expect(array_values(array_filter($names, fn (string $name) => preg_match('/store|shop/i', $name) === 1)))->toBe([])
        ->and(array_values(array_filter($files, fn (string $file) => preg_match('/store|shop/i', $file) === 1)))->toBe([])
        ->and(Schema::hasTable('organizations'))->toBeTrue()
        ->and(Schema::hasTable('organization_user'))->toBeTrue();

    foreach (['activity_logs', 'builder_ads', 'builder_assets', 'channels', 'dayparts', 'invitations', 'media', 'roles',
        'screens', 'organization_user', 'uploads'] as $table) {
        expect(Schema::hasColumn($table, 'organization_id'))->toBeTrue("{$table} has no organization_id");
    }

    $mediaKey = collect(Schema::getForeignKeys('media'))->firstWhere('columns', ['organization_id']);
    expect($mediaKey['foreign_table'])->toBe('organizations')->and($mediaKey['on_delete'])->toBe('cascade');
});

test('a database that has its tables already is left exactly as it was by every migration', function () {
    Role::create(['name' => 'Night Desk']);
    $before = schemaSnapshot();

    foreach (migrationFiles() as $file) {
        // Laravel's own three kept their names: a database built earlier ran them under those and never runs them again.
        if (str_contains(basename($file), '0001_01_01_')) {
            continue;
        }

        (require $file)->up();
    }

    expect(schemaSnapshot())->toBe($before);
});

test('an installation that has its catalogue keeps it, and forgets the migrations these files replaced', function () {
    DB::table('permissions')->where('name', 'media-view')->update(['label' => 'See the Library']);
    Role::starter(Role::STAFF)->delete();
    $recorded = DB::table('migrations')->count();
    DB::table('migrations')->insert([
        ['migration' => '0001_01_01_000005_stores', 'batch' => 1],
        ['migration' => '2026_10_01_150000_call_stores_organizations_in_the_database', 'batch' => 26],
    ]);

    (require database_path('migrations/2026_10_01_202300_insert_permissions_and_starter_roles.php'))->up();

    expect(DB::table('permissions')->where('name', 'media-view')->value('label'))->toBe('See the Library')
        ->and(DB::table('permissions')->count())->toBe(count(Permission::LABELS))
        ->and(Role::where('key', Role::STAFF)->exists())->toBeFalse()
        ->and(DB::table('migrations')->where('migration', 'like', '%store%')->count())->toBe(0)
        ->and(DB::table('migrations')->count())->toBe($recorded)
        ->and(DB::table('migrations')->pluck('migration')->sort()->values()->all())
        ->toBe(array_map(fn (string $file) => basename($file, '.php'), migrationFiles()));
});

test('rolled back, every table goes in an order the keys allow, and comes back the same', function () {
    $before = array_map(fn (array $table) => [...$table, 'rows' => 0], schemaSnapshot());

    foreach (array_reverse(migrationFiles()) as $file) {
        (require $file)->down();
    }

    expect(tablesOfTheApp())->toBe([]);

    foreach (migrationFiles() as $file) {
        (require $file)->up();
    }

    $after = schemaSnapshot();
    // What the migrations themselves insert comes back with them.
    foreach (['permissions', 'roles', 'role_has_permissions'] as $table) {
        expect($after[$table]['rows'])->toBeGreaterThan(0);
        $after[$table]['rows'] = 0;
    }

    expect($after)->toBe($before);
});
