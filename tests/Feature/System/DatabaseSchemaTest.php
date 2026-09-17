<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| What the migrations leave behind
|--------------------------------------------------------------------------
|
| The rules that live in the schema and the starting data rather than in code, checked on the
| database the tests run on — before any seeder.
|
*/

test('a fresh install starts with the whole permission catalogue and the four starter store roles', function () {
    expect(DB::table('permissions')->pluck('label', 'name')->sortKeys()->all())->toBe(collect(Permission::LABELS)->sortKeys()->all());

    foreach (Role::STARTERS as $key => $starter) {
        $role = Role::starter($key);

        expect($role->name)->toBe($starter['name'])
            ->and($role->is_global)->toBeFalse()
            ->and($role->store_id)->toBeNull()
            ->and($role->permissions()->pluck('name')->sort()->values()->all())
            ->toBe(collect(Role::starterPermissions($key))->sort()->values()->all());
    }

    expect(Role::count())->toBe(count(Role::STARTERS));
});

test('channels and the activity log carry a store, and a channel name need not be unique', function () {
    expect(Schema::hasColumn('channels', 'store_id'))->toBeTrue()
        ->and(Schema::hasIndex('channels', ['name'], 'unique'))->toBeFalse()
        ->and(Schema::hasColumn('activity_logs', 'store_id'))->toBeTrue()
        ->and(Schema::hasIndex('activity_logs', ['store_id', 'created_at']))->toBeTrue();
});

test('a store is deleted for good, and the database itself takes its custom roles with it', function () {
    $foreignKey = collect(Schema::getForeignKeys('roles'))->first(fn (array $key) => $key['columns'] === ['store_id']);

    expect(Schema::hasColumn('stores', 'deleted_at'))->toBeFalse()
        ->and($foreignKey['on_delete'])->toBe('cascade');

    $store = Store::factory()->create();
    $role = Role::create(['name' => 'Night Shift', 'store_id' => $store->id]);
    DB::table('stores')->where('id', $store->id)->delete();

    expect(Role::find($role->id))->toBeNull();
});

test('nothing of the old people model, or of API tokens, is left in the schema', function () {
    expect(Schema::hasColumn('users', 'created_by'))->toBeFalse()
        ->and(Schema::hasColumn('roles', 'is_signup_default'))->toBeFalse()
        ->and(Schema::hasTable('personal_access_tokens'))->toBeFalse();
});
