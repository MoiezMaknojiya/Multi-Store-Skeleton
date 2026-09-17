<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Three kinds of role (docs/STORE-ORGANIZATION-SPEC.md §4): a store role (not global, no store) is made by the
     * super admin and offered in every store; a custom role (a store) is that store's own; a platform role (global)
     * is held on the store_id = 0 membership.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // What the code recognises a role by — never its name, which a person may reword: `owner` marks the
            // Owner role; the other starter roles keep keys that give no power.
            $table->string('key', 32)->nullable()->unique();
            $table->boolean('is_global')->default(false);
            // A custom role's store. Deleting the store takes its custom roles with it, so one can never outlive
            // its store and turn into a store role offered everywhere.
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
