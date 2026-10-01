<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The database says organization where it said store (owner, 2026-10-01: "ab haar jagha organization kardo, Database
 * mein aur jaha jaha bhi store use huwa ha usko organization kardo"): `stores` becomes `organizations`, `store_user`
 * becomes `organization_user`, every `store_id` becomes `organization_id`, and every index and foreign key named after
 * them is named again. The data follows: the four permissions about organizations (`store-view` becomes
 * `organization-view` …), the logged actions (`store.created` becomes `organization.created` …) and the subject a log line
 * names (`Store` becomes `Organization`, the model's new name). Rows keep their ids, so every role keeps what it holds.
 *
 * The migrations before this one keep their words: they are what every database already ran, and this one renames what
 * they made. Index and key names are read from the database rather than written here, because the owner's own database
 * was built by migrations that were squashed later, and a name it carries may not be the one a fresh install gets.
 * On SQLite a foreign key has no name and follows its column and its table by itself; on MySQL each key is dropped
 * and added again under its new name.
 */
return new class extends Migration
{
    /** @var array<string, string> the tables named after the organization, by their old names */
    private const TABLES = ['stores' => 'organizations', 'store_user' => 'organization_user'];

    /** @var list<string> the tables that name an organization in a column, by their old names */
    private const OWNED = [
        'activity_logs', 'builder_ads', 'builder_assets', 'channels', 'dayparts', 'invitations', 'media', 'roles',
        'screens', 'store_user', 'uploads',
    ];

    /** @var array<string, string> the permissions about organizations, by their old names */
    private const PERMISSIONS = [
        'store-view' => 'organization-view',
        'store-store' => 'organization-store',
        'store-update' => 'organization-update',
        'store-destroy' => 'organization-destroy',
    ];

    public function up(): void
    {
        $this->rename('store', 'organization', self::TABLES, self::PERMISSIONS, 'Store', 'Organization');
    }

    public function down(): void
    {
        $this->rename('organization', 'store', array_flip(self::TABLES), array_flip(self::PERMISSIONS), 'Organization', 'Store');
    }

    /**
     * @param  array<string, string>  $tables  the renamed tables, from their names now to their names after
     * @param  array<string, string>  $permissions  the renamed permissions, likewise
     */
    private function rename(string $from, string $to, array $tables, array $permissions, string $fromModel, string $toModel): void
    {
        $named = fn (string $name): string => str_replace($from, $to, $name);
        $mysql = DB::getDriverName() !== 'sqlite';

        // Every table this touches, by its name now: the owned ones (store_user among them) and the organizations' own.
        $now = array_values(array_unique(array_map(
            fn (string $table): string => $from === 'store' ? $table : (self::TABLES[$table] ?? $table),
            [...self::OWNED, 'stores'],
        )));

        // 1. What carries the old word, read before anything moves: the keys that name it, point at its table or hold its
        //    column, and the indexes that name it.
        $keys = [];
        $indexes = [];
        foreach ($now as $table) {
            foreach (Schema::getForeignKeys($table) as $key) {
                if (str_contains($key['name'], $from) || in_array("{$from}_id", $key['columns'], true) || isset($tables[$key['foreign_table']])) {
                    $keys[] = ['table' => $table, ...$key];
                }
            }

            foreach (Schema::getIndexes($table) as $index) {
                if (! $index['primary'] && ! str_starts_with($index['name'], 'sqlite_') && str_contains($index['name'], $from)) {
                    $indexes[] = ['table' => $table, 'name' => $index['name']];
                }
            }
        }

        // 2. MySQL cannot rename a foreign key: it goes now and comes back under its new name at the end.
        if ($mysql) {
            foreach ($keys as $key) {
                Schema::table($key['table'], fn (Blueprint $table) => $table->dropForeign($key['name']));
            }
        }

        // 3. The columns, then the tables.
        foreach ($now as $table) {
            if (Schema::hasColumn($table, "{$from}_id")) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->renameColumn("{$from}_id", "{$to}_id"));
            }
        }

        foreach ($tables as $old => $new) {
            Schema::rename($old, $new);
        }

        // 4. The indexes, on the tables' new names.
        foreach ($indexes as $index) {
            Schema::table($tables[$index['table']] ?? $index['table'],
                fn (Blueprint $table) => $table->renameIndex($index['name'], $named($index['name'])));
        }

        // 5. The keys again, as they were but for the names.
        if ($mysql) {
            foreach ($keys as $key) {
                Schema::table($tables[$key['table']] ?? $key['table'], function (Blueprint $table) use ($key, $named, $tables, $from, $to) {
                    $table->foreign(array_map(fn (string $column) => $column === "{$from}_id" ? "{$to}_id" : $column, $key['columns']), $named($key['name']))
                        ->references($key['foreign_columns'])
                        ->on($tables[$key['foreign_table']] ?? $key['foreign_table'])
                        ->onUpdate($key['on_update'])
                        ->onDelete($key['on_delete']);
                });
            }
        }

        // 6. The data that names them. Compared in PHP: a collation would match other capitals too.
        DB::transaction(function () use ($from, $to, $permissions, $fromModel, $toModel) {
            foreach (DB::table('permissions')->whereIn('name', array_keys($permissions))->get(['id', 'name']) as $permission) {
                if (isset($permissions[$permission->name])) {
                    DB::table('permissions')->where('id', $permission->id)->update(['name' => $permissions[$permission->name], 'updated_at' => now()]);
                }
            }

            foreach (DB::table('activity_logs')->distinct()->pluck('action') as $action) {
                if (is_string($action) && str_starts_with($action, "{$from}.")) {
                    DB::table('activity_logs')->where('action', $action)->update(['action' => $to.substr($action, strlen($from))]);
                }
            }

            DB::table('activity_logs')->where('subject_type', $fromModel)->update(['subject_type' => $toModel]);
        });
    }
};
